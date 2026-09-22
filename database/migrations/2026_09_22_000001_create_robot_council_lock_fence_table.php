<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row holding the fence every lock acquisition draws from.
 *
 * **The fence used to be a per-row counter, which is what made the locks table un-prunable.** A
 * fence has to be greater than any fence previously issued for that name, and `fence + 1` on the
 * row meant the row *was* the record of what had been issued -- so deleting it and taking the name
 * again restarted at 1, and a stale holder's fence would be accepted again by whatever it guarded.
 * The table therefore grew forever, one permanent row per distinct name (#63).
 *
 * A single sequence shared by every name makes per-name monotonicity hold trivially: any later
 * acquisition of any name draws a number above everything the sequence has ever issued, so a row
 * that nobody holds carries no information and can be deleted.
 *
 * **The locks migration rejected "a second table" and this is not the table it rejected.** That
 * note is about a second row *per name*, never deleted -- the same growth with an extra join. This
 * is one row for the whole installation, forever.
 *
 * **It is a new global serialization point, and it costs nothing measurable**, because every
 * acquisition already serializes on `robot_council_feed_lock` to record its event. The order is
 * the lock's own row, then this row, then the feed sentinel; `Support\Locks` is the only writer.
 */
return new class extends Migration
{
    /**
     * Create the fence sequence and seed it above every fence already issued.
     */
    public function up(): void
    {
        if (Schema::hasTable('robot_council_lock_fence')) {
            return;
        }

        Schema::create('robot_council_lock_fence', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('value')->default(0);
        });

        // **Seeded from the highest fence any existing name has already issued, not from zero.**
        // An installation that has been running holds per-name counters this sequence is taking
        // over from, and starting below the largest of them would hand out a number some name has
        // already used -- which is the exact failure the fence exists to prevent, introduced by
        // the change meant to fix it. Guarded on the table existing, because a fresh install runs
        // this file too and has nothing to read.
        $highest = Schema::hasTable('robot_council_locks')
            ? DB::table('robot_council_locks')->max('fence')
            : null;

        DB::table('robot_council_lock_fence')->insert([
            'id' => 1,
            'value' => is_numeric($highest) ? (int) $highest : 0,
        ]);
    }

    /**
     * Drop the sequence.
     *
     * Rolling back leaves the lock rows' `fence` values where they are, which is the safe
     * direction: the per-row `fence + 1` this reverts to reads that column, so every name resumes
     * from the number it last carried rather than from zero.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_lock_fence');
    }
};
