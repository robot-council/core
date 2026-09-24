<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table of `blocked_by` edges GitHub has reported (#318).
 *
 * One row per edge, from `issue_dependencies` deliveries: the blocked issue and the issue blocking
 * it, each repository-qualified, because an edge may cross repositories. A placement refuses a
 * ticket with an open blocker (#320), and whether a blocker is open is read from
 * `robot_council_github_items`, so an edge whose blocker has closed stops blocking without being
 * deleted.
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
        if (Schema::hasTable('robot_council_github_blockers')) {
            return;
        }

        Schema::create('robot_council_github_blockers', function (Blueprint $table): void {
            $blocked = $table->string('repository', 140);
            $table->unsignedBigInteger('number');
            $blocker = $table->string('blocker_repository', 140);
            $table->unsignedBigInteger('blocker_number');

            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $blocked->collation('utf8mb4_bin');
                $blocker->collation('utf8mb4_bin');
            }

            // Named: the generated name is past MySQL's 64-character limit
            $table->primary(['repository', 'number', 'blocker_repository', 'blocker_number'], 'robot_council_github_blockers_edge');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_github_blockers');
    }
};
