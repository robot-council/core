<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table of lanes a coordinator is keeping idle on purpose, and why (#334).
 *
 * **One row per lane, and a lane is a session**, as #314 settled: the hold describes what the
 * coordinator is doing with this session now, and placing the session clears it in the same
 * transaction. A seat's own settings belong to its developer (#322); this is the coordinator's
 * record, which is why it is not a column on `robot_council_seats`.
 *
 * `held_at` is when the coordinator recorded it, which the board shows as `Known since` -- how long
 * ago the state was observed, not how long it has held.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        // Guarded, because three populations run this file: a host that installed before it, one
        // that installs after, and one rolling back and forward again.
        if (Schema::hasTable('robot_council_lane_holds')) {
            return;
        }

        Schema::create('robot_council_lane_holds', function (Blueprint $table): void {
            // Cascades, because a hold on a pruned session describes nothing. Placement clears it
            // explicitly; this only tidies what pruning leaves.
            $table->foreignId('agent_session_id')->primary()->constrained('robot_council_agent_sessions')->cascadeOnDelete();

            $table->string('party_kind', 16);

            // A GitHub login (39 characters at most) or `IssueReference::MAX`, written out rather than
            // read from the class so this file means the same thing after that class is edited
            $party = $table->string('party', 151);
            $table->string('reason', 32);

            // The coordinator session that recorded it. No foreign key: which session held the
            // ability is history, and outlives that session's row.
            $table->unsignedBigInteger('held_by');
            $table->dateTime('held_at');

            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $party->collation('utf8mb4_bin');
            }
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_lane_holds');
    }
};
