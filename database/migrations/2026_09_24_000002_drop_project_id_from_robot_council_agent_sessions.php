<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\ProjectId;

/**
 * Drop `robot_council_agent_sessions.project_id`, the one label the two work-identity fields replaced.
 *
 * `robot-council/core#234` split the label into `repository` and `work_location` and deliberately
 * kept it, because a released `robot-council/cli` still sent it and nothing had yet seen a fleet
 * store the two fields. `robot-council/cli#137` closed that: measured against the production
 * deployment on 2026-09-24, running `core` v0.4.0, fifteen sessions answered `repository` and
 * `work_location` from the checkout, and `project_id` was null on every one of them that a current
 * client started from a readable checkout.
 *
 * **The legacy label still arrives, and it is still honored -- at the edge rather than on the row.**
 * `--project` remains a flag on the client's `api`, `mcp` and `pending` commands, and
 * `robot-council/cli#137` recorded sessions started that way (150, 151, 154).
 * `Http\Controllers\SessionStartController` now translates such a request into the two fields with
 * `Support\WorkIdentity::fromProjectId()`, on the same rule the store used to apply -- the split
 * happens only when the client names neither field. So an un-upgraded bridge keeps its identity in
 * the fleet; what ends here is storing the raw label a second time.
 *
 * **Guarded on what the schema reports, because three populations run this file.** A host that
 * installed before `#234` has the column from the create migration; one that installed after it
 * still has it, because `#234` added the two fields beside it rather than replacing it; and one
 * that rolled this back and migrated again has it restored by `down()`. `Schema::hasColumn()` is
 * the only thing that tells those apart, and an unguarded `dropColumn` stops the whole batch on the
 * one that has already run.
 *
 * **`down()` restores the column and not its contents, and this is where the old label's data
 * ends.** `2026_09_23_000004_add_work_identity_to_robot_council_agent_sessions` backfills the two
 * fields *from* `project_id`, and its own docblock says a rollback discards the split because a
 * later `up()` can perform it again. That stops being true once this runs: rolling this back
 * restores an empty column, so a subsequent rollback of `#234` loses the split with nothing left to
 * rebuild it from. The direction that matters is preserved -- the data was copied into `repository`
 * and `work_location` before this file drops its source, and those are the columns the fleet reads.
 */
return new class extends Migration
{
    /**
     * The table this changes.
     */
    private const string TABLE = 'robot_council_agent_sessions';

    /**
     * The column it drops.
     */
    private const string COLUMN = 'project_id';

    /**
     * Drop the column, if this host still has it.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $blueprint): void {
            $blueprint->dropColumn(self::COLUMN);
        });
    }

    /**
     * Put the column back, with the width and nullability the create migration declared.
     *
     * Restated here rather than inferred, because a rollback that changed either would leave a host
     * in a state no migration describes. `ProjectId::MAX` is the same constant
     * `2026_09_18_000002_create_robot_council_agent_sessions_table` pins it to, for the reason that
     * class records: an unlengthed `string()` takes `Schema::$defaultStringLength`, which a host may
     * lower.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $blueprint): void {
            $blueprint->string(self::COLUMN, ProjectId::MAX)->nullable();
        });
    }
};
