<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

/**
 * Who put a task in a lane's hands.
 *
 * The lane board shows it beside a working lane, because a coordinator reading the board has to
 * tell work it placed from work a lane took on its own -- the first is the coordinator's to follow
 * up, and the second may not be something the coordinator knows about at all.
 *
 * **Two values, and the two #314 inventoried that are not here are left out on purpose.**
 *
 * - *Never sent* -- a placement the lane was never told about -- cannot happen: a coordinator's
 *   placement and the directive telling the lane are written in one transaction, so there is no
 *   state in which one exists without the other. A value naming it would be a value nothing writes.
 * - *Placed by a developer directly* has no writer. A developer who tells their own agent to take a
 *   task produces a claim, which is indistinguishable from the agent choosing it -- and a hint the
 *   agent sent to say otherwise would be self-asserted and unverifiable. It belongs here once a
 *   developer has a way to place work that the service can see.
 */
enum Placement: string
{
    /**
     * A coordinator handed it over, with a directive telling the lane.
     */
    case Coordinator = 'coordinator';

    /**
     * The lane claimed it itself.
     */
    case Lane = 'lane';
}
