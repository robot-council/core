<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Access\AccessList;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\AllowlistRemoval;
use RobotCouncil\Models\FleetEventType;

/**
 * The allowlist entries an administrator adds beside the environment lists (#406, #312's decision).
 *
 * **Every bound is held here, not only on a page**, because this is a public method on a `final`
 * class a host can resolve and call (`CLAUDE.md`): the ID is read by `Allowlist::githubId()`, the
 * same rule the environment lists are read by, and the login by the GitHub login rule the rest of
 * the package uses. **It refuses rather than truncates**, for `HostKey`'s reason: an entry that
 * named a different account than the one asked for would be an access-control failure.
 *
 * `Access\Allowlist` reads the table; this is its only writer. The page is #407.
 *
 * **Every change is recorded in the change feed, in the same transaction** (#408), as
 * `allowlist.entry_added` or `allowlist.entry_removed`, so the feed cannot describe a change that
 * did not write, nor a write go unrecorded. A refused or repeated change writes no event. The
 * table row is taken before the feed sentinel; no other path takes this table's rows, so the order
 * cannot invert (`CLAUDE.md`'s lock order).
 */
final class AllowlistEntries
{
    /**
     * @param  Allowlist  $allowlist  Which entries come from configuration.
     * @param  FleetEvents  $events  The change feed each change is recorded in (#408).
     */
    public function __construct(
        private readonly Allowlist $allowlist,
        private readonly FleetEvents $events
    ) {}

    /**
     * Add a GitHub account to a list.
     *
     * @param  AccessList  $list  The list.
     * @param  mixed  $githubId  The account's numeric GitHub user ID.
     * @param  mixed  $login  Its login as of now, kept for display.
     * @param  int|null  $addedBy  The adding administrator's GitHub user ID, if one did.
     * @param  string|null  $actor  The adding administrator's host user key, which the event
     *                              records as who made the change.
     * @return bool True when it was added, false when the table already had it.
     *
     * @throws InvalidArgumentException When the ID is not a positive whole number, the login is not
     *                                  a GitHub login, or the host's configuration already puts the
     *                                  account on that list.
     */
    public function add(AccessList $list, mixed $githubId, mixed $login, ?int $addedBy = null, ?string $actor = null): bool
    {
        $id = Allowlist::githubId($githubId);

        if ($id === null) {
            throw new InvalidArgumentException('A GitHub user ID is a positive whole number.');
        }

        if (! \is_string($login) || preg_match(LaneHolds::LOGIN, $login) !== 1) {
            throw new InvalidArgumentException('A GitHub login is 1 to 39 letters, digits and single hyphens, not leading or trailing.');
        }

        // Refused rather than stored (#407's review): a table row for an account the environment
        // already names changes nothing today, and would quietly keep the account admitted after the
        // host removed it from its configuration -- the one revocation the host believes it made
        if (\in_array($id, $this->allowlist->configured($list), true)) {
            throw new InvalidArgumentException(sprintf(
                'GitHub user %d is already on the %s list through the host configuration (%s). Nothing changed.',
                $id,
                $list->value,
                $list->variable()
            ));
        }

        $added = DB::transaction(function () use ($list, $id, $login, $addedBy, $actor): bool {
            $inserted = DB::table('robot_council_allowlist_entries')->insertOrIgnore([
                'github_id' => $id,
                'list' => $list->value,
                'login' => $login,
                'added_by' => $addedBy,
                'created_at' => Carbon::now(),
            ]) === 1;

            // Only a row that was written is described
            if ($inserted) {
                $this->record(FleetEventType::AllowlistEntryAdded, 'added to', $list, $id, $login, $actor);
            }

            return $inserted;
        });

        self::forgetRead();

        return $added;
    }

