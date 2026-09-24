<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Models\AssignmentHours;

/**
 * One developer's assignment hours and days off.
 *
 * **Every write takes the developer it writes for, and nothing else chooses them.** The dashboard
 * passes the key it read off the package's web guard, so a developer edits only their own; no
 * machine route reaches a method here, which is how #314's rule -- the coordinator reads these and
 * never writes them -- holds by construction rather than by a check somebody has to remember.
 *
 * Each value is bounded here as well as on the page, because this is a public method on a `final`
 * class a host can resolve and call, and a rule on a form protects the form and nothing else.
 */
final class DeveloperSettings
{
    /**
     * The most days off one developer may list.
     *
     * A ceiling on what one account can make the package store, not a calendar rule: a generous
     * year of public holidays is a dozen or two, so ten years' worth fits with room to spare.
     */
    public const int MAX_HOLIDAYS = 200;

    /**
     * Set a developer's assignment hours, replacing any they had.
     *
     * @param  string  $developer  The developer's host user key.
     * @param  string  $timezone  An IANA zone name.
     * @param  string  $startsAt  The start of the window, `HH:MM`, local to the zone.
     * @param  string  $endsAt  The end of the window, `HH:MM`, local to the zone.
     * @param  bool  $skipWeekends  Whether Saturday and Sunday take no new placements.
     *
     * @throws InvalidArgumentException When a value is outside what `AssignmentWindow` admits.
     */
    public function setHours(string $developer, string $timezone, string $startsAt, string $endsAt, bool $skipWeekends): void
    {
        $developer = HostKey::from($developer);

        AssignmentWindow::ensure($timezone, $startsAt, $endsAt);

        $now = PresenceClock::now();

        AssignmentHours::query()->upsert(
            [[
                'user_id' => $developer,
                'timezone' => $timezone,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'skip_weekends' => $skipWeekends,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id'],
            ['timezone', 'starts_at', 'ends_at', 'skip_weekends', 'updated_at']
        );
    }

    /**
     * Remove a developer's assignment hours, so nothing gates their seats.
     *
     * @param  string  $developer  The developer's host user key.
     */
    public function clearHours(string $developer): void
    {
        AssignmentHours::query()->whereKey(HostKey::from($developer))->delete();
    }

    /**
     * A developer's assignment hours.
     *
     * @param  string  $developer  The developer's host user key.
     * @return AssignmentHours|null Their hours, or null when none are set.
     */
    public function hours(string $developer): ?AssignmentHours
    {
        return AssignmentHours::query()->find(HostKey::from($developer));
    }

    /**
     * Add a day off.
     *
     * **The ceiling is checked by a count taken before the insert**, so two additions at once can
     * pass it by one. It bounds storage, and a developer racing themselves past it by one day is
     * not a state anything relies on being impossible.
     *
     * @param  string  $developer  The developer's host user key.
     * @param  string  $day  The date, `YYYY-MM-DD`, in the developer's own timezone.
     * @return bool True when it was added, false when it was already listed.
     *
     * @throws InvalidArgumentException When the day is not a real date, or the list is full.
     */
    public function addHoliday(string $developer, string $day): bool
    {
        $developer = HostKey::from($developer);

        if (! AssignmentWindow::isDay($day)) {
            throw new InvalidArgumentException('A day off is a real date written as YYYY-MM-DD.');
        }

        if (DB::table('robot_council_holidays')->where('user_id', $developer)->count() >= self::MAX_HOLIDAYS) {
            throw new InvalidArgumentException(sprintf('A developer lists at most %d days off. Remove a past one first.', self::MAX_HOLIDAYS));
        }

        return DB::table('robot_council_holidays')->insertOrIgnore(['user_id' => $developer, 'day' => $day]) === 1;
    }

    /**
     * Remove a day off.
     *
     * @param  string  $developer  The developer's host user key.
     * @param  string  $day  The date, `YYYY-MM-DD`.
     * @return bool True when it was listed.
     */
    public function removeHoliday(string $developer, string $day): bool
    {
        // Checked before the query, not left to it: the page hands this whatever a client sends,
        // and Postgres casts the value to a `date` and fails with a 500 (22007, or 22008 for
        // `2026-02-30`) where SQLite and MySQL simply match nothing
        if (! AssignmentWindow::isDay($day)) {
            return false;
        }

        return DB::table('robot_council_holidays')
            ->where('user_id', HostKey::from($developer))
            ->where('day', $day)
            ->delete() === 1;
    }

    /**
     * A developer's days off, earliest first.
     *
     * @param  string  $developer  The developer's host user key.
     * @return list<string> The dates, `YYYY-MM-DD`.
     */
    public function holidays(string $developer): array
    {
        return $this->days(DB::table('robot_council_holidays')
            ->where('user_id', HostKey::from($developer))
            ->orderBy('day')
            ->pluck('day')
            ->all());
    }

    /**
     * Whether a developer's seats take new placements at a moment.
     *
     * This is the question #320 asks at placement, answered in the developer's own timezone.
     * A seat exempted from the hours skips it; that is the seat's setting, and `Support\Seats`
     * holds it.
     *
     * @param  string  $developer  The developer's host user key.
     * @param  DateTimeInterface  $at  The moment to ask about.
     * @return bool False on a listed day off, a skipped weekend, or outside the window.
     */
    public function takesNewWorkAt(string $developer, DateTimeInterface $at): bool
    {
        return AssignmentWindow::isOpen($this->hours($developer), $this->holidays($developer), $at);
    }

    /**
     * Every developer who has set anything, for the coordinator to read.
     *
     * **A list carrying each key as a value, never an array keyed by it.** PHP turns a numeric
     * string key into an integer, so `'5102'` comes back out as `5102` -- and an integer host key
     * is the exact value #247 found matching every numerically equal row on MySQL. It was measured
     * here first: keyed that way, `AgentLogins::forUsers()` resolved no login for the key at all.
     *
     * @return list<array{user_id: string, hours: AssignmentHours|null, holidays: list<string>}> In
     *                                                                                           key order.
     */
    public function everyone(): array
    {
        $hours = AssignmentHours::query()->get()->keyBy(static fn (AssignmentHours $row): string => 'k'.$row->user_id);

        $holidays = [];

        foreach (DB::table('robot_council_holidays')->orderBy('day')->get(['user_id', 'day']) as $row) {
            $holidays['k'.HostKey::from($row->user_id)][] = $row->day;
        }

        $keys = array_unique([...$hours->keys()->all(), ...array_keys($holidays)]);
        sort($keys, SORT_STRING);

        return array_map(fn (string $prefixed): array => [
            'user_id' => substr($prefixed, 1),
            'hours' => $hours->get($prefixed),
            'holidays' => $this->days($holidays[$prefixed] ?? []),
        ], $keys);
    }

    /**
     * Dates as `YYYY-MM-DD`, whatever shape the driver returned them in.
     *
     * A `date` column reads back as `2026-12-25` on SQLite and Postgres and may carry a time on a
     * driver configured otherwise, so the first ten characters are what is kept.
     *
     * @param  array<mixed>  $values  What the query returned.
     * @return list<string> The dates.
     */
    private function days(array $values): array
    {
        $days = [];

        foreach ($values as $value) {
            if (\is_string($value)) {
                $days[] = substr($value, 0, 10);
            }
        }

        return $days;
    }
}
