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
 * `Models\AgentSession` casts `role` to `Access\Role`, so a row outside the enum raises `ValueError`
 * the moment anything hydrates it; `Http\Middleware\EnsureAgentSession` never reads the column, and
 * a token's abilities are minted from a hydrated enum. Such a row grants nothing -- it produces a
 * wrong boolean from the one query that dodges the cast, and a 500 elsewhere.
 *
 * The reason for the collation is drift: the column should mean one thing whoever wrote it, rather
 * than every future SQL comparison remembering to re-check in PHP.
 *
 * **Separate from `HostKeyComparisonTest` on purpose.** That file's subject is host-supplied
 * identifiers and its failure message is about two developers comparing equal, which is not what
 * goes wrong here. The two sets share a property -- their comparison has to be byte-exact -- and
 * nothing else.
 *
 * @command  DB_CONNECTION=mysql vendor/bin/pest --compact tests/RoleComparisonTest.php
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every column holding a role, which `2026_09_23_000007` gives a binary collation.
 *
 * @return list<array{0: string, 1: string}>
 */
function roleColumns(): array
{
    return [
        ['robot_council_agent_sessions', 'role'],
        ['robot_council_agent_sessions', 'requested_role'],
    ];
}

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
});

it('gives every column holding a role a binary collation', function (string $table, string $column): void {
    // **MySQL-only because the question is.** Postgres and SQLite compare bytes whatever is
    // declared, so there is nothing to assert there. The behavioral coverage that runs everywhere
    // is `FleetCanDirectTest`'s planted-role dataset.
    $collation = schemaField(DB::selectOne(
        'select collation_name as value from information_schema.columns
         where table_schema = database() and table_name = ? and column_name = ?',
        [$table, $column]
    ), 'value');

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
})->with(roleColumns())->skip(notMySql(...), 'Only MySQL lets a collation decide this.');

it('keeps the enumeration closed, so a third role column cannot be added without a decision', function (): void {
    // **The list is what the migration reads from and what the assertion above iterates.** A role
    // column added to the schema and not to both is exactly the silent gap `robot-council/core#54`
    // paid for -- a collation change that covered six of seven key columns and reported success.
    $declared = array_map(static fn (array $row): string => $row[1], roleColumns());

    $inSchema = array_values(array_filter(
        array_map(static fn (array $row): string => $row[1], roleColumns()),
        static fn (string $column): bool => Schema::hasColumn('robot_council_agent_sessions', $column)
    ));

    expect($inSchema)->toBe($declared)
        // And the migration covers exactly what this file claims, read from the file rather than
        // restated: a column in one and not the other is the drift this pins.
        ->and(file_get_contents(__DIR__.'/../database/migrations/2026_09_23_000007_compare_session_roles_byte_exactly.php'))
        ->toContain("'column' => 'role'")
        ->toContain("'column' => 'requested_role'");
});
