<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use RobotCouncil\Access\Ability;

/**
 * What can be done to a task, who may do it, and what it leaves behind.
 *
 * The whole transition table from #25 is here rather than spread across controllers, because every
 * part of it is read by the same conditional update: the statuses it may start from become the
 * write's `where`, so a transition that is impossible writes nothing rather than being refused by a
 * check that raced. A route's URL segment is this enum's value, and the route's regex is built from
 * `values()`, so an unknown transition is a 404 from the router and cannot reach the store.
 */
enum TaskTransition: string
{
    /**
     * Take an unclaimed task, subject to #16's eligibility rule.
     */
    case Claim = 'claim';

    /**
     * Begin work on a task this session holds.
     */
    case Start = 'start';

    /**
     * Say a task this session holds is waiting on something else.
     */
    case Block = 'block';

    /**
     * Finish a task this session holds, successfully.
     */
    case Complete = 'complete';

    /**
     * Finish a task this session holds, unsuccessfully.
     */
    case Fail = 'fail';

    /**
     * Give a task back to the queue.
     */
    case Release = 'release';

    /**
     * Hand a task to a named session, whether or not anybody held it: this is a coordinator's
     * placement as well as a move.
     *
     * **It starts from `pending` too, since #316.** Before then it moved only a held task, so a
     * coordinator had no way to put unclaimed work in a particular lane's hands -- the one act the
     * lane board exists to record. It carries a directive, written to the assignee in the same
     * transaction, and the assignee must pass #16's rule for the task exactly as a claimant would.
     *
     * **That is the task's rule, not the session's ability, and the difference is deliberate.**
     * Every role preset includes `tasks:claim`, so a check on the preset could never fail; the only
     * session lacking it holds a token an administrator narrowed below its preset. Such a session
     * can be handed work it cannot then start -- as it could be before #316 by moving held work --
     * and only a coordinator's release or the gone-session sweep takes it back.
     */
    case Reassign = 'reassign';

    /**
     * Call a task off for good.
     */
    case Cancel = 'cancel';

    /**
     * The statuses this transition may start from.
     *
     * @return list<TaskStatus> The allowed starting statuses.
     */
    public function startsFrom(): array
    {
        return match ($this) {
            self::Claim => [TaskStatus::Pending],
            self::Start => [TaskStatus::Claimed, TaskStatus::Blocked],
            self::Block, self::Complete => [TaskStatus::Claimed, TaskStatus::InProgress],
            self::Fail, self::Release => TaskStatus::held(),
            self::Reassign => [TaskStatus::Pending, ...TaskStatus::held()],
            self::Cancel => [TaskStatus::Pending, ...TaskStatus::held()],
        };
    }

    /**
     * The status this transition leaves the task in.
     *
     * @return TaskStatus The resulting status.
     */
    public function to(): TaskStatus
    {
        return match ($this) {
            self::Claim, self::Reassign => TaskStatus::Claimed,
            self::Start => TaskStatus::InProgress,
            self::Block => TaskStatus::Blocked,
            self::Complete => TaskStatus::Done,
            self::Fail => TaskStatus::Failed,
            self::Release => TaskStatus::Pending,
            self::Cancel => TaskStatus::Cancelled,
        };
    }

    /**
     * The ability the ordinary actor needs.
     *
     * A coordinator's transitions need the coordinator's ability and nothing else: a session that
     * directs other developers' agents has no reason to also hold `tasks:claim`.
     *
     * @return Ability The ability required to attempt this.
     */
    public function ability(): Ability
    {
        return match ($this) {
            self::Reassign, self::Cancel => Ability::CoordinatorDirect,
            self::Claim, self::Start, self::Block, self::Complete, self::Fail, self::Release => Ability::TasksClaim,
        };
    }

    /**
     * Whether only the session currently holding the task may do this.
     *
     * @return bool True for the claimant's own transitions.
     */
    public function needsTheClaim(): bool
    {
        return match ($this) {
            self::Start, self::Block, self::Complete, self::Fail, self::Release => true,
            self::Claim, self::Reassign, self::Cancel => false,
        };
    }

    /**
     * Whether a session holding `coordinator:direct` may do this without holding the task.
     *
     * Only a release. A coordinator takes a stuck task back from an agent that cannot finish it;
     * completing or failing one on that agent's behalf would be the coordinator reporting an
     * outcome it did not observe.
     *
     * @return bool True when the coordinator's ability is an alternative to holding the claim.
     */
    public function coordinatorMayOverride(): bool
    {
        return $this === self::Release;
    }

    /**
     * Whether this transition leaves the task in somebody's hands.
     *
     * @return bool True when it writes a claimant, false when it clears one.
     */
    public function takesTheClaim(): bool
    {
        return $this === self::Claim || $this === self::Reassign;
    }

    /**
     * Whether this transition carries a directive to the session it hands the task to.
     *
     * Only a reassignment, and it is required there rather than offered: a placement the lane was
     * never told about is the failure #314 measured at 48 minutes, and making the two one write is
     * what removes that state rather than detecting it.
     *
     * @return bool True when the request must carry a `directive`.
     */
    public function takesADirective(): bool
    {
        return $this === self::Reassign;
    }

    /**
     * Whether this transition may record the branch the lane is working on.
     *
     * Only a start, which is a lane taking up a task: that is the moment it knows the branch, and
     * the board keeps take-up apart from placement for exactly that reason.
     *
     * **A start's optional `sub_label` rides the same rule (#409)**: it says which of a lane's
     * subagents took the task up, which is known at the same moment and nowhere earlier.
     *
     * @return bool True when the request may carry a `branch` and a `sub_label`.
     */
    public function takesABranch(): bool
    {
        return $this === self::Start;
    }

    /**
     * Whether this transition records an outcome the agent reports.
     *
     * Only the two that finish a task. A result on a release or a cancel would be the fleet
     * recording an outcome nobody observed.
     *
     * @return bool True when the request may carry a `result`.
     */
    public function takesAResult(): bool
    {
        return $this === self::Complete || $this === self::Fail;
    }

    /**
     * What the change feed records for this transition.
     *
     * @return FleetEventType The event type.
     */
    public function event(): FleetEventType
    {
        return match ($this) {
            self::Claim => FleetEventType::TaskClaimed,
            self::Start => FleetEventType::TaskStarted,
            self::Block => FleetEventType::TaskBlocked,
            self::Complete => FleetEventType::TaskCompleted,
            self::Fail => FleetEventType::TaskFailed,
            self::Release => FleetEventType::TaskReleased,
            self::Reassign => FleetEventType::TaskReassigned,
            self::Cancel => FleetEventType::TaskCancelled,
        };
    }

    /**
     * How the transition reads in the feed.
     *
     * @return string A past-tense verb phrase.
     */
    public function reads(): string
    {
        return match ($this) {
            self::Claim => 'claimed',
            self::Start => 'started',
            self::Block => 'blocked',
            self::Complete => 'completed',
            self::Fail => 'failed',
            self::Release => 'released',
            self::Reassign => 'reassigned',
            self::Cancel => 'cancelled',
        };
    }

    /**
     * Every transition's URL segment.
     *
     * The route's own constraint is built from this, so a transition that exists is routable and
     * one that does not is a 404 before any controller runs.
     *
     * @return list<string> The values, in declaration order.
     */
    public static function values(): array
    {
        return array_map(static fn (self $transition): string => $transition->value, self::cases());
    }
}
