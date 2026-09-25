<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Models\AgentSession;

/**
 * Each repository's open-issue count, as sessions report it, against a start-of-day baseline (#339).
 *
 * **A count nobody measured recently is unreadable, never a number.** #314 recorded a board that
 * showed the last number it had after the thing that produced it stopped; the meter says "count
 * unreadable" instead. A reading older than `robot-council.backlog.stale_after_minutes` is the same
 * as no reading.
 *
 * **The baseline is taken at 08:00 on the configured timezone's clock**, from the latest reading at
 * or before that instant, once per repository per local day. It is checked by a scheduled command
 * that runs every few minutes and is idempotent, rather than one scheduled at 08:00: an instant
 * computed each run from the local date is right on both daylight saving days, and a scheduler that
 * missed a minute catches up on its next run instead of skipping a day.
 */
final class Backlog
{
    /**
     * The local time the baseline is taken at.
     */
    public const string BASELINE_AT = '08:00';

    /**
     * The largest count the package stores. Refused beyond it rather than clamped: a count is a
     * measurement, and a clamped one is a different measurement.
     */
    public const int MAX_COUNT = 1_000_000;

    /**
     * How long readings are kept. A baseline needs only the latest before 08:00.
     */
    public const int KEEP_READINGS_DAYS = 2;

    /**
     * @param  Repository  $config  The application's configuration.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * Record a session's count of a repository's open issues, pull requests excluded.
     *
     * @param  AgentSession  $session  The session reporting it.
     * @param  string  $repository  `owner/name`.
     * @param  int  $openIssues  The count.
     *
     * @throws InvalidArgumentException When the repository or the count is outside what is stored.
     */
    public function report(AgentSession $session, string $repository, int $openIssues): void
    {
        if (mb_strlen($repository) > WorkIdentity::MAX_REPOSITORY || preg_match(WorkIdentity::REPOSITORY, $repository) !== 1) {
            throw new InvalidArgumentException('A repository is named as owner/name.');
        }

        if ($openIssues < 0 || $openIssues > self::MAX_COUNT) {
            throw new InvalidArgumentException(sprintf('A count is between 0 and %d.', self::MAX_COUNT));
        }

        DB::table('robot_council_backlog_readings')->insert([
            'repository' => $repository,
            'open_issues' => $openIssues,
            'reported_by' => $session->getKey(),
            'read_at' => PresenceClock::now(),
        ]);
    }

    /**
     * The meter for each repository: its count, the signed delta against today's baseline, and the
     * reading's age.
     *
     * @param  list<string>  $repositories  The repositories to read.
     * @param  DateTimeInterface  $at  The moment to read them at.
     * @return array<string, array{count: int|null, delta: int|null, age_seconds: int|null}> By repository,
     *                                                                                       in the order given.
     */
    public function meters(array $repositories, DateTimeInterface $at): array
    {
        $now = CarbonImmutable::instance($at)->utc();
        $fresh = $now->subMinutes($this->staleAfterMinutes());
        $today = $now->setTimezone($this->timezone())->format('Y-m-d');
        $meters = [];

        foreach ($repositories as $repository) {
            $latest = DB::table('robot_council_backlog_readings')
                ->where('repository', $repository)
                ->where('read_at', '<=', $now)
                ->orderByDesc('read_at')
                ->orderByDesc('id')
                ->first(['open_issues', 'read_at']);

            $readAt = \is_object($latest) && \is_string($latest->read_at ?? null) ? CarbonImmutable::parse($latest->read_at, 'UTC') : null;
            $count = $readAt instanceof CarbonImmutable && $readAt->greaterThanOrEqualTo($fresh) && is_numeric($latest->open_issues ?? null)
                ? (int) $latest->open_issues
                : null;

            $baseline = DB::table('robot_council_backlog_baselines')
                ->where('repository', $repository)
                ->where('day', $today)
                ->value('open_issues');

            $meters[$repository] = [
                'count' => $count,
                'delta' => $count !== null && is_numeric($baseline) ? $count - (int) $baseline : null,
                'age_seconds' => $count !== null ? (int) $readAt->diffInSeconds($now, true) : null,
            ];
        }

        return $meters;
    }

    /**
     * Take today's baseline for every repository that has none yet, once local time is past 08:00.
     *
     * @param  DateTimeInterface  $at  The moment the check runs.
     * @return int How many baselines it took.
     */
    public function takeBaselines(DateTimeInterface $at): int
    {
        $now = CarbonImmutable::instance($at)->utc();
        $local = $now->setTimezone($this->timezone());

        // 08:00 on today's local date, as an instant. Built from the local date each run, so the
        // spring-forward and fall-back days need nothing special: 08:00 exists once on both.
        $instant = CarbonImmutable::parse($local->format('Y-m-d').' '.self::BASELINE_AT, $this->timezone())->utc();

        if ($now->lessThan($instant)) {
            return 0;
        }

        $day = $local->format('Y-m-d');
        $fresh = $instant->subMinutes($this->staleAfterMinutes());
        $taken = 0;

        $repositories = DB::table('robot_council_backlog_readings')->distinct()->pluck('repository');

        foreach ($repositories as $repository) {
            if (! \is_string($repository)) {
                continue;
            }

            // The latest reading at or before 08:00, and only a fresh one: a baseline from a stale
            // reading would be a number nobody measured that morning
            $reading = DB::table('robot_council_backlog_readings')
                ->where('repository', $repository)
                ->whereBetween('read_at', [$fresh, $instant])
                ->orderByDesc('read_at')
                ->orderByDesc('id')
                ->value('open_issues');

            if (! is_numeric($reading)) {
                continue;
            }

            $taken += DB::table('robot_council_backlog_baselines')->insertOrIgnore([
                'repository' => $repository,
                'day' => $day,
                'open_issues' => (int) $reading,
                'taken_at' => $now,
            ]);
        }

        DB::table('robot_council_backlog_readings')->where('read_at', '<', $now->subDays(self::KEEP_READINGS_DAYS))->delete();

        return $taken;
    }

    /**
     * The timezone the baseline is taken in, falling back to UTC rather than failing.
     *
     * @return string The zone.
     */
    private function timezone(): string
    {
        $timezone = $this->config->get('robot-council.dashboard.timezone');

        return AssignmentWindow::isTimezone($timezone) && \is_string($timezone) ? $timezone : 'UTC';
    }

    /**
     * How old a reading may be and still be read.
     *
     * @return int Minutes, at least one.
     */
    private function staleAfterMinutes(): int
    {
        $minutes = $this->config->get('robot-council.backlog.stale_after_minutes', 60);

        return max(1, \is_int($minutes) ? $minutes : 60);
    }
}
