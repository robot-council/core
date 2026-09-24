<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Makes every column holding a host user key compare byte-exactly.
 *
 * **On MySQL a host user key was compared case- and accent-insensitively, and that decided who
 * sees and claims what.** MySQL 8's default collation is `utf8mb4_0900_ai_ci`, so two distinct
 * host users keyed `bob` and `Bob` compare equal -- and `#37` settled that the key is stored as a
 * string precisely so a host keyed by a UUID, a ULID, a username or an email works. Laravel allows
 * any of those as a primary key, and a case-significant one is therefore supported (#54).
 *
 * Two things went wrong, and both failed **open**:
 *
 * - `Support\Tasks`'s claim carries #16's eligibility rule in its `where`, so one developer's agent
 *   could take another's task. `Models\Task::isClaimableBy()` compares with PHP's `===` and would
 *   have refused, but it only runs to diagnose a write that matched nothing.
 * - `Support\FleetFeed` decides whose narration reaches whose agent on `user_id`, so one
 *   developer's words were served to the other's agents.
 *
 * **Every column that holds a key is changed, not only the three that are compared today.** The
 * set that is compared will grow, the difference is invisible at the call site, and a distinction
 * between "compared" and "merely stored" is the kind that decays. `approved_by` and `decided_by`
 * are provenance rather than predicates, and they are included for that reason rather than because
 * anything reads them this way now.
 *
 * **Postgres and SQLite already compare bytes**, and neither accepts MySQL's collation names, so
 * this is a no-op there rather than a portable declaration -- `robot_council_locks.name` records
 * the same asymmetry in its create migration for the same reason.
 *
 * A separate migration rather than an edit to the create migrations, per CLAUDE.md: those have
 * already run on a deployment. Each column is guarded on what the schema reports, because the
 * three populations -- installed before this, installed after it, and rolled back -- all run it.
 *
 * **`robot_council_github_identities.user_id` is handled here since #132**, and was not when this
 * shipped. Its create migration carried no date prefix, so it sorted after every `2026_*` file and
 * ran last -- this one found no table and skipped the column **silently**, which is how an
 * access-control change shipped covering six of seven. The workaround was a second unprefixed file
 * named to sort after it; #132 dated the create instead, so the column is in the list below like
 * the rest and the workaround is gone.
 *
 * **It is also the first `unique()` column this generic `change()` touches.** The other six are
 * `index()` or bare, and `change()` redefines rather than amends -- so if the unique index did not
 * survive, two host users could claim one GitHub identity. `MODIFY COLUMN` does not drop secondary
 * indexes, which the deleted workaround asserted and nothing checked;
 * `tests/IdentityMigrationRenameTest.php` checks it now, on MySQL, where the rebuild happens.
 *
 * **A host that already ran both keeps its collation and re-runs nothing.** This migration's row
 * is already in `migrations`, so adding a column to the list does not re-apply it -- the workaround
 * had already collated that column on exactly those hosts. A fresh install reaches this after the
 * dated create, so the guard finds the table and does it here.
 */
return new class extends Migration
{
    /**
     * Every column holding a host user key, with whether it is nullable.
     *
     * `change()` redefines a column rather than amending it, so the nullability has to be restated
     * or a nullable column would come back `NOT NULL` and refuse the rows already in it.
     *
     * @return list<array{table: string, column: string, nullable: bool}>
     */
    private function columns(): array
    {
        return [
            ['table' => 'robot_council_installations', 'column' => 'user_id', 'nullable' => false],
            ['table' => 'robot_council_installations', 'column' => 'approved_by', 'nullable' => true],
            ['table' => 'robot_council_agent_sessions', 'column' => 'user_id', 'nullable' => false],
            ['table' => 'robot_council_events', 'column' => 'user_id', 'nullable' => true],
            ['table' => 'robot_council_tasks', 'column' => 'user_id', 'nullable' => false],
            ['table' => 'robot_council_device_codes', 'column' => 'decided_by', 'nullable' => true],
            ['table' => 'robot_council_github_identities', 'column' => 'user_id', 'nullable' => false],
        ];
    }

    /**
     * Give each key column a binary collation on MySQL.
     */
    public function up(): void
    {
        $this->collate('utf8mb4_bin');
    }

    /**
     * Put each key column back on the server's default collation.
     *
     * **Rolling back reopens the defect**, which is the honest direction for a down migration: it
     * restores the schema this changed rather than pretending the change was cosmetic. A host that
     * rolls back gets the comparison it had before.
     */
    public function down(): void
    {
        $this->collate(null);
    }

    /**
     * Apply one collation to every key column that is actually there.
     *
     * @param  string|null  $collation  The collation to set, or null for the server's default.
     */
    private function collate(?string $collation): void
    {
        // Nothing to do where the comparison is already byte-exact, and neither Postgres nor SQLite
        // would accept the name anyway.
        if (! Engines::needsBinaryCollation(DB::getDriverName())) {
            return;
        }

        foreach ($this->columns() as $column) {
            if (! Schema::hasTable($column['table']) || ! Schema::hasColumn($column['table'], $column['column'])) {
                continue;
            }

            Schema::table($column['table'], function (Blueprint $table) use ($column, $collation): void {
                $changed = $table->string($column['column'], 64);

                if ($collation !== null) {
                    $changed->collation($collation);
                }

                if ($column['nullable']) {
                    $changed->nullable();
                }

                $changed->change();
            });
        }
    }
};
