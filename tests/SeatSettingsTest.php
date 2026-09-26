<?php

declare(strict_types=1);

/**
 * A developer's own parked seats, assignment hours and days off (#322).
 *
 * The acceptance criteria are about WHO may write, so most of these run two developers and a
 * coordinator side by side and assert on the row afterwards: a refusal proved on the returned
 * outcome alone would pass against a store that refused and wrote anyway.
 *
 * @command  vendor/bin/pest --compact tests/SeatSettingsTest.php
 */

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RobotCouncil\Livewire\SeatSettings;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AssignmentHours;
use RobotCouncil\Models\Installation;
use RobotCouncil\Models\Seat;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\AssignmentWindow;
use RobotCouncil\Support\DeveloperSettings;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\Seats;
use RobotCouncil\Tests\Fixtures\HostileContent;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [5101, 5102]);

    $this->alice = $this->enrollDeveloper(5101, login: 'alice-dev');
    $this->bob = $this->enrollDeveloper(5102, login: 'bob-dev');
});

/**
 * A developer's installation with a live session working in one place.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  User  $developer  Whose machine it is.
 * @param  string  $repository  Where the session says it is working.
 * @param  string|null  $location  Its work location, if any.
 * @return array{Installation, AgentSession} The installation and its session.
 */
function seatedSession(TestCase $case, User $developer, string $repository = 'robot-council/core', ?string $location = 'a'): array
{
    $installation = $case->approveInstallation($developer, 'office-mac');

    $session = $case->service(AgentSessions::class)->start($installation, $repository, $location)->owner;

    return [$installation->refresh(), $session];
}

/**
 * A developer's host user key, as the package reads it off the guard.
 *
 * @param  User  $developer  The developer.
 * @return string The key.
 */
function keyOf(User $developer): string
{
    return HostKey::from($developer->getAuthIdentifier());
}

/**
 * The one seat a developer has, recorded the way the settings page records it.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  Installation  $installation  The installation whose owner is asking.
 * @return Seat The seat.
 */
function onlySeatOf(TestCase $case, Installation $installation): Seat
{
    $seats = $case->service(Seats::class)->forDeveloper($installation->user_id);

    expect($seats)->toHaveCount(1);

    return $seats[0];
}

/**
 * The seat's row, read fresh.
 *
 * @param  Seat  $seat  The seat.
 * @return Seat The row as it stands.
 */
function seatRow(Seat $seat): Seat
{
    $row = Seat::query()->find($seat->id);

    expect($row)->toBeInstanceOf(Seat::class);

    return $row instanceof Seat ? $row : $seat;
}

/**
 * Hours for a developer, stored and read back.
 *
 * @param  string  $timezone  The zone.
 * @param  string  $from  The start, `HH:MM`.
 * @param  string  $until  The end, `HH:MM`.
 * @param  bool  $skipWeekends  Whether weekends are closed.
 * @return AssignmentHours An unsaved model carrying them, for `AssignmentWindow` to read.
 */
function hoursOf(string $timezone, string $from, string $until, bool $skipWeekends = true): AssignmentHours
{
    $hours = new AssignmentHours;
    $hours->timezone = $timezone;
    $hours->starts_at = $from;
    $hours->ends_at = $until;
    $hours->skip_weekends = $skipWeekends;

    return $hours;
}

// --- Seats ---------------------------------------------------------------------------------------

it('records a seat for the place a live session reports, and a second read records nothing new', function (): void {
    [$installation] = seatedSession($this, $this->alice);

    $seat = onlySeatOf($this, $installation);

    expect($seat->user_id)->toBe($installation->user_id)
        ->and($seat->installation_id)->toBe($installation->id)
        ->and($seat->repository)->toBe('robot-council/core')
        ->and($seat->work_location)->toBe('a')
        ->and($seat->isParked())->toBeFalse();

    onlySeatOf($this, $installation);

    expect(Seat::query()->count())->toBe(1);
});

it('keys a seat on the machine and place, so a new session in the same place is the same seat', function (): void {
    [$installation, $first] = seatedSession($this, $this->alice);
    $seat = onlySeatOf($this, $installation);

    $this->service(Seats::class)->park($installation->user_id, $seat->id);
    $this->markSessionGone($first);

    // The process restarts: a new session, in the same checkout on the same machine
    $this->service(AgentSessions::class)->start($installation, 'robot-council/core', 'a');

    expect(onlySeatOf($this, $installation)->id)->toBe($seat->id)
        ->and(seatRow($seat)->isParked())->toBeTrue();
});

it('records a seat with no work location as the empty string, so it cannot be recorded twice', function (): void {
    $installation = $this->approveInstallation($this->alice)->refresh();
    $this->service(AgentSessions::class)->start($installation, 'robot-council/core');
    $this->service(AgentSessions::class)->start($installation, 'robot-council/core');

    expect(onlySeatOf($this, $installation)->work_location)->toBeEmpty();
});

