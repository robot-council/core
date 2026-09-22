<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a session somewhere to keep how far it has read.
 *
 * #61 handed a starting cursor back in the response that created the session and stored nothing,
 * so the value was unrecoverable: a bridge that restarted while its token was still valid had to
 * choose between replaying the whole feed and guessing. Both read surfaces also defaulted a
 * missing `after` to zero, which is the walk #61 exists to prevent (#86).
 *
 * **What this column holds is the position the READER has acknowledged**, not the position the
 * server last sent. `Support\FeedCursors` advances it to the `after` a read supplies, because a
 * request for everything after N is the reader's own statement that it processed through N. A page
 * that is returned but never arrives is therefore never acknowledged and comes again -- delivery
 * is at-least-once, and the rejected alternative would have skipped it permanently. The decision
 * and its alternatives are on #86.
 *
 * **A new migration rather than an edit to the create migration**, because the era of editing those
 * is over: `robot-council/robot-council` has already run them, so an edit would leave a deployed
 * database and a fresh install with different schemas and nothing to say so (#94, #100).
 *
 * Guarded on what the schema reports rather than on an assumption, for the same reason: the three
 * populations -- installed before this, installed after it, and rolled back -- all run this file.
 */
return new class extends Migration
{
    /**
     * The table this adds to.
     */
    private const string TABLE = 'robot_council_agent_sessions';

    /**
     * The column added.
     */
    private const string COLUMN = 'feed_cursor';

    /**
     * Add the cursor column.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            // **Not nullable, defaulting to zero, and sessions that already exist keep that zero.**
            // Zero means the whole history, which is what those sessions get today, so nothing
            // regresses -- and they heal on their first read, because a bridge still holding its
            // cursor passes it and that acknowledgement writes the row. A backfill would have to
            // guess a position from event history for bridges that have already lost theirs, which
            // is the state this column exists to make recoverable rather than to reconstruct.
            //
            // `unsignedBigInteger` to match `robot_council_events.id`, which is what it holds. No
            // foreign key: the feed is pruned (#47) and a cursor must survive the event it names
            // being deleted, exactly as #50 dropped the events table's own session reference.
            $table->unsignedBigInteger(self::COLUMN)->default(0);
        });
    }

    /**
     * Drop it.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
