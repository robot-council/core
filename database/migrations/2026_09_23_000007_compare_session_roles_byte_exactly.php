<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a session's role a byte-exact comparison, as `2026_09_22_000002` did for the host user keys.
 *
 * **Measured, not reasoned about.** On MySQL 9.4.0 under Testbench's default `utf8mb4_unicode_ci`,
 * a row holding `COORDINATOR` is matched by `Support\FleetAbilities`'s `whereIn('role', ['coordinator'])`
 * and the fleet reports a coordinator that is not running (2026-09-23). SQLite and PostgreSQL 17.0
 * compare case-sensitively and do not. `utf8mb4_unicode_ci` is accent-insensitive too, so
 * `coordinator` with a diacritic matches as well.
 *
 * **What this is NOT, because `robot-council/core#245` first said otherwise.** It is not privilege
 * escalation. `Models\AgentSession` casts `role` to `Access\Role`, and Eloquent casts in
 * `getAttribute()` rather than at hydration -- so a row outside the enum raises `ValueError` the
 * moment anything READS the attribute, which the dashboard and every feed render do.
 * `Http\Middleware\EnsureAgentSession` never reads the column at all, and a token's abilities are
 * minted from a hydrated enum. The measured consequence is a wrong `fleet_can_direct` from the one
 * query that never reads `role` back, and a `ValueError` anywhere that does. Such a row grants
 * nothing.
 *
 * **The reason is drift rather than blast radius.** This package's settled position is that a bound
 * a validation rule states is not a bound the package holds -- every store here holds its own rather
 * than trusting the input path to be the only writer. A collation is the same argument applied to a
 * comparison: the column should mean one thing whoever wrote it. The alternative, a strict PHP
 * re-check at the one call site that compares `role` in SQL, relies on every future call site
 * remembering, and the first such call site was added the same week.
 *
 * `requested_role` comes along. Nothing compares it to a value today -- `Support\RoleRequests` only
 * asks `whereNotNull` -- but it holds the same vocabulary written by the same enum, and a list whose
 * membership depends on which columns happen to be compared this month is a list that rots.
 *
 * **The list this joins is now wider than its name.** `2026_09_22_000002` covers seven host user
 * key columns, which are host-supplied identifiers; these two are written by this package from a
 * closed enum. What both have in common is that their comparison has to be byte-exact, which is the
 * honest description of the set.
 *
 * **No CI job can prove this.** There is no `mysql` job, and `tests/HostKeyComparisonTest.php`'s
 * collation assertions skip on every other engine -- the same position `robot-council/core#54` was
 * in. It is verified by a local MySQL run and by the behavioral test that runs everywhere.
 */
return new class extends Migration
{
    /**
     * The role columns, with their width, nullability and default.
     *
     * **`change()` redefines a column rather than amending it, so EVERYTHING has to be restated.**
     * `2026_09_22_000002` records that for nullability; the default is the same trap and cost a
     * full MySQL suite to find. `role` was created `->default('build')`, and a version of this file
     * that restated only the width and nullability dropped it -- after which every insert that
     * relies on the default fails with `SQLSTATE[HY000] 1364 Field 'role' doesn't have a default
     * value`. Five tests, none of them about roles, and **no CI job could have caught it**: there
     * is no `mysql` job, and on SQLite and Postgres this migration returns before touching anything.
     *
     * @return list<array{table: string, column: string, width: int, nullable: bool, default: string|null}>
     */
    private function columns(): array
    {
        return [
            ['table' => 'robot_council_agent_sessions', 'column' => 'role', 'width' => 16, 'nullable' => false, 'default' => 'build'],
            ['table' => 'robot_council_agent_sessions', 'column' => 'requested_role', 'width' => 16, 'nullable' => true, 'default' => null],
        ];
    }

    /**
     * Give each role column a binary collation on MySQL.
     */
    public function up(): void
    {
        $this->collate('utf8mb4_bin');
    }

    /**
     * Put each role column back on the TABLE's collation.
     *
     * **Not the server's, which the sibling migration's wording says and which is wrong.** The
     * compiled `down` carries no `collate` clause, so MySQL applies the table's charset and
     * collation -- whatever the connection config set when the table was created. On a host whose
     * server default has changed since, those are different.
     *
     * **Rolling back reopens the defect**, which is the honest direction for a down migration: it
     * restores the schema this changed rather than pretending the change was cosmetic.
     */
    public function down(): void
    {
        $this->collate(null);
    }

    /**
     * Apply one collation to every role column that is actually there.
     *
     * @param  string|null  $collation  The collation to set, or null for the server's default.
     */
    private function collate(?string $collation): void
    {
        // Nothing to do where the comparison is already byte-exact, and neither Postgres nor SQLite
        // would accept the name anyway.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->columns() as $column) {
            // Guarded on what the schema reports, because three populations run this file --
            // installed before the role columns existed, installed after them, and rolled back then
            // migrated again.
            if (! Schema::hasTable($column['table']) || ! Schema::hasColumn($column['table'], $column['column'])) {
                continue;
            }

            Schema::table($column['table'], function (Blueprint $table) use ($column, $collation): void {
                $changed = $table->string($column['column'], $column['width']);

                if ($collation !== null) {
                    $changed->collation($collation);
                }

                if ($column['nullable']) {
                    $changed->nullable();
                }

                if ($column['default'] !== null) {
                    $changed->default($column['default']);
                }

                $changed->change();
            });
        }
    }
};
