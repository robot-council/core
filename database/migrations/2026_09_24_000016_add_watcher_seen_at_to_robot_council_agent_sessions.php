<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds when a session's watcher last reported, apart from the session's own contact (#337).
 *
 * **Session contact cannot say whether anyone is watching.** Every request an agent makes refreshes
 * `last_seen_at`, so a lane whose bridge watcher died while its agent kept calling tools reads as
 * watched. The watcher -- the bridge process that wakes an idle agent -- reports its own heartbeat,
 * and only that route writes this column.
 *
 * Nullable, and null means the watcher never reported: the lane board reads it as `absent`. A
 * `dateTime` rather than a `timestamp`, for the reason `last_seen_at` is one: MySQL's implicit
 * `ON UPDATE` would refresh it with every other write to the row.
 */
return new class extends Migration
{
    /**
     * Add the column.
     */
    public function up(): void
    {
        // Guarded on what the schema reports, because three populations run this file
        if (! Schema::hasTable('robot_council_agent_sessions') || Schema::hasColumn('robot_council_agent_sessions', 'watcher_seen_at')) {
            return;
        }

        Schema::table('robot_council_agent_sessions', function (Blueprint $table): void {
            $table->dateTime('watcher_seen_at')->nullable();
        });
    }

    /**
     * Drop the column.
     */
    public function down(): void
    {
        if (! Schema::hasTable('robot_council_agent_sessions') || ! Schema::hasColumn('robot_council_agent_sessions', 'watcher_seen_at')) {
            return;
        }

        Schema::table('robot_council_agent_sessions', function (Blueprint $table): void {
            $table->dropColumn('watcher_seen_at');
        });
    }
};