it("records no seat for a gone session, another developer's session, a revoked machine, or no repository", function (): void {
    [$installation, $session] = seatedSession($this, $this->alice);
    $this->markSessionGone($session);

    [$bobs] = seatedSession($this, $this->bob, location: 'b');

    $revoked = $this->approveInstallation($this->alice, 'old-laptop');
    $this->service(AgentSessions::class)->start($revoked, 'robot-council/cli', 'c');
    $revoked->forceFill(['revoked_at' => Carbon::now()])->save();

    $this->service(AgentSessions::class)->start($installation, null, 'd');

    expect($this->service(Seats::class)->forDeveloper($installation->user_id))->toBeEmpty()
        ->and(Seat::query()->where('user_id', $installation->user_id)->count())->toBe(0)
        // And the other developer's session made a seat for them, not for Alice
        ->and(onlySeatOf($this, $bobs)->user_id)->toBe($bobs->user_id);
});

it('finds the seat a session sits in, which is what the board and a placement read', function (): void {
    [$installation, $session] = seatedSession($this, $this->alice);
    $seat = onlySeatOf($this, $installation);

    $this->service(Seats::class)->park(keyOf($this->alice), $seat->id);

    $elsewhere = $this->service(AgentSessions::class)->start($installation, 'robot-council/core', 'b')->owner;
    $noRepository = $this->service(AgentSessions::class)->start($installation, null, 'a')->owner;
    $restarted = $this->service(AgentSessions::class)->start($installation, 'robot-council/core', 'a')->owner;

    expect($this->service(Seats::class)->of($session)?->id)->toBe($seat->id)
        ->and($this->service(Seats::class)->of($restarted)?->isParked())->toBeTrue()
        // Another work location on the same machine is another seat, not yet recorded
        ->and($this->service(Seats::class)->of($elsewhere))->toBeNull()
        ->and($this->service(Seats::class)->of($noRepository))->toBeNull();
});

it('parks and lifts a seat for its own developer, recording who parked it and when', function (): void {
    [$installation] = seatedSession($this, $this->alice);
    $seat = onlySeatOf($this, $installation);

    Carbon::setTestNow('2026-09-24 15:04:05');

    expect($this->service(Seats::class)->park($installation->user_id, $seat->id))->toBe(Outcome::Applied);

    $row = seatRow($seat);

    expect($row->parked_by)->toBe($installation->user_id)
        ->and($row->parked_at?->toIso8601String())->toBe('2026-09-24T15:04:05+00:00')
        ->and($this->service(Seats::class)->lift($installation->user_id, $seat->id))->toBe(Outcome::Applied);

    $row = seatRow($seat);

    expect($row->parked_by)->toBeNull()
        ->and($row->parked_at)->toBeNull();
});

it('refuses another developer lifting a seat, and leaves it parked', function (): void {
    [$installation] = seatedSession($this, $this->alice);
    [$bobs] = seatedSession($this, $this->bob, location: 'b');
    $seat = onlySeatOf($this, $installation);

    $this->service(Seats::class)->park($installation->user_id, $seat->id);

    expect($this->service(Seats::class)->lift($bobs->user_id, $seat->id))->toBe(Outcome::Forbidden)
        ->and(seatRow($seat)->parked_by)->toBe($installation->user_id);

    // And a free seat of hers is still not his to act on: he is told he may not, not that it is free
    $this->service(Seats::class)->lift($installation->user_id, $seat->id);

    expect($this->service(Seats::class)->lift($bobs->user_id, $seat->id))->toBe(Outcome::Forbidden);
});

it("refuses a developer parking another developer's seat, and leaves it free", function (): void {
    [$installation] = seatedSession($this, $this->alice);
    [$bobs] = seatedSession($this, $this->bob, location: 'b');
    $seat = onlySeatOf($this, $installation);

    expect($this->service(Seats::class)->park($bobs->user_id, $seat->id))->toBe(Outcome::Forbidden)
        ->and(seatRow($seat)->parked_by)->toBeNull();
});

it('answers a conflict for a seat already parked or not parked, and not found for no seat', function (): void {
    [$installation] = seatedSession($this, $this->alice);
    $seat = onlySeatOf($this, $installation);
    $seats = $this->service(Seats::class);

    expect($seats->lift($installation->user_id, $seat->id))->toBe(Outcome::Conflict);

    $seats->park($installation->user_id, $seat->id);

    expect($seats->park($installation->user_id, $seat->id))->toBe(Outcome::Conflict)
        ->and($seats->park($installation->user_id, 999999))->toBe(Outcome::NotFound)
        ->and($seats->lift($installation->user_id, 999999))->toBe(Outcome::NotFound);
});

it('keeps a seat parked however much time passes, and through the presence sweep', function (): void {
    [$installation, $session] = seatedSession($this, $this->alice);
    $seat = onlySeatOf($this, $installation);

    $this->service(Seats::class)->park($installation->user_id, $seat->id);

    $this->travel(400)->days();
    expect(Artisan::call('robot-council:sweep-sessions'))->toBe(0)
        ->and($session->refresh()->status->value)->toBe('gone')
        ->and(seatRow($seat)->parked_by)->toBe($installation->user_id);
});

