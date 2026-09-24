<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RobotCouncil\Models\AssignmentHours;

/**
 * The rules a developer's assignment hours are held to, and whether a moment falls inside them.
 *
 * **Every question is answered in the developer's own timezone**, never the application's or the
 * fleet's. A window of 09:00 to 17:00 means those hours on the developer's clock, so an instant is
 * converted to that zone before anything is compared -- which is also what makes daylight saving
 * come out right, since the window was never converted in the first place.
 *
 * **A holiday is a calendar date on that same clock.** The moment is converted first and its date
 * read afterwards, so a holiday in Auckland starts at Auckland's midnight, not UTC's.
 *
 * **A window whose end comes before its start runs overnight**: 22:00 to 06:00 is open late in the
 * evening and early the next morning. Weekends and holidays are judged by the date the moment
 * falls on, so the part of a Friday-night window that runs past midnight is closed when weekends
 * are skipped. A window whose start and end are equal is refused, because it could mean an empty
 * window or a whole day and nothing says which.
 */
final class AssignmentWindow
{
    /**
     * What a time of day may be: `HH:MM` on a 24-hour clock. `/D` so a trailing newline cannot
     * slip past `$`.
     */
    public const string TIME = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/D';

    /**
     * The longest timezone name the package stores.
     */
    public const int MAX_TIMEZONE = 64;

    /**
     * Refuse hours the package will not store.
     *
     * @param  mixed  $timezone  An IANA zone name, such as `America/Chicago`.
     * @param  mixed  $startsAt  The start of the window, as `HH:MM`.
     * @param  mixed  $endsAt  The end of the window, as `HH:MM`.
     *
     * @throws InvalidArgumentException When any of them is not something this class can answer for.
     */
    public static function ensure(mixed $timezone, mixed $startsAt, mixed $endsAt): void
    {
        if (! self::isTimezone($timezone)) {
            throw new InvalidArgumentException('A timezone is an IANA zone name, such as America/Chicago.');
        }

        if (! \is_string($startsAt) || preg_match(self::TIME, $startsAt) !== 1
            || ! \is_string($endsAt) || preg_match(self::TIME, $endsAt) !== 1) {
            throw new InvalidArgumentException('A window starts and ends at a time written as HH:MM on a 24-hour clock.');
        }

        if ($startsAt === $endsAt) {
            throw new InvalidArgumentException('A window cannot start and end at the same time: that could mean no hours or all of them.');
        }
    }

    /**
     * Whether a value is a timezone this class can convert into.
     *
     * The list PHP itself knows, rather than whatever `new DateTimeZone()` accepts: that also takes
     * offsets like `+05:00` and abbreviations like `EST`, neither of which observes daylight
     * saving, so a developer who typed one would find their window an hour off for half the year.
     *
     * @param  mixed  $timezone  The value to check.
     * @return bool True for a zone name PHP lists.
     */
    public static function isTimezone(mixed $timezone): bool
    {
        return \is_string($timezone)
            && mb_strlen($timezone) <= self::MAX_TIMEZONE
            && \in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Whether a value is a real calendar date written as `YYYY-MM-DD`.
     *
     * Parsed and written back out rather than matched against a pattern, because a pattern admits
     * `2026-02-30` and PHP's parser rolls that over to March rather than refusing it.
     *
     * @param  mixed  $day  The value to check.
     * @return bool True for a date that exists.
     */
    public static function isDay(mixed $day): bool
    {
        if (! \is_string($day) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $day) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $day;
    }

    /**
     * Whether a moment is one a developer's seats take new placements in.
     *
     * @param  AssignmentHours|null  $hours  The developer's hours, or null when none are set.
     * @param  list<string>  $holidays  The developer's own days off, as `YYYY-MM-DD`.
     * @param  DateTimeInterface  $at  The moment to ask about.
     * @return bool False on a holiday, on a skipped weekend, or outside the window.
     */
    public static function isOpen(?AssignmentHours $hours, array $holidays, DateTimeInterface $at): bool
    {
        // **No hours means nothing is gated, holidays included.** A holiday is a date, and a date
        // needs a timezone to say when it starts; reading it in UTC would open or close it hours
        // away from the developer's own midnight. The hours carry the zone, so the days off apply
        // once hours are set, and the settings page says so beside the list.
        if (! $hours instanceof AssignmentHours) {
            return true;
        }

        $local = CarbonImmutable::instance($at)->setTimezone($hours->timezone);

        if (\in_array($local->format('Y-m-d'), $holidays, true)) {
            return false;
        }

        if ($hours->skip_weekends && $local->isWeekend()) {
            return false;
        }

        $minute = self::minutes($local->format('H:i'));
        $start = self::minutes($hours->starts_at);
        $end = self::minutes($hours->ends_at);

        return $start < $end
            ? $minute >= $start && $minute < $end
            : $minute >= $start || $minute < $end;
    }

    /**
     * Minutes since midnight for an `HH:MM` time.
     *
     * @param  string  $time  The time, already known to match `TIME`.
     * @return int The minutes.
     */
    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
