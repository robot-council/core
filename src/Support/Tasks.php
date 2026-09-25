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
use RobotCouncil\Models\LaneHold;
use RobotCouncil\Models\Placement;
use RobotCouncil\Models\PlacementRule;
use RobotCouncil\Models\Seat;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use Throwable;

/**
 * Creating tasks and moving them between states.
 *
 * **Every transition is one conditional update, and the count of changed rows is the decision.**
 * The statuses the transition may start from, the claimant it requires, and -- for a claim -- #16's
 * eligibility rule all go into the same `where`. So two agents claiming one task is settled by the
 * database rather than by whoever read first, and a read-then-write implementation that looked
 * correct would hand the same task to both.
 *
 * A failed write costs one extra read, and only a failed one. The write cannot say *why* it matched
 * nothing -- a missing task, a status that moved, a session that does not hold the task, and a task
 * belonging to another developer all look identical to `update()` -- so the diagnosis happens
 * afterwards, off the hot path, and answers 404, 409, or 403.
 *
 * **Lock order: agent sessions, then tasks, then the feed sentinel.** Every path here takes the
 * session row first where it touches one, including the two transitions that write `claimed_by` --
 * because on InnoDB an update that changes a foreign key takes a shared lock on the new parent, so
 * a claim or a reassign locks a session row whether or not the code says so. Writing the task first
 * and letting the foreign key take the session afterwards inverts the order against the release
 * step, which locks the session explicitly, and two paths taking the same two rows in opposite
 * orders is a deadlock on every engine that locks rows.
 */
final class Tasks
{
    /**
     * @param  FleetEvents  $events  The change feed.
     * @param  Credentials  $credentials  The configured bounds.
     */
    public function __construct(
        private readonly FleetEvents $events,
        private readonly Credentials $credentials,
        private readonly PlacementRules $rules,
        private readonly Seats $seats,
        private readonly PlacementWaivers $waivers
    ) {}

    /**
     * Create a task, pending and unclaimed.
     *
     * @param  AgentSession  $session  The session creating it.
     * @param  array<string, mixed>  $attributes  The validated fields from the request.
     * @param  bool  $withCoordinator  Whether the session held `coordinator:direct` as it created.
     * @return Task The created task.
     */
    public function create(AgentSession $session, array $attributes, bool $withCoordinator): Task
    {
        $this->withinBounds($attributes);

        return DB::transaction(function () use ($session, $attributes, $withCoordinator): Task {
            $task = Task::query()->create([
                ...$attributes,
                'status' => TaskStatus::Pending,
                'created_by' => $session->getKey(),

                // Copied from the session rather than taken from the request, so nothing can
                // create a task on another developer's behalf and make it claimable by them
                'user_id' => $session->user_id,

                // Read from the token as it creates, and stored. Revoking the ability afterwards
                // must not narrow who may claim a task that was already open to the fleet.
                'created_with_coordinator' => $withCoordinator,
            ]);

            $this->events->record(
                FleetEventType::TaskCreated,
                $session,
                // The id and nothing the creator wrote. The feed reaches every agent, and a task's
                // own words are served by `TaskList`, which decides who may read them.
                sprintf('Task #%d created.', $task->id),
                ['task_id' => $task->id, 'project_id' => $task->project_id],
                $withCoordinator
            );

            return $task;
        });
    }

