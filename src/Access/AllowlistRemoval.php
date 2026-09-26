<?php

declare(strict_types=1);

namespace RobotCouncil\Access;

/**
 * What came of removing an allowlist entry (#406).
 *
 * `FromConfiguration` is its own answer rather than a silent no-op, because an entry that comes
 * from the host's environment is still in effect after the call: a page that reported "removed"
 * or "not listed" would tell an administrator the account had lost access when it had not.
 */
enum AllowlistRemoval
{
    /**
     * The table entry was deleted, and the account loses that list on its next request.
     */
    case Removed;

    /**
     * No table entry, and no environment entry either.
     */
    case NotListed;

    /**
     * The account is on that list through the host's configuration, which only the host can change.
     * Any table entry for it is left in place, since it changes nothing while the environment holds.
     */
    case FromConfiguration;
}
