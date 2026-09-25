<?php

declare(strict_types=1);

/**
 * Session-reported open-issue counts, and the start-of-day baseline they are measured against (#339).
 *
 * The baseline tests run on both 2026 daylight saving days in Chicago, because a baseline computed
 * once in UTC is an hour wrong on each of them.
 *
 * @command  vendor/bin/pest --compact tests/BacklogTest.php
 */

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Support\Backlog;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242]);

    $this->app?->make('config')->set('robot-council.dashboard.timezone', 'America/Chicago');

    $this->developer = $this->enrollDeveloper(4242);
    [$this->session, $this->token] = $this->startAgentSession($this->approveInstallation($this->developer));
});

/**
 * Record a reading at a moment.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $at  The UTC moment.
 * @param  int  $count  The count.
 * @param  string  $repository  The repository.
 */
function readingAt(TestCase $case, string $at, int $count, string $repository = 'robot-council/core'): void
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));

    $case->service(Backlog::class)->report($case->session, $repository, $count);
}

/**
 * The baseline recorded for a local day.
 *
 * @param  string  $day  `YYYY-MM-DD`.
 * @return int|null The count, or null when none was taken.
 */
function baselineOn(string $day): ?int
{
    $value = DB::table('robot_council_backlog_baselines')->where('repository', 'robot-council/core')->where('day', $day)->value('open_issues');

    return is_numeric($value) ? (int) $value : null;
}

it('records a count through the endpoint with who reported it and when', function (): void {
    Carbon::setTestNow('2026-09-24 15:00:00');

    $this->machine($this->token)
        ->postJson(route('robot-council.backlog.readings'), ['repository' => 'robot-council/core', 'open_issues' => 42])
        ->assertCreated()
        ->assertExactJson(['repository' => 'robot-council/core', 'open_issues' => 42]);

    $row = DB::table('robot_council_backlog_readings')->sole();

    expect($row->open_issues)->toBe(42)
        ->and($row->reported_by)->toBe($this->session->getKey())
        ->and(Carbon::parse(is_string($row->read_at) ? $row->read_at : '')->format('Y-m-d H:i:s'))->toBe('2026-09-24 15:00:00');
});

it('refuses a repository or a count outside what is stored, at the edge and in the store', function (string $repository, int $count): void {
    $this->machine($this->token)
        ->postJson(route('robot-council.backlog.readings'), ['repository' => $repository, 'open_issues' => $count])
        ->assertUnprocessable();

    expect(fn () => $this->service(Backlog::class)->report($this->session, $repository, $count))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('robot_council_backlog_readings')->count())->toBe(0);
})->with([
    'no owner' => ['core', 3],
    'a negative count' => ['robot-council/core', -1],
    'past the ceiling' => ['robot-council/core', Backlog::MAX_COUNT + 1],
]);

it('refuses a trailing newline in the store, which the edge trims before it validates', function (): void {
    expect(fn () => $this->service(Backlog::class)->report($this->session, "robot-council/core\n", 3))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('robot_council_backlog_readings')->count())->toBe(0);
});

it('refuses a session without events:post', function (): void {
    [, $narrow] = $this->startAgentSessionWithAbilities($this->approveInstallation($this->developer, 'box-2'), ['tasks:claim']);

    $this->machine($narrow)
        ->postJson(route('robot-council.backlog.readings'), ['repository' => 'robot-council/core', 'open_issues' => 3])
        ->assertForbidden();

    expect(DB::table('robot_council_backlog_readings')->count())->toBe(0);
});

it('reads an absent count, and a stale one, as unreadable -- never as a number', function (): void {
    $backlog = $this->service(Backlog::class);

    expect($backlog->meters(['robot-council/core'], Carbon::parse('2026-09-24 15:00:00', 'UTC'))['robot-council/core'])
        ->toBe(['count' => null, 'delta' => null, 'age_seconds' => null]);

    readingAt($this, '2026-09-24 13:00:00', 30);

    // Sixty minutes is the default: fresh at 13:59, unreadable at 14:01
    expect($backlog->meters(['robot-council/core'], Carbon::parse('2026-09-24 13:59:00', 'UTC'))['robot-council/core']['count'])->toBe(30)
        ->and($backlog->meters(['robot-council/core'], Carbon::parse('2026-09-24 14:01:00', 'UTC'))['robot-council/core'])
        ->toBe(['count' => null, 'delta' => null, 'age_seconds' => null]);
});

it('takes the baseline at 08:00 on the configured clock, and not before', function (string $day, string $before, string $after): void {
    // 07:55 local, then 08:05 local, on the day under test
    readingAt($this, $before, 10);
    readingAt($this, $after, 12);

    $backlog = $this->service(Backlog::class);
    $eight = Carbon::parse($day.' 08:00', 'America/Chicago')->utc();

    expect($backlog->takeBaselines($eight->copy()->subMinute()))->toBe(0)
        ->and(baselineOn($day))->toBeNull()
        ->and($backlog->takeBaselines($eight->copy()->addMinutes(10)))->toBe(1)
        // The latest reading at or before 08:00, not the one after it
        ->and(baselineOn($day))->toBe(10)
        // And once: a later run takes nothing more
        ->and($backlog->takeBaselines($eight->copy()->addMinutes(20)))->toBe(0);
})->with([
    // 08:00 CDT is 13:00 UTC; 8 March is the spring-forward day
    'the spring-forward day' => ['2026-03-08', '2026-03-08 12:55:00', '2026-03-08 13:05:00'],
    // 08:00 CST is 14:00 UTC; 1 November is the fall-back day
    'the fall-back day' => ['2026-11-01', '2026-11-01 13:55:00', '2026-11-01 14:05:00'],
    'an ordinary day' => ['2026-09-24', '2026-09-24 12:55:00', '2026-09-24 13:05:00'],
]);

it('takes no baseline from a reading that was already stale at 08:00', function (): void {
    readingAt($this, '2026-09-24 10:00:00', 10);

    expect($this->service(Backlog::class)->takeBaselines(Carbon::parse('2026-09-24 13:10:00', 'UTC')))->toBe(0)
        ->and(baselineOn('2026-09-24'))->toBeNull();
});

it('exposes the signed delta against the baseline', function (): void {
    readingAt($this, '2026-09-24 12:55:00', 10);
    $this->service(Backlog::class)->takeBaselines(Carbon::parse('2026-09-24 13:05:00', 'UTC'));

    readingAt($this, '2026-09-24 15:00:00', 7);

    expect($this->service(Backlog::class)->meters(['robot-council/core'], Carbon::parse('2026-09-24 15:30:00', 'UTC'))['robot-council/core'])
        ->toBe(['count' => 7, 'delta' => -3, 'age_seconds' => 1800]);
});

it('schedules the baseline check every five minutes', function (): void {
    $scheduled = collect($this->service(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'robot-council:backlog-baseline'))
        ->values();

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled->first() instanceof ScheduledEvent ? $scheduled->first()->expression : null)->toBe('*/5 * * * *');
});