    /**
     * Attempt one transition.
     *
     * @param  int  $taskId  The task to move.
     * @param  TaskTransition  $transition  What to do to it.
     * @param  AgentSession  $actor  The session attempting it.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @param  AgentSession|null  $assignee  Who to hand it to, for a reassignment.
     * @param  array<array-key, mixed>|null  $result  What the agent reports, for a completion.
     * @param  string|null  $directive  What to tell the assignee, for a reassignment, where it is required.
     * @param  bool  $handBack  Whether a reassignment returns a gate's pull request to the lane that made it.
     * @param  string|null  $branch  The branch the lane is working on, for a start.
     * @param  TaskStatus|null  $expect  For a reassignment, the one status the task must still be in
     *                                   -- `pending` for a coordinator placing unclaimed work that
     *                                   must not take it from a lane that claimed it meanwhile.
     * @param  string|null  $subLabel  Which of the lane's subagents takes it up, for a start (#409).
     *                                 Display only, within `SubLabel`.
     * @return Outcome What came of it.
     *
     * @throws InvalidArgumentException When a reassignment names nobody or says nothing, a branch or
     *                                  sub-label is unusable, or `expect` is given where it cannot
     *                                  apply.
     * @throws PlacementRefused When a placement breaks an invariant no waiver covers (#320).
     */
    public function transition(
        int $taskId,
        TaskTransition $transition,
        AgentSession $actor,
        bool $asCoordinator,
        ?AgentSession $assignee = null,
        ?array $result = null,
        ?string $directive = null,
        bool $handBack = false,
        ?string $branch = null,
        ?TaskStatus $expect = null,
        ?string $subLabel = null
    ): Outcome {
        $holder = $transition->takesTheClaim()
            ? ($assignee ?? $actor)
            : null;

        if ($transition === TaskTransition::Reassign && ! $assignee instanceof AgentSession) {
            // Unreachable through the endpoint, which validates `session_id` as required -- but
            // this is a public method on an injectable service, and #26 and #33 call it directly.
            // Without this a reassignment with no assignee silently hands the task to the actor.
            throw new InvalidArgumentException('A reassignment needs the session to hand the task to.');
        }

        if ($transition->takesADirective() && ($directive === null || trim($directive) === '')) {
            // The same reason as the guard above, and the one #316 is about: a stored placement with
            // no directive is the lane that was never told. Refused here so a host calling the store
            // directly cannot reach the state the endpoint's validation keeps out.
            throw new InvalidArgumentException('A reassignment needs a directive telling the session what it is being handed.');
        }

        // #328: a placement may insist on the status it read. Refused elsewhere rather than ignored,
        // because a caller that meant it as a guard and had it silently dropped would believe it
        // was guarded.
        if ($expect instanceof TaskStatus
            && ($transition !== TaskTransition::Reassign || ! \in_array($expect, $transition->startsFrom(), true))) {
            throw new InvalidArgumentException('Only a reassignment takes `expect`, and only a status it starts from.');
        }

        // Only a start carries one; anything else is ignored rather than refused, so a caller that
        // passes a branch it happens to know on another transition writes nothing it did not mean to
        $branch = $transition->takesABranch() ? $branch : null;
        BranchName::ensure($branch);

        // The same rule for the same reason: only a start says which subagent took the task up
        $subLabel = $transition->takesABranch() ? $subLabel : null;
        SubLabel::ensure($subLabel);

        return DB::transaction(function () use ($taskId, $transition, $actor, $asCoordinator, $holder, $result, $directive, $handBack, $branch, $expect, $subLabel): Outcome {
            // The session row before the task row, which is the package's lock order. A claim or a
            // reassign writes `claimed_by`, and on InnoDB that takes a shared lock on the new
            // parent -- after the task row, inverting the order against the release step. Taking it
            // here also closes the window the endpoint's own liveness read leaves open: the
            // assignee is re-read under the lock, so a session that went between the two is
            // refused rather than handed a task it will never work.
            if ($holder instanceof AgentSession && ! $this->stillWorkable($holder)) {
                return Outcome::Conflict;
            }

            // #320: the invariants a placement must hold, assessed before the write -- whether a
            // placement is new depends on the status the task had -- and enforced after it, so a
            // placement that loses its race never spends a waiver it did not use
            $broken = $transition === TaskTransition::Reassign && $holder instanceof AgentSession
                ? $this->placementRefusals($taskId, $holder, $handBack)
                : [];

            $changed = $this->write($taskId, $transition, $actor, $asCoordinator, $holder, $result, $handBack, $branch, $expect, $subLabel);

            if ($changed !== 1) {
                return $this->diagnose($taskId, $transition, $actor, $asCoordinator, $holder, $expect);
            }

            if ($broken !== []) {
                $seat = $this->seats->of($holder);
                $uncovered = $seat instanceof Seat ? $this->waivers->spend($seat->id, $broken, $taskId) : $broken;

                // Thrown rather than returned, so the placement's write rolls back with everything
                // else in this transaction -- including any waiver spent on a rule that still
                // refused alongside another
                if ($uncovered !== []) {
                    throw new PlacementRefused($uncovered);
                }
            }

            // The sub-label the event carries (#409), read AFTER the write, whose row lock it reads
            // under -- a query on the task before the deciding write would put a read ahead of it
            // that nothing needs. A new holder has none, since the write cleared it. **A release
            // clears it here rather than in the write**, so the feed can still say which subagent's
            // ticket went back: the label is read, then removed, inside the same transaction.
            $label = $transition->takesTheClaim() ? null : Task::query()->whereKey($taskId)->value('sub_label');

            if ($transition->to() === TaskStatus::Pending && $label !== null) {
                Task::query()->whereKey($taskId)->update(['sub_label' => null]);
            }

            // A lane given work is no longer idle on purpose, so its hold goes in the same
            // transaction (#334). After the task row and before the feed sentinel, which is the
            // package's lock order; the session row is already held from `stillWorkable()`.
            if ($transition->takesTheClaim() && $holder instanceof AgentSession) {
                LaneHold::query()->whereKey($holder->getKey())->delete();
            }

            $this->events->record(
                $transition->event(),
                $actor,
                sprintf('Task #%d %s.', $taskId, $transition->reads()),
                array_filter([
                    'task_id' => $taskId,
                    'to' => $transition->to()->value,
                    'assigned_to' => $holder?->getKey(),

                    // Charset-limited by `SubLabel`, since this reaches every session in the fleet
                    'sub_label' => \is_string($label) ? $label : null,
                ], static fn (mixed $value): bool => $value !== null),
                $asCoordinator
            );

            // **Inside the same transaction as the placement, and that is the whole of #316's
            // guarantee.** A throw here -- a body over `FleetEvent::MAX_BODY`, a feed that cannot be
            // written -- rolls the placement back with it, so there is no committed state in which a
            // lane holds work nobody told it about, nor one in which it was told about work it does
            // not hold.
            //
            // **Two events, split by who may read them (#331).** The directive, which is what wakes
            // an idle lane and reaches every agent, says only what the package composes: the task,
            // the lane, whether it is a hand-back, and which event holds the instructions. The
            // coordinator's own words go as a `placement.instruction`, readable by the lane and the
            // coordinator's own developer -- they may name an issue another developer's agent may
            // not read. Slack still mirrors it while `slack.mirror_restricted` is on, its default.
            //
            // **The instruction is written first, so the directive can name it.** A lane whose
            // bridge has already read past it (its feed cursor moves on every poll) reads it back
            // with `after` one below that id; a bare "go and read the feed" would resume past it.
            if ($transition->takesADirective() && $holder instanceof AgentSession && $directive !== null) {
                $instruction = $this->events->record(
                    FleetEventType::PlacementInstruction,
                    $actor,
                    $directive,
                    // `to` so a reader can tell it is the addressee without asking the service,
                    // as a narration's `to` does (#324)
                    ['task_id' => $taskId, 'hand_back' => $handBack, 'to' => [$holder->id]],
                    $asCoordinator,
                    addressees: [$holder]
                );

                $this->events->record(
                    FleetEventType::Directive,
                    $actor,
                    sprintf(
                        'Task #%d was placed on session #%d%s. Its instructions are event #%d, a placement.instruction addressed to that session: read the feed from after=%d to see them.',
                        $taskId,
                        $holder->id,
                        $handBack ? ' as a hand-back' : '',
                        $instruction->id,
                        $instruction->id - 1
                    ),
                    ['targets' => [$holder->id], 'task_id' => $taskId, 'hand_back' => $handBack, 'instruction_id' => $instruction->id],
                    $asCoordinator
                );
            }

            return Outcome::Applied;
        });
    }

