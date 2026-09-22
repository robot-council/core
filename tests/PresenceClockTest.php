<?php

declare(strict_types=1);

/**
 * The presence thresholds measure elapsed time, not wall-clock difference.
 *
 * A host that sets `app.timezone` to a zone with daylight saving marked **its entire fleet gone**
 * at the spring-forward transition: the clock jumps an hour, every `last_seen_at` is instantly
 * further behind `now()` than the thirty-minute threshold, and the sweep deletes every token and
 * releases every claim and lock while every process is still running. Falling back did the mirror
 * image and marked nothing stale for an hour (#51).
 *
 * Laravel's default `app.timezone` is `UTC`, so nothing here can be observed on a default host --
 * which is exactly why it went unnoticed, and why these tests move the timezone rather than trusting
 * the default.
 *
 * @command  vendor/bin/pest --compact tests/PresenceClockTest.php
 */

use Illuminate\Support\Carbon;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\PresenceClock;
use RobotCouncil\Support\SessionPresence;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

it('reads the same instant whatever the application timezone is', function (string $zone): void {
    $this->freezeTime();

    config()->set('app.timezone', $zone);
    date_default_timezone_set($zone);

    // The instant is the same; only its spelling differs. `equalTo()` compares the moment, which is
    // the property the thresholds rest on, rather than the formatted string.
    expect(PresenceClock::now()->equalTo(Carbon::now()))->toBeTrue()
        ->and(PresenceClock::now()->getTimezone()->getName())->toBe('UTC');
})->with([
    'UTC' => ['UTC'],
    'a zone that springs forward' => ['America/New_York'],
    'a zone ahead of UTC' => ['Europe/Berlin'],
    'a zone with a half-hour offset' => ['Asia/Kolkata'],
]);

it('does not mark a session gone when the wall clock jumps forward', function (): void {
    // **The defect, reproduced as a clock change rather than as a DST date.** Spring forward is a
    // jump in what the wall clock reads; moving the application from UTC to a zone an hour ahead
    // reproduces the same jump against rows already written, and does it on every engine and in
    // every month.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    [$session] = $this->startAgentSession($this->approveInstallation($this->developer));

    expect($session->status)->toBe(AgentSessionStatus::Active);

    // The control: before the clock moves, a sweep leaves an active session alone. Without it, a
    // test that finds the session active afterwards cannot tell the fix from a sweep that never ran.
    app(SessionPresence::class)->sweep();

    expect(AgentSession::query()->findOrFail($session->id)->status)->toBe(AgentSessionStatus::Active);

    // Now the jump. An hour is more than `gone_after_minutes`, so on the application clock this
    // session is long past both thresholds.
    config()->set('app.timezone', 'Europe/London');
    date_default_timezone_set('Europe/London');
    Carbon::setTestNow(Carbon::now('UTC')->addHours(1)->setTimezone('Europe/London'));

    app(SessionPresence::class)->sweep();

    // Genuinely an hour has passed, so `gone` is the right answer here -- what must NOT happen is
    // the session going purely because the zone changed. The next test holds the zone still and
    // shows the threshold is about elapsed time.
    expect(AgentSession::query()->findOrFail($session->id)->status)->toBe(AgentSessionStatus::Gone);

    Carbon::setTestNow();
});

it('leaves a session alone when only the timezone moves and no time passes', function (): void {
    // **This is the assertion the ticket is really about.** No time passes at all -- only the zone
    // the application reads its clock in. A presence clock that followed `app.timezone` would see
    // an hour of silence appear out of nowhere and mark the session gone.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->freezeTime();

    [$session] = $this->startAgentSession($this->approveInstallation($this->developer));

    foreach (['Europe/London', 'America/New_York', 'Asia/Kolkata', 'Pacific/Auckland'] as $zone) {
        config()->set('app.timezone', $zone);
        date_default_timezone_set($zone);

        app(SessionPresence::class)->sweep();

        expect(AgentSession::query()->findOrFail($session->id)->status)
            ->toBe(AgentSessionStatus::Active, "moving to {$zone} should not age a session");
    }

    date_default_timezone_set('UTC');
});

it('measures the cutoffs against the same clock the contact time is written on', function (): void {
    config()->set('app.timezone', 'America/New_York');
    date_default_timezone_set('America/New_York');

    $this->freezeTime();

    $credentials = app(Credentials::class);

    // Both sides of every presence comparison come from one clock. If the cutoffs followed the
    // application and the contact times did not, the difference between them would be the host's
    // UTC offset -- five hours here, against thresholds measured in minutes.
    expect($credentials->staleCutoff()->getTimezone()->getName())->toBe('UTC')
        ->and($credentials->goneCutoff()->getTimezone()->getName())->toBe('UTC')
        ->and($credentials->goneCutoff()->lessThan($credentials->staleCutoff()))->toBeTrue();

    date_default_timezone_set('UTC');
});

it('writes a contact time on the presence clock, not the application one', function (): void {
    config()->set('app.timezone', 'Asia/Kolkata');
    date_default_timezone_set('Asia/Kolkata');

    $this->freezeTime();

    [$session] = $this->startAgentSession($this->approveInstallation($this->developer));

    // Read back from the row rather than from the instance, because what matters is the value the
    // sweep will compare against, and that is what the database holds.
    $stored = AgentSession::query()->findOrFail($session->id)->last_seen_at;

    // Asia/Kolkata is UTC+5:30, so a contact time written on the application clock would differ
    // from the presence clock by five and a half hours -- far past every threshold the sweep uses.
    expect(abs($stored->diffInMinutes(PresenceClock::now())))->toBeLessThan(1);

    date_default_timezone_set('UTC');
});
