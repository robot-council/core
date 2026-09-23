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
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            // **Not nullable, and defaulted rather than backfilled from nothing.** A null role
            // would mean a session with no preset and therefore no abilities, which is a state
            // `Access\Role` has no case for and which every reader would have to guess at. 16
            // characters because the column holds the enum's backing value, and the longest is
            // `coordinator`; declared explicitly rather than taking `Schema::$defaultStringLength`,
            // which is a public static a host may lower.
            $table->string(self::COLUMN, 16)->default('build');
        });

        $coordinators = $this->coordinatorInstallations();

        if ($coordinators === []) {
            return;
        }

        // `whereIn` over ids read in PHP, not `whereJsonContains`, which compiles differently on
        // each of the three engines this package supports. The set is small -- an admin grants
        // `coordinator:direct` by hand -- and this runs once.
        DB::table(self::TABLE)
            ->whereIn('installation_id', $coordinators)
            ->update([self::COLUMN => 'coordinator']);
    }

    /**
     * Drop it.
     *
     * The backfill is not undone, because the column it wrote is going with it.
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