    /**
     * Finish or release a held task because GitHub reported its work finished (#318).
     *
     * **Attributed to no session, as the sweep's release is**: the fleet is told what GitHub
     * observed, not what any agent said. #318's decision: an issue closing, or the task's pull
     * request merging, is `done`; a pull request closed without merging releases the task to
     * `pending` with the same clean slate a release writes. A task that is not held -- pending,
     * or already finished -- is left alone, so a redelivered close cannot finish a task twice.
     *
     * **The event names the task and the reason, never the issue.** A task's issue is readable only
     * by sessions that may claim it (`TaskList`), and a state change reaches every session.
     *
     * @param  int  $taskId  The task.
     * @param  bool  $completed  True to finish it, false to release it.
     * @param  string  $why  The reason, in words the feed shows.
     * @param  array<string, string>  $still  Columns the task must still hold, which is what matched
     *                                        it -- its issue, or its branch. Re-checked in the write,
     *                                        because a coordinator can re-place the task between the
     *                                        read that found it and this, and the new lane must not
     *                                        be finished by the previous one's pull request.
     * @return bool True when it moved.
     */
    public function finishFromGitHub(int $taskId, bool $completed, string $why, array $still = []): bool
    {
        return DB::transaction(function () use ($taskId, $completed, $why, $still): bool {
            $to = $completed ? TaskStatus::Done : TaskStatus::Pending;

            // Who held it, and the label its holder gave it, for the event -- read under the lock
            // the update takes anyway
            $held = Task::query()->whereKey($taskId)->lockForUpdate()->first(['claimed_by', 'sub_label']);
            $claimant = $held?->claimed_by;

            $changed = Task::query()
                ->whereKey($taskId)
                ->where($still)
                ->whereIn('status', TaskStatus::values(TaskStatus::held()))
                ->update($completed
                    ? ['status' => $to->value, 'updated_at' => Carbon::now()]
                    : [
                        'status' => $to->value,
                        'claimed_by' => null,
                        'claimed_at' => null,
                        'placed_by' => null,
                        'hand_back' => false,
                        'branch' => null,
                        'sub_label' => null,
                        'updated_at' => Carbon::now(),
                    ]);

            if ($changed !== 1) {
                return false;
            }

            $this->events->record(
                $completed ? FleetEventType::TaskCompleted : FleetEventType::TaskReleased,
                null,
                sprintf('Task #%d %s: %s.', $taskId, $completed ? 'completed' : 'released', $why),
                [
                    'task_id' => $taskId,
                    'to' => $to->value,
                    'source' => 'github',
                    'released_from' => $claimant,
                    ...self::labelled($held?->sub_label),
                ]
            );

            return true;
        });
    }

