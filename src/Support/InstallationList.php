<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Installation;

/**
 * Every installation, as an admin sees it.
 *
 * Held apart from `Installations` the way `TaskList` is held apart from `Tasks`: that class writes
 * and this one reads, so a change to how an installation is displayed cannot reach the paths that
 * revoke one.
 *
 * **Nothing here returns a credential, and there is no column it could come from.** Sanctum stores
 * a token as a hash, the plaintext exists only in the response that issued it, and a `device_code`
 * and its verifier belong to a different table this class never touches. That is what makes #77's
 * "no page shows a token" criterion a property of the reader rather than a habit of the view.
 */
final class InstallationList
{
    /**
     * The most installations one read returns, whatever a caller asks for.
     */
    public const int MAX_PAGE = 200;

    /**
     * The most sessions shown under one installation.
     *
     * **Bounded because nothing deletes a session row.** `SessionPresence::goesNow()` deletes the
     * tokens and keeps the row, and the only prune in the package is for device codes, so an
     * installation accumulates one row per agent process it has ever started. Unbounded, this
     * panel would hydrate and render every one of them on a `wire:poll` interval.
     * `Support\FleetPresence` bounds its own read the same way.
     */
    public const int SESSIONS_PER_INSTALLATION = 10;

    /**
     * @param  AgentLogins  $logins  The GitHub login behind a host user key.
     */
    public function __construct(private readonly AgentLogins $logins) {}

    /**
     * One page of installations, newest first, with their live sessions.
     *
     * **Keyed on `id`, which never moves.** The obvious alternative -- sorting live rows ahead of
     * retired ones so a revocation cannot push a live installation off the page -- puts a mutable
     * column in the ordering, and a cursor over one of those skips rows silently. #83 records that
     * failure for the presence lists. A scope does the same job without it: a retired installation
     * is not on the page at all rather than sorted below.
     *
     * `Scope::Live` is neither revoked nor expired, which is `Installation::isUsable()` written as
     * a query. The two are shown apart on the page, because a guard treats them alike and an admin
     * does not: one is a decision somebody made and the other is only the clock.
     *
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @param  Scope  $scope  Installations that can still act, or every row.
     * @param  int|null  $after  The id of the last installation the reader has seen.
     * @return array{installations: list<array<string, mixed>>, cursor: int|null, more: bool, live: int, retired: int}
     */
    public function everything(int $limit, Scope $scope = Scope::Live, ?int $after = null): array
    {
        // Eager-loaded rather than read per row. `Model::preventLazyLoading()` raises on a query
        // that hydrated more than one row, so a host running strict mode would take a
        // `LazyLoadingViolationException` off the first page holding two installations.
        //
        // Live sessions first and newest first, so the rows that get shown under the bound below
        // are the ones an admin might act on rather than whichever the engine returned.
        $size = max(1, min($limit, self::MAX_PAGE));

        $now = Carbon::now();

        // One more than the page, so whether a next page exists is known rather than guessed.
        //
        // Fetching one MORE than one extra changes nothing anyone can observe -- `$more` and the
        // page are both taken from `$size` -- so that direction has no input that can kill it.
        // @pest-mutate-ignore: IncrementInteger
        $installations = Installation::query()
            ->with(['sessions' => static fn (Relation $sessions): Relation => $sessions->orderByDesc('id')])
            ->when($scope === Scope::Live, fn (Builder $query) => $query->whereNull('revoked_at')->where('expires_at', '>', $now))
            ->when($after !== null, fn (Builder $query) => $query->where('id', '<', $after))
            ->orderByDesc('id')
            ->limit($size + 1)
            ->get();

        $more = $installations->count() > $size;

        $installations = $installations->take($size);

        $logins = $this->logins->forUsers($installations->pluck('user_id')->all());

        // Both totals in one pass. They were two `count()` queries and the second was only ever
        // `total - live`, so the table was scanned twice to answer one question -- the same shape
        // #192 removed from `FleetPresence`. `sum(case when)` rather than `count(*) filter (where)`,
        // which Postgres and SQLite have and MySQL does not.
        //
        // `$now` is bound rather than interpolated, and `Connection::prepareBindings()` converts a
        // `DateTimeInterface` to the grammar's own date format before it reaches the driver -- the
        // same conversion the `where()` above relies on, so the two cannot disagree about a
        // boundary row.
        $totals = Installation::query()
            ->toBase()
            ->selectRaw('count(*) as total, sum(case when revoked_at is null and expires_at > ? then 1 else 0 end) as live', [$now])
            ->first();

        $live = AggregateCount::from($totals?->live);
        $total = AggregateCount::from($totals?->total);

        return [
            'cursor' => $installations->last()?->id,
            'more' => $more,

            // Counted rather than inferred from the page. A truncated list and a complete one look
            // identical, and this panel is the only interface for revoking a credential.
            'live' => $live,
            'retired' => $total - $live,
            'installations' => array_values($installations->map(fn (Installation $installation): array => [
                'id' => $installation->id,
                'github_login' => $logins[$installation->user_id] ?? null,
                'harness' => $installation->harness,
                'machine_label' => $installation->machine_label,

                // Through the model's own accessor, which drops anything the fixed list no longer
                // holds. A retired ability still sitting in the stored row must not be offered back as
                // a control that revokes it, because the guards no longer check it either.
                'abilities' => $installation->abilities(),

                // Both halves of `isUsable()`, separately. "Revoked" and "expired" are the same to a
                // guard and different to an admin: one is a decision somebody made and the other is
                // the clock, and only the first is worth asking about.
                'revoked' => $installation->revoked_at !== null,
                'expired' => $installation->expires_at->isBefore($now),
                'sessions' => $this->sessionsOf($installation),
            ])->all()),
        ];
    }