    /**
     * Remove a GitHub account from a list.
     *
     * **An entry that comes from configuration is refused, and says so**, whether or not the table
     * also holds it: the account keeps that list while the environment names it, so deleting the
     * table's copy would report a revocation that had not happened.
     *
     * **`Removed` is about this list, not about the account's access.** An account taken off the
     * developer list while it is still an administrator, from either source, is still admitted,
     * because an administrator is. A page reporting a removal reads `Allowlist::admits()` afterwards
     * rather than taking `Removed` to mean the account is out (#407).
     *
     * @param  AccessList  $list  The list.
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @param  string|null  $actor  The removing administrator's host user key, for the event.
     * @return AllowlistRemoval What came of it.
     */
    public function remove(AccessList $list, int $githubId, ?string $actor = null): AllowlistRemoval
    {
        if (\in_array($githubId, $this->allowlist->configured($list), true)) {
            return AllowlistRemoval::FromConfiguration;
        }

        $deleted = DB::transaction(function () use ($list, $githubId, $actor): int {
            $row = DB::table('robot_council_allowlist_entries')
                ->where('list', $list->value)
                ->where('github_id', $githubId)
                ->lockForUpdate()
                ->first(['id', 'login']);

            if ($row === null) {
                return 0;
            }

            // The count of deleted rows decides, as every write here does: a removal racing this one
            // deletes the row first and records it, and this one records nothing
            $deleted = DB::table('robot_council_allowlist_entries')->where('id', $row->id)->delete();

            if ($deleted === 1) {
                $this->record(FleetEventType::AllowlistEntryRemoved, 'removed from', $list, $githubId, \is_string($row->login) ? $row->login : '', $actor);
            }

            return $deleted;
        });

        self::forgetRead();

        return $deleted === 1 ? AllowlistRemoval::Removed : AllowlistRemoval::NotListed;
    }

    /**
     * Record one change in the feed.
     *
     * Attributed to the administrator through `actor`, as `installation.revoked` is, and to no
     * session: an administrator acts through the dashboard, not as a process the fleet knows. The
     * body and `meta` carry only values this store has bounded -- a list name, a whole-number ID,
     * and a login within GitHub's charset -- because the event reaches every session in the fleet.
     *
     * @param  FleetEventType  $type  Added or removed.
     * @param  string  $happened  `added to` or `removed from`.
     * @param  AccessList  $list  The list.
     * @param  int  $githubId  The account.
     * @param  string  $login  Its login, as stored.
     * @param  string|null  $actor  The administrator's host user key.
     */
    private function record(FleetEventType $type, string $happened, AccessList $list, int $githubId, string $login, ?string $actor): void
    {
        $this->events->record(
            $type,
            null,
            sprintf('%s (GitHub user %d) was %s the %s list.', $login, $githubId, $happened, $list === AccessList::Admin ? 'administrator' : 'developer'),
            ['list' => $list->value, 'github_id' => $githubId, 'login' => $login],
            actor: $actor
        );
    }

    /**
     * Drop this request's read of the table, so the next check in the same process sees the change.
     *
     * `Access\Allowlist` is scoped and reads the table once per instance; without this, the request
     * that made the change -- or a test issuing several in one application -- would keep answering
     * from the list as it was.
     */
    private static function forgetRead(): void
    {
        app()->forgetInstance(Allowlist::class);
    }

    /**
     * A list's table entries, earliest first.
     *
     * @param  AccessList  $list  The list.
     * @return list<array{github_id: int, login: string, added_by: int|null, created_at: string}> The entries.
     */
    public function entries(AccessList $list): array
    {
        $entries = [];

        foreach (DB::table('robot_council_allowlist_entries')->where('list', $list->value)->orderBy('id')->get() as $row) {
            // Each column narrowed rather than cast: a driver may hand an integer back as a string
            $entries[] = [
                'github_id' => is_numeric($row->github_id) ? (int) $row->github_id : 0,
                'login' => \is_string($row->login) ? $row->login : '',
                'added_by' => is_numeric($row->added_by) ? (int) $row->added_by : null,
                'created_at' => \is_string($row->created_at) ? $row->created_at : '',
            ];
        }

        return $entries;
    }

    /**
     * Why a refusal to remove an entry is a refusal, in words a page can show.
     *
     * @param  AccessList  $list  The list.
     * @param  int  $githubId  The account.
     * @return string The reason.
     */
    public static function fromConfiguration(AccessList $list, int $githubId): string
    {
        return sprintf(
            'GitHub user %d is on the %s list through the host configuration (%s), so it can only be removed there.',
            $githubId,
            $list->value,
            $list->variable()
        );
    }
}
