<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the tables behind the lane board's backlog meters (#339).
 *
 * **Counts come from sessions, not from the webhook.** A webhook carries events and not totals, so
 * a session that can see a repository's open issues reports the count, and each report is kept with
 * who sent it and when. The start-of-day baseline is a snapshot of the latest reading at 08:00 in
 * the configured timezone, one row per repository per local day.
 *
 * Readings are append-only and pruned when a baseline is taken; a baseline is small and kept.
 */
return new class extends Migration
{
    /**
     * Create the tables.
     */
    public function up(): void
    {
        // Each guarded on its own, because three populations run this file: a host that installed
        // before it, one that installs after, and one rolling back and forward again
        if (! Schema::hasTable('robot_council_backlog_readings')) {
            Schema::create('robot_council_backlog_readings', function (Blueprint $table): void {
                $table->id();

                // `WorkIdentity::MAX_REPOSITORY`, written out so this file means the same thing
                // after that class is edited
                $repository = $table->string('repository', 140);
                $table->unsignedInteger('open_issues');

                // The session that reported it. No foreign key, for #50's reason: who reported a
                // number is history and outlives that session's row.
                $table->unsignedBigInteger('reported_by');
                $table->dateTime('read_at');

                if (Engines::needsBinaryCollation(DB::getDriverName())) {
                    $repository->collation('utf8mb4_bin');
                }

                $table->index(['repository', 'read_at'], 'robot_council_backlog_readings_latest');
            });
        }

        if (! Schema::hasTable('robot_council_backlog_baselines')) {
            Schema::create('robot_council_backlog_baselines', function (Blueprint $table): void {
                $repository = $table->string('repository', 140);

                // The local date the baseline is for, in the configured timezone
                $table->date('day');
                $table->unsignedInteger('open_issues');
                $table->dateTime('taken_at');

                if (Engines::needsBinaryCollation(DB::getDriverName())) {
                    $repository->collation('utf8mb4_bin');
                }

                $table->primary(['repository', 'day'], 'robot_council_backlog_baselines_day');
            });
        }
    }

    /**
     * Drop the tables.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_backlog_baselines');
        Schema::dropIfExists('robot_council_backlog_readings');
    }
};
