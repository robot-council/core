<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds when the fleet was told a session had stopped answering (#574).
 *
 * A session now goes `stale` at once and is announced only if it is still stale one threshold
 * later, so a short flap writes no event at all. The column is what says whether this stretch was
 * announced, which decides whether a resumption is announced too.
 *
 * **Every session already stale is marked announced**, because it was: before this, the transition
 * itself wrote `session.stale`. Left null, its resumption would be withheld and the fleet would be
 * left believing it still stale.
 *
 * **Code from before this that is still running after it** -- an old instance or scheduler during a
 * rolling deploy -- can move a session to stale with its event and no stamp. For that window such a
 * session's resumption inside the announcing window is not told, and one that stays quiet is told
 * twice. It closes when the old code stops, and is accepted rather than guarded.
 *
 * A `dateTime` rather than a `timestamp`, for the reason `last_seen_at` is one.
 */
return new class extends Migration
{
    /**
     * Add the column, and mark every stale session announced.
     */
    public function up(): void
    {
        if (! Schema::hasTable('robot_council_agent_sessions')) {
            return;
        }

        if (! Schema::hasColumn('robot_council_agent_sessions', 'stale_announced_at')) {
            Schema::table('robot_council_agent_sessions', function (Blueprint $table): void {
                $table->dateTime('stale_announced_at')->nullable();
            });
        }

        // Whenever this runs, not only after the column was added, for the reason
        // `2026_10_07_000001` gives: a deploy killed between the two statements must not skip it
        DB::table('robot_council_agent_sessions')
            ->where('status', 'stale')
            ->whereNull('stale_announced_at')
            ->update(['stale_announced_at' => DB::raw('last_seen_at')]);
    }

    /**
     * Drop the column.
     */
    public function down(): void
    {
        if (! Schema::hasTable('robot_council_agent_sessions') || ! Schema::hasColumn('robot_council_agent_sessions', 'stale_announced_at')) {
            return;
        }

        Schema::table('robot_council_agent_sessions', function (Blueprint $table): void {
            $table->dropColumn('stale_announced_at');
        });
    }
};
