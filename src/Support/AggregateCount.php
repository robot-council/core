<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * One aggregate column, read as a whole number.
 *
 * **The drivers do not agree on what they hand back, so nothing may assume.** Measured on
 * 2026-09-23: `count(*)` arrives as a PHP int on SQLite, PostgreSQL 17.0 and MySQL 9.4.0, while
 * `sum(...)` arrives as an int on the first two, a **string** on MySQL, which returns DECIMAL, and
 * **null** on all three over an empty set. A query builder types none of that better than `mixed`.
 *
 * Narrowed rather than cast, because a cast turns anything at all into a number and these are the
 * figures a reader uses to tell a truncated list from a complete one. A value that is not numeric
 * is not a count, and zero is the honest answer for the only case that legitimately produces one.
 *
 * It is a class of its own for the reason `HostKey`, `ProjectId` and `MachineIdentity` are: the
 * rule lives in one place rather than being restated by each store that reads an aggregate. It was
 * restated once already, which is what this replaced.
 */
final class AggregateCount
{
    /**
     * The column's value, as a count.
     *
     * @param  mixed  $value  Whatever the driver returned for the column.
     * @return int The value, or zero when the aggregate covered no rows.
     */
    public static function from(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
