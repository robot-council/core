<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use InvalidArgumentException;

/**
 * The one place a held task's sub-label is bounded (#409).
 *
 * A lane that works through subagents holds several tasks at once, and every claim, lock and
 * narration stays the session's, since subagents do not join. The sub-label is how the lane tells two
 * of its held tasks apart for a reader -- the worktree a subagent is working in, say. **It is display
 * only**: nothing reads it to decide anything, and #386 decided against the same label on locks,
 * where it would suggest an isolation nothing enforces.
 *
 * **Bounded like `machine_label`, because it reaches every agent in the fleet**: `task.*` events
 * carry it in their `meta`, and `FleetFeed` serves state changes to every session. Event content is
 * untrusted input to something that may have shell access, so it is charset-limited rather than
 * merely length-limited. **One step narrower than `machine_label`**: it must start with a letter or
 * digit, so `.`, `..` and `-rf` are refused -- a label whose example is a worktree invites a consumer
 * to join it into a path or hand it to a command, the reason `WorkIdentity::LOCATION` has the same
 * rule.
 *
 * Refused rather than rewritten, because a rewritten label names something else.
 */
final class SubLabel
{
    /**
     * The longest sub-label the package stores, in characters: `robot_council_tasks.sub_label`'s width.
     */
    public const int MAX = 64;

    /**
     * What a sub-label may contain. Length lives in `MAX` alone, for the reason
     * `MachineIdentity::HARNESS` gives; `/D` so a trailing newline cannot slip past `$`.
     */
    public const string PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D';

    /**
     * Refuse a sub-label the package will not store.
     *
     * Null is allowed, and means the caller named none.
     *
     * @param  mixed  $label  What the caller is asking to store.
     *
     * @throws InvalidArgumentException When it is present and outside the bound.
     */
    public static function ensure(mixed $label): void
    {
        if ($label === null) {
            return;
        }

        // Characters, the unit every bound here uses: what Laravel's `max:` rule measures
        if (! \is_string($label) || mb_strlen($label) > self::MAX || preg_match(self::PATTERN, $label) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A sub-label is 1 to %d characters of [A-Za-z0-9._-], starting with a letter or digit, and this one is %s.',
                self::MAX,
                \is_string($label) ? sprintf('%d characters', mb_strlen($label)) : get_debug_type($label)
            ));
        }
    }
}
