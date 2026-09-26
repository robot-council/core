<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Access\AccessList;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\AllowlistRemoval;

/**
 * The allowlist entries an administrator adds beside the environment lists (#406, #312's decision).
 *
 * **Every bound is held here, not only on a page**, because this is a public method on a `final`
 * class a host can resolve and call (`CLAUDE.md`): the ID is read by `Allowlist::githubId()`, the
 * same rule the environment lists are read by, and the login by the GitHub login rule the rest of
 * the package uses. **It refuses rather than truncates**, for `HostKey`'s reason: an entry that
 * named a different account than the one asked for would be an access-control failure.
 *
 * `Access\Allowlist` reads the table; this is its only writer. The page is #407 and the feed
 * events are #408.
 */
final class AllowlistEntries
{
    /**
     * @param  Allowlist  $allowlist  Which entries come from configuration.
     */
    public function __construct(private readonly Allowlist $allowlist) {}

    /**
     * Add a GitHub account to a list.
     *
     * @param  AccessList  $list  The list.
     * @param  mixed  $githubId  The account's numeric GitHub user ID.
     * @param  mixed  $login  Its login as of now, kept for display.
     * @param  int|null  $addedBy  The adding administrator's GitHub user ID, if one did.
     * @return bool True when it was added, false when the table already had it.
     *
     * @throws InvalidArgumentException When the ID is not a positive whole number, or the login is
     *                                  not a GitHub login.
     */
    public function add(AccessList $list, mixed $githubId, mixed $login, ?int $addedBy = null): bool
    {
        $id = Allowlist::githubId($githubId);

        if ($id === null) {
            throw new InvalidArgumentException('A GitHub user ID is a positive whole number.');
        }

        if (! \is_string($login) || preg_match(LaneHolds::LOGIN, $login) !== 1) {
            throw new InvalidArgumentException('A GitHub login is 1 to 39 letters, digits and single hyphens, not leading or trailing.');
        }

        $added = DB::table('robot_council_allowlist_entries')->insertOrIgnore([
            'github_id' => $id,
            'list' => $list->value,
            'login' => $login,
            'added_by' => $addedBy,
            'created_at' => Carbon::now(),
        ]) === 1;

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
     * @param  AccessList  $list  The list.
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @return AllowlistRemoval What came of it.
     */
    public function remove(AccessList $list, int $githubId): AllowlistRemoval
    {
        if (\in_array($githubId, $this->allowlist->configured($list), true)) {
            return AllowlistRemoval::FromConfiguration;
        }

        $deleted = DB::table('robot_council_allowlist_entries')
            ->where('list', $list->value)
            ->where('github_id', $githubId)
            ->delete();

        self::forgetRead();

        return $deleted === 1 ? AllowlistRemoval::Removed : AllowlistRemoval::NotListed;
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
