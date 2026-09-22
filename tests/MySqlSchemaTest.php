<?php

declare(strict_types=1);

/**
 * What only MySQL can say about the package's own schema.
 *
 * **`timestamp` and `dateTime` are the same thing until they are not.** With
 * `explicit_defaults_for_timestamp` off, MySQL and MariaDB give the first `NOT NULL` `TIMESTAMP`
 * column in a table an implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` -- so any
 * UPDATE to that row silently rewrites the column. `robot_council_device_codes.expires_at` was such
 * a column: approving a code is an UPDATE, so approval reset the expiry to now and made the
 * approved code impossible to exchange. #22 changed the columns; #39 is what stops the next one.
 *
 * Neither database CI ran before this could see it. SQLite has no such rule and Postgres has no
 * `TIMESTAMP ... ON UPDATE` at all, so the defect was invisible to the whole matrix.
 *
 * @command  DB_CONNECTION=mysql vendor/bin/pest --compact tests/MySqlSchemaTest.php
 */

use Illuminate\Support\Facades\DB;

it('is on MySQL, in the mode the job exists for, whenever the workflow says it should be', function (): void {
    // **Gated on the workflow's own promise rather than on the driver, and that is the point.**
    // Every other test here skips when the driver is not MySQL, so a `mysql` job whose
    // `DB_CONNECTION` never took effect would run the whole suite on SQLite, skip all of them, and
    // report green -- a job that tests nothing, in the reassuring direction. This one runs
    // whenever `ROBOT_COUNCIL_EXPECT_MYSQL` is set, which only that job sets, and fails if the
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

it('gives no package column the implicit ON UPDATE that a NOT NULL timestamp would carry', function (): void {
    // The regression guard #39 asks for. Every one of the package's own date columns is declared
    // `dateTime` rather than `timestamp` for this reason, and nothing but a test keeps the next
    // one from being declared the other way.
    $columns = DB::select(<<<'SQL'
        select table_name, column_name, is_nullable, data_type, extra
        from information_schema.columns
        where table_schema = database()
          and table_name like 'robot\_council\_%'
        SQL);

    // **The control, before the assertion.** `information_schema` answers for the schema the
    // connection is on, and a wrong database or a name pattern that matched nothing would return
    // an empty set -- which passes the assertion below for the wrong reason. The package owns
    // eight tables and every one of them has date columns.
    expect($columns)->not->toBeEmpty();

    $offenders = array_filter(
        $columns,
        static fn (mixed $column): bool => strtolower(schemaField($column, 'data_type')) === 'timestamp'
            && strtoupper(schemaField($column, 'is_nullable')) === 'NO'
    );

    $named = array_values(array_map(static function (mixed $column): string {
        $extra = schemaField($column, 'extra');

        return sprintf(
            '%s.%s (%s)',
            schemaField($column, 'table_name'),
            schemaField($column, 'column_name'),
            $extra === '' ? 'no extra' : $extra
        );
    }, $offenders));

    expect($named)->toBe([], sprintf(
        'A NOT NULL `timestamp` column carries an implicit ON UPDATE CURRENT_TIMESTAMP while '
        .'explicit_defaults_for_timestamp is off, so any update to the row rewrites it. Declare it '
        .'`dateTime` instead. Found: %s',
        implode(', ', $named)
    ));
})->skip(notMySql(...), 'Only MySQL gives a NOT NULL timestamp an implicit ON UPDATE.');
