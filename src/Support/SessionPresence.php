<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Events\SessionGone;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\TaskStatus;

/**
 * Which agent sessions are alive: what contact updates, what the sweep changes, and how a session
 * ends.
 *
 * **No harness is asked to keep a process running.** Presence is inferred from contact, which every
 * authenticated agent request is, so a harness that only ever reads the feed is as visible as one
 * that narrates. A process with nothing to send posts a heartbeat instead.
 *
 * **Every write is conditional on the row, never on the instance, and the count of changed rows is
 * the decision.** Each names the statuses it may move from and, where a clock decides it, the
 * contact time it read. So a request that lands between the sweep's read and its write keeps its
 * session active, a second end changes nothing, and two sweeps running at once produce one
 * transition and one event between them. Nothing here takes a lock and nothing needs one: the
 * database's own row-level write is the serialization point, and losing a race is always the
 * outcome of doing nothing.
 *
 * Reading the instance instead would be wrong in a way that lasts. Sanctum's guard materializes the
 * session several queries before this runs, so a sweep committing `stale` in between would be
 * overwritten by a contact write that left the status alone -- and a `stale` row with a fresh
 * contact time is unreachable by both passes, because the stale pass accepts only an active row and
 * the gone pass only an old contact time that every later request pushes further away. A live
 * process would report `stale` to the whole fleet until it died.
 *
 * **Lock order is `robot_council_installations`, then `robot_council_agent_sessions`, then the feed
 * sentinel, then `personal_access_tokens`**, and every path that touches more than one of them takes
 * them in that order. Two paths taking two rows in opposite orders is a deadlock on any engine that
 * locks rows, which is every engine but SQLite -- and SQLite serializes writers, so no test here can
 * show it.
 */
final class SessionPresence
{
    /**
     * The reason recorded against a session the process itself ended.
     */
    public const string ENDED = 'ended';

    /**
     * The reason recorded against a session an admin revoked.
     */
    public const string REVOKED = 'revoked';

    /**
     * The reason recorded against a session the sweep found silent.
     */
    public const string TIMEOUT = 'timeout';

    /**
     * How many sessions one read of the sweep's candidates holds.
     */
    private const int CHUNK = 100;

    /**
     * @param  Credentials  $credentials  The configured thresholds.
     * @param  FleetEvents  $events  The change feed.
     * @param  SessionReleases  $releases  What runs at the end of every sweep.
     * @param  Dispatcher  $dispatcher  The application's event dispatcher.
     * @param  LaneConditions  $conditions  Raises the coordinator's lane conditions (#319).
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly FleetEvents $events,
        private readonly SessionReleases $releases,
        private readonly Dispatcher $dispatcher,
        private readonly LaneConditions $conditions
    ) {}

    /**
     * Record that a session's process is alive, and bring it back if it had gone quiet.
     *
     * Called for every authenticated agent request, so the ordinary case is one statement: an
     * update that matches an active row and stops there. Everything else is a lost race or a
     * resumption, and costs a second.
     *
     * A session that has gone is left exactly as it is. Both writes below refuse it by naming the
     * statuses they accept, so that is a property of the statements rather than of a guard that
     * read a stale instance.
     *
     * @param  AgentSession  $session  The session the request authenticated as.
     */
    public function sighted(AgentSession $session): void
    {
        if ($this->touch($session)) {
            return;
        }

        $this->resume($session);
    }

    /**
     * Mark the sessions that have stopped answering, then run the release steps.
     *
     * The gone pass runs first, so a session that has been silent past both thresholds moves
     * straight to gone and writes one event rather than two in the same run.
     *
     * @return array{stale: int, gone: int} How many sessions each pass changed.
     */
    public function sweep(): array
    {
        $gone = $this->pass($this->credentials->goneCutoff(), AgentSessionStatus::Gone);
        $stale = $this->pass($this->credentials->staleCutoff(), AgentSessionStatus::Stale);

        // After the passes, and on every sweep whether or not either of them changed anything:
        // the steps exist to catch what a missed `SessionGone` left holding
        $this->releases->run();

        return ['stale' => $stale, 'gone' => $gone];
    }

