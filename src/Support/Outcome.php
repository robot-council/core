<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * What came of attempting a conditional write, in the answers the API has for one.
 *
 * Shared by every store that decides with a write rather than with a check: tasks and locks answer
 * the same four things, and a second copy of this enum would be a second place for the mapping from
 * outcome to status code to drift. A task alone has a fifth, `Added` (#433).
 *
 * A store returns this rather than throwing, because three of the four are ordinary outcomes of a
 * race rather than errors: two agents claiming one task, a coordinator cancelling while its
 * claimant works, two sessions reaching for one free name. Only the caller knows whether that is
 * worth reporting.
 */
enum Outcome
{
    /**
     * The write changed the row, and an event was recorded.
     */
    case Applied;

    /**
     * No such row.
     */
    case NotFound;

    /**
     * The row exists and is not in a state this write starts from -- including because somebody
     * else got there first.
     */
    case Conflict;

    /**
     * The row exists and is in a state this write could start from, but this session may not.
     */
    case Forbidden;

    /**
     * The row was already where the write leads, and the write added to it without moving it.
     *
     * Only a task answers this: a completion from the session GitHub displaced, which adds its
     * result to a task the webhook already finished (#433). A client must not read it as the task
     * having moved, which is why it is not `Applied`.
     */
    case Added;

    /**
     * The HTTP status this outcome answers with.
     *
     * @return int The status code.
     */
    public function status(): int
    {
        return match ($this) {
            self::Applied, self::Added => Response::HTTP_OK,
            self::NotFound => Response::HTTP_NOT_FOUND,
            self::Conflict => Response::HTTP_CONFLICT,
            self::Forbidden => Response::HTTP_FORBIDDEN,
        };
    }
}
