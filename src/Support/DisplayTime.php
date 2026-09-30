<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use IntlTimeZone;
use RobotCouncil\Access\CurrentDeveloper;
use RobotCouncil\Models\AssignmentHours;

/**
 * Times as a person reads them on the dashboard (#487).
 *
 * **Two zones, and each has one job.** The fleet zone, `robot-council.dashboard.timezone`, is when
 * the shared 8 a.m. backlog baseline is taken, so every developer sees the same number for the same
 * repository. Every timestamp a page shows is in the **viewer's** zone -- the one on their assignment
 * hours, else the fleet zone -- and names that zone, so nobody has to convert.
 *
 * **Written the way the maintainer asked**: the 12-hour clock with "a.m." and "p.m.", no ":00" on a
 * whole hour, and "noon" and "midnight" for the two that are neither. A zone is named in words
 * ("Central Time") where the host has `ext-intl`, and by its identifier where it does not.
 * Machine-readable values stay ISO 8601: `iso()` is what goes into a `<time datetime>`.
 */
final class DisplayTime
{
    /**
     * The zone this request's timestamps are shown in, once read.
     */
    private ?string $viewerZone = null;

    /**
     * @param  Repository  $config  The fleet zone.
     * @param  CurrentDeveloper  $developer  Whose hours name the viewer's zone.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly CurrentDeveloper $developer
    ) {}

    /**
     * A clock time, such as "8 a.m.", "2:05 p.m.", "noon" or "midnight".
     *
     * @param  DateTimeInterface  $at  The time, read in its own zone.
     * @return string The words.
     */
    public static function clock(DateTimeInterface $at): string
    {
        $hour = (int) $at->format('G');
        $minute = (int) $at->format('i');

        if ($minute === 0 && $hour === 12) {
            return 'noon';
        }

        if ($minute === 0 && $hour === 0) {
            return 'midnight';
        }

        $twelve = $hour % 12 === 0 ? 12 : $hour % 12;

        return ($minute === 0 ? (string) $twelve : sprintf('%d:%02d', $twelve, $minute)).($hour < 12 ? ' a.m.' : ' p.m.');
    }

    /**
     * A zone in words, such as "Central Time", or its identifier when that cannot be had.
     *
     * @param  string  $zone  An identifier PHP lists.
     * @return string The name.
     */
    public static function zoneName(string $zone): string
    {
        if ($zone === 'UTC') {
            return 'UTC';
        }

        if (class_exists(IntlTimeZone::class)) {
            $name = IntlTimeZone::createTimeZone($zone)->getDisplayName(false, IntlTimeZone::DISPLAY_LONG_GENERIC, 'en');

            // ICU answers a zone it has no generic name for with a GMT offset, which says less than
            // the identifier does
            if ($name !== '' && ! str_starts_with($name, 'GMT')) {
                return $name;
            }
        }

        return $zone;
    }

    /**
     * The fleet zone, which the baseline is taken in, falling back to UTC rather than failing.
     *
     * @return string An identifier PHP lists.
     */
    public function fleetZone(): string
    {
        $zone = $this->config->get('robot-council.dashboard.timezone');

        return AssignmentWindow::isTimezone($zone) && \is_string($zone) ? $zone : 'UTC';
    }

    /**
     * The zone this viewer's timestamps are shown in: their hours' zone, else the fleet zone.
     *
     * @return string An identifier PHP lists.
     */
    public function viewerZone(): string
    {
        if ($this->viewerZone !== null) {
            return $this->viewerZone;
        }

        $key = $this->developer->key();
        $zone = $key === null ? null : AssignmentHours::query()->where('user_id', $key)->value('timezone');

        return $this->viewerZone = AssignmentWindow::isTimezone($zone) && \is_string($zone) ? $zone : $this->fleetZone();
    }

    /**
     * When the backlog baseline is taken, in words: "8 a.m. Central Time".
     *
     * @return string The words.
     */
    public function baseline(): string
    {
        $fleet = $this->fleetZone();

        return self::clock(CarbonImmutable::parse(Backlog::BASELINE_AT, $fleet)).' '.self::zoneName($fleet);
    }

    /**
     * A date and time in the viewer's zone, named: "Sep 30, 2026, 2:05 p.m. Central Time".
     *
     * @param  DateTimeInterface  $instant  The instant.
     * @return string The words.
     */
    public function at(DateTimeInterface $instant): string
    {
        $local = CarbonImmutable::instance($instant)->setTimezone($this->viewerZone());

        return $local->format('M j, Y').', '.self::clock($local).' '.self::zoneName($this->viewerZone());
    }

    /**
     * A time of day in the viewer's zone, named: "2:05 p.m. Central Time".
     *
     * @param  DateTimeInterface  $instant  The instant.
     * @return string The words.
     */
    public function clockAt(DateTimeInterface $instant): string
    {
        return self::clock(CarbonImmutable::instance($instant)->setTimezone($this->viewerZone())).' '.self::zoneName($this->viewerZone());
    }

    /**
     * The instant for a `<time datetime>` attribute, ISO 8601 in UTC.
     *
     * @param  DateTimeInterface  $instant  The instant.
     * @return string The value.
     */
    public static function iso(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
