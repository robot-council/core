<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RuntimeException;

/**
 * The one place a host application's user key is narrowed.
 *
 * The package stores that key as a string, so an integer, a UUID, and a ULID host all work: every
 * table holding it has one row per developer, or per installation, or per running process, so none
 * of them is large enough for the usual argument against a string key to apply.
 *
 * Narrowing happens here rather than at each call site because the cast that must never happen is
 * `(int)`. A UUID beginning with digits casts to a plausible-looking number instead of failing, so
 * a stray cast would not announce itself -- it would quietly file a developer under somebody else's
 * key.
 */
final class HostKey
{
    /**
     * The longest host user key the package stores.
     *
     * Every column holding one is `varchar(64)`: `installations.user_id` and `.approved_by`,
     * `agent_sessions.user_id`, `tasks.user_id`, `github_identities.user_id`,
     * `device_codes.decided_by`, `events.user_id` and `.actor_user_id`, `event_addressees.user_id`,
     * `seats.user_id` and `.parked_by`, `assignment_hours.user_id`, `holidays.user_id`, and
     * `placement_waivers.granted_by`. Fourteen of them, and
     * `tests/HostKeyComparisonTest.php`'s `hostKeyColumns()` is the list that has to stay closed --
     * this enumeration is prose and that one is a check. A key longer than this cannot be stored,
     * and **truncating it would be worse than refusing it** -- two developers whose keys share a
     * 64-character prefix would collapse into one, which is an access-control failure rather than
     * a storage one.
     */
    public const int MAX = 64;

    /**
     * A host user key as the package stores it.
     *
     * @param  mixed  $key  Whatever the host's model or guard returned.
     * @return string The key as text.
     *
     * @throws RuntimeException When the key is neither an integer nor a non-empty string, or is
     *                          longer than the columns that hold it.
     */
    public static function from(mixed $key): string
    {
        $value = self::tryFrom($key);

        if ($value === null) {
            throw new RuntimeException(sprintf(
                'robot-council stores a host user key as up to %d characters of text, and this one is %s.',
                self::MAX,
                \is_string($key) ? sprintf('%d characters', mb_strlen($key)) : get_debug_type($key)
            ));
        }

        return $value;
    }

    /**
     * A host user key, or null when there is nothing usable to store.
     *
     * @param  mixed  $key  Whatever the host's model or guard returned.
     * @return string|null The key as text, or null.
     */
    public static function tryFrom(mixed $key): ?string
    {
        $value = \is_int($key) ? (string) $key : $key;

        if (! \is_string($value) || $value === '') {
            return null;
        }

        // Characters, as every other bound in this package is measured. A key past this length has
        // no column to live in, and the caller is told rather than handed a truncated one.
        return mb_strlen($value) > self::MAX ? null : $value;
    }
}