it('exempts a seat from hours for its own developer, treats a repeat as done, and refuses anyone else', function (): void {
    [$installation] = seatedSession($this, $this->alice);
    [$bobs] = seatedSession($this, $this->bob, location: 'b');
    $seat = onlySeatOf($this, $installation);
    $seats = $this->service(Seats::class);

    expect($seats->exempt($installation->user_id, $seat->id, true))->toBe(Outcome::Applied)
        ->and(seatRow($seat)->hours_exempt)->toBeTrue()
        ->and($seats->exempt($installation->user_id, $seat->id, true))->toBe(Outcome::Applied)
        ->and(seatRow($seat)->hours_exempt)->toBeTrue()
        ->and($seats->exempt($bobs->user_id, $seat->id, false))->toBe(Outcome::Forbidden)
        ->and(seatRow($seat)->hours_exempt)->toBeTrue()
        ->and($seats->exempt($installation->user_id, $seat->id, false))->toBe(Outcome::Applied)
        ->and(seatRow($seat)->hours_exempt)->toBeFalse()
        ->and($seats->exempt($installation->user_id, 999999, true))->toBe(Outcome::NotFound);
});

it('refuses a host user key past its bound rather than truncating it', function (string $write): void {
    [$installation] = seatedSession($this, $this->alice);
    $seat = onlySeatOf($this, $installation);
    $tooLong = str_repeat('k', 65);

    $attempt = match ($write) {
        'park' => fn () => $this->service(Seats::class)->park($tooLong, $seat->id),
        'hours' => fn () => $this->service(DeveloperSettings::class)->setHours($tooLong, 'UTC', '09:00', '17:00', true),
        default => fn () => $this->service(DeveloperSettings::class)->addHoliday($tooLong, '2026-12-25'),
    };

    // `HostKey` refuses with a `RuntimeException`: a key it cannot hold is a fault in the host's
    // user model, not a value somebody typed
    expect($attempt)->toThrow(RuntimeException::class, 'up to 64 characters');

    expect(seatRow($seat)->parked_by)->toBeNull()
        ->and(AssignmentHours::query()->count())->toBe(0)
        ->and(DB::table('robot_council_holidays')->count())->toBe(0);
})->with(['park', 'hours', 'holiday']);

// --- Hours and days off --------------------------------------------------------------------------

it("stores each developer's hours and days off on their own key, and replaces hours on a second save", function (): void {
    $settings = $this->service(DeveloperSettings::class);
    $alice = keyOf($this->alice);
    $bob = keyOf($this->bob);

    $settings->setHours($alice, 'America/Chicago', '09:00', '17:00', true);
    $settings->setHours($bob, 'Pacific/Auckland', '22:00', '06:00', false);
    $settings->setHours($alice, 'America/Chicago', '08:30', '16:30', false);
    $settings->addHoliday($alice, '2026-12-25');

    $row = AssignmentHours::query()->find($alice);

    expect(AssignmentHours::query()->count())->toBe(2)
        ->and($row?->starts_at)->toBe('08:30')
        ->and($row?->ends_at)->toBe('16:30')
        ->and($row?->skip_weekends)->toBeFalse()
        ->and($settings->holidays($alice))->toBe(['2026-12-25'])
        ->and($settings->holidays($bob))->toBeEmpty();

    $settings->clearHours($alice);

    expect(AssignmentHours::query()->find($alice))->toBeNull()
        ->and(AssignmentHours::query()->find($bob))->not->toBeNull();
});

it('refuses hours it cannot answer for, and stores nothing', function (string $timezone, string $from, string $until): void {
    $alice = keyOf($this->alice);

    expect(fn () => $this->service(DeveloperSettings::class)->setHours($alice, $timezone, $from, $until, true))
        ->toThrow(InvalidArgumentException::class)
        ->and(AssignmentHours::query()->count())->toBe(0);
})->with([
    'an abbreviation, which observes no daylight saving' => ['EST', '09:00', '17:00'],
    'an offset, likewise' => ['+05:00', '09:00', '17:00'],
    'a zone that does not exist' => ['Mars/Olympus_Mons', '09:00', '17:00'],
    'a trailing newline on the zone' => ["UTC\n", '09:00', '17:00'],
    'hour 24' => ['UTC', '24:00', '17:00'],
    'a time with seconds' => ['UTC', '09:00:00', '17:00'],
    'a trailing newline on a time' => ['UTC', "09:00\n", '17:00'],
    'a single-digit hour' => ['UTC', '9:00', '17:00'],
    'a start equal to its end' => ['UTC', '09:00', '09:00'],
]);

it('refuses a day off that is not a real date, and stores nothing', function (string $day): void {
    $alice = keyOf($this->alice);

    expect(fn () => $this->service(DeveloperSettings::class)->addHoliday($alice, $day))
        ->toThrow(InvalidArgumentException::class)
        ->and(DB::table('robot_council_holidays')->count())->toBe(0);
})->with(['2026-02-30', '2026-13-01', '26-12-25', "2026-12-25\n", 'christmas', '']);

