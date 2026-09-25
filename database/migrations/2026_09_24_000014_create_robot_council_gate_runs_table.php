<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table of pull requests each gate is validating (#336).
 *
 * One row per gate session -- a gate validates one pull request at a time -- written by the gate
 * itself and cleared by it, or by a GitHub delivery reporting that pull request closed or merged
 * (#318). The lane board reads it to mark a pull request `running` and to show a gate's work.
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
        if (Schema::hasTable('robot_council_gate_runs')) {
            return;
        }

        Schema::create('robot_council_gate_runs', function (Blueprint $table): void {
            // Cascades, because a run of a pruned session describes nothing
            $table->foreignId('agent_session_id')->primary()->constrained('robot_council_agent_sessions')->cascadeOnDelete();

            // `WorkIdentity::MAX_REPOSITORY`, written out so this file means the same thing after
            // that class is edited
            $repository = $table->string('repository', 140);
            $table->unsignedBigInteger('number');
            $table->dateTime('started_at');

            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $repository->collation('utf8mb4_bin');
            }

            $table->index(['repository', 'number'], 'robot_council_gate_runs_pull');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_gate_runs');
    }
};