    /**
     * End a session because its process said so.
     *
     * @param  AgentSession  $session  The session to end.
     * @return int How many tokens were deleted, and none when it had already gone.
     */
    public function end(AgentSession $session): int
    {
        return $this->goesNow($session, self::ENDED, null) ?? 0;
    }

    /**
     * End a session because an admin revoked it.
     *
     * @param  AgentSession  $session  The session to revoke.
     * @param  string|null  $actor  The signed-in developer doing it, when one is.
     * @return int How many tokens were deleted, and none when it had already gone.
     */
    public function revoke(AgentSession $session, ?string $actor = null): int
    {
        return $this->goesNow($session, self::REVOKED, null, $actor) ?? 0;
    }

    /**
     * Record contact against a session that is active, and say whether one was there.
     *
     * @param  AgentSession  $session  The session that made contact.
     * @return bool True when an active row took the contact.
     */
    private function touch(AgentSession $session): bool
    {
        return $this->conditionally(
            $session,
            [AgentSessionStatus::Active],
            ['last_seen_at' => PresenceClock::now()],
            null
        ) === 1;
    }

    /**
     * Move every session silent since a cutoff into one state.
     *
     * Read in chunks and written one at a time, because the write has to be conditional on what the
     * read saw: a single `update ... where last_seen_at <= ?` would be atomic and would still be
     * wrong, because it could not say which sessions it changed, and `SessionGone` has to be
     * dispatched once per session rather than once per sweep.
     *
     * Bounded per run. A fleet that all went silent at once -- an outage, a laptop lid, a network
     * partition -- would otherwise be one unbounded batch holding the feed's writer lock in turn for
     * every session in it, with every agent's narration queued behind it. What is left over is not
     * lost: it is a minute older when the next sweep reads it.
     *
     * @param  Carbon  $cutoff  The contact time at or before which a session qualifies.
     * @param  AgentSessionStatus  $into  The state to move qualifying sessions into.
     * @return int How many sessions changed.
     */
    private function pass(Carbon $cutoff, AgentSessionStatus $into): int
    {
        $changed = 0;
        $examined = 0;
        $ceiling = $this->credentials->maxPerSweep();

        $this->candidates($cutoff, $into)->chunkById(
            min(self::CHUNK, $ceiling),
            function (Collection $sessions) use ($cutoff, $into, $ceiling, &$changed, &$examined): ?bool {
                foreach ($sessions as $session) {
                    $changed += $this->move($session, $cutoff, $into) ? 1 : 0;
                    $examined++;

                    if ($examined >= $ceiling) {
                        return false;
                    }
                }

                return null;
            }
        );

        return $changed;
    }

    /**
     * The sessions a pass should look at.
     *
     * A session moves to stale only from active, and to gone from either -- a process silent for
     * longer than the gone threshold has gone whether or not a sweep ever saw it stale.
     *
     * @param  Carbon  $cutoff  The contact time at or before which a session qualifies.
     * @param  AgentSessionStatus  $into  The state the pass is moving sessions into.
     * @return Builder<AgentSession> The candidate query.
     */
    private function candidates(Carbon $cutoff, AgentSessionStatus $into): Builder
    {
        return AgentSession::query()
            ->with('installation')
            ->whereIn('status', $this->storable($this->movesFrom($into)))
            ->where('last_seen_at', '<=', $cutoff);
    }

