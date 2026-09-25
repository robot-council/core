<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds when the coordinator was last told a session had gone quiet (#332).
 *
 * The quiet check runs on a schedule, so without a record of what it already said it would tell the
 * coordinator again on every run. A session is told about once per quiet stretch: the column is
 * compared with the session's latest substantive act, and a later act starts a new stretch.
 *
 * A `dateTime` rather than a `timestamp`, for the reason `last_seen_at` is one.
 */
return new class extends Migration
{
    /**
     * Add the column.
     */
    public function up(): void
    {
        if (! Schema::hasTable('robot_council_agent_sessions') || Schema::hasColumn('robot_council_agent_sessions', 'quiet_noticed_at')) {
            return;
        }

        Schema::table('robot_council_agent_sessions', function (Blueprint $table): void {
            $table->dateTime('quiet_noticed_at')->nullable();
        });
    }

    /**
     * Drop the column.
     */
    public function down(): void
    {
        if (! Schema::hasTable('robot_council_agent_sessions') || ! Schema::hasColumn('robot_council_agent_sessions', 'quiet_noticed_at')) {
            return;
        }

        Schema::table('robot_council_agent_sessions', function (Blueprint $table): void {
            $table->dropColumn('quiet_noticed_at');
        });
    }
};
