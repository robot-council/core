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
     *
     * **Each guarded on its own existence.** MySQL adds them in two statements outside any
     * transaction, so a deploy killed between them leaves one; a guard on the first alone would
     * then return early on every re-run and never add the second.
     */
    public function up(): void
    {
        if (! Schema::hasTable('robot_council_agent_sessions')) {
            return;
        }

        foreach (['os_family' => 16, 'arch' => 32] as $column => $length) {
            if (! Schema::hasColumn('robot_council_agent_sessions', $column)) {
                // `PHP_OS_FAMILY`'s longest value is seven characters; `arch` is `Platform::MAX_ARCH`
                Schema::table('robot_council_agent_sessions', function (Blueprint $table) use ($column, $length): void {
                    $table->string($column, $length)->nullable();
                });
            }
        }
    }

    /**
     * Drop them, each only where it exists, for the same reason.
     */
    public function down(): void
    {
        if (! Schema::hasTable('robot_council_agent_sessions')) {
            return;
        }

        foreach (['os_family', 'arch'] as $column) {
            if (Schema::hasColumn('robot_council_agent_sessions', $column)) {
                Schema::table('robot_council_agent_sessions', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
