<?php

declare(strict_types=1);

/**
 * That the task queue's two read shapes are each walked down an index rather than scanned and
 * sorted, measured on Postgres because no other engine this suite runs can answer it.
 *
 * **SQLite cannot stand in for this.** It has its own planner, and the plans recorded in
 * `2026_09_18_000005_create_robot_council_tasks_table.php` were taken there -- so the claim that
 * Postgres, which is what the deployment runs on Laravel Cloud, agrees with them was until
 * robot-council/core#80 an assumption. This is where it stops being one.
 *
 * **The table has to be big enough for the question to exist.** On a few hundred rows Postgres
 * prefers a sequential scan and a sort whatever indexes are present, because it is cheaper -- so a
 * guard seeded with a handful of rows would assert the opposite of this one and be equally right.
 * `ROWS` is what makes the ordering worth an index at all, and `analyze` is what lets the planner
 * know it.
 *
 * The full matrix -- both seeding profiles against all three index configurations -- is written to
 * `build/plans-pgsql.md` when `ROBOT_COUNCIL_MEASURE_PLANS` is set. That is the recording #80 asked
 * for; the tests below are what keeps it true.
 *
 * @command  DB_CONNECTION=pgsql vendor/bin/pest --compact tests/TaskQueuePlanTest.php
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\TaskList;

/**
 * How many tasks the fixture holds.
 */
const PLAN_ROWS = 20000;

/**
 * The index serving a status-filtered read, by its columns.
 */
const PLAN_COMPOSITE_INDEX = ['status', 'queue_rank', 'id'];

/**
 * The index serving the unfiltered read, by its columns.
 */
const PLAN_QUEUE_INDEX = ['queue_rank', 'id'];

beforeEach(function (): void {
    if (notPostgres()) {
        $this->markTestSkipped('The planner being asked is Postgres.');
    }

    $this->migrateFresh();
});

/**
 * The default name Laravel gives an index over these columns.
 *
 * @param  array<int, string>  $columns  The indexed columns, in order.
 * @return string The index name.
 */
function planIndexName(array $columns): string
{
    return 'robot_council_tasks_'.implode('_', $columns).'_index';
}

/**
 * Fill the tasks table and bring the planner's statistics up to date.
 *
 * Two profiles, because what the composite index is worth depends entirely on how rare the status
 * being filtered for is. `mixed` spreads the seven statuses evenly, which is the friendliest case
 * there is for dropping it. `aged` is what the table actually becomes: nothing prunes it, so
 * finished work accumulates and `pending` is a small minority of a table that only grows.
 *
 * @param  string  $profile  Either `mixed` or `aged`.
 */
function seedQueue(string $profile = 'aged'): void
{
    // Each status repeated by its share in a hundred rows.
    $mix = $profile === 'aged'
        ? array_merge(
            array_fill(0, 91, TaskStatus::Done->value),
            array_fill(0, 3, TaskStatus::Failed->value),
            array_fill(0, 2, TaskStatus::Cancelled->value),
            array_fill(0, 1, TaskStatus::Blocked->value),
            array_fill(0, 1, TaskStatus::InProgress->value),
            array_fill(0, 1, TaskStatus::Claimed->value),
            array_fill(0, 1, TaskStatus::Pending->value),
        )
        : array_map(fn (TaskStatus $status): string => $status->value, TaskStatus::cases());

    // Chunked: twenty thousand single inserts is minutes, this is under a second.
    foreach (array_chunk(range(1, PLAN_ROWS), 1000) as $chunk) {
        $rows = [];

        foreach ($chunk as $n) {
            $priority = $n % (Task::MAX_PRIORITY + 1);

            $rows[] = [
                'title' => 'Task '.$n,

                // **Advanced once per priority cycle, not once per row.** Indexing the mix by
                // `$n % count($mix)` aliases the two columns whenever their lengths share a
                // factor: at a hundred shares and ten priorities, `$n % 100` fixes `$n % 10`, so
                // every status collapses onto one `queue_rank` and the filtered plans measure a
                // table no fleet could produce. Stepping every tenth row instead gives each
                // status the full spread of priorities and keeps the shares exact.
                'status' => $mix[intdiv($n, Task::MAX_PRIORITY + 1) % \count($mix)],
                'priority' => $priority,

                // Written the way `Models\Task`'s mutator writes it, because the fixture bypasses
                // the model and the two columns disagreeing would invalidate every plan here.
                'queue_rank' => Task::MAX_PRIORITY - $priority,
                'user_id' => 'dev-'.($n % 5),
                'created_with_coordinator' => false,
                'created_at' => '2026-09-01 00:00:00',
                'updated_at' => '2026-09-01 00:00:00',
            ];
        }

        DB::table('robot_council_tasks')->insert($rows);
    }

    DB::statement('analyze robot_council_tasks');
}

