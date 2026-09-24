<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

/**
 * Who or what a held lane is waiting on (#334).
 */
enum HoldParty: string
{
    /**
     * A developer, named by GitHub login.
     */
    case Developer = 'developer';

    /**
     * A ticket, named as `owner/name#N`.
     */
    case Ticket = 'ticket';
}
