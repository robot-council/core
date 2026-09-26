<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * Where a seat stands against its developer's assignment hours at one moment (#440).
 *
 * One answer for two readers: `PlacementRules` refuses a new placement on `Outside`, and the
 * `developer_settings` tool and `GET /api/developers/settings` report it, so what a coordinator
 * reads and what a placement is refused for come from the same code and cannot disagree.
 */
enum HoursStanding
{
    /**
     * The developer's hours are set and the moment falls inside them.
     */
    case Inside;

    /**
     * The developer's hours are set and the moment falls outside them: a new placement is refused.
     */
    case Outside;

    /**
     * Nothing gates the seat: its developer set no hours, or the seat is exempt from them.
     */
    case Ungated;

    /**
     * The value the API reports: `true`, `false`, or `"ungated"`.
     *
     * @return bool|string The reported value.
     */
    public function reported(): bool|string
    {
        return match ($this) {
            self::Inside => true,
            self::Outside => false,
            self::Ungated => 'ungated',
        };
    }
}
