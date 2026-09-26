<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Lock;

/**
 * Who is alive in the fleet, and what they are holding.
 *
 * Two reads that answer the same question from opposite ends: an agent session is a process that
 * might be stuck, and a lock is the thing a stuck process is blocking.
 *
 * **Both are for a signed-in developer and neither takes a session**, which is the shape the
 * decision on robot-council/core#73 fixed for an unfiltered read -- a named method rather than a
 * flag, so that no agent-facing call can reach one by passing a boolean. There is no agent-facing
 * equivalent to drift from: the machine API has never listed other sessions or other holders.
 *
 * The caller is responsible for having established that the reader is an allowlisted developer.
 * Nothing here checks it, because nothing here can.
 */
final class FleetPresence
{
    /**
     * The most sessions or locks one read returns.
     */
    // Reported `uncovered` rather than `untested`, and the difference is the point: no test reaches
    // this LINE, because a constant declaration is not executed anywhere coverage can see it. The
    // same structural blind spot the ticket records for `#[Fillable]` attributes. The value is
    // exercised -- `PresenceReadShapeTest` asserts it and pages through it -- and the mutants stayed
    // uncovered anyway, which is what says this is the shape of the instrument rather than a gap.
    // @pest-mutate-ignore
    public const int MAX_PAGE = 200;

    /**
     * @param  AgentLogins  $logins  Resolves the GitHub account behind a session.
     */
    public function __construct(private readonly AgentLogins $logins) {}

