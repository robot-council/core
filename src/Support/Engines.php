<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * Which database drivers need a collation stated because their default compares loosely.
 *
 * **MariaDB is a separate driver in Laravel, and asking for `mysql` alone silently excludes it.**
 * `ConnectionFactory` returns a `MariaDbConnection` for a host configured with `DB_CONNECTION=mariadb`
 * and `DatabaseManager::supportedDrivers()` lists `mariadb` beside `mysql`, so `getDriverName()`
 * answers `mariadb` there. Four places asked `!== 'mysql'` or `=== 'mysql'` and each therefore did
 * nothing on such a host (#257).
 *
 * What that cost, on a MariaDB host:
 *
 * - **Two developers whose host keys differ only in case were one developer.** `2026_09_22_000002`
 *   exists to stop exactly that, and returned early.
 * - **Two lock names differing only in case were one lock.** `robot_council_locks.name` never got
 *   its binary collation, so `branch:Main` and `branch:main` collide -- two agents each believing
 *   they hold a lease on a different thing.
 *
 * Measured on **MariaDB 11.8.9**: `collation_server` is `utf8mb4_uca1400_ai_ci`, which is accent-
 * and case-insensitive, so the loose default is the shipped one rather than a configuration nobody
 * would choose.
 *
 * **A host pointing `DB_CONNECTION=mysql` at a MariaDB server was never affected**, and that is
 * worth knowing before reading a green run as evidence: Laravel takes the driver from the
 * connection's configuration rather than from the server, so such a host reports `mysql` and runs
 * every guarded statement. Confirmed by connecting to one MariaDB server both ways.
 *
 * This is one function rather than a copy per call site because the copies had already drifted:
 * `tests/Pest.php`'s `notMySqlFamily()` documented "MySQL or MariaDB" while its code asked about
 * `mysql` alone, which is how #257 was found.
 */
final class Engines
{
    /**
     * The drivers whose default collation compares case- and accent-insensitively.
     *
     * @var list<string>
     */
    private const array LOOSE_BY_DEFAULT = ['mysql', 'mariadb'];

    /**
     * Whether a column on this driver needs an explicit binary collation to compare byte-exactly.
     *
     * Postgres and SQLite compare bytes already and accept neither engine's collation names, so the
     * answer for them is no rather than "not yet decided".
     *
     * @param  string  $driver  The driver name, as `Connection::getDriverName()` reports it.
     * @return bool Whether the caller has to state a collation.
     */
    public static function needsBinaryCollation(string $driver): bool
    {
        return in_array($driver, self::LOOSE_BY_DEFAULT, true);
    }
}
