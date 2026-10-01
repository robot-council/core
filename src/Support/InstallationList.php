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
    // Reported `uncovered` rather than `untested`: no test reaches this LINE, because a constant
    // declaration is not executed anywhere coverage can see it. The same structural blind spot
    // #232 records for `#[Fillable]`.
    //
    // **The value is pinned to its literal in `tests/AdministrationTest.php`, and it has to be.**
    // An earlier version of this comment claimed the read tests exercised it; they do not. Both
    // tests that bound a session list derive the fixture size AND the expectation from this
    // constant, so the two move together and no change to it can go red. Measured for #283: `10`
    // to `11` left the whole suite green. The pin is what makes this marker honest, because a bare
    // `@pest-mutate-ignore` silences the mutant permanently rather than reporting it each run.
    // @pest-mutate-ignore
    public const int MAX_PAGE = 200;

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
     * @return array{installations: list<array<string, mixed>>, groups: list<array{developer: string|null, continued: bool, machines: list<array{machine: string, continued: bool, installations: list<array<string, mixed>>}>}>, cursor: int|null, more: bool, live: int, retired: int}
     */
    public function everything(int $limit, Scope $scope = Scope::Live, ?int $after = null): array
    {
        // Eager-loaded rather than read per row. `Model::preventLazyLoading()` raises on a query
        // that hydrated more than one row, so a host running strict mode would take a
        // `LazyLoadingViolationException` off the first page holding two installations.
        //
        // Newest first, as read; `sessionsOf()` lists every live one in id order (#414).
        $size = max(1, min($limit, self::MAX_PAGE));

        $now = Carbon::now();

        // One more than the page, so whether a next page exists is known rather than guessed.
        //
        // Fetching one MORE than one extra changes nothing anyone can observe -- `$more` and the
        // page are both taken from `$size` -- so that direction has no input that can kill it.
        // @pest-mutate-ignore: IncrementInteger
        $installations = Installation::query()
            // **Every session but a gone ephemeral one** (#424). `robot-council api` starts one around
            // each read, so a loop of reads leaves a gone row per read: counted, they would inflate
            // this panel's "gone" by the length of the loop, and loaded, every one would be
            // hydrated on each poll. A LIVE ephemeral session is kept, because this panel is where
            // an administrator revokes one.
            ->with(['sessions' => static fn (Relation $sessions): Relation => $sessions
                ->where(static fn (Builder $session): Builder => $session
                    ->where('ephemeral', false)
                    ->orWhere('status', '<>', AgentSessionStatus::Gone->value))
                ->orderByDesc('id')])
            ->when($scope === Scope::Live, fn (Builder $query) => $query->whereNull('revoked_at')->where('expires_at', '>', $now))
            ->when($after !== null, fn (Builder $query) => $query->where('id', '<', $after))
            ->orderByDesc('id')
            ->limit($size + 1)
            ->get();

        // The developer and machine of every installation on an EARLIER page, so a group this page
        // continues says so (#518). Distinct pairs, which is developers times machines rather than
        // installations, and only read past the first page, which has no earlier one.
        $earlier = $after === null ? collect() : Installation::query()
            ->toBase()
            ->when($scope === Scope::Live, fn (\Illuminate\Database\Query\Builder $query) => $query->whereNull('revoked_at')->where('expires_at', '>', $now))
            ->where('id', '>=', $after)
            ->distinct()
            ->get(['user_id', 'machine_label']);

        $more = $installations->count() > $size;

        $installations = $installations->take($size);

        $logins = $this->logins->forUsers([...$installations->pluck('user_id')->all(), ...$earlier->pluck('user_id')->all()]);

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

        // Unkillable, for the reason recorded on the same pair in `Support\FleetPresence`: an
        // aggregate with no `GROUP BY` returns exactly one row whatever the table holds, so
        // `$totals` is never null. Measured against an empty table -- `first()` came back a
        // `stdClass` with `total` 0 and `live` NULL. Kept as the guard against a future `groupBy`.
        // @pest-mutate-ignore: RemoveNullSafeOperator
        $live = AggregateCount::from($totals?->live);
        // @pest-mutate-ignore: RemoveNullSafeOperator
        $total = AggregateCount::from($totals?->total);

        return [
            'cursor' => $installations->last()?->id,
            'more' => $more,

            // Counted rather than inferred from the page. A truncated list and a complete one look
            // identical, and this panel is the only interface for revoking a credential.
            'live' => $live,
            'retired' => $total - $live,
            // Defensive and unkillable HERE: the page is a query result narrowed with
            // `->take($size)`, which slices from zero, so the keys are already 0..n-1. Note this is
            // NOT true of the `shown` list in `sessionsOf()` below, which is load-bearing and has a
            // test. Two calls to the same function, one equivalent and one not.
            //
            // **TWO independent causes make it load-bearing there, and naming only one is how it
            // gets deleted** (#283). An earlier version of this comment named the filter alone, so
            // a reader who removed the filtering would conclude the call had become equivalent.
            // Measured, each sufficient on its own:
            //
            //   - `Collection::filter()` preserves keys, so removing the row at key 0 leaves `[1]`
            //     -- which encodes as `{"1":{…`. The relation is eager-loaded `orderByDesc('id')`,
            //     so key 0 is the NEWEST session, and this is reachable with one gone session.
            //   - `sortBy('id')` over that same descending relation reverses `[0, 1]` into `[1, 0]`
            //     with nothing filtered at all. Measured on a two-live-session fixture: `[0, 1]`
            //     after `filter`, `[0, 1]` after `take`, `[1, 0]` only after `sortBy`. Negative
            //     controls: dropping `sortBy` leaves `[0, 1]`, and `sortBy` over already-ascending
            //     keys leaves `[0, 1]`.
            //
            // `tests/AdminReadShapeTest.php` covers both, one test each, and removing this call
            // turns both red.
            // @pest-mutate-ignore: UnwrapArrayValues
            'installations' => $rows = array_values($installations->map(fn (Installation $installation): array => [
                'id' => $installation->id,
                'github_login' => $logins[$installation->user_id] ?? null,
                'harness' => $installation->harness,
                'machine_label' => $installation->machine_label,

                // Both halves of `isUsable()`, separately. "Revoked" and "expired" are the same to a
                // guard and different to an admin: one is a decision somebody made and the other is
                // the clock, and only the first is worth asking about.
                'revoked' => $installation->revoked_at !== null,
                'expired' => $installation->expires_at->isBefore($now),
                'sessions' => $this->sessionsOf($installation),
            ])->all()),

            'groups' => self::groups($rows, $this->earlierGroups($earlier->all(), $logins)),
        ];
    }

    /**
     * The developer and machine of each installation on an earlier page, by login.
     *
     * @param  array<mixed>  $pairs  Distinct `user_id` and `machine_label` rows.
     * @param  array<string, string>  $logins  GitHub logins by host key.
     * @return list<array{developer: string|null, machine: string}> The pairs.
     */
    private function earlierGroups(array $pairs, array $logins): array
    {
        $groups = [];

        foreach ($pairs as $pair) {
            if (! \is_object($pair)) {
                continue;
            }

            $user = $pair->user_id ?? null;
            $machine = $pair->machine_label ?? null;

            $groups[] = [
                'developer' => \is_string($user) || \is_int($user) ? ($logins[(string) $user] ?? null) : null,
                'machine' => \is_string($machine) ? $machine : '',
            ];
        }

        return $groups;
    }

    /**
     * One page's installations grouped by developer, then machine, then harness (#518).
     *
     * Developers by login without regard to case, with the installations whose developer is not
     * known last; machines by label; harnesses by name, and the newest first where two match. The
     * page is grouped as read, so the cursor stays on `id`: a group that began on an earlier page
     * is marked `continued` rather than moved.
     *
     * @param  list<array<string, mixed>>  $rows  The page's installations.
     * @param  list<array{developer: string|null, machine: string}>  $earlier  Every developer and machine on earlier pages.
     * @return list<array{developer: string|null, continued: bool, machines: list<array{machine: string, continued: bool, installations: list<array<string, mixed>>}>}> The groups.
     */
    private static function groups(array $rows, array $earlier): array
    {
        $byDeveloper = [];

        foreach ($rows as $row) {
            $developer = \is_string($row['github_login'] ?? null) ? $row['github_login'] : '';
            $machine = \is_string($row['machine_label'] ?? null) ? $row['machine_label'] : '';
            $byDeveloper[$developer][$machine][] = $row;
        }

        // Compared as text, never as numbers: `<=>` reads a login or label of digits as a number,
        // and an array key of digits alone becomes an integer, hence the casts. The empty developer
        // is the unknown one, and it sorts after every login
        $text = static fn (int|string $a, int|string $b): int => strcasecmp((string) $a, (string) $b) ?: strcmp((string) $a, (string) $b);
        uksort($byDeveloper, static fn (int|string $a, int|string $b): int => (((string) $a === '') <=> ((string) $b === '')) ?: $text($a, $b));

        $groups = [];

        foreach ($byDeveloper as $developer => $machines) {
            $developer = $developer === '' ? null : $developer;
            uksort($machines, $text);

            $grouped = [];

            foreach ($machines as $machine => $installations) {
                usort($installations, static fn (array $a, array $b): int => $text(\is_string($a['harness'] ?? null) ? $a['harness'] : '', \is_string($b['harness'] ?? null) ? $b['harness'] : '')
                    ?: ($b['id'] ?? 0) <=> ($a['id'] ?? 0));

                $grouped[] = [
                    'machine' => $machine,
                    'continued' => \in_array(['developer' => $developer, 'machine' => $machine], $earlier, true),
                    'installations' => $installations,
                ];
            }

            $groups[] = [
                'developer' => $developer,
                'continued' => \in_array($developer, array_column($earlier, 'developer'), true),
                'machines' => $grouped,
            ];
        }

        return $groups;
    }

    /**
     * Every one of an installation's live sessions, and how many have ended.
     *
     * **Every live session, with no cap** (#414). A cap of ten left the rest counted but unlisted,
     * so an administrator could neither see nor revoke them from the one page meant for it; one
     * machine running build, gate and coordinator seats across several repositories passes ten
     * routinely. Every session is already on the eager-loaded installation, so listing them all
     * adds no query.
     *
     * **What bounds the list is the session-start rate limit, not the machine.** A session that
     * stops heartbeating goes after `presence.gone_after_minutes`, so one that never heartbeats can
     * keep at most `rate_limits.sessions_per_installation` times that many live at once -- 60 a
     * minute for 30 minutes, 1,800 -- and one that does heartbeat keeps them indefinitely. Only an
     * allowlisted developer's approved installation can start sessions at all, so a list that long
     * is a trusted party misbehaving, and this page is where an administrator would go to revoke
     * them: rendering every one is the accepted cost, where hiding some was the defect.
     *
     * A session that has gone is counted, not listed. #75 decided a gone session stays visible on
     * the presence panel, which is where a reader goes to look at one; here it would be a row with
     * no control on it. `robot-council:prune-sessions` deletes gone rows past
     * `retention.sessions_days`.
     *
     * A gone ephemeral session is neither listed nor counted: the eager load in `everything()`
     * never reads one (#424).
     *
     * @param  Installation  $installation  The installation to read.
     * @return array{shown: list<array<string, mixed>>, gone: int} Every live session, and how many
     *                                                             have ended.
     */
    private function sessionsOf(Installation $installation): array
    {
        $live = $installation->sessions
            ->filter(static fn (AgentSession $session): bool => $session->status !== AgentSessionStatus::Gone);

        $shown = $live->sortBy('id');

        return [
            // Every session here is live, so every one can be revoked. There is no `revocable`
            // flag: a boolean that is true for every row it is ever computed on says nothing, and
            // no test could tell it from a constant.
            'shown' => array_values($shown->map(static fn (AgentSession $session): array => [
                'id' => $session->id,

                // Read from the row rather than derived from the contact time, for the reason #24
                // records: the row is the decision every conditional update in the package makes.
                'status' => $session->status->value,

                // What this session may do, and since `robot-council/core#231` the only such answer
                // the panel renders. The machine-level list above reaches no view: a session's
                // abilities come from its role, so a per-machine list could not describe two
                // sessions of one machine that differ.
                'role' => $session->role->value,

                // What it has ASKED to be, which is a different fact from what it is and is the
                // one an administrator acts on. Null when nothing is pending.
                'requested_role' => $session->requested_role?->value,
                'requested_at' => $session->requested_at?->toIso8601String(),

                // Agent-supplied, charset-limited at the edge by `WorkIdentity`, and escaped by
                // the view like every other string that reached this package from a machine
                'repository' => $session->repository,
                'work_location' => $session->work_location,

                // Listed here because it can be revoked here, and marked because it is on no other
                // list an administrator reads (#424)
                'ephemeral' => $session->isEphemeral(),

                // What tells two sessions in one checkout apart (#419): a restarted agent joins as a
                // new session beside the old one, and an administrator revoking the stale one needs
                // to see which is which. Already on the eager-loaded row, so no query is added.
                'joined_at' => $session->created_at?->toIso8601String(),
                'last_seen_at' => $session->last_seen_at->toIso8601String(),
            ])->all()),

            // Said rather than left to be inferred: a session that has ended is not on the list
            'gone' => $installation->sessions->count() - $live->count(),
        ];
    }
}
