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
 *
 * **Two moments a year the local clock is not a line, and the window follows the clock.** On the
 * day it springs forward, the skipped hour never happens, so a window lying wholly inside it is
 * closed all that day; on the day it falls back, the repeated hour happens twice, so a window
 * inside it is open both times. Both are what the developer's own wall clock shows, which is the
 * one thing this class promises, and a test pins each.
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
     * How far ahead `nextOpening()` looks, in days.
     *
     * Longer than `DeveloperSettings::MAX_HOLIDAYS` days off in a row plus the weekends around
     * them, so a developer who listed every day they may is still found open again.
     */
    public const int LOOKAHEAD_DAYS = 400;

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
     * The next moment after `$at` that a developer's seats take new placements in (#440).
     *
     * **The first open moment of any stretch is one of a few candidates**: a date's window start;
     * for a window running overnight, the date's own midnight, where the part carried over from the
     * evening before resumes; and, around a clock change, the change itself and the wall times that
     * follow it again. Each is tried, earliest first, and `isOpen()` decides, which is what keeps
     * this and the placement check from disagreeing about a holiday, a weekend, or a clock change.
     *
     * **Clock changes are why the list is not just the start times.** Springing forward, a start
     * inside the skipped hour -- 02:30 in Chicago on 8 March 2026 -- is open from 03:00, the change
     * itself, which no wall time names. Falling back, a start inside the repeated hour happens
     * twice, and parsing a wall time finds only the first, so the second is reached from the change.
     *
     * @param  AssignmentHours|null  $hours  The developer's hours, or null when none are set.
     * @param  list<string>  $holidays  The developer's own days off, as `YYYY-MM-DD`.
     * @param  DateTimeInterface  $at  The moment to look forward from.
     * @return CarbonImmutable|null The moment, in the developer's timezone; null when no hours are
     *                              set, since nothing is closed then, or when nothing opens within
     *                              `LOOKAHEAD_DAYS`.
     */
    public static function nextOpening(?AssignmentHours $hours, array $holidays, DateTimeInterface $at): ?CarbonImmutable
    {
        if (! $hours instanceof AssignmentHours) {
            return null;
        }

        $local = CarbonImmutable::instance($at)->setTimezone($hours->timezone);
        $times = self::minutes($hours->starts_at) > self::minutes($hours->ends_at) && $hours->ends_at !== '00:00'
            ? ['00:00', $hours->starts_at]
            : [$hours->starts_at];

        $candidates = [];

        for ($day = 0; $day <= self::LOOKAHEAD_DAYS; $day++) {
            $date = $local->startOfDay()->addDays($day)->format('Y-m-d');

            foreach ($times as $time) {
                $candidates[] = CarbonImmutable::parse($date.' '.$time, $hours->timezone);
            }
        }

        $horizon = $local->addDays(self::LOOKAHEAD_DAYS + 1);
        // From the day before, because a change earlier today still decides what follows it: from
        // 01:10 on the second pass of a repeated hour, the change was at 01:00
        $transitions = new DateTimeZone($hours->timezone)->getTransitions($local->subDay()->getTimestamp(), $horizon->getTimestamp());

        // The first entry is the state at the start of the range, not a change
        foreach (\array_slice($transitions, 1) as $transition) {
            $change = CarbonImmutable::createFromTimestamp($transition['ts'], $hours->timezone);
            $candidates[] = $change;

            // Each wall time later on the same day, counted on from the change: for a repeated
            // hour this is its second occurrence, which parsing the wall time never finds
            foreach ($times as $time) {
                $ahead = self::minutes($time) - self::minutes($change->format('H:i'));

                if ($ahead > 0) {
                    $candidates[] = $change->addMinutes($ahead);
                }
            }
        }

        usort($candidates, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        foreach ($candidates as $candidate) {
            if ($candidate->greaterThan($local) && self::isOpen($hours, $holidays, $candidate)) {
                return $candidate;
            }
        }

        return null;
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