    /**
     * One page of agent sessions, newest first.
     *
     * **Ordered by `id`, not by `last_seen_at`, and that is the whole reason this method has a
     * cursor at all.** Contact time is rewritten on every request a session makes, so a keyset
     * built on it walks an ordering that moves underneath the reader: a session on page two that
     * heartbeats moves ahead of the cursor, and the next page skips it. A reader would see a
     * shorter fleet than exists and nothing would say so -- the exact failure this issue is about,
     * reintroduced by the fix. `id` never moves, and "newest session first" is a coherent presence
     * order with the contact age on each row.
     *
     * **`gone` sessions are listed by default**, because #24 keeps the row and #75 decided it
     * should be shown: a task sitting held is explained by the process that ended, and a reader
     * hunting that would not find it behind a filter. `Scope::Live` narrows to the ones still
     * running. This is the opposite default from `locks()` below, and the reason is in `Scope`.
     *
     * **`$only` narrows the page to one session and nothing else.** It is how a lock's holder
     * links to the agent holding it (#308), which is the diagnosis path the shared panel used to
     * give by scrolling. The totals are still the whole fleet's, because a count that shrank with
     * the narrowing would read as a fleet of one. An id no row carries -- zero, a negative, or a
     * session long since pruned -- matches nothing and yields an empty page, which is the honest
     * answer to a stale link.
     *
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @param  Scope  $scope  Live sessions, or every one the table holds.
     * @param  int|null  $after  The id of the last session the reader has seen.
     * @param  int|null  $only  The one session to list, or null for every session in scope.
     * @return array{sessions: list<array<string, mixed>>, cursor: int|null, more: bool, live: int, gone: int}
     */
    public function sessions(int $limit, Scope $scope = Scope::All, ?int $after = null, ?int $only = null): array
    {
        // Eager-loaded rather than read per row. `Model::preventLazyLoading()` raises on a query
        // that hydrated more than one row, so a host running strict mode would take a
        // `LazyLoadingViolationException` off the first page with two sessions on it -- and the
        // harness and machine label live on the installation rather than on the session.
        $size = max(1, min($limit, self::MAX_PAGE));

        // One more than the page, so whether a next page exists is known rather than guessed.
        // Deciding it from `count() === $size` is a page behind: on a set that is an exact multiple
        // of the page size it offers a next page that turns out to be empty.
        //
        // Fetching one MORE than one extra changes nothing anyone can observe -- `$more` and the
        // page are both taken from `$size` -- so that direction has no input that can kill it. The
        // other direction, fetching no extra, is what the exact-multiple test pins.
        // @pest-mutate-ignore: IncrementInteger
        // `announced()` in every scope, and in the totals below with it (#424): an ephemeral
        // session is not a seat on this page whether it is live or gone, and a count that included
        // what the list leaves out would disagree with it. **Except for `$only`**, which is one
        // session looked up by id rather than a listing: the Locks page links a lock's holder
        // here, and a lock an ephemeral session holds has to lead somewhere that shows it.
        $sessions = ($only !== null ? AgentSession::query() : AgentSession::announced())
            ->with('installation')
            ->when($scope === Scope::Live, fn (Builder $query) => $query->where('status', '!=', AgentSessionStatus::Gone->value))
            ->when($after !== null, fn (Builder $query) => $query->where('id', '<', $after))
            ->when($only !== null, fn (Builder $query) => $query->where('id', $only))
            ->orderByDesc('id')
            ->limit($size + 1)
            ->get();

        $more = $sessions->count() > $size;

        $sessions = $sessions->take($size);

        // `forUsers()` rather than `forSessions()`, which would ask the session table for the
        // `user_id` of rows this method has just loaded in full -- one query for a column already
        // in hand. It is also the more direct read of the two: the key comes off the live row
        // rather than being looked up from an id, so the reuse hazard `forSessions()` carries for
        // a DEAD session id cannot arise here at all. `locks()` below still needs `forSessions()`,
        // because the ids it resolves belong to sessions it has not loaded.
        $logins = $this->logins->forUsers($sessions->pluck('user_id')->all());

        // The presence clock, not the application's: this is compared against
        // `last_seen_at`, which is written on the same clock (#51).
        $now = PresenceClock::now();

        // Both totals in one pass. They were two `count()` queries, and the second was only ever
        // `total - live`, so the table was scanned twice to answer one question. `sum(case when)`
        // rather than `count(*) filter (where)`, which Postgres and SQLite have and MySQL does not.
        $totals = AgentSession::announced()
            ->toBase()
            ->selectRaw('count(*) as total, sum(case when status <> ? then 1 else 0 end) as live', [AgentSessionStatus::Gone->value])
            ->first();

        // **The `?->` cannot be killed and is kept anyway.** An aggregate with no `GROUP BY` returns
        // exactly one row whatever the table holds, so `$totals` is never null and removing the
        // null-safe operator changes no input's outcome. Measured against an empty table: `first()`
        // came back as a `stdClass` with `total` 0 and `live` NULL, not as null. It stays as the
        // guard against a future `groupBy`, which is the one edit that would make it matter.
        // @pest-mutate-ignore: RemoveNullSafeOperator
        $live = AggregateCount::from($totals?->live);
        // @pest-mutate-ignore: RemoveNullSafeOperator
        $total = AggregateCount::from($totals?->total);

        return [
            'cursor' => $sessions->last()?->id,
            'more' => $more,

            // Counted rather than inferred from the page. A truncated list and a complete one look
            // identical, and the number that would tell them apart is the one not printed.
            'live' => $live,
            'gone' => $total - $live,
            // **`array_values()` is defensive and cannot be killed here.** The page comes from
            // `->get()` and is narrowed with `->take($size)`, which slices from zero, so the keys
            // are 0..n-1 either way and unwrapping it changes no output. The mutant surviving a
            // test that asserts this list encodes as a JSON array is itself the demonstration. It
            // stays because a later `filter()` or `reject()` would make the keys sparse and turn
            // this into a JSON object, which is the defect `Support\Installations` records for
            // `granted_abilities` -- a client reading `[0]` would get nothing.
            // @pest-mutate-ignore: UnwrapArrayValues
            'sessions' => array_values($sessions->map(fn (AgentSession $session): array => [
                'id' => $session->id,
                // Keyed by the row's own `user_id`, which is what `forUsers()` returns against
                'github_login' => $logins[$session->user_id] ?? null,
                // Not nullsafe: `installation_id` is a non-nullable foreign key and the relation is
                // eager-loaded above, so a null here would mean a row the schema forbids
                'harness' => $session->installation->harness,
                'machine_label' => $session->installation->machine_label,

                // The session's own, not the installation's. That is the whole point of the
                // column: one machine runs several checkouts from one harness, and before roles
                // every one of them had identical authority with nothing on the page saying so.
                'role' => $session->role->value,

                // Where the work is. Null for a session that named neither, and rendered as such
                // rather than filled in. Both are agent-supplied strings, charset-limited at the
                // edge by `WorkIdentity`, and both are escaped by the view like every other string
                // that reached this package from a machine.
                'repository' => $session->repository,
                'work_location' => $session->work_location,

                // As the bridge reported them at start (#351), charset-limited by `Platform`
                'os_family' => $session->os_family,
                'arch' => $session->arch,

                // Read from the row rather than recomputed from `last_seen_at`. #24 made the row the
                // decision, and a view that derived the status itself would disagree with the sweep
                // for as long as the sweep had not run -- showing `stale` to a developer while every
                // conditional update in the package still treated the session as active.
                'status' => $session->status->value,
                // Cast, because Carbon 3's `diffInSeconds()` returns a float and `last_seen_at` is a
                // `dateTime` column with no microseconds while `now()` has them -- so the difference is
                // fractional on every read and rendered straight it reads `30.482913s ago`
                // `MultiplicationToDivision` is an equivalent mutant: `x * -1` and `x / -1`
                // are the same number for every finite `x`, so no contact time can tell them
                // apart. The `0` and the `-1` are both killable and both have tests -- a future
                // contact time reaches the floor, and a past one pins the sign.
                // @pest-mutate-ignore: MultiplicationToDivision
                'seconds_since_contact' => (int) max(0, $now->diffInSeconds($session->last_seen_at, false) * -1),
            ])->all()),
        ];
    }

