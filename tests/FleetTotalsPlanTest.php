<?php

declare(strict_types=1);

/**
 * How Postgres answers the two counting queries behind the overview's totals row.
 *
 * **Both are recorded, and they do not agree.** `FleetPresence::liveSessions()` asks
 * `status <> 'gone'`, which a btree cannot serve because it has no `<>` strategy, so it is a
 * sequential scan however the rows are distributed. `FleetPresence::heldLocks()` asks
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
 * that silently does not. At 100,000 rows the scan cost 4.0 ms against 0.8 ms, on a table the
 * default configuration prunes after thirty days -- so the saving does not pay for a predicate
 * whose meaning changes the next time somebody adds a status. **If that ever stops being true, the
 * move that costs no meaning is a partial index** on `(status) where status <> 'gone'`, which keeps
 * the open set; it is Postgres and SQLite only, which is why it is a note here rather than a
 * migration.
 *
 * That last sentence is measured rather than reasoned: creating exactly that index against this
 * fixture flips the first case below from a sequential scan to an index scan, which is how the
 * case was shown able to fail at all. So the escape route is known to work before anyone needs it,
 * and the note is not an untested suggestion left for somebody else to discover is wrong.
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
                (user_id, harness, machine_label, granted_abilities, expires_at, created_at, updated_at)
            values ('dev-0', 'probe', 'plan-fixture', '[]', ?::timestamp, ?::timestamp, ?::timestamp)
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
 * The plan Postgres produces for one statement.
 *
 * `analyze` rather than a bare `explain`, because an estimate cannot answer this. `buffers`
 * explicitly, because Postgres 18 reports them under `analyze` by default and 17 does not, so two
 * versions would otherwise produce reports that cannot be compared.
 *
 * @param  string  $sql  The statement to explain.
 * @return string The plan, as Postgres prints it.
 */
function totalsPlan(string $sql): string
{
    $lines = [];

    foreach (DB::select('explain (analyze, buffers, costs off, timing off, summary off) '.$sql) as $row) {
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

    $plan = totalsPlan("select count(*) from robot_council_agent_sessions where status <> 'gone'");

    expect($plan)->toContain('Seq Scan on robot_council_agent_sessions')
        ->and($plan)->not->toContain('Index Scan')
        ->and($plan)->not->toContain('Bitmap Index Scan');

    // And the scan really is reading the whole table rather than being one in name only
    expect($plan)->toContain('Rows Removed by Filter');
});

it('walks an index for the held-lock count, which is what says the scan above is the predicate', function (): void {
    seedForTotals();

    // **The control.** Same connection, same seeding, same helper, a predicate a btree can serve.
    // Without this, the assertion above would be satisfied by a database whose indexes were missing
    // altogether -- it would report a sequential scan for every query and look correct.
    $plan = totalsPlan('select count(*) from robot_council_locks where holder_id is not null');

    expect($plan)->toContain('Index')
        ->and($plan)->toContain('robot_council_locks_holder_id');
});

it('measures what the components actually call, not a hand-written copy of it', function (): void {
    seedForTotals();

    $presence = app(FleetPresence::class);

    // The two queries above are transcriptions. If a store stopped issuing them, the plans would
    // keep passing while measuring something nothing runs, so the counts themselves are asserted
    // against the same fixture: 1 in 20 sessions is active and 1 in 20 stale, 1 in 5 locks is held.
    expect($presence->liveSessions())->toBe(TOTALS_PLAN_ROWS / 10)
        ->and($presence->heldLocks())->toBe(TOTALS_PLAN_ROWS / 5);
});
