<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;

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
     */
    public function acknowledge(AgentSession $session, int $cursor): void
    {
        if ($cursor <= 0) {
            return;
        }

        DB::table($session->getTable())
            ->where('id', $session->getKey())
            ->where('feed_cursor', '<', $cursor)

            // **Bounded by the feed itself, in the same statement.** Nothing lowers this column,
            // so an acknowledgement past the end of the feed is not a bad page, it is permanent:
            // one request carrying a timestamp where an event id belongs would blind the session
            // to everything the fleet says from then on, recoverable only by re-enrolling. A
            // reader cannot have processed an event that does not exist, so a position beyond the
            // last one is refused rather than stored.
            //
            // The bound lives here rather than in a validation rule because this is a public
            // method on a store a host can resolve and call, and a rule in a controller protects
            // the endpoint and nothing else. Expressed as "an event at or after this exists"
            // rather than as a `max(id)` comparison: it says the same thing, needs no raw SQL,
            // and is a primary-key range the engine can stop at the first row of. It is part of
            // the same statement, so it replaces no round trip and adds none.
            ->whereExists(fn (QueryBuilder $events): QueryBuilder => $events
                ->from(new FleetEvent()->getTable())
                ->where('id', '>=', $cursor))
            ->update(['feed_cursor' => $cursor]);

        // Nothing is returned and nothing checks a row count. `Builder::update()` reports rows
        // CHANGED on MySQL rather than rows matched, and every reason this can write zero rows --
        // the row already at or past this position, a cursor beyond the feed, a session deleted
        // mid-request -- is a no-op rather than a lost race.
    }

    /**
     * Seed a session's position when it is created.
     *
     * **Monotonic like every other write here, although the caller is the creating transaction.**
     * The row it writes is one nothing else can have reached yet, so the guard buys nothing at the
     * only call site -- and this is a public method on a store a host can resolve and call, where
     * an unconditional write would rewind a live session to the start of the feed. A store that is
     * safe only when called correctly is not safe.
     *
     * @param  AgentSession  $session  The session being started.
     * @param  int  $cursor  The feed's position at the moment it started.
     */
    public function seed(AgentSession $session, int $cursor): void
    {
        DB::table($session->getTable())
            ->where('id', $session->getKey())
            ->where('feed_cursor', '<', $cursor)
            ->update(['feed_cursor' => $cursor]);

        // Kept in step on the instance the caller goes on to use, so a response built from it
        // states the position the row holds rather than the default it was created with.
        $session->setAttribute('feed_cursor', $cursor);
    }
}
