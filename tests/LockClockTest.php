<?php

declare(strict_types=1);

/**
 * A lock's lease measures elapsed time, not wall-clock difference.
 *
 * **The same defect `#51` fixed for presence, in the half it deliberately left alone.** `Locks`
 * wrote `expires_at` with the application clock and compared it the same way, and
 * `Connection::prepareBindings()` writes the value naive -- so the stored digits were whatever wall
 * clock the host happened to be on.
 *
 * `locks.max_ttl_seconds` defaults to 900, and an hour is longer than any lease can be. So at the
 * spring-forward transition every held lock read as expired at once, and another session could take
 * a name whose holder still believed it owned it. The fence value lets the displaced holder find
 * out **afterwards**, which is detection rather than prevention: it learns the next time it checks,
 * after whatever it was guarding has already been entered by somebody else (#149).
 *
 * Laravel's default `app.timezone` is `UTC`, so nothing here is observable on a default host --
 * which is why it went unnoticed, and why these tests move the timezone rather than trusting the
 * default.
 *
 * @command  vendor/bin/pest --compact tests/LockClockTest.php
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\Lock;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\PresenceClock;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer, [Ability::LocksAcquire->value]);

    [$this->session] = $this->startAgentSession($this->installation);
});

it('writes a lease on a clock that does not shift', function (string $zone): void {
    $this->freezeTime();

    config()->set('app.timezone', $zone);
    date_default_timezone_set($zone);

    app(Locks::class)->acquire($this->session, 'deploy', 300, false);

    // The instant is the same; only its spelling differs. What the column must not carry is the
    // host's wall clock, because nothing records which zone those digits were written in.
    expect(Lock::query()->where('name', 'deploy')->firstOrFail()->expires_at?->getTimezone()->getName())
        ->toBe(PresenceClock::ZONE);

    date_default_timezone_set('UTC');
})->with([
    'UTC' => ['UTC'],
    'a zone that springs forward' => ['America/New_York'],
    'a zone ahead of UTC' => ['Europe/Berlin'],
    'a zone with a half-hour offset' => ['Asia/Kolkata'],
]);

it('keeps a lease held when only the timezone moves and no time passes', function (): void {
    // **The assertion this ticket is really about.** No time passes at all -- only the zone the
    // application reads its clock in. A lease measured on `app.timezone` would see an hour appear
    // out of nowhere and read as lapsed, letting a second session take a name the first still holds.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->freezeTime();

    expect(app(Locks::class)->acquire($this->session, 'deploy', 300, false)['outcome'])
        ->toBe(Outcome::Applied);

    foreach (['Europe/London', 'America/New_York', 'Asia/Kolkata', 'Pacific/Auckland'] as $zone) {
        config()->set('app.timezone', $zone);
        date_default_timezone_set($zone);

        // A rival taking the name is what a lapsed lease permits, so this asks the question the
        // way the defect would answer it rather than reading a column and judging for itself.
        [$rival] = $this->startAgentSession($this->installation);

        expect(app(Locks::class)->acquire($rival, 'deploy', 300, false)['outcome'])
            ->toBe(Outcome::Conflict, "moving to {$zone} should not lapse a held lease");
    }

    date_default_timezone_set('UTC');
});

it('still expires a lease that genuinely elapsed, so the fix cannot pass by never expiring', function (): void {
    // **The control for the test above.** A clock that never expired anything would satisfy it
    // completely, and a lock that is never released is a worse defect than the one being fixed.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->travelTo(Carbon::parse('2026-01-01 12:00:00'));

    expect(app(Locks::class)->acquire($this->session, 'deploy', 300, false)['outcome'])
        ->toBe(Outcome::Applied);

    // Past the 300-second lease, with the zone held still, so elapsed time is the only variable.
    $this->travelTo(Carbon::parse('2026-01-01 12:06:00'));

    [$rival] = $this->startAgentSession($this->installation);

    expect(app(Locks::class)->acquire($rival, 'deploy', 300, false)['outcome'])
        ->toBe(Outcome::Applied);
});

it('reads a lease back on the same clock it was written on', function (): void {
    // The cast, which is the half a `where` cannot cover: `Locks` re-reads a row and compares
    // `expires_at` in PHP as well as in SQL, so a value relabelled on hydration is wrong in exactly
    // the paths that decide whether a lease is still held.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->freezeTime();

    app(Locks::class)->acquire($this->session, 'deploy', 300, false);

    $writtenAt = PresenceClock::now();

    config()->set('app.timezone', 'Pacific/Auckland');
    date_default_timezone_set('Pacific/Auckland');

    $lock = Lock::query()->where('name', 'deploy')->firstOrFail();

    // Read in a zone twelve hours away, the instant must be unchanged. `equalTo` compares the
    // moment rather than the spelling, which is the property the comparisons rest on.
    // **Compared as timestamps, not with `equalTo`.** The column is a `dateTime`, which stores
    // whole seconds, while a frozen clock carries microseconds -- and Carbon's `equalTo` compares
    // those too, so it answers false for two values naming the same second. That would be a test
    // measuring the column's precision rather than the clock's, and it fails against a correct fix.
    expect($lock->expires_at?->getTimestamp())->toBe($writtenAt->copy()->addSeconds(300)->getTimestamp())
        ->and($lock->acquired_at?->getTimestamp())->toBe($writtenAt->getTimestamp())
        // And the instant is labelled with the zone it was written in, which is what the cast is for.
        ->and($lock->expires_at?->getTimezone()->getName())->toBe(PresenceClock::ZONE);

    date_default_timezone_set('UTC');
});

it('measures lock retention on the clock the column is written on', function (): void {
    // **The consequence of moving the writes, rather than the lease itself.** Every path in
    // `Locks` stamps `updated_at` with the presence clock now, and `robot-council:prune-locks` is
    // that column's only reader. A cutoff built from `Carbon::now()` would compare UTC digits
    // against the host's wall clock, so the retention boundary would move by the host's offset --
    // ahead of UTC it deletes early, behind it keeps rows past their retention.
    config()->set('robot-council.retention.locks_days', 1);

    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    // Parsed with the zone named, never from the ambient default: the default moves inside
    // this test, and an instant that moves with it would not be a fixed point to measure from.
    $this->travelTo(Carbon::parse('2026-01-01 00:00:00', 'UTC'));

    app(Locks::class)->acquire($this->session, 'deploy', 300, false);
    app(Locks::class)->release($this->session, 'deploy', false);

    // Twenty-three hours later: free, and one hour short of the one-day retention. A zone twelve
    // hours ahead of UTC is more than enough to carry it across that boundary.
    $this->travelTo(Carbon::parse('2026-01-01 23:00:00', 'UTC'));

    config()->set('app.timezone', 'Pacific/Auckland');
    date_default_timezone_set('Pacific/Auckland');

    // `Artisan::call` rather than `artisan()`: it returns an `int` rather than a
    // `PendingCommand|int` union `composer analyse` refuses, it runs when it is called rather
    // than when something happens to read it, and its output can be read back -- which is how the
    // cutoff itself is asserted below rather than only its effect.
    expect(Artisan::call('robot-council:prune-locks'))->toBe(0);

    expect(Lock::query()->where('name', 'deploy')->exists())
        ->toBeTrue('a lock an hour short of its retention must survive whatever zone the host is on');

    // The cutoff named directly. On the application clock it would read `2026-01-01 12:00:00`,
    // thirteen hours out, which is the whole defect in one string.
    expect(Artisan::output())->toContain('before 2025-12-31 23:00:00 UTC');

    // The control, so this cannot pass by never deleting anything: past the boundary it goes.
    $this->travelTo(Carbon::parse('2026-01-02 01:00:00', 'UTC'));

    expect(Artisan::call('robot-council:prune-locks'))->toBe(0)
        ->and(Lock::query()->where('name', 'deploy')->exists())->toBeFalse();

    date_default_timezone_set('UTC');
});