it('adds a day off once, and removes it', function (): void {
    $settings = $this->service(DeveloperSettings::class);
    $alice = keyOf($this->alice);

    expect($settings->addHoliday($alice, '2026-12-25'))->toBeTrue()
        ->and($settings->addHoliday($alice, '2026-12-25'))->toBeFalse()
        ->and($settings->holidays($alice))->toBe(['2026-12-25'])
        ->and($settings->removeHoliday($alice, '2026-12-25'))->toBeTrue()
        ->and($settings->removeHoliday($alice, '2026-12-25'))->toBeFalse()
        ->and($settings->holidays($alice))->toBeEmpty();

    // A day that is not a date matches nothing on every engine, rather than a 500 on Postgres
    expect($settings->removeHoliday($alice, 'x'))->toBeFalse()
        ->and($settings->removeHoliday($alice, '2026-02-30'))->toBeFalse();
});

it('refuses a day off past the ceiling, and accepts one at it', function (): void {
    $alice = keyOf($this->alice);
    $first = CarbonImmutable::parse('2020-01-01');

    DB::table('robot_council_holidays')->insert(array_map(
        static fn (int $offset): array => ['user_id' => $alice, 'day' => $first->addDays($offset)->format('Y-m-d')],
        range(0, DeveloperSettings::MAX_HOLIDAYS - 2)
    ));

    expect($this->service(DeveloperSettings::class)->addHoliday($alice, '2030-01-01'))->toBeTrue()
        ->and(fn () => $this->service(DeveloperSettings::class)->addHoliday($alice, '2030-01-02'))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('robot_council_holidays')->where('user_id', $alice)->count())->toBe(DeveloperSettings::MAX_HOLIDAYS);
});

it("reads a window on the developer's own clock", function (string $at, bool $open): void {
    expect(AssignmentWindow::isOpen(hoursOf('America/Chicago', '09:00', '17:00'), [], CarbonImmutable::parse($at)))->toBe($open);
})->with([
    // Chicago is UTC-5 in September, so 09:00 there is 14:00 UTC
    'a minute before it opens' => ['2026-09-24 13:59:00 UTC', false],
    'the minute it opens' => ['2026-09-24 14:00:00 UTC', true],
    'the last minute before it closes' => ['2026-09-24 21:59:00 UTC', true],
    'the minute it closes' => ['2026-09-24 22:00:00 UTC', false],
    // In January Chicago is UTC-6, so the same local window is an hour later in UTC. A window
    // converted to UTC once would be an hour wrong here.
    'the same UTC minute in winter, when Chicago is an hour further behind' => ['2026-01-15 14:30:00 UTC', false],
    'an hour later in winter' => ['2026-01-15 15:30:00 UTC', true],
]);

it("skips weekends by the date on the developer's clock, and only when asked", function (): void {
    // Saturday 26 September at 10:00 in Chicago is 15:00 UTC; Friday 25 at 16:00 is 21:00 UTC
    $saturday = CarbonImmutable::parse('2026-09-26 15:00:00 UTC');
    $friday = CarbonImmutable::parse('2026-09-25 21:00:00 UTC');

    expect(AssignmentWindow::isOpen(hoursOf('America/Chicago', '09:00', '17:00', skipWeekends: true), [], $saturday))->toBeFalse()
        ->and(AssignmentWindow::isOpen(hoursOf('America/Chicago', '09:00', '17:00', skipWeekends: false), [], $saturday))->toBeTrue()
        ->and(AssignmentWindow::isOpen(hoursOf('America/Chicago', '09:00', '17:00', skipWeekends: true), [], $friday))->toBeTrue();

    // 01:00 UTC on Saturday is still Friday evening in Chicago, so it is not a weekend there
    expect(AssignmentWindow::isOpen(hoursOf('America/Chicago', '18:00', '23:00', skipWeekends: true), [], CarbonImmutable::parse('2026-09-26 01:00:00 UTC')))->toBeTrue();
});

it('closes the part of a Friday-night window past midnight when weekends are skipped', function (): void {
    $fridayNight = hoursOf('UTC', '22:00', '06:00', skipWeekends: true);

    expect(AssignmentWindow::isOpen($fridayNight, [], CarbonImmutable::parse('2026-09-25 23:00:00 UTC')))->toBeTrue()
        ->and(AssignmentWindow::isOpen($fridayNight, [], CarbonImmutable::parse('2026-09-26 01:00:00 UTC')))->toBeFalse();
});

