<?php

declare(strict_types=1);

/**
 * That `fleet_can_direct` is answered down an index rather than by scanning every session, measured
 * on Postgres because no other engine this suite runs can answer it.
 *
 * **The question moved onto a table that grows with traffic** (`robot-council/core#223`). It used to
 * walk installations, which a human creates one device code at a time; it now walks
 * `robot_council_agent_sessions`, which a bridge creates on every start and which nothing prunes for
 * `retention.sessions_days`. And it runs on `GET {prefix}/api/agent/session`, the route that bridge
 * calls after every start and every renewal, up to `rate_limits.agent_per_session` a minute.
 *
 * **The normal state is the worst case, which is why this exists.** `Support\Doctor` says in its own
 * text that a fleet whose agents only receive is a legitimate configuration -- so no coordinator
 * running is the expected reading, and it is exactly the case where the filter matches nothing and a
 * scan has to exhaust the table before it can say so. Measured on 2026-09-23 before the index:
 * `Seq Scan`, `Rows Removed by Filter: 20000`, 267 buffers.
 *
 * **`CHUNK` does not bound this, and that was the assumption worth breaking.** Without an index
 * providing the order, `Limit 50` sits above a `Sort` of the whole filtered set, so the chunk size
 * bounds how many models are hydrated and not how many rows are read.
 *
 * **SQLite cannot stand in for it.** It has its own planner, and the deployment runs Postgres on
 * Laravel Cloud, so this skips everywhere else -- exactly as `TaskQueuePlanTest` does, and for the
 * same reason.
 *
 * @command  DB_CONNECTION=pgsql vendor/bin/pest --compact tests/FleetCanDirectPlanTest.php
 */

use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Support\FleetAbilities;

/**
 * How many live sessions the fixture holds.
 *
 * Large enough that a sequential scan is genuinely the more expensive plan. On a few hundred rows
 * Postgres prefers one whatever indexes exist, because it is cheaper -- so a thinly seeded guard
 * would pin the opposite of this one and be equally right.
 */
const FLEET_PLAN_ROWS = 20000;

/**
 * The index this is about, by the name its migration gives it.
 */
const FLEET_PLAN_INDEX = 'robot_council_agent_sessions_role_status_id_index';

beforeEach(function (): void {
    if (notPostgres()) {
        $this->markTestSkipped('The planner being asked is Postgres.');
    }

    $this->migrateFresh();
});

/**
 * Fill the sessions table and bring the planner's statistics up to date.
 *
 * Written through the query builder rather than the stores: twenty thousand enrollments would be
 * minutes of feed writes and token mints, and what is being measured is the shape of one read.
 *
 * @param  int  $coordinators  How many of the rows are coordinators. Zero is the normal state.
 */
function seedFleet(int $coordinators = 0): void
{
    DB::table('robot_council_installations')->insert(array_map(static fn (int $n): array => [
        'user_id' => '1',
        'machine_label' => 'machine-'.$n,
        'harness' => 'claude-code',
        'granted_abilities' => '[]',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
        'expires_at' => '2126-09-01 00:00:00',
    ], range(1, 50)));

    $installations = DB::table('robot_council_installations')->pluck('id')->all();

    foreach (array_chunk(range(1, FLEET_PLAN_ROWS), 1000) as $chunk) {
        $rows = [];

        foreach ($chunk as $n) {
            $rows[] = [
                'installation_id' => $installations[$n % \count($installations)],
                'user_id' => '1',
                'status' => AgentSessionStatus::Active->value,

                // **The coordinators go LAST, at the highest ids there are.** A plan measured with
                // the match near the front is a plan measured on a table that answers early, which
                // is the one shape a scan handles well.
                'role' => $n > FLEET_PLAN_ROWS - $coordinators ? Role::Coordinator->value : Role::Build->value,
                'last_seen_at' => '2026-09-01 00:00:00',
                'created_at' => '2026-09-01 00:00:00',
                'updated_at' => '2026-09-01 00:00:00',
            ];
        }

        DB::table('robot_council_agent_sessions')->insert($rows);
    }

    DB::statement('analyze robot_council_installations');
    DB::statement('analyze robot_council_agent_sessions');
}

/**
 * The SQL and bindings the real read emits, captured rather than retyped.
 *
 * Retyping the query would make this a test of the string in this file. Capturing it means a change
 * to `Support\FleetAbilities` that alters the shape is measured rather than missed.
 *
 * @return array{0: string, 1: array<int, mixed>} The SQL and its bindings.
 */
