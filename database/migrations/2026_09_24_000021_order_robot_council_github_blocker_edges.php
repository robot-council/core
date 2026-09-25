<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Orders `blocked_by` edge deliveries (#341).
 *
 * GitHub does not promise delivery order, and an edge carried no time of its own, so a removal
 * delivered before the older add left the edge in place for good. Each edge now carries a stamp --
 * the later of the two issues' `updated_at` in the delivery that wrote it -- and a removed edge
 * leaves a tombstone with the removal's stamp for GitHub's redelivery window. A delivery strictly
 * older than what is stored is ignored. `updated_at` never goes backward, so a later change always
 * carries a stamp at least as late; on a tie the last delivery received wins, as before.
 */
return new class extends Migration
{
    /**
     * Add the stamp and the tombstones.
     */
    public function up(): void
    {
        // Guarded on what the schema reports, because three populations run this file: a host that
        // installed before it, one that installs after, and one rolling back and forward again
        if (Schema::hasTable('robot_council_github_blockers') && ! Schema::hasColumn('robot_council_github_blockers', 'stamped_at')) {
            Schema::table('robot_council_github_blockers', function (Blueprint $table): void {
                // Null for an edge written before this, or from a delivery carrying no time
                $table->dateTime('stamped_at')->nullable();
            });
        }

        if (Schema::hasTable('robot_council_github_blocker_removals')) {
            return;
        }

        Schema::create('robot_council_github_blocker_removals', function (Blueprint $table): void {
            $blocked = $table->string('repository', 140);
            $table->unsignedBigInteger('number');
            $blocker = $table->string('blocker_repository', 140);
            $table->unsignedBigInteger('blocker_number');

            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $blocked->collation('utf8mb4_bin');
                $blocker->collation('utf8mb4_bin');
            }

            // The removal's own stamp, which an older add is compared against
            $table->dateTime('stamped_at');

            // When it arrived, on the presence clock, which is what the pruning reads
            $table->dateTime('received_at')->index();

            // Named: the generated name is past MySQL's 64-character limit
            $table->primary(['repository', 'number', 'blocker_repository', 'blocker_number'], 'robot_council_github_blocker_removals_edge');
        });
    }

    /**
     * Remove them.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_github_blocker_removals');

        if (Schema::hasTable('robot_council_github_blockers') && Schema::hasColumn('robot_council_github_blockers', 'stamped_at')) {
            Schema::table('robot_council_github_blockers', function (Blueprint $table): void {
                $table->dropColumn('stamped_at');
            });
        }
    }
};