it('follows the wall clock through both daylight saving changes', function (): void {
    // 8 March 2026, Chicago springs forward: 01:59 CST is followed by 03:00 CDT, so 02:00-02:30
    // local never happens and a window inside it is closed every minute of that day
    $skipped = hoursOf('America/Chicago', '02:00', '02:30', skipWeekends: false);
    $openAtAll = false;

    for ($minute = 0; $minute < 24 * 60; $minute += 5) {
        $openAtAll = $openAtAll || AssignmentWindow::isOpen($skipped, [], CarbonImmutable::parse('2026-03-08 06:00:00 UTC')->addMinutes($minute));
    }

    expect($openAtAll)->toBeFalse()
        // The day before, the same window opens as usual: 02:15 CST is 08:15 UTC
        ->and(AssignmentWindow::isOpen($skipped, [], CarbonImmutable::parse('2026-03-07 08:15:00 UTC')))->toBeTrue();

    // 1 November 2026, Chicago falls back: 01:00-02:00 local happens twice, at 06:00 UTC (CDT)
    // and again at 07:00 UTC (CST), and a window inside it is open both times
    $repeated = hoursOf('America/Chicago', '01:00', '02:00', skipWeekends: false);

    expect(AssignmentWindow::isOpen($repeated, [], CarbonImmutable::parse('2026-11-01 06:30:00 UTC')))->toBeTrue()
        ->and(AssignmentWindow::isOpen($repeated, [], CarbonImmutable::parse('2026-11-01 07:30:00 UTC')))->toBeTrue()
        ->and(AssignmentWindow::isOpen($repeated, [], CarbonImmutable::parse('2026-11-01 08:30:00 UTC')))->toBeFalse();
});

it('runs a window overnight when it ends before it starts', function (string $local, bool $open): void {
    expect(AssignmentWindow::isOpen(hoursOf('UTC', '22:00', '06:00', skipWeekends: false), [], CarbonImmutable::parse($local.' UTC')))->toBe($open);
})->with([
    'late evening' => ['2026-09-24 23:00:00', true],
    'the start' => ['2026-09-24 22:00:00', true],
    'early morning' => ['2026-09-24 05:59:00', true],
    'the end' => ['2026-09-24 06:00:00', false],
    'midday' => ['2026-09-24 12:00:00', false],
]);

it("closes a holiday from the developer's own midnight, not UTC's", function (): void {
    $auckland = hoursOf('Pacific/Auckland', '00:00', '23:59', skipWeekends: false);

    // Christmas starts in Auckland (UTC+13 in December) at 11:00 UTC on the 24th
    expect(AssignmentWindow::isOpen($auckland, ['2026-12-25'], CarbonImmutable::parse('2026-12-24 10:30:00 UTC')))->toBeTrue()
        ->and(AssignmentWindow::isOpen($auckland, ['2026-12-25'], CarbonImmutable::parse('2026-12-24 11:30:00 UTC')))->toBeFalse()
        ->and(AssignmentWindow::isOpen($auckland, [], CarbonImmutable::parse('2026-12-24 11:30:00 UTC')))->toBeTrue();
});

it('finds when a closed window next opens, past weekends and days off (#440)', function (string $hours, array $holidays, string $at, ?string $opens): void {
    [$zone, $from, $until, $skip] = explode(' ', $hours);

    $next = AssignmentWindow::nextOpening(hoursOf($zone, $from, $until, $skip === 'skip'), array_values(array_map(stringValue(...), $holidays)), CarbonImmutable::parse($at));

    expect($next?->utc()->format('Y-m-d H:i'))->toBe($opens);
})->with([
    // Friday evening, skipping weekends: Monday morning
    'past a weekend' => ['UTC 09:00 17:00 skip', [], '2026-09-25 18:00:00 UTC', '2026-09-28 09:00'],
    'before the start today' => ['UTC 09:00 17:00 skip', [], '2026-09-24 07:00:00 UTC', '2026-09-24 09:00'],
    'past a day off and the weekend after it' => ['UTC 09:00 17:00 skip', ['2026-09-25'], '2026-09-24 18:00:00 UTC', '2026-09-28 09:00'],
    'the same weekend, not skipped' => ['UTC 09:00 17:00 keep', [], '2026-09-25 18:00:00 UTC', '2026-09-26 09:00'],
    // An overnight window reopens at its evening start, or at a date's own midnight when the
    // evening before was closed: Sunday 22:00 is a weekend, so Monday opens at 00:00
    'overnight, later today' => ['UTC 22:00 06:00 skip', [], '2026-09-25 12:00:00 UTC', '2026-09-25 22:00'],
    'overnight, past a weekend' => ['UTC 22:00 06:00 skip', [], '2026-09-26 03:00:00 UTC', '2026-09-28 00:00'],
    // Chicago springs forward on 8 March 2026: 02:00 local does not happen, and the window
    // opens at 03:00 CDT, which is 08:00 UTC
    'a window starting in the skipped hour' => ['America/Chicago 02:00 04:00 keep', [], '2026-03-08 07:00:00 UTC', '2026-03-08 08:00'],
    // A start inside the skipped hour is open from the change itself, 03:00 CDT, not 03:30
    'a start half an hour into the skipped hour' => ['America/Chicago 02:30 09:00 keep', [], '2026-03-08 07:00:00 UTC', '2026-03-08 08:00'],
    // Chicago falls back on 1 November: 01:00-02:00 local happens at 06:00 UTC (CDT) and again at
    // 07:00 UTC (CST). From 01:10 CST, a 01:30 start's second occurrence is 07:30 UTC that day
    'the second pass through the repeated hour' => ['America/Chicago 01:30 02:00 keep', [], '2026-11-01 07:10:00 UTC', '2026-11-01 07:30'],
    // And a 09:00 start after the change is 15:00 UTC, not 14:00
    'across the fall-back change' => ['America/Chicago 09:00 17:00 keep', [], '2026-10-31 23:00:00 UTC', '2026-11-01 15:00'],
]);