function capturedFleetSql(): array
{
    // The query log rather than `DB::listen`, for the reason `TaskQueuePlanTest` records: Laravel
    // offers no way to remove a listener, and the log is flushed, filled and read back with nothing
    // left behind.
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect);

    DB::connection()->disableQueryLog();

    foreach (DB::connection()->getQueryLog() as $entry) {
        $sql = stringValue(arrayValue($entry)['query'] ?? null);

        if (str_contains($sql, 'robot_council_agent_sessions')) {
            return [$sql, array_values(arrayValue(arrayValue($entry)['bindings'] ?? []))];
        }
    }

    throw new RuntimeException('No query against robot_council_agent_sessions was captured.');
}

/**
 * The plan Postgres produces for one query.
 *
 * `analyze` rather than a bare `explain`, because an estimate cannot answer this: a read that
 * touches twenty thousand rows to return none shows up only as `Rows Removed by Filter`. `buffers`
 * explicitly, because Postgres 18 reports them under `analyze` by default and 17 does not, so
 * without it two versions produce reports that cannot be compared.
 *
 * @param  string  $sql  The statement to explain.
 * @param  array<int, mixed>  $bindings  Its bindings.
 * @return string The plan, as Postgres prints it.
 */
function fleetPlan(string $sql, array $bindings): string
{
    $lines = [];

    foreach (DB::select('explain (analyze, buffers, costs off, timing off, summary off) '.$sql, $bindings) as $row) {
        if (! \is_object($row)) {
            throw new RuntimeException(sprintf('Expected a plan row, got %s.', get_debug_type($row)));
        }

        $fields = get_object_vars($row);

        $lines[] = stringValue(reset($fields));
    }

    return implode("\n", $lines);
}

/**
 * How many rows a plan admits and then throws away.
 *
 * **This is the number the whole file is about, and the plan LINES are not a substitute for it.** An
 * index Postgres technically uses while still visiting every row satisfies a `toContain('Index
 * Scan')` assertion and fixes nothing. Measured on 2026-09-23 before the index existed:
 * `Rows Removed by Filter: 20000`, one per seeded session.
 *
 * @param  string  $plan  The plan, as Postgres prints it.
 * @return int The rows discarded across every node.
 */
function fleetRowsDiscarded(string $plan): int
{
    preg_match_all('/Rows Removed by (?:Filter|Join Filter|Index Recheck): (\d+)/', $plan, $matches);

    return array_sum(array_map(intval(...), $matches[1]));
}

/**
 * Assert one plan reads the sessions table through the index rather than scanning it.
 *
 * **There is no assertion here that the plan contains no `Sort`, and that is deliberate.** A first
 * draft had one, on the reasoning that a sort above the limit means the page size bounds hydration
 * rather than the read. Measured: the sort survives the index, because the installations subquery is
 * joined and the join does not preserve the index's order -- but it now sorts the MATCHED rows
 * (`rows=1`) rather than the filtered table (`rows=20000`). The line reads identically in both
 * cases, so it cannot tell them apart, and `fleetRowsDiscarded()` can.
 *
 * @param  string  $plan  The plan to check.
 */
function expectFleetPlanIsIndexed(string $plan): void
{
    expect($plan)
        ->toContain('Index Scan using '.FLEET_PLAN_INDEX)

        // **Named to the sessions table, not a blanket `Seq Scan`.** The installations subquery
        // reads fifty rows and Postgres scans them sequentially because that is cheaper than an
        // index; forbidding every sequential scan would fail on the half that is correct.
        ->not->toContain('Seq Scan on robot_council_agent_sessions')

        // The control for the two assertions above: a plan was genuinely read back, rather than an
        // empty string that contains no `Seq Scan` because it contains nothing.
        ->and($plan)->toContain('robot_council_agent_sessions')
        ->and(fleetRowsDiscarded($plan))->toBeLessThan(FLEET_PLAN_ROWS / 100);
}

it('walks the role index when no session is coordinating, which is the expected state', function (): void {
    seedFleet();

    [$sql, $bindings] = capturedFleetSql();

    expectFleetPlanIsIndexed(fleetPlan($sql, $bindings));
});

it('walks the role index when the only coordinator is the last row in the table', function (): void {
    seedFleet(coordinators: 1);

    [$sql, $bindings] = capturedFleetSql();

    expectFleetPlanIsIndexed(fleetPlan($sql, $bindings));
});
