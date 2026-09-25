<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table recording how each repository's latest backlog fetch went (#383).
 *
 * **A failed fetch stores no reading, so the readings cannot say why a meter is blank.** One row
 * per repository, overwritten by each attempt, holds the outcome -- read, no installation for its
 * owner, or the status GitHub answered -- so `robot-council:doctor` can name a missing installation
 * or a revoked key rather than leave it to be inferred from a dash.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        if (Schema::hasTable('robot_council_backlog_fetches')) {
            return;
        }

        Schema::create('robot_council_backlog_fetches', function (Blueprint $table): void {
            // `WorkIdentity::MAX_REPOSITORY`, written out so this file means the same thing after
            // that class is edited
            $repository = $table->string('repository', 140);

            // `BacklogFetches::MAX_OUTCOME`, written out for the same reason
            $table->string('outcome', 32);

            // The HTTP status GitHub answered with, when it answered at all
            $table->unsignedSmallInteger('status')->nullable();
            $table->dateTime('attempted_at');

            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $repository->collation('utf8mb4_bin');
            }

            $table->primary('repository', 'robot_council_backlog_fetches_repository');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_backlog_fetches');
    }
};
