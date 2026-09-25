<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

/**
 * A hard invariant a coordinator's placement must hold, or be refused (#320).
 *
 * #314's evidence is that the guards that worked refused: a check that printed and proceeded was
 * scrolled past. Each case here refuses a placement outright, before it is written, unless the
 * developer who owns the lane's seat has waived it for one placement (`Support\PlacementWaivers`).
 */
enum PlacementRule: string
{
    /**
     * The task's issue is known to the fleet and open.
     */
    case TicketOpen = 'ticket_open';

    /**
     * The lane works in the repository the task's issue belongs to.
     */
    case LaneInRepository = 'lane_in_repository';

    /**
     * The lane holds fewer other tasks than its capacity, which is one unless the session declared
     * more and its seat allows it (#409).
     *
     * **The value and the refusal text are unchanged from before #409**, so a coordinator or a
     * waiver recorded against `lane_free` means the same thing: at a capacity of one, "fewer than
     * one other task" is "no other task", and once a larger lane is full it does already hold
     * another task.
     */
    case LaneFree = 'lane_free';

    /**
     * The lane's seat is not parked.
     */
    case LaneNotParked = 'lane_not_parked';

    /**
     * The task's issue has no open `blocked_by` edge.
     */
    case TicketUnblocked = 'ticket_unblocked';

    /**
     * The lane's developer takes new placements at this moment.
     */
    case AssignmentHours = 'assignment_hours';

    /**
     * Why a placement breaking this rule was refused, in words a coordinator can act on.
     *
     * @return string The reason.
     */
    public function reads(): string
    {
        return match ($this) {
            self::TicketOpen => 'the ticket is closed, or the fleet has no record of it',
            self::LaneInRepository => "the lane does not work in the ticket's repository",
            self::LaneFree => 'the lane already holds another task',
            self::LaneNotParked => "the lane's seat is parked by its developer",
            self::TicketUnblocked => 'the ticket has an open blocked_by edge',
            self::AssignmentHours => "it is outside the lane's developer's assignment hours",
        };
    }

    /**
     * Every rule's stored value.
     *
     * @return list<string> The values, in declaration order.
     */
    public static function values(): array
    {
        return array_map(static fn (self $rule): string => $rule->value, self::cases());
    }
}
