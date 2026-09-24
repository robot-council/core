<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table recording which sessions an event was addressed to (#315).
 *
 * **A narration can name its readers, and this is what the feed's filter reads to honor that.** #29
 * restricts narration to its author's own developer's sessions, which leaves no way for a seat to
 * answer another developer's coordinator, or for a CI session to tell another developer's build
 * session to look at a hand-back. An addressed narration also reaches the sessions it names.
 *
 * **A table rather than a list inside `meta`**, because the filter has to ask "is this reader one of
 * them" inside the feed's single statement, and a JSON containment test is a different function on
 * each engine, with different answers for `1` against `"1"`. A key lookup means the same thing on
 * all three. `meta.to` still records the list, for a reader to see; this table is what decides.
 *
 * **Each row carries the addressee's developer as well as its session id, and the filter matches
 * both.** Session ids are reused -- CLAUDE.md records a truncate restarting them on SQLite and
 * Postgres -- and there is no foreign key on `agent_session_id` for the reason #50 gives on the
 * events table, so nothing removes a row here when its session goes. Matching on the id alone would
 * serve a dead session's addressed narration to whichever developer's session takes its id next.
 * The key is recorded at write time for the same reason `robot_council_events.user_id` is.
 */
return new class extends Migration
{
    /**
     * The width of a host user key, matching every other column that holds one.
     */
    private const int KEY_LENGTH = 64;

    /**
     * Create the table.
     */
    public function up(): void
    {
        // Guarded, because three populations run this file: a host that installed before it, one
        // that installs after, and one rolling back and forward again.
        if (Schema::hasTable('robot_council_event_addressees')) {
            return;
        }

        Schema::create('robot_council_event_addressees', function (Blueprint $table): void {
            // **A foreign key, unlike the events table's own `agent_session_id`.** #50's deadlock
            // was a child insert taking a shared lock on a SESSION row after the feed sentinel;
            // this parent is the event the same transaction has just inserted, which nothing else
            // can hold. No cascade: `FleetEvents::prune()` deletes these first, so a prune that
            // forgot to would fail on the engines that enforce the constraint rather than leave
            // orphans behind.
            //
            // **Event ids can be reused too, and this constraint is what stops a reused one
            // inheriting another event's addressees.** Truncating the events table restarts its ids
            // on SQLite (`delete from sqlite_sequence`), and a leftover row here would then address
            // the next event to take the id -- an unaddressed narration included -- to a session
            // of another developer. Postgres and MySQL refuse that truncate while a row here points
            // at it; SQLite, which Testbench runs with foreign keys off, does not. So a host that
            // truncates the feed truncates this table first.
            $table->foreignId('event_id')->constrained('robot_council_events');

            // Not a foreign key, for #50's reason, and so nothing nulls it when a session row goes
            $table->unsignedBigInteger('agent_session_id');

            $key = $table->string('user_id', self::KEY_LENGTH);

            // #54: a host user key compares byte-exactly, and MySQL's default collation does not.
            // Set at creation rather than by a later `change()`, since this table is new.
            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $key->collation('utf8mb4_bin');
            }

            // The filter's lookup is exactly this key, one probe per event in the read's window
            $table->primary(['event_id', 'agent_session_id']);
        });
    }

    /**
     * Drop the table.
     *
     * A rollback loses who each addressed narration was sent to, so those events fall back to #29's
     * reach, which is where they would have been before this existed. `meta.to` still says who
     * they named.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_event_addressees');
    }
};
