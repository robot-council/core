<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RuntimeException;

/**
 * Writes the fleet's change feed.
 *
 * Every coordination state change is recorded through here, in the same transaction as the change
 * itself, so a change that rolls back leaves no event behind and an event never describes something
 * that did not happen.
 *
 * **Events have to commit in ID order.** An agent without a push connection reads this feed by
 * paging an ID cursor, so an insert that takes its ID early and commits late would be skipped
 * forever by a reader that had already passed it -- permanently, because paging is `id > cursor`.
 *
 * Both Postgres and MySQL make that gap real. Postgres draws sequence values outside the
 * transaction, and InnoDB hands out `AUTO_INCREMENT` values at insert time, so under
 * `innodb_autoinc_lock_mode=2` -- the MySQL 8 default -- two transactions can take 5 and 6 and
 * commit 6 first. SQLite serializes writers and cannot. Rather than one branch per driver, every
 * writer takes a row lock on a single sentinel row, which is transaction-scoped everywhere,
 * releases on commit and on rollback with no hook to forget, and collides with nothing a host owns.
 */
final class FleetEvents
{
    /**
     * The table holding the one row every writer locks before inserting.
     */
    public const string LOCK_TABLE = 'robot_council_feed_lock';

    /**
     * The row every writer locks. There is only ever one.
     */
    public const int LOCK_ROW = 1;

    /**
     * @param  SlackMirror  $slack  Whether, and where, to mirror an event to Slack.
     */
    public function __construct(private readonly SlackMirror $slack) {}

    /**
     * Record one event, and queue its Slack mirror once the surrounding work commits.
     *
     * @param  FleetEventType  $type  What happened.
     * @param  AgentSession|null  $session  The session responsible, when one was.
     * @param  string|null  $body  What a human reads.
     * @param  array<string, mixed>  $meta  Structured detail.
     * @param  bool  $withCoordinator  Whether the session held `coordinator:direct` as it posted.
     * @param  string|null  $actor  The developer responsible, for a change no session made.
     * @return FleetEvent The recorded event.
     *
     * @throws InvalidArgumentException When the body is longer than `FleetEvent::MAX_BODY`.
     * @throws RuntimeException When the actor's key is not one the package can store.
     */
    public function record(
        FleetEventType $type,
        ?AgentSession $session = null,
        ?string $body = null,
        array $meta = [],
        bool $withCoordinator = false,
        ?string $actor = null
    ): FleetEvent {
        // Bounded here rather than where the branch below reads it, so an unusable key is refused
        // whether or not a session was also passed. `user_id` is `varchar(64)`, which Postgres
        // refuses past its length and SQLite stores whole -- one call, two outcomes.
        $actor = $actor === null ? null : HostKey::from($actor);

        // Bounded here as well as at the four call sites that validate it. `record()` is a public
        // method a host may call directly, and `body` is a `text` column -- 65,535 bytes on MySQL
        // and unbounded on Postgres and SQLite, so an over-long body is an error on one engine and
        // a silently enormous row on the other two. Every in-package caller is bounded by
        // construction (task ids, lock names at 191, a harness and label at 96 together), so this
        // is exactly the direct-call path the bound exists for.
        if ($body !== null && mb_strlen($body) > FleetEvent::MAX_BODY) {
            throw new InvalidArgumentException(sprintf(
                'An event body is limited to %d characters, and this one is %d.',
                FleetEvent::MAX_BODY,
                mb_strlen($body)
            ));
        }

        // A savepoint when a caller already has a transaction open, which is the ordinary case:
        // the event and the state change it records commit or roll back together. The advisory
        // lock below is scoped to the outermost transaction either way.
        return DB::transaction(function () use ($type, $session, $body, $meta, $withCoordinator, $actor): FleetEvent {
            $this->holdTheFeed();

            $event = FleetEvent::query()->create([
                'agent_session_id' => $session?->getKey(),

                // Read off the session now, not looked up from the id later. There is no foreign
                // key on `agent_session_id` (#50), so a session row can go and its id can be taken
                // by a different developer's session -- and #29's visibility rule must not follow
                // it there.
                //
                // An admin's change to authorization has no session at all, so it names the
                // developer who made it instead. The column means the same thing either way --
                // the developer this event belongs to -- which is what keeps #29's rule intact for
                // a restricted type recorded this way later.
                //
                // Written as a conditional rather than `$session?->user_id ?? $actor`, which
                // Larastan refuses at bleeding edge: `??` suppresses the null-property read on its
                // own, so the nullsafe operator there is dead. The explicit form also says which
                // of the two branches is being taken.
                'user_id' => $session instanceof AgentSession ? $session->user_id : $actor,
                'type' => $type,
                'body' => $body,
                'meta' => $meta === [] ? null : $meta,

                // Recorded now, never read back off the session: revoking the coordinator's
                // ability later must not hide what was said while it was held
                'posted_with_coordinator' => $withCoordinator,
            ]);

            $this->slack->mirror($event);

            return $event;
        });
    }

    /**
     * Take the feed's writer lock for the rest of the transaction.
     *
     * Taken **before** the insert, which is the whole point: an ID drawn before the lock is an ID
     * that can commit out of order, and a drawn ID is not given back by a rollback.
     *
     * SQLite compiles `for update` to nothing, which is correct rather than a gap: it takes a
     * write lock for the whole transaction on its own.
     *
     * **A missing sentinel row is fatal rather than ignored.** `first()` on a row that is not there
     * returns null and locks nothing, and the insert below would then go ahead unordered -- so a
     * host that truncated this table, or restored it without its one row, would get a feed whose
     * ordering had silently stopped holding. That was one skippable event before a session's
     * starting cursor was derived from this lock; now it is every fresh session's watermark.
     *
     * @throws RuntimeException When the sentinel row is missing, because the alternative is writing
     *                          a feed whose order nothing guarantees.
     */
    private function holdTheFeed(): void
    {
        $held = DB::table(self::LOCK_TABLE)->where('id', self::LOCK_ROW)->lockForUpdate()->first();

        if ($held === null) {
            throw new RuntimeException(sprintf(
                'robot-council: the feed lock row %s.%d is missing, so event ordering cannot be guaranteed. Re-run the package migrations.',
                self::LOCK_TABLE,
                self::LOCK_ROW
            ));
        }
    }
}
