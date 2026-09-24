<?php

declare(strict_types=1);

/**
 * How Postgres answers the two counting queries behind the overview's totals row.
 *
 * **Both are recorded, and they do not agree.** `FleetPresence::liveSessions()` asks
 * `status <> 'gone'`, which a btree cannot serve because it has no `<>` strategy, so it is a
 * sequential scan. That is invariant under the row distribution and, measured, under the
 * visibility map too: an all-visible table could in principle be read by an index-only scan over
 * `(status, last_seen_at)` with the predicate as a filter, and at this size Postgres still prefers
 * the scan. Checked by vacuuming the table and re-explaining -- `relallvisible` went from 0 to
 * every page and the plan did not move. `FleetPresence::heldLocks()` asks
 * `holder_id is not null`, which a btree CAN serve because Postgres indexes store nulls. The two
 * were expected to share an answer on #228 and do not, which is why each has its own case here.
 *
 * **The disagreement is what makes this test able to fail.** A pair of assertions that both said
 * "sequential scan" would be satisfied by a database with no usable indexes at all. One of each
 * means the instrument has been shown to tell the two plans apart, on the same connection, in the
 * same run.
 *
 * **The sequential scan is recorded rather than fixed, and the reasoning is on #228.** The
 * alternative predicate, `whereIn(['active', 'stale'])`, is index-servable and is not equivalent:
 * `<>` is an open set that counts a status added to the enum later, and `whereIn` is a closed one
 * that silently does not. The saving does not pay for a predicate whose meaning changes the next
 * time somebody adds a status, on a table the default configuration prunes after thirty days.
 *
 * **No timing is quoted here, deliberately.** These plans are taken with `timing off`, so this file
 * cannot produce a millisecond figure at all; the cost comparison behind the decision was measured
 * separately at 100,000 rows on PostgreSQL 17.0 and is recorded on #228 with its conditions. A
 * number a file cannot reproduce does not belong in that file.
 *
 * **If the decision is ever taken again, the move that costs no meaning is a partial index** on
 * `(status) where status <> 'gone'`, which keeps the open set. That is not a suggestion left for
 * somebody else to find wrong: the last case below creates exactly that index and asserts the plan
 * changes, so the escape route is committed and re-runnable rather than claimed.
 *
 * This case fails if somebody indexes around it, which is the point: the decision above was made
 * against a measurement, and a change to either plan is a reason to take it again.
 *
 * **SQLite cannot stand in for any of this**, and neither can MySQL. It is Postgres's planner being
 * asked, and Postgres is what the deployment runs.
 *
 * @command  DB_CONNECTION=pgsql vendor/bin/pest --compact tests/FleetTotalsPlanTest.php
 */

use Illuminate\Support\Facades\DB;
use RobotCouncil\Support\FleetPresence;

/**
 * How many rows each table holds for these measurements.
 *
 * Large enough that the planner has a real choice. On a few hundred rows a sequential scan genuinely
 * is cheaper than any index, so a thinly seeded guard would pin the opposite plan and pass while
 * proving the reverse of what it claims.
 */
const TOTALS_PLAN_ROWS = 20000;

beforeEach(function (): void {
    if (notPostgres()) {
        $this->markTestSkipped('The planner being asked is Postgres.');
    }

    $this->migrateFresh();
});

/**
 * Fill both tables, then let the planner see what is in them.
 */
function seedForTotals(): void
{
    $now = now()->toDateTimeString();

    // A parent for the sessions to hang from. **Postgres enforces this foreign key and SQLite in
    // this suite does not**, so a fixture written without it passes locally and fails only here --
    // which is the whole reason this file runs where it does.
    DB::statement(
        <<<'SQL'
            insert into robot_council_installations
                (user_id, harness, machine_label, expires_at, created_at, updated_at)
            values ('dev-0', 'probe', 'plan-fixture', ?::timestamp, ?::timestamp, ?::timestamp)
            SQL,
        [now()->addYear()->toDateTimeString(), $now, $now]
    );

    $installation = DB::table('robot_council_installations')->value('id');

    DB::statement(
        <<<'SQL'
            insert into robot_council_agent_sessions
                (installation_id, user_id, status, last_seen_at, created_at, updated_at)
            select
                ?,
                'dev-' || (n % 50),
                case when n % 20 = 0 then 'active' when n % 20 = 1 then 'stale' else 'gone' end,
                ?::timestamp - (n || ' seconds')::interval,
                ?::timestamp,
                ?::timestamp
            from generate_series(1, ?) as n
            SQL,
        [$installation, $now, $now, $now, TOTALS_PLAN_ROWS]
    );

    DB::statement(
        <<<'SQL'
            insert into robot_council_locks
                (name, holder_id, fence, acquired_at, expires_at, created_at, updated_at)
            select
                'lock-' || n,
                case when n % 5 = 0 then n else null end,
                n,
                ?::timestamp,
                ?::timestamp,
                ?::timestamp,
                ?::timestamp
            from generate_series(1, ?) as n
            SQL,
        [$now, $now, $now, $now, TOTALS_PLAN_ROWS]
    );

    // Without this the planner is working from defaults and its choice says nothing about the rows
    DB::statement('analyze robot_council_agent_sessions');
    DB::statement('analyze robot_council_locks');
}

/**
 * The SQL one of the totals counts actually issues, with its bindings.
 *
 * **Captured rather than retyped, and that is the whole point of it.** A transcribed query is a
 * test of the string in this file: replace `liveSessions()`'s predicate with the `whereIn` the
 * docblock rejects and a hand-typed `<>` would keep explaining happily, reporting a plan for a
 * query nobody issues while the finding this file records quietly stopped being about the package.
 * The counts are identical under this fixture, so nothing else here would notice either.
 *
 * The query log rather than `DB::listen`, for the reason `TaskQueuePlanTest` records: Laravel
 * offers no way to remove a listener, so capturing that way leaves one behind per call.
 *
 * @param  callable(FleetPresence): mixed  $read  The store method to run.
 * @param  string  $table  The table whose query to keep.
 * @return array{string, list<mixed>} The statement and its bindings.
 */
