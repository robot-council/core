<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Lock;
use RuntimeException;
use Throwable;

/**
 * Taking, extending, and giving up the fleet's named leases.
 *
 * **Every state change is one conditional update, and the count of changed rows is the decision.**
 * Acquiring names the states a lock may be taken from -- free, or held by a lease that has lapsed --
 * so two sessions reaching for one free name is settled by the write. Renewing and releasing name
 * the holder and an unexpired lease, so a session that was taken over cannot extend or release what
 * it no longer has.
 *
 * **A fence is what makes an advisory lease safe to act on.** Nothing here can stop a session that
 * lost its lease from carrying on, so the holder carries its fence into whatever it guards, and the
 * guarded thing refuses anything below the highest it has seen. Every acquisition must therefore
 * return a number greater than any previously issued for that name, across releases and takeovers
 * alike.
 *
 * **The fence comes from one sequence shared by every name, not from a counter on the row.** That
 * is what lets a free row be deleted, which is what bounds this table (#63): a per-row `fence + 1`
 * made the row the only record of what its name had issued, so deleting it and taking the name
 * again restarted at 1 and handed a stale holder its authority back. Drawing from the sequence
 * makes per-name monotonicity hold trivially, and a row nobody holds carries nothing.
 *
 * **Lock order: agent sessions, then locks, then the fence sequence, then the feed sentinel**, the
 * same order the rest of the package takes. The release step locks the session row before the lock
 * row; nothing else here touches a session row, and the `holder_id` foreign key's implicit parent
 * lock is taken after the lock row only where no path holds the two the other way round. Only
 * `acquire()` draws a fence, so the sequence has exactly one writer and adds no order to remember.
 */
final class Locks
{
    /**
     * What a lock name may contain. Kept beside the controller's rule rather than only in it,
     * because this service is public and other slices call it directly.
     */
    public const string NAME = '/^[A-Za-z0-9._:\/-]+$/D';

    /**
     * How many times a contended write is retried before it is reported.
     */
    private const int ATTEMPTS = 3;

    /**
     * The one-row table holding the sequence every acquisition draws its fence from.
     */
    public const string FENCE_TABLE = 'robot_council_lock_fence';

    /**
     * The sequence row's key.
     */
    public const int FENCE_ROW = 1;

