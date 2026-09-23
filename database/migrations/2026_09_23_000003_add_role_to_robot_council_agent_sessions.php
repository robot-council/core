<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a session a role, and backfills one for every session written before the column existed.
 *
 * The role is what a session's token abilities come from after `robot-council/core#221`, so a row
 * without one is a row whose next renewal would mint nothing. That is why this backfills rather
 * than leaving the default to speak for the rows already there: a session belonging to a machine an
 * admin made a coordinator holds `coordinator:direct` today, and taking it away on the next renewal
 * -- silently, from a process that is running -- is the regression this file exists to prevent.
 *
 * **The derivation is the one `Access\Role::defaultFor()` applies going forward**, stated here in
 * literals rather than read from the enum. A migration describes a change between two fixed points
 * in time, and reading `Access\Ability` or `Access\Role` would make this file mean something
 * different the next time a case is renamed -- which is the class of drift
 * `2026_09_23_000002_rename_session_enrolled_events.php` was written to repair.
 *
 * **A new migration rather than an edit to the create migration**, because
 * `robot-council/robot-council` has already run that one and an edit would leave a deployed
 * database and a fresh install with different schemas and nothing to say so (#94, #100).
 *
 * Guarded on what the schema reports, because three populations run this file: installed before
 * the column, installed after it, and rolled back then migrated again.
 */
return new class extends Migration
{
    /**
     * The table this adds to.
     */
    private const string TABLE = 'robot_council_agent_sessions';

    /**
     * The column added.
     */
    private const string COLUMN = 'role';

    /**
     * The table the derivation reads.
     */
    private const string INSTALLATIONS = 'robot_council_installations';

    /**
     * The ability that made a machine a coordinator before roles existed.
     */
    private const string COORDINATOR_ABILITY = 'coordinator:direct';

    /**
     * Add the role column, and give every existing session the role it is already running as.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // **Read before the ALTER, and the backfill is not gated on the column being new.** Two
        // reasons, both about a run that does not finish. Only Postgres and SQL Server wrap a
        // migration in a transaction -- `Schema\Grammars\Grammar::$transactions` is false and only
        // `PostgresGrammar` overrides it -- so on SQLite and MySQL the ALTER and the UPDATE are
        // independent statements. A crash between them leaves the column added, nothing backfilled
        // and no `migrations` row; a guard reading `hasColumn` would then skip the backfill on the
        // re-run and report success, which is exactly how #54 shipped an access-control change
        // covering six of seven columns. Reading first also keeps the scan of
        // `robot_council_installations` outside the window where Postgres holds ACCESS EXCLUSIVE
        // on this table, which every session-token lookup in the fleet blocks behind.
        //
        // Re-running the backfill is safe because it is idempotent: it sets the same rows to the
        // same value from the same input.
        $coordinators = $this->coordinatorInstallations();

        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            $this->addColumn();
        }

        if ($coordinators === []) {
            return;
        }

        // `whereIn` over ids read in PHP, not `whereJsonContains`, which compiles differently on
        // each of the three engines this package supports. The set is small -- an admin grants
        // `coordinator:direct` by hand -- and the bind ceiling it would have to pass is 32,766 on
        // SQLite and 65,535 on Postgres and MySQL.
        DB::table(self::TABLE)
            ->whereIn('installation_id', $coordinators)
            ->update([self::COLUMN => 'coordinator']);
    }

    /**
     * Add the column itself.
     */
    private function addColumn(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            // **Not nullable, and defaulted rather than backfilled from nothing.** A null role
            // would mean a session with no preset and therefore no abilities, which is a state
            // `Access\Role` has no case for and which every reader would have to guess at. 16
            // characters because the column holds the enum's backing value, and the longest is
            // `coordinator`; declared explicitly rather than taking `Schema::$defaultStringLength`,
            // which is a public static a host may lower.
            $table->string(self::COLUMN, 16)->default('build');
        });
    }

    /**
     * Drop it.
     *
     * **A rollback discards every role the running system has written, and a later `up()` derives
     * them again from `granted_abilities`.** That is the most this can do -- the column is where
     * the roles live, so dropping it destroys them -- but the consequence is worth stating rather
     * than leaving to be discovered: a session an admin demoted while its machine kept
     * `coordinator:direct` comes back a coordinator on the next `migrate`. Today that is the only
     * value a rollback cycle can invent, because `start()` derives the same way. It stops being
     * the only one when a session can ask for a role (`robot-council/core#222`), and whichever
     * migration introduces that needs to decide what a rollback means for a requested role.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }

    /**
     * The ids of the installations that could direct the fleet before this ran.
     *
     * Decoded in PHP rather than matched in SQL, and every value narrowed on the way: the column is
     * `json` and the row decides what is in it, so anything that is not a list of strings
     * contributes nothing rather than raising from inside a migration a host is running.
     *
     * **`granted_abilities` arrives as a PHP string on all three engines**, which is what makes the
     * `is_string()` arm the live one rather than a silent no-op. Measured 2026-09-23 through
     * prepared statements with the options `Connectors\Connector` sets: PostgreSQL 17.0 `json`,
     * MySQL 9.4.0 `json` and SQLite 3.45.2 `text` all return `string`, while a `bigint` returns
     * `int` and a Postgres `boolean` returns `bool` -- so the probe could have reported a non-string
     * and did not.
     *
     * **It calls `json_decode()` directly rather than Laravel's `Json::decode()`**, so a host that
     * installed its own decoder with `Json::decodeUsing()` and stores something the standard
     * decoder cannot read would get no backfill. A migration reads the row rather than the
     * package's accessors by design, and that is the price of it.
     *
     * The read is deliberately unfiltered: a revoked or expired installation still gets its
     * sessions' roles derived, and so do sessions that have already gone. Neither can act --
     * `Http\Middleware\EnsureAgentSession` refuses both -- and recording what a session WAS is
     * more honest than defaulting it to `build`, which would say it was never a coordinator.
     *
     * @return list<int> The installation ids whose sessions become coordinators.
     */
    private function coordinatorInstallations(): array
    {
        if (! Schema::hasTable(self::INSTALLATIONS)) {
            return [];
        }

        $coordinators = [];

        foreach (DB::table(self::INSTALLATIONS)->select('id', 'granted_abilities')->cursor() as $row) {
            if (! is_object($row) || ! property_exists($row, 'id') || ! property_exists($row, 'granted_abilities')) {
                continue;
            }

            $abilities = is_string($row->granted_abilities) ? json_decode($row->granted_abilities, true) : null;

            if (is_array($abilities) && in_array(self::COORDINATOR_ABILITY, $abilities, true) && is_numeric($row->id)) {
                $coordinators[] = (int) $row->id;
            }
        }

        return $coordinators;
    }
};
