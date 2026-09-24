<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use InvalidArgumentException;

/**
 * The one place a branch a lane reports is bounded.
 *
 * `robot_council_tasks.branch` holds it, written when a lane takes up a task it was placed on. It
 * is what the lane board shows beside the ticket, so a coordinator can tell a lane that has started
 * from one that has only been told to.
 *
 * **Deliberately narrower than what git allows.** Git accepts a great deal in a ref name, including
 * characters that mean something to a shell or to Markdown. This reaches another developer's agent
 * when that agent may read the task, so it carries the same restricted set as every other
 * agent-facing identifier here -- letters, digits, `.`, `_`, `/` and `-` -- plus the handful of git's
 * own rules that shape could otherwise break: no leading `-`, `.` or `/`, no `..`, no `//`, no
 * component that starts with `.`, no trailing `.` or `/`, and no `.lock` at the end. Every one of
 * those is a name git refuses, so no branch a lane can actually be on is turned away by them.
 *
 * A branch outside this set is refused rather than rewritten, because a rewritten branch name names
 * a different branch.
 */
final class BranchName
{
    /**
     * The longest branch name the package stores.
     */
    public const int MAX = 200;

    /**
     * What a branch name may be. `/D` so a trailing newline cannot slip past `$`.
     */
    public const string PATTERN = '/^(?![-.\/])(?!.*\.\.)(?!.*\/\/)(?!.*\/\.)(?!.*\.lock$)[A-Za-z0-9._\/-]+(?<![.\/])$/D';

    /**
     * Refuse a branch name the package will not store.
     *
     * Null is allowed: a lane that cannot say which branch it is on reports none.
     *
     * @param  mixed  $branch  What the caller is asking to store.
     *
     * @throws InvalidArgumentException When it is present and outside the bound.
     */
    public static function ensure(mixed $branch): void
    {
        if ($branch === null) {
            return;
        }

        // Measured in characters, as the `max:` rule the endpoints apply does
        if (! \is_string($branch) || mb_strlen($branch) > self::MAX || preg_match(self::PATTERN, $branch) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A branch is up to %d characters of [A-Za-z0-9._/-], not starting or ending with a separator, and this one is %s.',
                self::MAX,
                \is_string($branch) ? sprintf('%d characters', mb_strlen($branch)) : get_debug_type($branch)
            ));
        }
    }
}