    /**
     * The states a session may be in to enter another one.
     *
     * Read by the sweep's candidate query and by the conditional write that follows it, so the two
     * predicates cannot drift apart. That matters: it is what makes a write that changed no rows
     * mean the row genuinely left the filter, rather than the two disagreeing about which rows were
     * ever eligible.
     *
     * @param  AgentSessionStatus  $into  The state being entered.
     * @return list<AgentSessionStatus> The states it may be entered from.
     */
    private function movesFrom(AgentSessionStatus $into): array
    {
        return match ($into) {
            AgentSessionStatus::Stale => [AgentSessionStatus::Active],
            AgentSessionStatus::Gone => [AgentSessionStatus::Active, AgentSessionStatus::Stale],
            AgentSessionStatus::Active => [AgentSessionStatus::Stale],
        };
    }

    /**
     * Move one session the sweep read, if it is still where the read found it.
     *
     * @param  AgentSession  $session  The session the sweep read.
     * @param  Carbon  $cutoff  The contact time the read qualified it against.
     * @param  AgentSessionStatus  $into  The state to move it into.
     * @return bool True when this call was the transition.
     */
    private function move(AgentSession $session, Carbon $cutoff, AgentSessionStatus $into): bool
    {
        if ($into === AgentSessionStatus::Gone) {
            return $this->goesNow($session, self::TIMEOUT, $cutoff) !== null;
        }

        return DB::transaction(function () use ($session, $cutoff): bool {
            $quietSince = $session->last_seen_at;

            $moved = $this->conditionally(
                $session,
                $this->movesFrom(AgentSessionStatus::Stale),
                ['status' => AgentSessionStatus::Stale->value],
                $cutoff
            );

            if ($moved !== 1) {
                return false;
            }

            $this->events->record(
                FleetEventType::SessionStale,
                $session,
                sprintf('%s stopped answering.', $this->describe($session)),
                ['installation_id' => $session->installation_id, 'quiet_since' => $quietSince->toIso8601String()]
            );

            // A lane holding work that stopped answering is the coordinator's to know at once, on
            // this transition rather than on the next scheduled check (#319)
            $this->conditions->sessionUnobserved($session, AgentSessionStatus::Stale);

            return true;
        });
    }

    /**
     * Bring a stale session back, if it is still stale.
     *
     * @param  AgentSession  $session  The session that made contact.
     * @return bool True when this call was the transition.
     */
    private function resume(AgentSession $session): bool
    {
        return DB::transaction(function () use ($session): bool {
            $resumed = $this->conditionally(
                $session,
                $this->movesFrom(AgentSessionStatus::Active),
                ['status' => AgentSessionStatus::Active->value, 'last_seen_at' => PresenceClock::now()],
                null
            );

            if ($resumed !== 1) {
                return false;
            }

            $this->events->record(
                FleetEventType::SessionResumed,
                $session,
                sprintf('%s is answering again.', $this->describe($session)),
                ['installation_id' => $session->installation_id]
            );

            return true;
        });
    }

    /**
     * End a session, if it has not already ended.
     *
     * The tokens go with the transition rather than beside it. A session marked gone is already
     * refused on every route, so deleting them is not what stops it; it is what stops the rows
     * accumulating for sessions nobody will authenticate again. They are deleted last, after the
     * event is written, because that is the package's lock order.
     *
     * @param  AgentSession  $session  The session to end.
     * @param  string  $reason  What ended it.
     * @param  Carbon|null  $cutoff  The contact time the sweep qualified it against, when a sweep
     *                               is what is ending it.
     * @param  string|null  $actor  The signed-in developer ending it, when one is.
     * @return int|null How many tokens were deleted, or null when it had already gone.
     */
    private function goesNow(AgentSession $session, string $reason, ?Carbon $cutoff, ?string $actor = null): ?int
    {
        return DB::transaction(function () use ($session, $reason, $cutoff, $actor): ?int {
            $ended = $this->conditionally(
                $session,
                $this->movesFrom(AgentSessionStatus::Gone),
                ['status' => AgentSessionStatus::Gone->value],
                $cutoff
            );

            if ($ended !== 1) {
                return null;
            }

            $this->events->record(
                FleetEventType::SessionGone,
                $session,
                sprintf('%s ended.', $this->describe($session)),
                ['installation_id' => $session->installation_id, 'reason' => $reason],

                // The session names the developer the event is about; this names the admin who
                // ended it, when an admin did. A session that ended on its own or was swept has
                // nobody to name, and null is the honest answer there rather than the owner
                // repeated (#115). Killing another developer's running agent is the action where
                // "by whom" matters most, and the feed could not say it before.
                actor: $actor
            );

            // Before the release the gone-session listener makes, so the coordinator is told which
            // work the lane was holding when it ended (#319)
            $this->conditions->sessionUnobserved($session, AgentSessionStatus::Gone);

            $deleted = Tokens::deleted($session->tokens()->delete());

            // After the commit, so a listener never releases what a rollback would have kept, and
            // exactly once, because only the call that changed the row reaches this line
            $this->dispatcher->dispatch(new SessionGone($session, $reason));

            return $deleted;
        });
    }