/**
 * The SQL and bindings a real queue read emits, captured rather than retyped.
 *
 * Retyping the query would make this a test of the string in this file. Capturing it means a change
 * to `Support\TaskList` that alters the shape is measured rather than missed.
 *
 * @param  TaskStatus|null  $status  The status to filter by, or null for every status.
 * @param  array{priority: int, id: int}|null  $after  The cursor, or null for the first page.
 * @return array{0: string, 1: array<int, mixed>} The SQL and its bindings.
 */
function capturedQueueSql(?TaskStatus $status = null, ?array $after = null): array
{
    // **The query log rather than `DB::listen`.** Laravel offers no way to remove a listener, so
    // capturing that way either registers one per call -- each surviving closure then appending
    // every seeded insert to an array nothing reads again -- or keeps a static that a fresh
    // Testbench application silently invalidates. The log is flushed, filled, and read back in
    // three statements, with nothing left behind.
    //
    // It also keeps the capture legible to Rector, which cannot see a closure writing to a
    // by-reference `use` and concluded the emptiness guard below was always true.
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    // `everything()` rather than the private query builder: the board's read is the one being
    // measured, and the reads it makes afterwards touch other tables.
    app(TaskList::class)->everything($status, 26, $after);

    DB::connection()->disableQueryLog();

    foreach (DB::connection()->getQueryLog() as $entry) {
        $sql = stringValue(arrayValue($entry)['query'] ?? null);

        if (str_contains($sql, 'robot_council_tasks')) {
            return [$sql, array_values(arrayValue(arrayValue($entry)['bindings'] ?? []))];
        }
    }

    throw new RuntimeException('No query against robot_council_tasks was captured.');
}

/**
 * The plan Postgres produces for one query.
 *
 * `analyze` rather than a bare `explain`, because an estimate cannot answer this: a read that
 * touches thousands of rows to return twenty-six shows up only as `Rows Removed by Filter`.
 * `buffers` explicitly, because Postgres 18 reports them under `analyze` by default and 17 does
 * not, so without it two versions produce reports that cannot be compared.
 *
 * @param  string  $sql  The statement to explain.
 * @param  array<int, mixed>  $bindings  Its bindings.
 * @return string The plan, as Postgres prints it.
 */
function queuePlan(string $sql, array $bindings): string
{
    $lines = [];

    foreach (DB::select('explain (analyze, buffers, costs off, timing off, summary off) '.$sql, $bindings) as $row) {
        // `DB::select()` rows arrive as `mixed`, and a plan line is the row's single column.
        // Narrowing here rather than casting, so an unexpected shape fails loudly.
        if (! \is_object($row)) {
            throw new RuntimeException(sprintf('Expected a plan row, got %s.', get_debug_type($row)));
        }

        $fields = get_object_vars($row);

        $lines[] = stringValue(reset($fields));
    }

    return implode("\n", $lines);
}

it('walks the queue_rank index for the board default rather than scanning and sorting', function (): void {
    seedQueue();

    [$sql, $bindings] = capturedQueueSql();

    $plan = queuePlan($sql, $bindings);

    // The board polls this one on a timer, and it is the shape no status-led index can serve.
    expect($plan)
        ->toContain('Index Scan using '.planIndexName(PLAN_QUEUE_INDEX))
        ->not->toContain('Seq Scan')
        ->not->toContain('Sort Method');
});

it('walks the queue_rank index for the board cursor', function (): void {
    seedQueue();

    [$sql, $bindings] = capturedQueueSql(null, ['priority' => 5, 'id' => 9000]);

    $plan = queuePlan($sql, $bindings);

    // The row-value comparison has to become a range seek rather than a filter, which is the half
    // `Support\TaskList` records as measured on SQLite alone.
    expect($plan)
        ->toContain('Index Scan using '.planIndexName(PLAN_QUEUE_INDEX))
        ->toContain('Index Cond:')
        ->not->toContain('Seq Scan')
        ->not->toContain('Sort Method');
});

it('walks the composite index for an agent-facing status read', function (): void {
    seedQueue();

    foreach ([null, ['priority' => 5, 'id' => 9000]] as $after) {
        [$sql, $bindings] = capturedQueueSql(TaskStatus::Pending, $after);

        $plan = queuePlan($sql, $bindings);

        // The agent-facing shape, which must not have regressed when the second index arrived.
        expect($plan)
            ->toContain('Index Scan using '.planIndexName(PLAN_COMPOSITE_INDEX))
            ->not->toContain('Seq Scan')
            ->not->toContain('Sort Method')
            ->not->toContain('Rows Removed by Filter');
    }
});

