<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use InvalidArgumentException;

/**
 * The one place a task's GitHub issue is bounded: always repository-qualified, never a bare number.
 *
 * `robot_council_tasks.issue` holds it, and the create endpoint and its tool validate it, so this is
 * the rule both of them and `Support\Tasks::create()` share rather than three copies of one regex.
 *
 * **A bare `#N` is refused, and that is the point of the class.** The same number exists in every
 * tracker the fleet works in -- `robot-council/core#314` and `robot-council/cli#314` are different
 * tickets -- so a task naming `#314` names nothing a reader can resolve, and a board that linked it
 * would invent a repository its writer never chose. #314 records the lane board refusing a bare
 * `#N` for exactly that reason; this is where the refusal starts, at the first write.
 *
 * **The repository half is `WorkIdentity::REPOSITORY`'s shape**, so a repository a session may
 * report as its own is one a task may name. A test holds the two shapes together rather than trusting
 * that nobody edits one of them. **They agree on shape, not on length:** only the whole reference is
 * bounded, by `MAX`, so a repository a few characters over `WorkIdentity::MAX_REPOSITORY` with a
 * short number is accepted here. Bounding the half separately would need the same check at the edge,
 * or a value the edge admits would reach the store and be refused there as a 500.
 *
 * It is charset-limited as well as length-limited because it reaches another developer's agent when
 * that agent may read the task, which is `TaskList`'s rule. It stays behind that rule rather than
 * going into the change feed, which is the difference from `ProjectId`.
 */
final class IssueReference
{
    /**
     * The longest issue number accepted, in digits.
     *
     * Ten digits holds any issue number GitHub has issued, with room to spare. It is stored as part
     * of a string, never parsed into an integer, so no integer width applies.
     */
    public const int MAX_NUMBER_DIGITS = 10;

    /**
     * The longest reference the package stores: a repository, the `#`, and the number.
     */
    public const int MAX = WorkIdentity::MAX_REPOSITORY + 1 + self::MAX_NUMBER_DIGITS;

    /**
     * What a reference may be: `owner/name#N`, with no leading zero on `N`.
     *
     * The repository half is `WorkIdentity::REPOSITORY` with its end anchor moved to the `#`: the
     * name may not be dots alone, so `(?!\.+$)` there is `(?!\.+#)` here. `/D` so a trailing
     * newline cannot slip past `$`.
     */
    public const string PATTERN = '/^(?!\.+\/)[A-Za-z0-9_.][A-Za-z0-9._-]*\/(?!\.+#)[A-Za-z0-9_.][A-Za-z0-9._-]*#[1-9][0-9]{0,9}$/D';

    /**
     * Refuse an issue reference the package will not store.
     *
     * Null is allowed: a task need not be about a GitHub issue at all.
     *
     * @param  mixed  $reference  What the caller is asking to store.
     *
     * @throws InvalidArgumentException When it is present and not a repository-qualified reference.
     */
    public static function ensure(mixed $reference): void
    {
        if ($reference === null) {
            return;
        }

        if (\is_string($reference) && preg_match('/^#?[0-9]+$/D', $reference) === 1) {
            // Named separately because it is the likeliest mistake and the least obvious refusal:
            // a number is exactly what most people write for an issue
            throw new InvalidArgumentException(sprintf(
                'An issue is named as owner/name#N, and "%s" names no repository. The same number exists in every tracker.',
                $reference
            ));
        }

        // Measured in characters, as the `max:` rule the endpoints apply does
        if (! \is_string($reference) || mb_strlen($reference) > self::MAX || preg_match(self::PATTERN, $reference) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'An issue is named as owner/name#N, up to %d characters, and this one is %s.',
                self::MAX,
                \is_string($reference) ? sprintf('%d characters', mb_strlen($reference)) : get_debug_type($reference)
            ));
        }
    }

    /**
     * The reference a title begins with, when it begins with one (#422).
     *
     * Coordinators write a task's title as `owner/name#N: what`, and a task placed without an
     * `issue` still names its ticket there. The title's first token -- everything before the first
     * character a reference cannot contain -- is accepted only if it passes the same pattern and
     * length `ensure()` holds an `issue` to. So a reference that only appears later in the title is
     * not read as the task's ticket, nor is a bare `#N`, which names no repository, nor a token such
     * as `owner/name#12abc` that merely starts like one.
     *
     * @param  string|null  $title  The task's title.
     * @return string|null The reference, or null when the title does not begin with one.
     */
    public static function leading(?string $title): ?string
    {
        if ($title === null || preg_match('/^\s*([A-Za-z0-9._\/#-]+)/', $title, $token) !== 1) {
            return null;
        }

        $candidate = $token[1];

        return mb_strlen($candidate) <= self::MAX && preg_match(self::PATTERN, $candidate) === 1 ? $candidate : null;
    }
}
