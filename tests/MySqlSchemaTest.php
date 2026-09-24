<?php

declare(strict_types=1);

/**
 * That a run promising MySQL is on MySQL, in the mode the promise is about.
 *
 * **The schema check this file was named for has moved to the source** (#136).
 * `tests/MigrationTimestampGuardTest.php` reads what the migrations declare rather than what one
 * engine does with the declaration, so it runs on every engine and needs no database -- and it
 * fails in the pull request that adds the column rather than in whichever job happens to have
 * MySQL. Two guards with different reach were worse than one, so the `information_schema` version
 * is gone; `robot-council:doctor` still asks that question of a host's live schema, where drift no
 * source scan can see is exactly what is being looked for.
 *
 * What stays here is the promise itself, and it guards every MySQL-gated test in the suite rather
 * than only the one it used to sit beside -- `HostKeyComparisonTest`'s collation assertions are the
 * others. A `mysql` job whose `DB_CONNECTION` never took effect would run the whole suite on SQLite,
 * skip all of them, and report green: a job that tests nothing, in the reassuring direction. This
 * file is what refuses that. The `mysql` job sets `ROBOT_COUNCIL_EXPECT_MYSQL`, so this runs there
 * and skips everywhere else.
 *
 * @command  DB_CONNECTION=mysql ROBOT_COUNCIL_EXPECT_MYSQL=1 vendor/bin/pest --compact tests/MySqlSchemaTest.php
 */

// Selected by the `mysql` job, which runs `--group=engine-semantics` rather than the whole
// suite. `EngineSemanticsGroupGuardTest` fails when a file that gates itself on MySQL omits
// this line, so the group cannot silently stop covering a test.
pest()->group('engine-semantics');

use Illuminate\Support\Facades\DB;

it('is on MySQL, in the mode the job exists for, whenever the workflow says it should be', function (): void {
    // **Gated on the workflow's own promise rather than on the driver, and that is the point.**
    // Every MySQL-gated test in the suite skips when the driver is not MySQL, so a `mysql` job
    // whose `DB_CONNECTION` never took effect would run the whole suite on SQLite, skip all of
    // them, and report green -- a job that tests nothing, in the reassuring direction. This one
    // runs whenever `ROBOT_COUNCIL_EXPECT_MYSQL` is set, which only that job sets, and fails if the
    // connection is not what the job intended.
    expect(DB::connection()->getDriverName())->toBe('mysql');

    // And the mode. The workflow turns it off with a `SET GLOBAL` before the suite runs; a `SET`
    // that silently did nothing -- a variable that stopped being dynamic, a step reordered, a
    // connection that did not inherit the global -- would leave the job exercising the safe
    // configuration. The whole value of the job is the risky mode, so the mode is a test.
    $setting = DB::selectOne('select @@session.explicit_defaults_for_timestamp as value');

    expect($setting)->not->toBeNull()
        ->and(schemaField($setting, 'value'))->toBe('0');
})->skip(
    fn (): bool => getenv('ROBOT_COUNCIL_EXPECT_MYSQL') === false,
    'Only the workflow job that promises MySQL runs this.'
);