    /**
     * Record the branch the lane holding a task is working on, after it has started, and which of
     * its subagents is working it.
     *
     * **After the start rather than at it, which is `robot-council/cli#238`'s decision.** At
     * `start` a lane has usually not made its branch yet, so what its checkout says then is `main`
     * or a reused worktree's previous branch -- a wrong value that looks authoritative on the lane
     * board. The lane knows its branch once it has made one, so it reports it then, and a second
     * report replaces the first, which is how a renamed branch is corrected.
     *
     * One conditional update naming the holder and the statuses it may report from, like every
     * other task write, so a session that does not hold the task writes nothing. It records no
     * fleet event: a branch is read off the row by whoever renders it, and every report would
     * otherwise be a line in every session's feed.
     *
     * **The sub-label rides the same report (#409)**, since it is the same kind of fact -- where the
     * work on this ticket is happening -- known at the same moment, and written by the same holder.
     * Either may be sent alone; one that is not sent is left as it was. It reaches the feed on the
     * task's next `task.*` event rather than through an event of its own, for the reason above.
     *
     * @param  int  $taskId  The task.
     * @param  AgentSession  $holder  The session reporting, which must hold it.
     * @param  string|null  $branch  The branch, within `BranchName`, or null to leave it.
     * @param  string|null  $subLabel  The sub-label, within `SubLabel`, or null to leave it.
     * @return Outcome Applied; NotFound; Conflict for a task not in progress or blocked; Forbidden
     *                 for a session that does not hold it.
     *
     * @throws InvalidArgumentException When either is outside its bound, or neither is given.
     */
    public function reportBranch(int $taskId, AgentSession $holder, ?string $branch, ?string $subLabel = null): Outcome
    {
        BranchName::ensure($branch);
        SubLabel::ensure($subLabel);

        // Only what was sent, so reporting a sub-label does not forget the branch, or the reverse
        $values = array_filter(['branch' => $branch, 'sub_label' => $subLabel], static fn (?string $value): bool => $value !== null);

        if ($values === []) {
            throw new InvalidArgumentException('A report names a branch, a sub-label, or both.');
        }

        $reportable = [TaskStatus::InProgress->value, TaskStatus::Blocked->value];

        $changed = Task::query()
            ->whereKey($taskId)
            ->where('claimed_by', $holder->getKey())
            ->whereIn('status', $reportable)
            ->update($values);

        if ($changed === 1) {
            return Outcome::Applied;
        }

        $task = Task::query()->find($taskId);

        return match (true) {
            ! $task instanceof Task => Outcome::NotFound,
            ! \in_array($task->status->value, $reportable, true) => Outcome::Conflict,
            $task->claimed_by !== $holder->getKey() => Outcome::Forbidden,
            // Everything the write tested holds. MySQL reports rows CHANGED, so a report of the
            // branch already recorded, in the second `updated_at` already holds, reads as 0 there
            // and 1 elsewhere; the row says what was asked, so it is applied either way.
            ($branch === null || $task->branch === $branch) && ($subLabel === null || $task->sub_label === $subLabel) => Outcome::Applied,
            default => Outcome::Conflict,
        };
    }

