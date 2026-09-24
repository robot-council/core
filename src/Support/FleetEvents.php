<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
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
     * The table recording which sessions an event was addressed to (#315).
     */
    public const string ADDRESSEE_TABLE = 'robot_council_event_addressees';

    /**
     * The row every writer locks. There is only ever one.
     */
    public const int LOCK_ROW = 1;

    /**
     * How many events one delete statement removes.
     *
     * A judgement about how long a single statement should hold rows in a table every writer in
     * the fleet inserts into, not a behaviour: the floor at one is what `prune()` enforces and
     * what has a test, and no input can tell this figure from one either side of it.
     */
    public const int PRUNE_BATCH = 1000;

    /**
     * How many batches one run of the prune may take.
     *
     * A ceiling so the first run after an upgrade is bounded rather than deleting a year in one
     * go. What it does not delete, the next run does. Tuning for the same reason as the batch.
     */
    public const int PRUNE_BATCHES = 50;

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
     * @param  string|null  $actor  The signed-in developer who made this happen, when one did.
     * @param  string|null  $subject  The developer the event is about, when no session says who.
     * @param  list<AgentSession>  $addressees  The sessions it is addressed to, which read it whatever
     *                                          developer they belong to.
     * @return FleetEvent The recorded event.
     *
     * @throws InvalidArgumentException When the body is longer than `FleetEvent::MAX_BODY`.
     * @throws RuntimeException When either key is not one the package can store.
     */
    public function record(
        FleetEventType $type,
        ?AgentSession $session = null,
        ?string $body = null,
        array $meta = [],
        bool $withCoordinator = false,
        ?string $actor = null,
        ?string $subject = null,
        array $addressees = []
    ): FleetEvent {
        // Bounded here rather than where the branch below reads it, so an unusable key is refused
        // whether or not a session was also passed. `user_id` is `varchar(64)`, which Postgres
        // refuses past its length and SQLite stores whole -- one call, two outcomes.
        $actor = $actor === null ? null : HostKey::from($actor);
        $subject = $subject === null ? null : HostKey::from($subject);

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
        return DB::transaction(function () use ($type, $session, $body, $meta, $withCoordinator, $actor, $subject, $addressees): FleetEvent {
            $this->holdTheFeed();

            $event = FleetEvent::query()->create([
                'agent_session_id' => $session?->getKey(),

                // Read off the session now, not looked up from the id later. There is no foreign
                // key on `agent_session_id` (#50), so a session row can go and its id can be taken
                // by a different developer's session -- and #29's visibility rule must not follow
                // it there.
                //
                // An admin's change to authorization has no session at all, so the caller names
                // the owner directly through `$subject`.
                //
                // **One meaning: the developer this event is ABOUT.** It used to hold the acting
                // admin for an administrative event, which is a different person by construction --
                // an admin administers other developers' installations. #29 reads this column for a
                // restricted type, so marking any `installation.*` type restricted would have
                // served the event to the admin's sessions and hidden it from the owner's, which is
                // backwards (#115). Who acted is `actor_user_id` now, and nothing reads that for
                // visibility.
                //
                // Written as a conditional rather than `$session?->user_id ?? $subject`, which
                // Larastan refuses at bleeding edge: `??` suppresses the null-property read on its
                // own, so the nullsafe operator there is dead. The explicit form also says which
                // of the two branches is being taken.
                'user_id' => $session instanceof AgentSession ? $session->user_id : $subject,

                // Who did it, when a signed-in developer did. Null for everything a process or a
                // sweep records, and for a console command, which has nobody signed in.
                'actor_user_id' => $actor,
                'type' => $type,
                'body' => $body,
                'meta' => $meta === [] ? null : $meta,

                // Recorded now, never read back off the session: revoking the coordinator's
                // ability later must not hide what was said while it was held
                'posted_with_coordinator' => $withCoordinator,
            ]);

            // In the same transaction as the event, so an addressed narration is never visible
            // without its addressees or the other way round. Each row carries the addressee's
            // developer beside its session id, read off the session now, because session ids are
            // reused and the feed's filter matches both.
            if ($addressees !== []) {
                DB::table(self::ADDRESSEE_TABLE)->insert(array_map(
                    static fn (AgentSession $addressee): array => [
                        'event_id' => $event->id,
                        'agent_session_id' => $addressee->id,
                        'user_id' => HostKey::from($addressee->user_id),
                    ],
                    $addressees
                ));
            }

            $this->slack->mirror($event);

            return $event;
        });
    }

    /**
     * Delete events older than a cutoff, in batches.
     *
     * **Batched because one statement would hold the feed against its writers.** Every writer takes
     * the sentinel row before inserting, and a delete large enough to matter is a delete long
     * enough to keep a transaction open across the whole table -- so a month's rows would stop
     * every agent's narration for as long as it ran. Each batch is its own statement and the loop
     * yields between them.
     *
     * **It takes no sentinel lock.** The lock exists so that ids commit in order, which is a
     * property of inserts; a delete draws no id and the readers page `id > cursor`, so nothing a
     * delete does can reorder anything. Taking it here would serialise the prune against every
     * writer in the fleet for no gain, which is the opposite of the batching above.
     *
     * A reader holding a cursor inside the deleted range is unaffected, because a cursor is a
     * number rather than a row: `id > cursor` still answers for an id that no longer exists.
     *
     * @param  Carbon  $before  Delete events created strictly before this.
     * @param  int  $batch  How many rows one statement removes.
     * @param  int  $maxBatches  A ceiling, so a run is bounded even on a table nobody has pruned.
     * @return int How many events were deleted.
     */
    public function prune(Carbon $before, int $batch = self::PRUNE_BATCH, int $maxBatches = self::PRUNE_BATCHES): int
    {
        $deleted = 0;

        for ($i = 0; $i < max(1, $maxBatches); $i++) {
            // `limit()` on a delete rather than a subquery: both Postgres and SQLite refuse
            // `delete ... limit`, so the ids are selected first and deleted by key.
            $ids = FleetEvent::query()
                ->where('created_at', '<', $before)
                ->orderBy('id')
                ->limit(max(1, $batch))
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            // The addressees first, because they hold a foreign key to the event
            DB::table(self::ADDRESSEE_TABLE)->whereIn('event_id', $ids)->delete();

            FleetEvent::query()->whereIn('id', $ids)->delete();

            // Counted from the ids selected rather than from what `delete()` returned, which
            // arrives untyped and would need a narrowing branch no input could reach. The two
            // differ only if something else deleted the same rows in between, and nothing else
            // deletes from this table at all -- which is the defect this method exists to fix.
            $deleted += \count($ids);
        }

        return $deleted;
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