    /**
     * @param  Credentials  $credentials  The configured bounds.
     * @param  FleetEvents  $events  The change feed.
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly FleetEvents $events
    ) {}

    /**
     * Take a lock, if it is free or its lease has lapsed.
     *
     * @param  AgentSession  $session  The session taking it.
     * @param  string  $name  The name to take.
     * @param  int  $ttl  How long to hold it, in seconds.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @return array{outcome: Outcome, lock: Lock|null} What came of it, and the lock when it was taken.
     */
    public function acquire(AgentSession $session, string $name, int $ttl, bool $asCoordinator): array
    {
        // Checked here as well as in the controller, because this is a public method on an
        // injectable service and the drivers disagree about what an over-long name does: MySQL's
        // `insert ignore` silently truncates it -- so every later lookup by the full name misses,
        // leaving a junk row and a permanent conflict -- while Postgres raises and SQLite stores it.
        if ($name === '' || mb_strlen($name) > Lock::MAX_NAME || preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException('A lock name must be 1 to 191 characters of [A-Za-z0-9._:/-].');
        }

        // Retried, because the contended case is what this method is for. A deadlock or a lock-wait
        // timeout rolls the whole transaction back, so a retry starts from a clean slate.
        return DB::transaction(function () use ($session, $name, $ttl, $asCoordinator): array {
            $now = PresenceClock::now();

            // The session row first, which is the package's lock order and is also what makes the
            // cap below hold. Counting without it is a plain read against rows keyed by `name`,
            // and two acquisitions from one session never touch the same row -- so both would
            // count the same number and both would pass a cap neither was under.
            AgentSession::query()->whereKey($session->getKey())->lockForUpdate()->first();

            // Counted inside the transaction, against live leases only: a session that holds
            // twenty names whose leases have all lapsed is holding nothing
            $held = Lock::query()
                ->where('holder_id', $session->getKey())
                ->where('expires_at', '>', $now)
                ->count();

            if ($held >= $this->credentials->locksPerSession()) {
                return ['outcome' => Outcome::Conflict, 'lock' => null];
            }

            // Only when the row is not there yet. Ignoring the unique conflict is what makes two
            // sessions creating one name at once a no-op rather than an error, but issuing it on
            // every acquisition is what makes the contended case deadlock: InnoDB gives a plain
            // insert that hits a duplicate key a SHARED lock on that record, so two acquisitions
            // of an existing name both take S and then both need X, and one of them is killed.
            // Checking first leaves that window only on a name's first-ever creation.
            //
            // The input was validated before any of this, because SQLite's `insert or ignore`
            // swallows a NOT NULL or CHECK violation too -- and MySQL's `insert ignore` swallows
            // truncation on top of that -- which would otherwise read as somebody else having won.
            if (! Lock::query()->where('name', $name)->exists()) {
                Lock::query()->insertOrIgnore([
                    'name' => $name,
                    'fence' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Locked, so the event's type and `taken_from` describe the row the update is about
            // to change rather than one that moved in between. Same row and the same order as the
            // update, so it adds no ordering edge.
            $before = Lock::query()->where('name', $name)->lockForUpdate()->first();

            // **Drawn only when the locked row says the lock is takeable, and that is not an
            // optimization.** The sequence row is one row for the whole fleet, and an exclusive
            // lock on it is held until this transaction commits -- so drawing unconditionally
            // would put every LOSING attempt on every contended name into one global queue, and
            // one hot lock would serialize acquisitions of every other name in the fleet. Locks
            // are the package's contention primitive; that is the last place to add a chokepoint.
            //
            // The condition mirrors the update's `where` exactly, including SQL's reading of a
            // null `expires_at` as not matching. It cannot go stale between here and there,
            // because `$before` was read with `lockForUpdate()` and this transaction holds that
            // row -- and the update below is still what decides, so a disagreement would cost a
            // burned number rather than a wrong answer.
            // Both `instanceof` checks here are unkillable by construction, and annotated rather
            // than left to be re-discovered: `insertOrIgnore` above guarantees the row exists, so
            // `$before` is never null, and a row with a holder always carries an `expires_at`. The
            // analyzer still requires both, because the types admit what the data does not.
            // @pest-mutate-ignore: InstanceOfToTrue
            $takeable = $before instanceof Lock
                && ($before->holder_id === null
                    || ($before->expires_at instanceof Carbon && $before->expires_at->lessThanOrEqualTo($now)));

            // Never written when it is not takeable: the update matches no row, and `$taken`
            // being 0 returns a conflict below. That also makes the literal unreachable, so no
            // input can tell 0 from 1 or -1 here -- an equivalent mutant, annotated rather than
            // covered by a test that would only be asserting the number nobody reads.
            // @pest-mutate-ignore: IncrementInteger, DecrementInteger
            $fence = $takeable ? $this->drawFence() : 0;

            $taken = Lock::query()
                ->where('name', $name)
                ->where(fn (Builder $free) => $free
                    ->whereNull('holder_id')
                    ->orWhere('expires_at', '<=', $now))
                ->update([
                    // Before `holder_id`, and the order matters: MySQL evaluates a SET clause left
                    // to right, so this has to read the old holder before the next line overwrites
                    // it. Postgres and SQLite evaluate every right-hand side against the pre-update
                    // row, so they agree either way.
                    //
                    // **Falling back to the recorded one when the lock is free (#367).** A force
                    // release frees the row and records the session it displaced; without the
                    // fallback, the next acquire overwrote that with the null `holder_id`, and the
                    // displaced session's renew was told it had never held the lock. A voluntary
                    // release clears the column, so a free row carries a previous holder only when
                    // one was taken from.
                    'previous_holder_id' => DB::raw('coalesce(holder_id, previous_holder_id)'),
                    'holder_id' => $session->getKey(),

                    // From the sequence rather than from this row, which is what lets the row be
                    // deleted: `fence + 1` made the row itself the only record of what the name
                    // had issued, so a deleted name restarted at 1 (#63)
                    'fence' => $fence,
                    'acquired_at' => $now,
                    'expires_at' => $now->copy()->addSeconds($ttl),
                    'updated_at' => $now,
                ]);

            if ($taken !== 1) {
                // Held, and the lease is still running. Whether it is this session's own hold or
                // another's, the answer is the same: it is not free to take.
                return ['outcome' => Outcome::Conflict, 'lock' => null];
            }

            $lock = Lock::query()->where('name', $name)->sole();

            $takenOver = $before instanceof Lock && $before->holder_id !== null;

            $this->events->record(
                $takenOver ? FleetEventType::LockTakenOver : FleetEventType::LockAcquired,
                $session,
                sprintf('%s %s.', $takenOver ? 'Took over' : 'Acquired', $name),
                array_filter([
                    'lock' => $name,
                    'fence' => $lock->fence,
                    'taken_from' => $takenOver ? $before->holder_id : null,
                ], static fn (mixed $value): bool => $value !== null),
                $asCoordinator
            );

            return ['outcome' => Outcome::Applied, 'lock' => $lock];
        }, self::ATTEMPTS);
    }

    /**
     * Extend a lease this session holds.
     *
     * @param  AgentSession  $session  The holder.
     * @param  string  $name  The name to extend.
     * @param  int  $ttl  How much longer to hold it, in seconds, from now.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @return array{outcome: Outcome, lock: Lock|null} What came of it.
     */
    public function renew(AgentSession $session, string $name, int $ttl, bool $asCoordinator): array
    {
        return DB::transaction(function () use ($session, $name, $ttl, $asCoordinator): array {
            $now = PresenceClock::now();
            $until = $now->copy()->addSeconds($ttl);

            // A hold cannot be pushed past the ceiling measured from when it was first acquired,
            // so a session cannot keep one name forever by renewing it. Expressed as a condition
            // on `acquired_at` so it rides in the same write as everything else.
            $acquiredAfter = $until->copy()->subSeconds($this->credentials->lockMaxHoldSeconds());

            $renewed = Lock::query()
                ->where('name', $name)
                ->where('holder_id', $session->getKey())
                ->where('expires_at', '>', $now)
                ->where('acquired_at', '>=', $acquiredAfter)
                ->update(['expires_at' => $until, 'updated_at' => $now]);

            if ($renewed !== 1) {
                // A renewal can change nothing and still be right. MySQL's `update()` reports rows
                // it CHANGED rather than rows it matched -- Laravel sets no `MYSQL_ATTR_FOUND_ROWS`
                // and reads `PDOStatement::rowCount()` -- and these columns are second-precision,
                // so renewing within the same second as the last write is a no-op on a row that
                // already says what was asked for. Reading that as a lost lease would tell a
                // holder that still holds the lock to abandon whatever it was guarding.
                $already = Lock::query()->where('name', $name)->lockForUpdate()->first();

                $satisfied = $already instanceof Lock
                    && $already->holder_id === $session->getKey()
                    && $already->expires_at instanceof Carbon
                    && $already->expires_at->greaterThanOrEqualTo($until);

                if (! $satisfied) {
                    return ['outcome' => $this->diagnose($name, $session, $now), 'lock' => null];
                }

                return ['outcome' => Outcome::Applied, 'lock' => $already];
            }

            $lock = Lock::query()->where('name', $name)->sole();

            // The fence is untouched. A renewal is the same hold continuing, so anything guarding
            // it must not be told the lease is newer than it is.
            $this->events->record(
                FleetEventType::LockRenewed,
                $session,
                sprintf('Renewed %s.', $name),
                ['lock' => $name, 'fence' => $lock->fence],
                $asCoordinator
            );

            return ['outcome' => Outcome::Applied, 'lock' => $lock];
        });
    }

    /**
     * Give up a lease this session holds.
     *
     * @param  AgentSession  $session  The holder.
     * @param  string  $name  The name to give up.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @return Outcome What came of it.
     */
    public function release(AgentSession $session, string $name, bool $asCoordinator): Outcome
    {
        return DB::transaction(function () use ($session, $name, $asCoordinator): Outcome {
            $now = PresenceClock::now();

            $released = Lock::query()
                ->where('name', $name)
                ->where('holder_id', $session->getKey())
                ->where('expires_at', '>', $now)
                // `previous_holder_id` cleared: giving a lock up is not having it taken, and a
                // stale value would be kept by the next acquire's fallback (#367)
                ->update(['holder_id' => null, 'previous_holder_id' => null, 'expires_at' => null, 'updated_at' => $now]);

            if ($released !== 1) {
                return $this->diagnose($name, $session, $now);
            }

            $this->events->record(
                FleetEventType::LockReleased,
                $session,
                sprintf('Released %s.', $name),
                ['lock' => $name],
                $asCoordinator
            );

            return Outcome::Applied;
        });
    }

    /**
     * Take a lock away from whoever holds it.
     *
     * @param  AgentSession  $session  The coordinator doing it.
     * @param  string  $name  The name to free.
     * @return Outcome What came of it.
     */
    public function forceRelease(AgentSession $session, string $name): Outcome
    {
        return DB::transaction(function () use ($session, $name): Outcome {
            $now = PresenceClock::now();

            // Locked, so `taken_from` names the session the write is about to displace rather
            // than one that released voluntarily a moment earlier
            $lock = Lock::query()->where('name', $name)->lockForUpdate()->first();

            if (! $lock instanceof Lock) {
                return Outcome::NotFound;
            }

            $freed = Lock::query()
                ->where('name', $name)
                ->whereNotNull('holder_id')
                // The displaced session is recorded, as a takeover records it, so its renew or
                // release is told it lost the lock rather than that it never held it (#367). Before
                // `holder_id` in the SET list, which MySQL evaluates left to right.
                ->update(['previous_holder_id' => DB::raw('holder_id'), 'holder_id' => null, 'expires_at' => null, 'updated_at' => $now]);

            if ($freed !== 1) {
                // Already free, which is what a second force release finds
                return Outcome::Conflict;
            }

            $this->events->record(
                FleetEventType::LockForceReleased,
                $session,
                sprintf('Force released %s.', $name),
                ['lock' => $name, 'taken_from' => $lock->holder_id],
                true
            );

            return Outcome::Applied;
        });
    }

    /**
     * Give back every lock a session that has gone was still holding.
     *
     * Registered on the presence sweep rather than driven by `Events\SessionGone`, for the reason
     * `SessionReleases` records: a signal can be missed, and a step that runs every sweep cannot.
     *
     * @return int How many locks were released.
     */
    public function releaseOrphaned(): int
    {
        $released = 0;
        $failed = null;

        $orphaned = Lock::query()
            ->whereNotNull('holder_id')
            ->whereIn('holder_id', AgentSession::query()
                ->select('id')
                ->where('status', AgentSessionStatus::Gone->value))
            ->orderBy('id')
            ->limit($this->credentials->maxPerSweep())
            ->get();

        foreach ($orphaned as $lock) {
            try {
                $released += $this->releaseOne($lock) ? 1 : 0;
            } catch (Throwable $failure) {
                // Isolated per lock, for the reason the task step records: the candidate read is
                // ordered by id with a limit, so one lock whose release fails deterministically
                // would be first on every sweep and hold every other orphan behind it
                Log::error(
                    sprintf('robot-council: releasing lock %s from its gone session failed.', $lock->name),
                    ['exception' => $failure]
                );

                $failed ??= $failure;
            }
        }

        if ($failed instanceof Throwable) {
            throw $failed;
        }

        return $released;
    }

    /**
     * Release one lock whose holder has gone, if its holder is still gone.
     *
     * @param  Lock  $lock  The lock to free.
     * @return bool True when this call released it.
     */
    private function releaseOne(Lock $lock): bool
    {
        return DB::transaction(function () use ($lock): bool {
            $holder = AgentSession::query()->whereKey($lock->holder_id)->lockForUpdate()->first();

            if (! $holder instanceof AgentSession || ! $holder->hasGone()) {
                return false;
            }

            $released = Lock::query()
                ->whereKey($lock->getKey())
                ->where('holder_id', $holder->getKey())
                ->update(['holder_id' => null, 'expires_at' => null, 'updated_at' => PresenceClock::now()]);

            if ($released !== 1) {
                return false;
            }

            // Attributed to no session: this is what the service observed, not what the session
            // that lost the lock had to say about it
            $this->events->record(
                FleetEventType::LockReleased,
                null,
                sprintf('Released %s: its session ended.', $lock->name),
                ['lock' => $lock->name, 'released_from' => $holder->getKey()]
            );

            return true;
        });
    }

    /**
     * Take the next number from the fleet's one fence sequence.
     *
     * **One sequence shared by every name, rather than a counter per row.** Per-name monotonicity
     * then holds trivially -- any later acquisition of any name draws a number above everything
     * this sequence has ever issued -- which is what makes a lock row safe to delete. A per-row
     * `fence + 1` made the row the only record of what its name had issued, so deleting it and
     * taking the name again restarted at 1 and re-blessed a stale holder's fence (#63).
     *
     * The update takes the row's exclusive lock and holds it until the transaction commits, so the
     * read that follows cannot see another writer's increment. It is the same one-row-lock shape
     * `FleetEvents::holdTheFeed()` uses, and it is drawn in the same place in the order.
     *
     * **`update()` reporting 0 here means the row is missing, not that nothing changed.** That
     * reading is unsafe in general on MySQL, which counts rows CHANGED -- but `value + 1` never
     * leaves a row saying what it already said, so changed and matched cannot differ.
     *
     * @return int The number to write, greater than every number issued before it.
     *
     * @throws RuntimeException When the sequence row is missing, because handing out a fence that
     *                          is not above the last one is worse than refusing the acquisition.
     */
    private function drawFence(): int
    {
        $advanced = DB::table(self::FENCE_TABLE)
            ->where('id', self::FENCE_ROW)
            ->update(['value' => DB::raw('value + 1')]);

        if ($advanced !== 1) {
            throw new RuntimeException(sprintf(
                'robot-council: the lock fence row %s.%d is missing, so a fence above every previously issued one cannot be drawn. Re-run the package migrations.',
                self::FENCE_TABLE,
                self::FENCE_ROW
            ));
        }

        $drawn = DB::table(self::FENCE_TABLE)->where('id', self::FENCE_ROW)->value('value');

        if (! is_numeric($drawn)) {
            throw new RuntimeException(sprintf(
                'robot-council: the lock fence row %s.%d does not hold a number.',
                self::FENCE_TABLE,
                self::FENCE_ROW
            ));
        }

        // No input can kill the cast on SQLite, where `value()` already returns an int. It earns
        // its place on Postgres, whose driver can hand a bigint back as a string, and the suite
        // has no engine that can tell the two apart -- so it is annotated rather than pretended to
        // be covered.
        // @pest-mutate-ignore: RemoveIntegerCast
        return (int) $drawn;
    }

    /**
     * Work out why a conditional write on a lock matched nothing.
     *
     * @param  string  $name  The name that was written.
     * @param  AgentSession  $session  The session that tried.
     * @param  Carbon  $now  The moment the write judged the lease at.
     * @return Outcome Why nothing happened.
     */
    private function diagnose(string $name, AgentSession $session, Carbon $now): Outcome
    {
        // Read unlocked first. A locking read that matches nothing takes a gap lock on InnoDB, and
        // this runs on an ordinary client mistake -- a renew or release of a name that was never
        // created -- with the gap chosen by whatever the caller sent.
        if (! Lock::query()->where('name', $name)->exists()) {
            return Outcome::NotFound;
        }

        $lock = Lock::query()->where('name', $name)->lockForUpdate()->first();

        if (! $lock instanceof Lock || $lock->acquired_at === null) {
            return Outcome::NotFound;
        }

        // A session this lock was taken from is not a stranger to it. 409 rather than 403,
        // because the useful thing to tell it is that what it held is gone -- which is what sends
        // it to acquire the name again rather than give up on it. Recorded by the takeover's own
        // write, so this costs no extra read and cannot disagree with what happened.
        if ($lock->previous_holder_id === $session->getKey()) {
            return Outcome::Conflict;
        }

        // Somebody else holds a running lease, and this session never held it: it may not touch it
        if ($lock->isHeldAt($now) && $lock->holder_id !== $session->getKey()) {
            return Outcome::Forbidden;
        }

        // Its own lease lapsed, or it was taken over and handed back, or the hold has run past its
        // ceiling. All of them are the same statement: what this session asked for is not available
        // any more, and the lock is not somebody else's to be refused from.
        return Outcome::Conflict;
    }

    /**
     * How many lock rows one delete statement removes.
     *
     * Tuning rather than behavior, for the reason `FleetEvents::PRUNE_BATCH` records.
     */
    public const int PRUNE_BATCH = 500;

    /**
     * How many batches one run may take.
     */
    public const int PRUNE_BATCHES = 50;

    /**
     * Delete locks that nobody holds and that nothing has touched since a cutoff, in batches.
     *
     * **This is only safe because the fence is a shared sequence.** While `fence` was a per-row
     * counter, the row was the only record of what its name had issued, so deleting it let the
     * name restart at 1 and re-bless the fence a stale holder was still carrying. `drawFence()`
     * draws above everything ever issued, so a free row carries nothing anybody needs (#63).
     *
     * **A lock anybody holds is never deleted, whatever its age.** "Held" is the same condition
     * `acquire()` refuses to take a lock from: a holder, and a lease that has not lapsed. A row
     * whose lease has lapsed is free -- the next acquisition would take it without asking -- so
     * age is the only remaining question for it.
     *
     * `updated_at` rather than `expires_at` decides that age, because it is the one column every
     * path writes: a release sets it with no expiry change, and using `expires_at` would keep a
     * row released this morning for as long as its original lease had left to run.
     *
     * @param  Carbon  $before  Delete locks untouched since this.
     * @param  int  $batch  How many rows one statement removes.
     * @param  int  $maxBatches  A ceiling, so a run is bounded even on a table nobody has pruned.
     * @return int How many locks were deleted.
     */
    public function prune(Carbon $before, int $batch = self::PRUNE_BATCH, int $maxBatches = self::PRUNE_BATCHES): int
    {
        $at = PresenceClock::now();

        $deleted = 0;

        for ($i = 0; $i < max(1, $maxBatches); $i++) {
            $ids = Lock::query()
                ->where(fn (Builder $free) => $free
                    ->whereNull('holder_id')
                    ->orWhere('expires_at', '<=', $at))
                ->where('updated_at', '<', $before)
                ->orderBy('id')
                ->limit(max(1, $batch))
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            Lock::query()->whereIn('id', $ids)->delete();

            // Counted from the ids selected, for the reason `FleetEvents::prune()` records.
            $deleted += \count($ids);
        }

        return $deleted;
    }
}