    /**
     * Give back the tasks every session that has gone was still holding.
     *
     * Registered on the presence sweep rather than driven by `Events\SessionGone`, so a signal that
     * was missed -- a worker that died, a listener that threw -- costs one sweep interval rather
     * than leaving a task claimed by a process that no longer exists for good. It is idempotent for
     * the same reason: it finds what is still held, whatever released the rest.
     *
     * @return int How many tasks were released.
     */
    public function releaseOrphaned(): int
    {
        $released = 0;

        $orphaned = Task::query()
            ->whereIn('status', TaskStatus::values(TaskStatus::held()))
            ->where(function (Builder $held): void {
                $held->whereIn('claimed_by', AgentSession::query()
                    ->select('id')
                    ->where('status', AgentSessionStatus::Gone->value))

                    // A held task with no claimant at all. `claimed_by` is `nullOnDelete`, so
                    // deleting an installation cascades to its sessions and leaves its tasks held
                    // by nobody -- matching neither a claim, nor the claimant's own release, nor
                    // the clause above. A held task with no holder is an invariant violation by
                    // definition, so picking it up costs nothing and closes the gap.
                    ->orWhereNull('claimed_by');
            })
            ->orderBy('id')
            ->limit($this->credentials->maxPerSweep())
            ->get();

        $failed = null;

        foreach ($orphaned as $task) {
            try {
                $released += $this->releaseOne($task) ? 1 : 0;
            } catch (Throwable $failure) {
                // Isolated per task, and the reason is the candidate order. The read is
                // `orderBy('id')` with a limit, so a task whose release fails deterministically --
                // a lock held elsewhere, a deadlock victim -- is first on every sweep from now on.
                // Letting it escape the loop would leave every other gone session's tasks held for
                // good, which is the one thing the sweep exists to prevent.
                Log::error(
                    sprintf('robot-council: releasing task %d from its gone session failed.', $task->id),
                    ['exception' => $failure]
                );

                $failed ??= $failure;
            }
        }

        // Still loud, and still after every task has had its turn
        if ($failed instanceof Throwable) {
            throw $failed;
        }

        return $released;
    }

    /**
     * Release one task whose session has gone, if its session is still gone.
     *
     * The session row is locked and re-read inside the transaction, which is what stops a release
     * racing the session coming back: a `gone` session never does, but the lock is what makes that
     * a property of the write rather than of the read that chose this task a moment earlier.
     *
     * @param  Task  $task  The task to give back.
     * @return bool True when this call released it.
     */
    private function releaseOne(Task $task): bool
    {
        return DB::transaction(function () use ($task): bool {
            $claimant = $task->claimed_by;

            $session = $claimant === null
                ? null
                : AgentSession::query()->whereKey($claimant)->lockForUpdate()->first();

            // A claimant that is still there has to still be gone. One that is not there at all
            // left a held task behind, which nothing else can recover.
            if ($claimant !== null && (! $session instanceof AgentSession || ! $session->hasGone())) {
                return false;
            }

            $changed = Task::query()
                ->whereKey($task->getKey())
                ->whereIn('status', TaskStatus::values(TaskStatus::held()))
                ->when(
                    $claimant === null,
                    fn (Builder $query) => $query->whereNull('claimed_by'),
                    fn (Builder $query) => $query->where('claimed_by', $claimant)
                )
                ->update([
                    'status' => TaskStatus::Pending->value,
                    'claimed_by' => null,
                    'claimed_at' => null,

                    // The same clean slate a release writes, so the sweep and a release cannot
                    // leave a pending task that still claims a placement from its last holder
                    'placed_by' => null,
                    'hand_back' => false,
                    'branch' => null,
                    'sub_label' => null,
                    'updated_at' => Carbon::now(),
                ]);

            if ($changed !== 1) {
                return false;
            }

            // Attributed to no session: the fleet is being told what the service observed, not
            // what the session that lost the task had to say about it
            $this->events->record(
                FleetEventType::TaskReleased,
                null,
                sprintf('Task #%d released: its session ended.', $task->id),
                [
                    'task_id' => $task->id,
                    'to' => TaskStatus::Pending->value,
                    'released_from' => $claimant,
                    ...self::labelled($task->sub_label),
                ]
            );

            return true;
        });
    }

    /**
     * A held task's sub-label as event `meta`, or nothing where it has none (#409).
     *
     * @param  string|null  $label  The label the holder gave it.
     * @return array<string, string> `['sub_label' => ...]`, or empty.
     */
    private static function labelled(?string $label): array
    {
        return $label === null ? [] : ['sub_label' => $label];
    }

    /**
     * Whether a session can still be handed a task, read under a lock.
     *
     * @param  AgentSession  $session  The session about to be given the task.
     * @return bool True when it is still there and has not gone.
     */
    private function stillWorkable(AgentSession $session): bool
    {
        $current = AgentSession::query()->whereKey($session->getKey())->lockForUpdate()->first();

        return $current instanceof AgentSession && ! $current->hasGone();
    }

