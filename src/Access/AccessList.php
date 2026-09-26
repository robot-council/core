<?php

declare(strict_types=1);

namespace RobotCouncil\Access;

/**
 * The two allowlists a GitHub account can be on (#406).
 *
 * Stored as its value in `robot_council_allowlist_entries.list`, so the names are the column's.
 */
enum AccessList: string
{
    /**
     * May sign in and use the dashboard: `ROBOT_COUNCIL_DEVELOPERS`, plus the table.
     */
    case Developer = 'developer';

    /**
     * May also administer: `ROBOT_COUNCIL_ADMINS`, plus the table.
     */
    case Admin = 'admin';

    /**
     * The configuration key that list's environment entries are read from.
     *
     * @return string The key.
     */
    public function configKey(): string
    {
        return match ($this) {
            self::Developer => 'robot-council.access.developers',
            self::Admin => 'robot-council.access.admins',
        };
    }

    /**
     * The environment variable a host sets that list with, for a refusal to name.
     *
     * @return string The variable.
     */
    public function variable(): string
    {
        return match ($this) {
            self::Developer => 'ROBOT_COUNCIL_DEVELOPERS',
            self::Admin => 'ROBOT_COUNCIL_ADMINS',
        };
    }
}
