<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Seat;

/**
 * How many tasks a coordinator may place on one lane at once (#409, from #386's decision).
 *
 * **Two numbers, and the smaller one is in effect.** A session declares how many tickets it will
 * hold when it joins -- a harness that starts subagents can work several -- and its developer's seat
 * settings cap it. Neither is a role: #386 rejected a `lead` role because capacity is not a
 * permission and has to combine with `build` or `ci`.
 *
 * **Computed on every read, never stored as the effective number.** A seat is recorded only when its
 * developer opens the seats page, from what their live sessions report, so a session joining a new
 * place always finds no seat and would be frozen at one if the cap were applied once at join. Read
 * each time, a developer who raises the cap reaches the running session at once, and one who lowers
 * it stops the next placement at once. A session can never raise it: the seat is written only from
 * the dashboard, by its own developer.
 *
 * **Both numbers default to one, which is exactly the behavior before #409**: `LaneFree` refused any
 * placement on a lane holding another task, and one held task out of a capacity of one is full.
 *
 * **An ordinal, so it clamps rather than refuses**, the pattern `Models\Task`'s `priority` settled
 * (#57): sixteen and seventeen both mean "as many as this seat will ever take", while a title cut
 * short says something else. The endpoint still refuses a number below one, which is no capacity at
 * all rather than a small one.
 */
final class Capacity
{
    /**
     * The capacity a session that declares nothing has, and a seat nobody has set caps at.
     */
    public const int DEFAULT = 1;

    /**
     * The most tickets one lane may be declared, or capped, to hold.
     *
     * A bound the store holds rather than a policy the fleet is asked to follow. Every held task is a
     * row on the lane board and a line an agent's supervisor has to follow; sixteen is past what one
     * harness session realistically supervises in parallel, and low enough that a typo such as 100
     * cannot turn one lane into the whole queue. Raising it is a one-line change here; the columns
     * are `smallint` and hold far more.
     */
    public const int MAX = 16;

    /**
     * @param  Seats  $seats  The seat store, whose cap applies.
     */
    public function __construct(private readonly Seats $seats) {}

    /**
     * One number, held inside the bounds.
     *
     * @param  int  $capacity  What was asked for.
     * @return int It, clamped to `DEFAULT`..`MAX`.
     */
    public static function clamp(int $capacity): int
    {
        return max(self::DEFAULT, min(self::MAX, $capacity));
    }

    /**
     * The capacity in effect for a lane in a seat.
     *
     * @param  AgentSession  $session  The lane.
     * @param  Seat|null  $seat  Its seat, or null where none has been recorded -- which caps at one.
     * @return int The smaller of what it declared and what its seat allows.
     */
    public static function effective(AgentSession $session, ?Seat $seat): int
    {
        return min(
            self::clamp($session->declared_capacity),
            self::clamp($seat instanceof Seat ? $seat->max_capacity : self::DEFAULT)
        );
    }

    /**
     * The capacity in effect for one lane, reading its seat.
     *
     * @param  AgentSession  $session  The lane.
     * @return int The capacity in effect.
     */
    public function of(AgentSession $session): int
    {
        return self::effective($session, $this->seats->of($session));
    }
}