    /**
     * The invariants a placement on this lane would break, read under the task's lock.
     *
     * An empty list for a task that is missing or not in a status a reassignment starts from:
     * the write then changes nothing, and the diagnosis says why, which is the more useful answer.
     *
     * @param  int  $taskId  The task.
     * @param  AgentSession  $lane  The session it would be placed on.
     * @param  bool  $handBack  Whether it returns a gate's pull request.
     * @return list<PlacementRule> The rules it breaks.
     */
    private function placementRefusals(int $taskId, AgentSession $lane, bool $handBack): array
    {
        $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();

        if (! $task instanceof Task || ! \in_array($task->status, TaskTransition::Reassign->startsFrom(), true)) {
            return [];
        }

        return $this->rules->refusals($task, $lane, $handBack, Carbon::now());
    }

    /**
     * The one statement that decides a transition.
     *
     * @param  int  $taskId  The task to move.
     * @param  TaskTransition  $transition  What to do to it.
     * @param  AgentSession  $actor  The session attempting it.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @param  AgentSession|null  $holder  Who ends up holding it, when anybody does.
     * @param  array<array-key, mixed>|null  $result  What the agent reports, for a completion.
     * @param  bool  $handBack  Whether a reassignment returns a gate's pull request.
     * @param  string|null  $branch  The branch a start reports, already bounded.
     * @param  TaskStatus|null  $expect  The one status a reassignment insists on, already checked.
     * @param  string|null  $subLabel  The sub-label a start reports, already bounded.
     * @return int How many rows changed, which is one or none.
     */
    private function write(
        int $taskId,
        TaskTransition $transition,
        AgentSession $actor,
        bool $asCoordinator,
        ?AgentSession $holder,
        ?array $result,
        bool $handBack,
        ?string $branch,
        ?TaskStatus $expect = null,
        ?string $subLabel = null
    ): int {
        // `expect` narrows the statuses the write starts from to the one the caller read, in the
        // write's own `where`, so the database decides the race rather than a read before it
        $query = Task::query()
            ->whereKey($taskId)
            ->whereIn('status', TaskStatus::values($expect instanceof TaskStatus ? [$expect] : $transition->startsFrom()));

        // A claimant's transition names the claimant in the write. A coordinator releasing does
        // not, which is the whole point of the override.
        if ($transition->needsTheClaim() && (! $asCoordinator || ! $transition->coordinatorMayOverride())) {
            $query->where('claimed_by', $actor->getKey());
        }

        if ($transition->takesTheClaim() && $holder instanceof AgentSession) {
            // #16, carried in the write rather than checked before it, so eligibility costs no
            // read and cannot be decided against a row that changed in between.
            //
            // **Tested against whoever ends up holding the task, which for a reassignment is the
            // assignee, not the coordinator doing it.** Before #316 a reassignment checked nobody,
            // so a coordinator could hand one developer's own task to another developer's session
            // -- which then held work `TaskList` would not let it read, since what a session may
            // read is exactly what it may claim. A placement is the lane taking the task at the
            // coordinator's word, so the lane has to be one that could have taken it itself.
            $query->where(function (Builder $eligible) use ($holder): void {
                $eligible->where('user_id', $holder->user_id)
                    ->orWhere('created_with_coordinator', true);
            });
        }

        $values = [
            'status' => $transition->to()->value,
            'updated_at' => Carbon::now(),
        ];

        if ($transition->takesTheClaim()) {
            $values['claimed_by'] = $holder?->getKey();
            $values['claimed_at'] = Carbon::now();

            // How it came to be held, and a clean slate for everything a previous holder reported:
            // a new holder has not started, so no branch of the last one's describes its work
            $values['placed_by'] = $transition === TaskTransition::Reassign
                ? Placement::Coordinator->value
                : Placement::Lane->value;
            $values['hand_back'] = $transition === TaskTransition::Reassign && $handBack;
            $values['branch'] = null;
            $values['sub_label'] = null;
        }

        if ($branch !== null) {
            // Only a start reaches here with one. Absent, the column is left alone, so resuming a
            // blocked task without naming the branch again does not forget the one it was on.
            $values['branch'] = $branch;
        }

        if ($subLabel !== null) {
            // The same rule as the branch, for the same reason
            $values['sub_label'] = $subLabel;
        }

        if ($transition->takesAResult() && $result !== null) {
            // Hand-encoded, because `Eloquent\Builder::update()` applies no casts: the model's
            // `array` cast never runs here and a raw array would reach the column as `Array`.
            // Throwing rather than returning false, because `prepareBindings()` turns false into
            // the integer 0, which is valid JSON -- so the row would commit with `result = 0` and
            // the failure would be silent. The model's own cast throws; this matches it.
            $values['result'] = json_encode($result, JSON_THROW_ON_ERROR);
        }

        if ($transition->to() === TaskStatus::Pending) {
            // A release, including the sweep's, gives the task back with no trace of who held it
            // or how they came by it
            $values['claimed_by'] = null;
            $values['claimed_at'] = null;
            $values['placed_by'] = null;
            $values['hand_back'] = false;
            $values['branch'] = null;

            // `sub_label` is cleared by `transition()` after it has read it for the event
        }

        return $query->update($values);
    }