    /**
     * Every named lock, by name.
     *
     * A lapsed lease is returned rather than hidden, with `held` saying which it is. A row whose
     * lease has run out but which still names a holder is precisely the state a developer is
     * looking for, and hiding it would make the page agree with nothing.
     *
     * **A released lock keeps its row forever, by design**, for the fence it carries -- so this
     * table fills with names nobody holds, in alphabetical order, and a live or lapsed lock whose
     * name sorts past the page was invisible with nothing saying so. `Scope::Live` is the rows that
     * still name a holder, which is held and lapsed together: a lease that has run out while the
     * row still names somebody is precisely what a developer is hunting, so it must not be filtered
     * out with the free ones.
     *
     * **`$heldBy` narrows the page to the locks one session holds**, which is how a session row
     * links to what it is blocking (#308). It matches `holder_id` alone: a lock the session held
     * once and released names it only as `previous_holder_id`, and "what is this session holding"
     * is the question the link asks. The totals are the whole table's, for the reason `sessions()`
     * gives.
     *
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @param  Scope  $scope  Locks that still name a holder, or every row the table holds.
     * @param  string|null  $after  The name of the last lock the reader has seen.
     * @param  int|null  $heldBy  The session whose locks to list, or null for every holder.
     * @return array{locks: list<array<string, mixed>>, cursor: string|null, more: bool, held: int, free: int}
     */
    public function locks(int $limit, Scope $scope = Scope::Live, ?string $after = null, ?int $heldBy = null): array
    {
        $size = max(1, min($limit, self::MAX_PAGE));

        // Keyed on `name`, which is the one column here that is unique, immutable and already the
        // order. The migration gives it `utf8mb4_bin` on MySQL precisely so that it compares as
        // bytes the way Postgres and SQLite already do, which is what makes `name > ?` mean the
        // same thing on every engine rather than depending on a collation.
        // One extra for the same reason as `sessions()` above, annotated for the same reason.
        // @pest-mutate-ignore: IncrementInteger
        $locks = Lock::query()
            ->when($scope === Scope::Live, fn (Builder $query) => $query->whereNotNull('holder_id'))
            ->when($after !== null, fn (Builder $query) => $query->where('name', '>', $after))
            ->when($heldBy !== null, fn (Builder $query) => $query->where('holder_id', $heldBy))
            ->orderBy('name')
            ->limit($size + 1)
            ->get();

        $more = $locks->count() > $size;

        $locks = $locks->take($size);

        $logins = $this->logins->forSessions([
            ...$locks->pluck('holder_id')->all(),
            ...$locks->pluck('previous_holder_id')->all(),
        ]);

        $now = Carbon::now();

        // One pass, for the reason `sessions()` above does it: `free` was only ever `total - held`.
        $totals = Lock::query()
            ->toBase()
            ->selectRaw('count(*) as total, sum(case when holder_id is not null then 1 else 0 end) as held')
            ->first();

        // Unkillable for the reason recorded on the same pair in `sessions()`: an aggregate with no
        // `GROUP BY` always returns a row, so `$totals` is never null.
        // @pest-mutate-ignore: RemoveNullSafeOperator
        $held = AggregateCount::from($totals?->held);
        // @pest-mutate-ignore: RemoveNullSafeOperator
        $total = AggregateCount::from($totals?->total);

        return [
            'cursor' => $locks->last()?->name,
            'more' => $more,
            'held' => $held,
            'free' => $total - $held,
            // Defensive and unkillable for the reason recorded on `sessions()` above: the keys
            // are already sequential, and this guards a filter nobody has added yet.
            // @pest-mutate-ignore: UnwrapArrayValues
            'locks' => array_values($locks->map(fn (Lock $lock): array => [
                // The row's own key. A `wire:key` built from `fence` and a loop index is neither stable
                // nor unique: two locks routinely share a fence, so one row's key can be taken over by
                // another between polls, and a re-acquire changes the key of a row that did not move.
                'id' => $lock->id,
                'name' => $lock->name,
                'fence' => $lock->fence,
                'held' => $lock->isHeldAt($now),
                'holder' => $lock->holder_id === null ? null : [
                    'session_id' => $lock->holder_id,
                    'github_login' => $logins[$lock->holder_id] ?? null,
                ],

                // Who had it before, which is what tells a reader whether a lock is being handed round
                // or has simply been sitting with one holder
                'previous_holder' => $lock->previous_holder_id === null ? null : [
                    'session_id' => $lock->previous_holder_id,
                    'github_login' => $logins[$lock->previous_holder_id] ?? null,
                ],
                'expires_at' => $lock->expires_at?->toIso8601String(),
            ])->all()),
        ];
    }

    /**
     * How many agent sessions are not gone.
     *
     * The same predicate `sessions()` counts `live` with, so the tile above the panels and the
     * panel itself cannot report different numbers for the same fleet -- which is the whole reason
     * this lives here rather than in a store of its own.
     *
     * A counting query rather than a `count()` over a paged read. Those pages are bounded by
     * `MAX_PAGE`, so measuring one would report the bound rather than the total on any fleet large
     * enough for the number to matter, and a capped total is indistinguishable from a real one.
     *
     * @return int The number of sessions still able to act.
     */
    public function liveSessions(): int
    {
        return AgentSession::announced()
            ->where('status', '<>', AgentSessionStatus::Gone->value)
            ->count();
    }

    /**
     * How many locks still name a holder.
     *
     * The same predicate `locks()` counts `held` with, and `Scope::Live` selects rows by. A lapsed
     * lease whose row still names somebody counts, because that is precisely the state a developer
     * is looking for.
     *
     * @return int The number of locks naming a holder.
     */
    public function heldLocks(): int
    {
        return Lock::query()->whereNotNull('holder_id')->count();
    }
}
