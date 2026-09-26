<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds whether a session is ephemeral (#424).
 *
 * An ephemeral session is one the fleet is not told about: it writes no presence event into the
 * feed and appears in no list of sessions or lanes, while authenticating, reading and releasing
 * exactly as any other. `robot-council api` starts one for a read, which holds nothing and so has
 * nothing to announce (`robot-council/cli#298`).
 *
 * Non-nullable with a default of false, so every existing row, and every session an older client
 * starts, is an ordinary one -- a null here would be a third answer to a yes-or-no question.
 */
return new class extends Migration
{
    /**
     * Add the column, where the schema does not already report it.
     */
    public function up(): void
    {
        if (! Schema::hasTable('robot_council_agent_sessions') || Schema::hasColumn('robot_council_agent_sessions', 'ephemeral')) {
            return;
        }

        Schema::table('robot_council_agent_sessions', function (Blueprint $table): void {
            $table->boolean('ephemeral')->default(false);
        });
    }

    /**
     * Drop the column, where the schema reports it.
     */
    public function down(): void
    {
        if (! Schema::hasTable('robot_council_agent_sessions') || ! Schema::hasColumn('robot_council_agent_sessions', 'ephemeral')) {
            return;
        }

        Schema::table('robot_council_agent_sessions', function (Blueprint $table): void {
            $table->dropColumn('ephemeral');
        });
    }
};