it('reads no next opening for a developer with no hours, since nothing is closed', function (): void {
    expect(AssignmentWindow::nextOpening(null, ['2026-12-25'], CarbonImmutable::parse('2026-12-25 12:00:00 UTC')))->toBeNull();
});

it('gates nothing for a developer with no hours set, holidays included', function (): void {
    expect(AssignmentWindow::isOpen(null, ['2026-12-25'], CarbonImmutable::parse('2026-12-25 12:00:00 UTC')))->toBeTrue();
});

it("answers from what a developer stored, and one developer's settings do not reach another", function (): void {
    $settings = $this->service(DeveloperSettings::class);
    $alice = keyOf($this->alice);
    $bob = keyOf($this->bob);

    $settings->setHours($alice, 'America/Chicago', '09:00', '17:00', true);
    $settings->addHoliday($alice, '2026-09-24');

    $thursdayMorningInChicago = CarbonImmutable::parse('2026-09-24 15:00:00 UTC');

    expect($settings->takesNewWorkAt($alice, $thursdayMorningInChicago))->toBeFalse()
        ->and($settings->takesNewWorkAt($bob, $thursdayMorningInChicago))->toBeTrue();

    $settings->removeHoliday($alice, '2026-09-24');

    expect($settings->takesNewWorkAt($alice, $thursdayMorningInChicago))->toBeTrue();
});

// --- The page -------------------------------------------------------------------------------------

it("shows a developer their own seats and none of another developer's", function (): void {
    seatedSession($this, $this->alice, 'robot-council/core', 'a');
    seatedSession($this, $this->bob, 'robot-council/cli', 'b');

    Livewire::actingAs($this->alice)
        ->test(SeatSettings::class)
        ->assertOk()
        ->assertSee('robot-council/core')
        ->assertDontSee('robot-council/cli');
});

it('parks and lifts through the page, and refuses a seat belonging to somebody else', function (): void {
    [$installation] = seatedSession($this, $this->alice);
    $seat = onlySeatOf($this, $installation);

    Livewire::actingAs($this->alice)->test(SeatSettings::class)->call('park', $seat->id)->assertSet('refused', false);

    expect(seatRow($seat)->parked_by)->toBe(keyOf($this->alice));

    // Bob's page is handed Alice's seat id: a Livewire action is a POST a client can shape freely
    Livewire::actingAs($this->bob)->test(SeatSettings::class)
        ->call('lift', $seat->id)
        ->assertSet('said', "Not allowed: only the developer who parked a seat can lift it, and only a seat's own developer can change it.");

    expect(seatRow($seat)->parked_by)->toBe(keyOf($this->alice));

    Livewire::actingAs($this->alice)->test(SeatSettings::class)->call('lift', $seat->id);

    expect(seatRow($seat)->parked_by)->toBeNull();
});

it('saves hours and days off through the page, and reports a refused value instead of failing', function (): void {
    $component = Livewire::actingAs($this->alice)->test(SeatSettings::class)
        ->set('timezone', 'America/Chicago')
        ->set('startsAt', '08:00')
        ->set('endsAt', '16:00')
        ->set('skipWeekends', false)
        ->call('saveHours')
        ->assertSet('refused', false)
        ->set('holiday', '2026-12-25')
        ->call('addHoliday')
        ->assertSet('holiday', '');

    $row = AssignmentHours::query()->find(keyOf($this->alice));

    expect($row?->timezone)->toBe('America/Chicago')
        ->and($row?->starts_at)->toBe('08:00')
        ->and($row?->skip_weekends)->toBeFalse()
        ->and($this->service(DeveloperSettings::class)->holidays(keyOf($this->alice)))->toBe(['2026-12-25']);

    $component->set('timezone', 'EST')->call('saveHours')
        ->assertSet('said', 'Not saved: A timezone is an IANA zone name, such as America/Chicago.');

    expect(AssignmentHours::query()->find(keyOf($this->alice))?->timezone)->toBe('America/Chicago');

    // A fresh page is filled from what was stored
    Livewire::actingAs($this->alice)->test(SeatSettings::class)
        ->assertSet('timezone', 'America/Chicago')
        ->assertSet('startsAt', '08:00')
        ->assertSet('skipWeekends', false);
});