    /**
     * Write one transition, and say whether the row was still where the caller last saw it.
     *
     * The in-memory session is updated only when the row was, so a caller that lost the race keeps
     * reading the state it actually has rather than the one it tried to write. `updated_at` is
     * written explicitly rather than left to `addUpdatedAtColumn()`, so the value the row took and
     * the value the instance carries are the same one, and only the attributes this wrote are
     * marked clean.
     *
     * @param  AgentSession  $session  The session to move.
     * @param  list<AgentSessionStatus>  $from  The statuses the row may currently hold.
     * @param  array<string, mixed>  $values  What to write.
     * @param  Carbon|null  $cutoff  The contact time to require, when a clock decides the move.
     * @return int How many rows changed, which is one or none.
     */
    private function conditionally(AgentSession $session, array $from, array $values, ?Carbon $cutoff): int
    {
        $query = AgentSession::query()
            ->whereKey($session->getKey())
            ->whereIn('status', $this->storable($from));

        if ($cutoff instanceof Carbon) {
            // The half of the condition the sweep needs: a session heard from since the read is a
            // session that is answering, whatever the read said a moment ago
            $query->where('last_seen_at', '<=', $cutoff);
        }

        $values['updated_at'] = Carbon::now();

        $changed = $query->update($values);

        if ($changed === 1) {
            $session->forceFill($values)->syncOriginalAttributes(array_keys($values));
        }

        return $changed;
    }

    /**
     * The statuses as the column holds them.
     *
     * @param  list<AgentSessionStatus>  $statuses  The statuses to store.
     * @return list<string> Their stored values.
     */
    private function storable(array $statuses): array
    {
        return array_map(static fn (AgentSessionStatus $status): string => $status->value, $statuses);
    }

    /**
     * How a session reads in the feed: the harness and machine a human would recognize.
     *
     * Loaded explicitly rather than reached through the relation, so this is correct for a caller
     * that did not eager-load and for a host that calls `Model::preventLazyLoading()`.
     *
     * No single mutation kills this line, and the reason is worth recording. `Builder::hydrate()`
     * sets a model's lazy-loading flag only `if (count($items) > 1)`, so a session loaded with
     * `first()` -- which is every caller but the sweep -- can never raise a violation whatever the
     * host configured. The sweep is the one query here that hydrates several, and it eager-loads.
     * So this line is what makes the rule hold for a future caller that does neither.
     *
     * @param  AgentSession  $session  The session to describe.
     * @return string The description.
     */
    private function describe(AgentSession $session): string
    {
        $session->loadMissing('installation');

        return sprintf('%s on %s', $session->installation->harness, $session->installation->machine_label);
    }

    /**
     * How many sessions one delete statement removes.
     *
     * Tuning rather than behavior, for the reason `FleetEvents::PRUNE_BATCH` records.
     */
    public const int PRUNE_BATCH = 500;