    /**
     * Work out why a write matched nothing.
     *
     * Ordered deliberately. A status the transition cannot start from is a conflict even when the
     * caller also does not hold the task, because the task being finished, cancelled, or already
     * taken is the more useful thing to be told -- and because a terminal task has no claimant to
     * not be.
     *
     * @param  int  $taskId  The task that was not moved.
     * @param  TaskTransition  $transition  What was attempted.
     * @param  AgentSession  $actor  The session that attempted it.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @param  AgentSession|null  $holder  Who would have held it, for a transition that takes the claim.
     * @param  TaskStatus|null  $expect  The one status the write insisted on, if any.
     * @return Outcome Why nothing happened.
     */
    private function diagnose(
        int $taskId,
        TaskTransition $transition,
        AgentSession $actor,
        bool $asCoordinator,
        ?AgentSession $holder,
        ?TaskStatus $expect = null
    ): Outcome {
        // Locked, so the diagnosis reads the row the write tested rather than one that moved in
        // between. Postgres releases the lock on a row an UPDATE's predicate rejected and MySQL's
        // REPEATABLE READ keeps it, so an unlocked read here answers 403 on one engine and 409 on
        // the other for the same race -- and the difference is exactly what tells an agent whether
        // to retry. Same row the update already targeted, so it adds no lock-order edge.
        $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();

        if (! $task instanceof Task) {
            return Outcome::NotFound;
        }

        if (! \in_array($task->status, $transition->startsFrom(), true)) {
            return Outcome::Conflict;
        }

        // Moved on from the status the placement read -- a lane claimed it first. A conflict, so the
        // coordinator re-reads, rather than a refusal it cannot act on.
        if ($expect instanceof TaskStatus && $task->status !== $expect) {
            return Outcome::Conflict;
        }

        // The same session the write tested: the claimant for a claim, the assignee for a
        // reassignment. Diagnosing a reassignment against the coordinator would call an ineligible
        // assignee a conflict and invite a retry that can never succeed.
        if ($transition->takesTheClaim() && $holder instanceof AgentSession && ! $task->isClaimableBy($holder)) {
            return Outcome::Forbidden;
        }

        $mustHold = $transition->needsTheClaim() && (! $asCoordinator || ! $transition->coordinatorMayOverride());

        if ($mustHold && $task->claimed_by !== $actor->getKey()) {
            return Outcome::Forbidden;
        }

        // Everything the write tested still holds, so the row moved between the write and this
        // read. Whoever moved it got there first.
        return Outcome::Conflict;
    }