it('refuses every entry point to a visitor the package guard does not resolve, and changes nothing', function (string $action, array $arguments): void {
    seatedSession($this, $this->alice);
    seatedSession($this, $this->alice, location: 'b');
    $seats = $this->service(Seats::class)->forDeveloper(keyOf($this->alice));
    $settings = $this->service(DeveloperSettings::class);

    // State for every action to disturb: one seat parked, one exempt, hours and a day off set
    $this->service(Seats::class)->park(keyOf($this->alice), $seats[0]->id);
    $this->service(Seats::class)->exempt(keyOf($this->alice), $seats[1]->id, true);
    $settings->setHours(keyOf($this->alice), 'America/Chicago', '09:00', '17:00', true);
    $settings->addHoliday(keyOf($this->alice), '2026-12-25');

    $alice = keyOf($this->alice);

    $snapshot = static fn (): array => [
        Seat::query()->orderBy('id')->get(['id', 'parked_by', 'hours_exempt'])->toArray(),
        AssignmentHours::query()->get(['user_id', 'timezone', 'starts_at', 'ends_at', 'skip_weekends'])->toArray(),
        $settings->holidays($alice),
    ];
    $before = $snapshot();

    $component = Livewire::actingAs($this->alice)->test(SeatSettings::class)
        ->set('timezone', 'UTC')->set('startsAt', '00:00')->set('endsAt', '23:00')->set('holiday', '2026-12-31');

    auth()->guard('web')->logout();

    $resolved = array_map(static fn ($argument) => match ($argument) {
        'PARKED' => $seats[0]->id,
        'FREE' => $seats[1]->id,
        default => $argument,
    }, $arguments);

    $component->call($action, ...$resolved)->assertForbidden();

    expect($snapshot())->toBe($before);
})->with([
    'park' => ['park', ['FREE']],
    'lift' => ['lift', ['PARKED']],
    'exempt' => ['exempt', ['PARKED']],
    'unexempt' => ['unexempt', ['FREE']],
    'save hours' => ['saveHours', []],
    'clear hours' => ['clearHours', []],
    'add a day off' => ['addHoliday', []],
    'remove a day off' => ['removeHoliday', ['2026-12-25']],
]);

it('escapes a hostile repository and machine label on the page', function (string $payload, array $forbidden, ?string $escaped): void {
    // Both written past validation deliberately: `WorkIdentity` and `MachineIdentity` charset-limit
    // them, so neither can arrive through the API and the page's escaping has to be its own
    // guarantee rather than the validator's. Each payload is well inside both columns' widths.
    expect(mb_strlen($payload))->toBeLessThanOrEqual(64);

    $installation = $this->approveInstallation($this->alice)->refresh();
    $installation->forceFill(['machine_label' => $payload])->save();

    Seat::query()->insert([
        'installation_id' => $installation->id,
        'user_id' => keyOf($this->alice),
        'repository' => $payload,
        'work_location' => '',
        'hours_exempt' => false,
    ]);

    $html = Livewire::actingAs($this->alice)->test(SeatSettings::class)->html();

    expect(substr_count($html, $escaped ?? $payload))->toBeGreaterThanOrEqual(2);

    foreach ($forbidden as $live) {
        expect($html)->not->toContain($live);
    }
})->with(HostileContent::dataset());

// --- The coordinator reads and never writes -------------------------------------------------------

it("lets a coordinator read every developer's settings and seats", function (): void {
    // Monday 28 September 2026, 13:00 in London, inside bob's hours
    Carbon::setTestNow('2026-09-28 12:00:00');

    [$installation] = seatedSession($this, $this->alice);
    $seat = onlySeatOf($this, $installation);
    $this->service(Seats::class)->park(keyOf($this->alice), $seat->id);
    $this->service(Seats::class)->exempt(keyOf($this->alice), $seat->id, true);
    $this->service(DeveloperSettings::class)->setHours(keyOf($this->bob), 'Europe/London', '10:00', '18:00', true);
    $this->service(DeveloperSettings::class)->addHoliday(keyOf($this->bob), '2026-12-26');

    // A developer with days off and no hours: the half of the read that comes from the holidays table
    $this->service(DeveloperSettings::class)->addHoliday(keyOf($this->alice), '2026-11-26');

    [, $token] = $this->startCoordinatorSession($this->approveInstallation($this->bob, 'coordinator-box'));

    $this->machine($token)->getJson(route('robot-council.developers.settings'))
        ->assertOk()
        ->assertExactJson([
            'developers' => [[
                'github_login' => 'alice-dev',
                'hours' => null,
                'holidays' => ['2026-11-26'],
                // No hours, so nothing gates her, days off included
                'inside_hours' => 'ungated',
                'next_opens_at' => null,
            ], [
                'github_login' => 'bob-dev',
                'hours' => ['timezone' => 'Europe/London', 'starts_at' => '10:00', 'ends_at' => '18:00', 'skip_weekends' => true],
                'holidays' => ['2026-12-26'],
                'inside_hours' => true,
                'next_opens_at' => null,
            ]],
            'seats' => [[
                'id' => $seat->id,
                'github_login' => 'alice-dev',
                'installation_id' => $installation->id,
                'harness' => $installation->harness,
                'machine_label' => 'office-mac',
                'repository' => 'robot-council/core',
                'work_location' => 'a',
                'parked' => true,
                'parked_by' => 'alice-dev',
                'parked_at' => seatRow($seat)->parked_at?->toIso8601String(),
                'hours_exempt' => true,
                'max_capacity' => 1,
                'inside_hours' => 'ungated',
                'next_opens_at' => null,
            ]],
        ]);
});