    /**
     * How many batches one run may take.
     */
    public const int PRUNE_BATCHES = 50;

    /**
     * Delete sessions that ended long ago, in batches.
     *
     * The last step of the lifecycle this class owns: a session goes, #24 keeps its row so a reader
     * can still see what the process was, and eventually nobody is looking (#113).
     *
     * **Only a session that has gone is ever deleted.** `active` and `stale` are live -- a stale
     * session is one request away from active -- so age is no reason to remove either.
     *
     * **A session still holding a task or a lock is never deleted, whatever its age.** Both
     * `robot_council_tasks.claimed_by` and `robot_council_locks.holder_id` are `nullOnDelete`, so
     * deleting the row rewrites rows this prune never selected: a task loses its claimant while
     * its status still says it is held, which no release path can then reach, and a lock is freed
     * without the feed event a release writes. `SessionReleases` is supposed to have released both
     * by now, and its own docblock is why that is not enough -- a step that throws fails the sweep,
     * so a mechanism that keeps failing leaves orphans indefinitely.
     *
     * **This is not about session id reuse**, which the ticket originally asked for and which
     * cannot happen here: `robot_council_agent_sessions.id` is `primary key autoincrement` on
     * SQLite, which keeps a high-water mark in `sqlite_sequence` -- measured, deleting the highest
     * row and starting a session gave the next id, not the deleted one. Resetting a sequence is
     * what `TRUNCATE` does, not `DELETE`.
     *
     * `robot_council_events.agent_session_id` is left dangling deliberately (#50). Nothing reads
     * it to decide visibility: `FleetFeed` and `AgentLogins::forUsers()` both read `user_id` off
     * the event, so a deleted session cannot re-point its narration at anybody.
     *
     * Age comes from `last_seen_at`, which nothing writes once a session has gone, rather than
     * `updated_at`, which any later touch of the row would move.
     *
     * @param  Carbon  $before  Delete sessions last heard from before this.
     * @param  int  $batch  How many rows one statement removes.
     * @param  int  $maxBatches  A ceiling, so a run is bounded even on a table nobody has pruned.
     * @return int How many sessions were deleted.
     */
    public function prune(Carbon $before, int $batch = self::PRUNE_BATCH, int $maxBatches = self::PRUNE_BATCHES): int
    {
        // **On the clock `robot_council_locks.expires_at` is written on**, which #149 moved to
        // `PresenceClock`. Its only use is the lease comparison below, and a binding is formatted
        // in the value's own zone by `Connection::prepareBindings()` -- so an application clock
        // here would compare the host's wall-clock digits against UTC digits. East of UTC every
        // live lease is shorter than the offset, so the guard would not be weakened but defeated:
        // a gone session holding a live lock would be deleted, `holder_id` nulled by the
        // `nullOnDelete`, and the lock freed with no `lock.released` event.
        $now = PresenceClock::now();

        $held = TaskStatus::values(TaskStatus::held());

        $deleted = 0;

        for ($i = 0; $i < max(1, $maxBatches); $i++) {
            $ids = AgentSession::query()
                ->where('status', AgentSessionStatus::Gone)
                ->where('last_seen_at', '<', $before)
                // Narrowed by status rather than by the relation, because `claimed_by` survives a
                // task finishing: a session that completed work still names every task it claimed.
                ->whereDoesntHave('claimedTasks', fn (Builder $task) => $task->whereIn('status', $held))
                // A lapsed lease is already free, and freeing it again costs nothing.
                ->whereDoesntHave('heldLocks', fn (Builder $lock) => $lock->where('expires_at', '>', $now))
                ->orderBy('id')
                ->limit(max(1, $batch))
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            AgentSession::query()->whereIn('id', $ids)->delete();

            // Counted from the ids selected, for the reason `FleetEvents::prune()` records.
            $deleted += \count($ids);
        }

        return $deleted;
    }
}
