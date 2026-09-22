<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\AgentSession;

/**
 * Where a session has read to in the change feed.
 *
 * **The position is the reader's, acknowledged by the reader.** A request for everything after N is
 * the reader's own statement that it processed through N, so that is what advances the column --
 * not the end of the page the server last sent. A page that is returned and never arrives is
 * therefore never acknowledged, and comes again on the next read. Delivery is at-least-once, and
 * the rejected alternative would have skipped it permanently, which on a feed carrying directives
 * is the worse of the two failures (#86).
 *
 * **Every write is conditional on the ROW, and monotonic.** The instance a caller holds was
 * hydrated by the guard several queries before any of this runs, so deciding from it would let a
 * concurrent read's acknowledgement be overwritten by an older one. `where feed_cursor < ?` makes
 * the row decide: a client re-reading older history cannot drag its own position backwards, two
 * readers on one session cannot fight, and the update is idempotent.
 *
 * Nothing here takes a lock beyond the single-statement update's own. The session row is second in
 * the package's lock order and the feed sentinel is third, so a cursor write must never be made
 * while the sentinel is held -- the read path holds nothing, and `AgentSessions::start()` writes
 * the seed through a row it already inserted in that transaction, before the sentinel was taken.
 */
final class FeedCursors
{
    /**
     * Where a session reads from when it does not say.
     *
     * @param  AgentSession  $session  The reading session.
     * @return int The last acknowledged event id, or zero for the whole history.
     */
    public function of(AgentSession $session): int
    {
        $stored = DB::table($session->getTable())
            ->where('id', $session->getKey())
            ->value('feed_cursor');

        // Read back from the row rather than taken off the instance, which the guard hydrated
        // before this request did anything. A session deleted mid-request reads as the whole
        // history, which is what a reader with no position has.
        return is_numeric($stored) ? (int) $stored : 0;
    }

    /**
     * Record that a session has processed everything up to a position.
     *
     * @param  AgentSession  $session  The reading session.
     * @param  int  $cursor  The position the reader acknowledged.
     * @return bool Whether this moved the session forward.
     */
    public function acknowledge(AgentSession $session, int $cursor): bool
    {
        if ($cursor <= 0) {
            return false;
        }

        $changed = DB::table($session->getTable())
            ->where('id', $session->getKey())
            ->where('feed_cursor', '<', $cursor)
            ->update(['feed_cursor' => $cursor]);

        // **Not compared with 1.** `Builder::update()` returns rows CHANGED on MySQL rather than
        // rows matched, so an acknowledgement of a position the row already holds reports 0 there
        // and 1 elsewhere -- and here the `where` already excludes that case, so a zero means the
        // row had moved on, which is a no-op rather than a lost race.
        return $changed > 0;
    }

    /**
     * Seed a session's position when it is created.
     *
     * Written unconditionally, because the row was inserted by the same transaction and no other
     * writer can have reached it: this is the one moment the column has no earlier value to defend.
     *
     * @param  AgentSession  $session  The session being started.
     * @param  int  $cursor  The feed's position at the moment it started.
     */
    public function seed(AgentSession $session, int $cursor): void
    {
        DB::table($session->getTable())
            ->where('id', $session->getKey())
            ->update(['feed_cursor' => $cursor]);

        // Kept in step on the instance the caller goes on to use, so a response built from it
        // states the position the row holds rather than the default it was created with.
        $session->setAttribute('feed_cursor', $cursor);
    }
}
