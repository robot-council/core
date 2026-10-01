<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use InvalidArgumentException;

/**
 * The one place the two strings a machine calls itself are bounded.
 *
 * `harness` and `machine_label` are written by `DeviceCodes::issue()` and copied forward by
 * `Installations::createFrom()`, and before this they were bounded only by `DeviceCodeController`.
 *
 * **They are bounded because they reach other developers' agents**, which is why `CLAUDE.md` names
 * them beside `project_id`: `AgentSessions::start()` writes `sprintf('%s on %s started a session.',
 * $harness, $machineLabel)` into the change feed, and `FleetFeed` serves that body to every session
 * in the fleet. Event content is untrusted input to something that may have shell access, so what
 * crosses that boundary is charset-limited rather than merely length-limited.
 *
 * The length half matters separately, and `CLAUDE.md` records the measurement: a 34-character write
 * into `robot_council_device_codes.harness`, which is `varchar(32)`, passed every local SQLite run
 * and failed only CI's `postgres` job. SQLite stores an over-long value whole, Postgres refuses it,
 * and MySQL refuses or truncates depending on strict mode -- one call, three outcomes.
 */
final class MachineIdentity
{
    /**
     * The longest harness name the package stores.
     */
    public const int MAX_HARNESS = 32;

    /**
     * The longest machine label the package stores.
     */
    public const int MAX_LABEL = 64;

    /**
     * What a harness name may contain.
     *
     * Lower case, because a harness names a piece of software rather than a person's machine, and
     * two spellings of one harness would read as two harnesses across the fleet.
     *
     * `+` rather than `{1,32}`, so the length lives in `MAX_HARNESS` alone. Baked into both, raising
     * the constant would leave the old bound quietly in force, because the regex runs second in the
     * same condition. `Locks::NAME` has this shape for the same reason.
     */
    public const string HARNESS = '/^[a-z0-9-]+$/D';

    /**
     * What a machine label may contain.
     *
     * Wider than `HARNESS`: a machine label is what a person calls their laptop, so it keeps case,
     * `.` and `_`. Length lives in `MAX_LABEL` alone, as above.
     */
    public const string LABEL = '/^[A-Za-z0-9._-]+$/D';

    /**
     * Refuse a harness or label the package will not store.
     *
     * @param  string  $harness  What the machine calls its agent software.
     * @param  string  $machineLabel  What the machine calls itself.
     *
     * @throws InvalidArgumentException When either is outside its bound.
     */
    public static function ensure(string $harness, string $machineLabel): void
    {
        // Characters, not bytes: the unit every bound in this package uses, because that is what
        // Laravel's `max:` rule measures and what Postgres and MySQL count a `varchar` in
        if ($harness === '' || mb_strlen($harness) > self::MAX_HARNESS || preg_match(self::HARNESS, $harness) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A harness is 1 to %d characters of [a-z0-9-], and this one is %d.',
                self::MAX_HARNESS,
                mb_strlen($harness)
            ));
        }

        self::ensureLabel($machineLabel);
    }

    /**
     * Refuse a machine label the package will not store.
     *
     * Its own entry point because a rename (#534) changes the label alone, and it has to refuse
     * exactly what enrollment refuses, with the same reason. One check called from both places
     * rather than a copy of it, so the two cannot drift.
     *
     * @param  string  $machineLabel  What the machine calls itself.
     *
     * @throws InvalidArgumentException When it is outside its bound.
     */
    public static function ensureLabel(string $machineLabel): void
    {
        if ($machineLabel === '' || mb_strlen($machineLabel) > self::MAX_LABEL || preg_match(self::LABEL, $machineLabel) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A machine label is 1 to %d characters of [A-Za-z0-9._-], and this one is %d.',
                self::MAX_LABEL,
                mb_strlen($machineLabel)
            ));
        }
    }
}
