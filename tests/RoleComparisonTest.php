<?php

declare(strict_types=1);

/**
 * That a session's role is compared byte for byte, whatever the server's default collation is.
 *
 * **The one comparison this protects, measured rather than assumed.** `Support\FleetAbilities` asks
 * `whereIn('role', $roles)` to answer whether a coordinator is running. On MySQL 9.4.0 under
 * Testbench's default `utf8mb4_unicode_ci`, a row holding `COORDINATOR` matched and the fleet
 * reported a coordinator that was not there (2026-09-23). SQLite and PostgreSQL 17.0 compare
 * case-sensitively and did not.
 *
 * **It is not privilege escalation, and `robot-council/core#245` first said it was.**
 * `Models\AgentSession` casts `role` to `Access\Role`, and Eloquent casts in `getAttribute()` rather
 * than at hydration -- so a row outside the enum raises `ValueError` the moment anything READS the
 * attribute. `Http\Middleware\EnsureAgentSession` never reads the column, and a token's abilities are
 * minted from a hydrated enum. Such a row grants nothing: it produces a wrong boolean from the one
 * query that never reads `role` back, and a `ValueError` anywhere that does.
 *
 * **That distinction was already recorded and this file got it wrong anyway.**
 * `Support\FleetAbilities` carries a comment retracting exactly this -- "a cast runs only when the
 * attribute is READ" -- written after an earlier version claimed the narrowed `select()` was what
 * prevented the raise. Prose copied forward from a draft reinstated the retracted version here.
 *
 * The reason for the collation is drift: the column should mean one thing whoever wrote it, rather
 * than every future SQL comparison remembering to re-check in PHP.
 *
 * **Separate from `HostKeyComparisonTest` on purpose.** That file's subject is host-supplied
 * identifiers and its failure message is about two developers comparing equal, which is not what
 * goes wrong here. The two sets share a property -- their comparison has to be byte-exact -- and
 * nothing else.
 *
 * **The command names every variable on purpose.** Run with `DB_CONNECTION=mysql` alone, Testbench
 * falls back to its defaults and `migrate:fresh` wipes a database called `laravel` on
 * `127.0.0.1:3306`.
 *
 * @command  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=<throwaway> DB_USERNAME=root DB_PASSWORD= vendor/bin/pest --compact tests/RoleComparisonTest.php
 */

// Selected by the `mysql` job, which runs `--group=engine-semantics` rather than the whole
// suite. `EngineSemanticsGroupGuardTest` fails when a file that gates itself on MySQL omits
// this line, so the group cannot silently stop covering a test.
pest()->group('engine-semantics');

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every column holding a role, which `2026_09_23_000007` gives a binary collation.
 *
 * @return list<array{0: string, 1: string, 2: string, 3: string}>
 */
function roleColumns(): array
{
    return [
        // Table, column, the default `change()` has to restate, and the nullability.
        ['robot_council_agent_sessions', 'role', 'build', 'NO'],
        ['robot_council_agent_sessions', 'requested_role', '(none)', 'YES'],
    ];
}

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
});

it('gives every column holding a role a binary collation, and keeps its default', function (string $table, string $column, string $expectedDefault, string $expectedNullable): void {
    // **MySQL-only because the question is.** Postgres and SQLite compare bytes whatever is
    // declared, so there is nothing to assert there. The behavioral coverage that runs everywhere
    // is `FleetCanDirectTest`'s planted-role dataset.
    $row = DB::selectOne(
        'select collation_name as collation, ifnull(column_default, ?) as `default`, is_nullable as nullable
         from information_schema.columns
         where table_schema = database() and table_name = ? and column_name = ?',
        ['(none)', $table, $column]
    );

    $collation = schemaField($row, 'collation');

    // The control: the column was found at all. A misspelled table or a wrong schema returns no
    // row, and an empty collation would then read as "not case-insensitive" and pass.
    expect($collation)->not->toBe('', sprintf('%s.%s was not found in information_schema.', $table, $column));

    expect($collation)->toEndWith('_bin', sprintf(
        '%s.%s is `%s`. A case- or accent-insensitive collation makes a role the enum does not '
        .'define compare equal to one it does, so `fleet_can_direct` reports a coordinator that is '
        .'not running.',
        $table,
        $column,
        $collation
    ));

    // **The default and the nullability, asserted here because `change()` drops what it is not
    // told.** That is not hypothetical: the first version of the migration restated only the width
    // and the nullability, dropped `role`'s `default('build')`, and five tests with nothing to do
    // with roles failed on `SQLSTATE[HY000] 1364`. Nothing pinned it -- this query already had the
    // row in hand and selected one column out of it.
    //
    // The whole point is that no CI job runs this. On SQLite and Postgres the migration returns
    // before touching anything, so a future edit that trims `'default'` back out is green on every
    // check the project has.
    expect(schemaField($row, 'default'))->toBe($expectedDefault)
        ->and(schemaField($row, 'nullable'))->toBe($expectedNullable);
})->with(roleColumns())->skip(notMySql(...), 'Only MySQL lets a collation decide this.');

