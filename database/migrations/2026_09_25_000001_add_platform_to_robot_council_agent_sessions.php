<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the operating system and architecture a session runs on (#351).
 *
 * On the session rather than the installation: an installation is a credential, and the session is
 * the process that runs somewhere, so one reading per start also covers a credential moved between
 * machines. Both nullable, so a session started by an older bridge stores nothing rather than a
 * guess.
 */
return new class extends Migration
{
    /**
     * Add the columns.
     */
    public function up(): void
    {
        if (! Schema::hasTable('robot_council_agent_sessions') || Schema::hasColumn('robot_council_agent_sessions', 'os_family')) {
            return;
        }

        Schema::table('robot_council_agent_sessions', function (Blueprint $table): void {
            // The longest `PHP_OS_FAMILY` value is seven characters
            $table->string('os_family', 16)->nullable();
            $table->string('arch', 32)->nullable();
        });
    }

    /**
     * Drop them.
     */
    public function down(): void
    {
        if (! Schema::hasTable('robot_council_agent_sessions') || ! Schema::hasColumn('robot_council_agent_sessions', 'os_family')) {
            return;
        }

        Schema::table('robot_council_agent_sessions', function (Blueprint $table): void {
            $table->dropColumn(['os_family', 'arch']);
        });
    }
};