function capturedTotalsSql(callable $read, string $table): array
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $read(app(FleetPresence::class));

    DB::connection()->disableQueryLog();

    foreach (DB::connection()->getQueryLog() as $entry) {
        $sql = stringValue(arrayValue($entry)['query'] ?? null);

        if (str_contains($sql, $table)) {
            return [$sql, array_values(arrayValue(arrayValue($entry)['bindings'] ?? []))];
        }
    }

    throw new RuntimeException(sprintf('No query against %s was captured.', $table));
}

/**
 * The plan Postgres produces for one statement.
 *
 * `analyze` rather than a bare `explain`, because an estimate cannot answer this. `buffers`
 * explicitly, because Postgres 18 reports them under `analyze` by default and 17 does not, so two
 * versions would otherwise produce reports that cannot be compared.
 *
 * @param  string  $sql  The statement to explain.
 * @param  list<mixed>  $bindings  Its bindings.
 * @return string The plan, as Postgres prints it.
 */
function totalsPlan(string $sql, array $bindings = []): string
{
    $lines = [];

    foreach (DB::select('explain (analyze, buffers, costs off, timing off, summary off) '.$sql, $bindings) as $row) {
        // Narrowed rather than cast: `DB::select()` rows arrive as `mixed`, and an unexpected shape
        // should fail loudly rather than stringify into a plan nobody can read.
        if (! \is_object($row)) {
            throw new RuntimeException(sprintf('Expected a plan row, got %s.', get_debug_type($row)));
        }

        $fields = get_object_vars($row);

        $lines[] = stringValue(reset($fields));
    }

    return implode("\n", $lines);
}

it('scans the sessions table for the live count, because a btree has no strategy for `<>`', function (): void {
    seedForTotals();

    [$sql, $bindings] = capturedTotalsSql(
        static fn (FleetPresence $presence): int => $presence->liveSessions(),
        'robot_council_agent_sessions'
    );

    // The predicate the decision on #228 is about, read off the query the store issued rather than
    // assumed. Swap it for the `whereIn` that ticket rejected and this fails here, before any plan
    // is explained -- which is the failure a retyped query could not produce.
    expect($sql)->toContain('<>');

    $plan = totalsPlan($sql, $bindings);

    expect($plan)->toContain('Seq Scan on robot_council_agent_sessions')

        // **One negative, and it is `Index` rather than `Index Scan`.** The plan Postgres could
        // reach for this qual is an INDEX ONLY scan over `(status, last_seen_at)` with the
        // predicate as a filter, and `Index Only Scan` does not contain the substring
        // `Index Scan` -- so the narrower guard was blind to the one index plan it existed to
        // exclude. `Index` catches that, `Index Scan`, and `Bitmap Index Scan` alike.
        ->and($plan)->not->toContain('Index')

        // And the scan really is reading the whole table rather than being one in name only
        ->and($plan)->toContain('Rows Removed by Filter');
});

it('walks the holder index for the held-lock count, which is what says the scan above is the predicate', function (): void {
    seedForTotals();

    // **The control.** Same connection, same seeding, same helper, a predicate a btree can serve.
    // Without this, the case above would be satisfied by a database whose indexes were missing
    // altogether -- it would report a sequential scan for every query and look correct.
    [$sql, $bindings] = capturedTotalsSql(
        static fn (FleetPresence $presence): int => $presence->heldLocks(),
        'robot_council_locks'
    );

    $plan = totalsPlan($sql, $bindings);

    // The index is named rather than matched loosely: `robot_council_locks` carries a second index
    // leading with the same column, `(holder_id, expires_at)`, so a prefix match would stay green
    // if the single-column one were dropped as redundant and a reader would take this as pinning
    // an index it no longer pins.
    expect($plan)->toContain('robot_council_locks_holder_id_index');
});

it('changes that plan under the partial index the decision names as its escape route', function (): void {
    seedForTotals();

    [$sql, $bindings] = capturedTotalsSql(
        static fn (FleetPresence $presence): int => $presence->liveSessions(),
        'robot_council_agent_sessions'
    );

    // **This is what shows the case above can fail at all**, and it is committed rather than
    // described: without it, "a sequential scan" is a claim no run in this suite ever contradicts,
    // and an assertion nothing can falsify pins nothing.
    //
    // Raw SQL because Laravel's `Blueprint` has no partial-index API. The predicate implies the
    // query's own, so Postgres drops the qual and reads the index alone.
    DB::statement("create index sessions_live_partial on robot_council_agent_sessions (status) where status <> 'gone'");
    DB::statement('analyze robot_council_agent_sessions');

    $plan = totalsPlan($sql, $bindings);

    expect($plan)->toContain('sessions_live_partial')
        ->and($plan)->not->toContain('Seq Scan on robot_council_agent_sessions');
});

it('counts what the components count, against the same fixture', function (): void {
    seedForTotals();

    $presence = app(FleetPresence::class);

    // `intdiv` rather than `/`: the fixture makes 1 row in 20 active and 1 in 20 stale, and 1 in 5
    // locks held, so these are exact only while the row count is a multiple of 20. `/` would turn
    // into a float the moment it is not, and fail on the type as well as the value with nothing
    // saying why.
    expect($presence->liveSessions())->toBe(intdiv(TOTALS_PLAN_ROWS, 10))
        ->and($presence->heldLocks())->toBe(intdiv(TOTALS_PLAN_ROWS, 5));
});