    /**
     * Refuse text the package will not store, before an engine decides what that means.
     *
     * **The column is not the bound, for two reasons.** It means something different on each engine:
     * `varchar` is refused past its length by Postgres and MySQL and stored whole by SQLite, so one
     * call writes a 300-character title on one engine and answers 500 on the other two. And its
     * width is not even ours -- `$table->string('title')` takes `Schema::$defaultStringLength`,
     * a public static a host may lower, so the migrations pin these columns to the constants below
     * and the bound lives here. Both endpoints validate this already; `create()` is a public method
     * a host may call directly, and it spreads what it is given straight into the insert.
     *
     * `description` is a `text` column, which on MySQL holds roughly four times `MAX_DESCRIPTION`
     * characters. Its bound is policy rather than capacity: like a title, it reaches other
     * developers' agents through `TaskList`.
     *
     * **Refused rather than truncated, which is the other half of the pattern #57 asks to settle.**
     * `priority` is an ordinal with a defined range, so `Models\Task` clamps it: 10 means "as urgent
     * as can be", and 9 says the same thing. A title is content, and shortening content changes what
     * it says -- silently, in a field other developers' agents read. Two of the three engines already
     * refuse it; this makes the third agree, and names the column instead of surfacing a driver
     * error.
     *
     * @param  array<string, mixed>  $attributes  What the caller is asking to store.
     *
     * @throws InvalidArgumentException When a field is longer than its column.
     */
    private function withinBounds(array $attributes): void
    {
        // The one field here that does NOT stay behind `TaskList`'s visibility rule: `create()`
        // puts it into the feed's `meta` below, and `FleetFeed` serves that to every session. So it
        // is bounded in charset as well as length, and by the one helper both stores share.
        ProjectId::ensure($attributes['project_id'] ?? null);

        // Repository-qualified or absent: a bare `#N` names a number that exists in every tracker
        IssueReference::ensure($attributes['issue'] ?? null);

        $limits = ['title' => Task::MAX_TITLE, 'description' => Task::MAX_DESCRIPTION];

        foreach ($limits as $field => $limit) {
            $value = $attributes[$field] ?? null;

            // Measured in characters, as the `max:` rule the endpoints apply does, so the store and
            // the edge refuse the same values rather than nearly the same ones
            if (\is_string($value) && mb_strlen($value) > $limit) {
                throw new InvalidArgumentException(sprintf(
                    "A task's %s is limited to %d characters, and this one is %d.",
                    $field,
                    $limit,
                    mb_strlen($value)
                ));
            }
        }
    }

    /**
     * How many tasks one delete statement removes.
     *
     * Tuning rather than behaviour, for the reason `FleetEvents::PRUNE_BATCH` records: the floor
     * is what `prune()` enforces and what has a test, and no input can tell this figure from one
     * either side of it.
     */
    public const int PRUNE_BATCH = 500;

    /**
     * How many batches one run may take.
     */
    public const int PRUNE_BATCHES = 50;

    /**
     * Delete finished tasks older than a cutoff, in batches.
     *
     * **Only a terminal task is ever deleted, whatever its age.** A task nobody has finished is
     * work the fleet still owes somebody, and age is the opposite of a reason to remove it -- an
     * old pending task is the one most worth looking at. The statuses come from
     * `TaskStatus::terminal()`, which derives from the `isTerminal()` match, so adding a status
     * forces the decision there rather than defaulting to prunable here.
     *
     * **Age is measured from `updated_at`, and that is correct precisely BECAUSE the status is
     * terminal**: nothing transitions out of one, so the last write is the moment the task
     * finished. Using `created_at` would delete a long-running task that finished yesterday and
     * keep one filed and cancelled this morning.
     *
     * **A task that still has a child is never deleted, however old it is.** `parent_task_id` is
     * `nullOnDelete`, so deleting a parent rewrites a row this prune did not select -- and that row
     * can be a task the fleet is still working on, which would silently lose the grouping an agent
     * reads it by. Leaving a parent until its children are gone can orphan nothing at any depth,
     * and it costs a **batch** per level rather than a run: a child deleted by one batch leaves its
     * parent selectable by the next, so a finished tree shallower than `$maxBatches` clears in one
     * run and a deeper one loses `$maxBatches` levels a night until it is gone.
     *
     * The exact-looking alternative is what gets this wrong. "Delete a parent when every child is
     * going in this run too" holds for a parent and its children and breaks one level further
     * down: a grandparent whose only child is prunable passes that test, while the child is itself
     * held back by a live grandchild, so the grandchild's parent link survives and the child's
     * does not. The condition here has no such case because it never looks past one level.
     *
     * Batched for the reason `FleetEvents::prune()` records -- one statement large enough to
     * matter holds a table other writers need. The batching is the same shape deliberately.
     *
     * @param  Carbon  $before  Delete tasks that finished strictly before this.
     * @param  int  $batch  How many rows one statement removes.
     * @param  int  $maxBatches  A ceiling, so a run is bounded even on a table nobody has pruned.
     * @return int How many tasks were deleted.
     */
    public function prune(Carbon $before, int $batch = self::PRUNE_BATCH, int $maxBatches = self::PRUNE_BATCHES): int
    {
        $deleted = 0;

        $terminal = TaskStatus::values(TaskStatus::terminal());

        for ($i = 0; $i < max(1, $maxBatches); $i++) {
            $ids = Task::query()
                ->whereIn('status', $terminal)
                ->where('updated_at', '<', $before)
                ->whereDoesntHave('children')
                ->orderBy('id')
                ->limit(max(1, $batch))
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            Task::query()->whereIn('id', $ids)->delete();

            // Counted from the ids selected, for the reason `FleetEvents::prune()` records.
            $deleted += \count($ids);
        }

        return $deleted;
    }
}