it('still needs the composite index for the status read', function (): void {
    seedQueue();

    Schema::table(
        'robot_council_tasks',
        fn (Blueprint $table) => $table->dropIndex(planIndexName(PLAN_COMPOSITE_INDEX))
    );

    DB::statement('analyze robot_council_tasks');

    [$sql, $bindings] = capturedQueueSql(TaskStatus::Pending);

    $plan = queuePlan($sql, $bindings);

    // Without it the read still walks an index, which is why a plain "an index was used" check
    // would call this fine -- it falls back to the queue index and filters, discarding thousands
    // of finished tasks to find twenty-six pending ones. That is the composite earning its place,
    // and the gap widens with every task the fleet completes.
    expect($plan)
        ->toContain('Index Scan using '.planIndexName(PLAN_QUEUE_INDEX))
        ->toContain('Rows Removed by Filter:');
});

it('records the full plan matrix', function (): void {
    if (getenv('ROBOT_COUNCIL_MEASURE_PLANS') === false) {
        $this->markTestSkipped('Set ROBOT_COUNCIL_MEASURE_PLANS to write the report.');
    }

    $shapes = [
        'unfiltered first page (the board default)' => [null, null],
        'unfiltered cursor' => [null, ['priority' => 5, 'id' => 9000]],
        'filtered first page' => [TaskStatus::Pending, null],
        'filtered cursor' => [TaskStatus::Pending, ['priority' => 5, 'id' => 9000]],
    ];

    // As shipped, the state before the queue index existed, and that index alone -- which is what
    // says whether the composite is still earning its place.
    $configurations = [
        'both indexes (as shipped)' => [PLAN_COMPOSITE_INDEX, PLAN_QUEUE_INDEX],
        'composite only (before the queue index)' => [PLAN_COMPOSITE_INDEX],
        'queue index only (composite dropped)' => [PLAN_QUEUE_INDEX],
    ];

    // The engine that produced these plans, named in the report: a plan is only evidence about the
    // version that printed it.
    $first = DB::select('select version() as v')[0] ?? null;

    $version = \is_object($first) ? stringValue(get_object_vars($first)['v'] ?? null) : 'unknown';

    $report = sprintf("# Task queue plans\n\n%s\n\nRows: %d\n", $version, PLAN_ROWS);

    foreach (['mixed', 'aged'] as $profile) {
        $this->migrateFresh();
        seedQueue($profile);

        $report .= "\n\n# Profile: ".$profile."\n";

        foreach ($configurations as $label => $wanted) {
            $dropped = array_values(array_filter(
                [PLAN_COMPOSITE_INDEX, PLAN_QUEUE_INDEX],
                fn (array $index): bool => ! \in_array($index, $wanted, true)
            ));

            foreach ($dropped as $index) {
                Schema::table(
                    'robot_council_tasks',
                    fn (Blueprint $table) => $table->dropIndex(planIndexName($index))
                );
            }

            DB::statement('analyze robot_council_tasks');

            // The indexes the engine reports, not the ones this loop believes it left behind. A
            // report whose headings and contents disagree is worse than no report, because every
            // figure read out of it is attributed to the wrong configuration.
            $present = array_values(array_filter(
                DB::connection()->getSchemaBuilder()->getIndexListing('robot_council_tasks'),
                fn (string $name): bool => str_contains($name, 'queue_rank')
            ));

            sort($present);

            $expected = array_map(planIndexName(...), $wanted);

            sort($expected);

            expect($present)->toBe($expected);

            $report .= "\n## ".$label."\n\nIndexes present: ".implode(', ', $present)
                ."\nProfile: ".$profile."\n";

            foreach ($shapes as $name => [$status, $after]) {
                [$sql, $bindings] = capturedQueueSql($status, $after);

                $report .= "\n### ".$name."\n\n```sql\n".$sql."\n```\n\n```\n"
                    .queuePlan($sql, $bindings)."\n```\n";
            }

            // Put back what was dropped, so the next configuration starts from the full set.
            foreach ($dropped as $index) {
                Schema::table('robot_council_tasks', fn (Blueprint $table) => $table->index($index));
            }
        }
    }

    // Anchored on this file rather than the cwd: a run started from elsewhere would otherwise
    // write the report somewhere else and still pass.
    $directory = __DIR__.'/../build';

    if (! is_dir($directory) && ! mkdir($directory, 0o777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create '.$directory.'.');
    }

    if (file_put_contents($directory.'/plans-pgsql.md', $report) === false) {
        throw new RuntimeException('Could not write the plan report.');
    }

    // Not that the report mentions the shapes -- it composed those headings itself, so that would
    // hold however wrong the plans were. That the two configurations which must differ actually
    // do: with the queue index the board's read is walked, and without it Postgres scans and sorts.
    expect($report)
        ->toContain('Index Scan using '.planIndexName(PLAN_QUEUE_INDEX))
        ->toContain('Seq Scan on robot_council_tasks')
        ->toContain('Sort Method:');
});