    /**
     * One installation's live sessions, with what was left out said rather than implied.
     *
     * A session that has gone is counted, not listed. #75 decided a gone session stays visible on
     * the presence panel, which is where a reader goes to look at one; here it would be a row with
     * no control on it, and it is the kind of row that accumulates forever because nothing deletes
     * a session.
     *
     * @param  Installation  $installation  The installation to read.
     * @return array{shown: list<array<string, mixed>>, hidden: int, gone: int} The sessions to
     *                                                                          list, and the two
     *                                                                          counts that are not
     *                                                                          in that list.
     */
    private function sessionsOf(Installation $installation): array
    {
        $live = $installation->sessions
            ->filter(static fn (AgentSession $session): bool => $session->status !== AgentSessionStatus::Gone);

        $shown = $live->take(self::SESSIONS_PER_INSTALLATION)->sortBy('id');

        return [
            // Every session here is live, so every one can be revoked. There is no `revocable`
            // flag: a boolean that is true for every row it is ever computed on says nothing, and
            // no test could tell it from a constant.
            'shown' => array_values($shown->map(static fn (AgentSession $session): array => [
                'id' => $session->id,

                // Read from the row rather than derived from the contact time, for the reason #24
                // records: the row is the decision every conditional update in the package makes.
                'status' => $session->status->value,

                // What this session may do, which since roles is no longer answered by the
                // installation's `abilities` above. Both are shown because they are now different
                // questions: what this machine is eligible for, and what this process is.
                'role' => $session->role->value,

                // Agent-supplied, charset-limited at the edge by `ProjectId`, and escaped by the
                // view like every other string that reached this package from a machine
                'project_id' => $session->project_id,
            ])->all()),

            // Said rather than left to be inferred from the length of the list. A truncated list
            // and a complete one look identical, which is the whole reason these are here.
            'hidden' => max(0, $live->count() - self::SESSIONS_PER_INSTALLATION),
            'gone' => $installation->sessions->count() - $live->count(),
        ];
    }
}