it('leaves the role index in place, which the rebuild could have dropped', function (): void {
    // **This is the first `change()` in the package to touch an indexed column.** `2026_09_23_000006`
    // creates `(role, status, id)` one migration earlier, and this one rebuilds `role` underneath
    // it. `MODIFY COLUMN` is documented to preserve secondary indexes, and the sibling migration
    // added a whole test the first time its `collate()` touched a `unique()` column for exactly
    // this reason -- so the precedent is to check rather than to trust the documentation.
    //
    // **If the index were lost the symptom is silence**: a sequential scan on the route an agent
    // calls after every start and renewal, with nothing failing. `FleetCanDirectPlanTest` names
    // this index but skips unless the driver is `pgsql`, so on MySQL -- the one engine where the
    // rebuild actually happens -- nothing else looks.
    $names = array_map(
        static fn (mixed $index): string => stringValue(arrayValue($index)['name'] ?? null),
        Schema::getIndexes('robot_council_agent_sessions')
    );

    expect($names)->toContain('robot_council_agent_sessions_role_status_id_index')
        // The control: indexes were read at all. An empty list contains nothing and would fail
        // above for the wrong reason, so this says the instrument found the others too.
        ->and(count($names))->toBeGreaterThan(1);
});

it('enumerates the schema, so a third role column cannot be added without this list', function (): void {
    // **The previous version of this test could not fail for the reason it named.** It filtered
    // `roleColumns()` by `Schema::hasColumn()` and compared the result with `roleColumns()` -- both
    // operands from the same source, so the assertion was `subset(X) === X` and the only way to
    // break it was deleting one of the two columns it already listed. A third role column added to
    // the schema and forgotten here stayed green on every engine, which is exactly the silent gap
    // `robot-council/core#54` paid for.
    //
    // This reads the SCHEMA and requires the list to cover it, which is the direction that can
    // fail.
    $inSchema = array_values(array_filter(
        array_map(
            static fn (array $column): string => stringValue(arrayValue($column)['name'] ?? null),
            array_map(arrayValue(...), Schema::getColumns('robot_council_agent_sessions'))
        ),
        static fn (string $name): bool => str_ends_with($name, 'role')
    ));

    sort($inSchema);

    $declared = array_map(static fn (array $row): string => $row[1], roleColumns());
    sort($declared);

    expect($inSchema)->toBe($declared, sprintf(
        'The schema holds [%s] and this file lists [%s]. A role column the migration does not '
        .'collate compares case-insensitively on MySQL.',
        implode(', ', $inSchema),
        implode(', ', $declared)
    ));

    // The control: the read found something. An empty schema read would satisfy an empty list.
    expect($inSchema)->not->toBeEmpty();
});

it('collates exactly the columns this file lists, read from the migration', function (): void {
    // The other half of the pair, and it asserts BOTH directions rather than containment. A
    // migration that collated a third column, or a list that grew past the migration, passed the
    // previous `toContain` version.
    $source = (string) file_get_contents(__DIR__.'/../database/migrations/2026_09_23_000007_compare_session_roles_byte_exactly.php');

    preg_match_all("/'column' => '([^']+)'/", $source, $matches);

    $collated = $matches[1];
    sort($collated);

    $declared = array_map(static fn (array $row): string => $row[1], roleColumns());
    sort($declared);

    expect($collated)->toBe($declared)
        // The control: the migration was actually read. A wrong path returns `''` and matches
        // nothing, which would compare equal to an empty list.
        ->and($collated)->not->toBeEmpty();
});