it("reads an exempt seat as ungated while its developer's hours are closed, and the rest as closed (#440)", function (): void {
    // Saturday 26 September 2026, noon in London: bob skips weekends
    Carbon::setTestNow('2026-09-26 11:00:00');

    [$exempt] = seatedSession($this, $this->bob, location: 'a');
    [$gated] = seatedSession($this, $this->bob, location: 'b');
    $seats = collect($this->service(Seats::class)->forDeveloper(keyOf($this->bob)));
    $this->service(Seats::class)->exempt(keyOf($this->bob), $seats->firstWhere('installation_id', $exempt->id)->id ?? 0, true);
    $this->service(DeveloperSettings::class)->setHours(keyOf($this->bob), 'Europe/London', '10:00', '18:00', true);

    [, $token] = $this->startCoordinatorSession($this->approveInstallation($this->alice, 'coordinator-box'));
    $read = arrayValue($this->machine($token)->getJson(route('robot-council.developers.settings'))->assertOk()->json());

    $byInstallation = [];

    foreach (arrayValue($read['seats'] ?? []) as $seat) {
        $seat = arrayValue($seat);
        $byInstallation[intValue($seat['installation_id'] ?? null)] = [$seat['inside_hours'] ?? null, $seat['next_opens_at'] ?? null];
    }

    // Monday 10:00 in London is 09:00 UTC
    expect($byInstallation[$exempt->id])->toBe(['ungated', null])
        ->and($byInstallation[$gated->id])->toBe([false, '2026-09-28T10:00:00+01:00'])
        ->and(arrayValue(arrayValue($read['developers'] ?? [])[0] ?? null))->toMatchArray(['inside_hours' => false]);
});

it('refuses the read to a session that is not a coordinator', function (): void {
    [, $token] = $this->startAgentSession($this->approveInstallation($this->bob));

    $this->machine($token)->getJson(route('robot-council.developers.settings'))->assertForbidden();
});

it('gives a coordinator no way to write, and its attempts change nothing', function (string $method): void {
    [$installation] = seatedSession($this, $this->alice);
    $seat = onlySeatOf($this, $installation);
    $this->service(Seats::class)->park(keyOf($this->alice), $seat->id);

    [, $token] = $this->startCoordinatorSession($this->approveInstallation($this->bob, 'coordinator-box'));

    $this->machine($token)
        ->json($method, route('robot-council.developers.settings'), [
            'seat_id' => $seat->id, 'parked' => false, 'timezone' => 'UTC', 'starts_at' => '00:00', 'ends_at' => '23:59',
        ])
        ->assertStatus(405);

    expect(seatRow($seat)->parked_by)->toBe(keyOf($this->alice))
        ->and(AssignmentHours::query()->count())->toBe(0);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('registers no route that reaches a settings writer other than the read and the page', function (): void {
    // By action class rather than by URI, so a writer mounted at `agent/park` is seen as surely as
    // one mounted under `developers`
    $reaching = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_contains($route->getActionName(), 'DeveloperSettings')
            || str_contains($route->getActionName(), 'SeatSettings')
            || str_contains($route->getActionName(), 'Seats'))
        ->map(fn (Route $route): string => implode('|', array_filter($route->methods(), is_string(...))).' '.$route->getName())
        ->sort()
        ->values()
        ->all();

    // The read, and the page. The page's own actions arrive through Livewire's update route, which
    // authenticates on the web guard, and the test above refuses every one of them to a visitor
    // that guard does not resolve.
    expect($reaching)->toBe([
        'GET|HEAD robot-council.developers.settings',
        'GET|HEAD robot-council.seats',
    ]);
});

// --- Migrations -----------------------------------------------------------------------------------

it('rolls the three tables back and forward again', function (): void {
    // **The waivers table first, as `migrate:rollback` would**: it holds a foreign key to the seats
    // table (#320), and Postgres refuses to drop a table another still references -- measured in
    // the `postgres` job as `SQLSTATE[2BP01]` once #320 landed. SQLite enforces no foreign key in
    // this suite, so only that job could see it.
    $files = [
        'robot_council_placement_waivers' => '2026_09_24_000012_create_robot_council_placement_waivers_table.php',
        'robot_council_holidays' => '2026_09_24_000007_create_robot_council_holidays_table.php',
        'robot_council_assignment_hours' => '2026_09_24_000006_create_robot_council_assignment_hours_table.php',
        'robot_council_seats' => '2026_09_24_000005_create_robot_council_seats_table.php',
    ];

    $migrations = [];

    foreach ($files as $table => $file) {
        $migration = require __DIR__.'/../database/migrations/'.$file;

        if (! \is_object($migration) || ! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
            throw new RuntimeException(sprintf('%s did not return a migration.', $file));
        }

        $migrations[$table] = $migration;
    }

    // Down in rollback order, all of them, then up in migration order -- as the migrator runs them.
    // One table at a time would recreate the waivers table, foreign key and all, before the seats
    // table's turn to be dropped.
    foreach ($migrations as $table => $migration) {
        $migration->down();
        expect(Schema::hasTable($table))->toBeFalse();
    }

    foreach (array_reverse($migrations, true) as $table => $migration) {
        // Twice, on a schema that already has it: a no-op rather than an error
        $migration->up();
        $migration->up();
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
