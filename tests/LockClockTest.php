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
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Lock;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\PresenceClock;
use RobotCouncil\Support\SessionPresence;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);

    [$this->session] = $this->startAgentSession($this->installation);
});

it('writes lease digits that do not follow the host clock', function (string $zone): void {
    $this->freezeTime();

    config()->set('app.timezone', $zone);
    date_default_timezone_set($zone);

    app(Locks::class)->acquire($this->session, 'deploy', 300, false);

    // **The RAW column, never the hydrated model.** `PresenceTimestamp::get()` labels every value
    // it returns with the presence clock unconditionally, so asking the model for its timezone
    // answers `UTC` whatever clock wrote the row -- an assertion that passes with the cast in
    // place and the writes reverted, which is a test of the cast wearing the write's clothes.
    // What the defect actually changes is the digits stored, and those are what this reads.
    $stored = DB::table('robot_council_locks')->where('name', 'deploy')->value('expires_at');

    // Narrowed rather than cast: `value()` is declared `mixed`, and `composer analyse` refuses a
    // cast from it. A non-string here means the column was not read, which is worth failing on
    // rather than stringifying into a mismatch nobody can explain.
    expect($stored)->toBeString();

    expect(substr(\is_string($stored) ? $stored : '', 0, 19))
        ->toBe(PresenceClock::now()->addSeconds(300)->format('Y-m-d H:i:s'));

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

it('renews and releases on the fixed clock too, not only acquires', function (): void {
    // **Two of the call sites the first draft of this file could not see.** `acquire()` was
    // covered and `renew()` and `release()` were not, so reverting those to the application clock
    // left the suite green. Each is exercised here with the host's clock moved away from the one
    // the row was written on.
    //
    // **`forceRelease()` is called below and is NOT discriminated by this test**, deliberately
    // named rather than implied: its conditions carry no clock term -- it takes a name from
    // whoever holds it -- so its `$now` reaches only `updated_at`, and reverting it leaves this
    // assertion green. What covers that site is the retention test further down, which reads the
    // consequence through the prune boundary. The call stays here because the path should still
    // work with the clocks apart.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->travelTo(Carbon::parse('2026-01-01 00:00:00', 'UTC'));

    app(Locks::class)->acquire($this->session, 'deploy', 300, false);

    // **A minute passes before the renewal, and that is load-bearing on MySQL.**
    // `Builder::update()` reports rows CHANGED there rather than rows matched, so renewing inside
    // the same second writes the `expires_at` the row already holds, reports 0, and `renew()`
    // reads that as a lost race. Measured: this test failed on MySQL 9.4 and passed on Postgres
    // and SQLite until the clock moved.
    $this->travelTo(Carbon::parse('2026-01-01 00:01:00', 'UTC'));

    config()->set('app.timezone', 'Asia/Kolkata');
    date_default_timezone_set('Asia/Kolkata');

    // A renewal read on the host's clock would find `acquired_at` five and a half hours in the
    // past against a ceiling measured from it, or the lease already lapsed, and refuse.
    expect(app(Locks::class)->renew($this->session, 'deploy', 300, false)['outcome'])
        ->toBe(Outcome::Applied);

    $stored = DB::table('robot_council_locks')->where('name', 'deploy')->value('expires_at');

    // Narrowed rather than cast: `value()` is declared `mixed`, and `composer analyse` refuses a
    // cast from it. A non-string here means the column was not read, which is worth failing on
    // rather than stringifying into a mismatch nobody can explain.
    expect($stored)->toBeString();

    expect(substr(\is_string($stored) ? $stored : '', 0, 19))
        ->toBe(PresenceClock::now()->addSeconds(300)->format('Y-m-d H:i:s'));

    // A release compares the lease as well, so it refuses a lock it reads as somebody else's or
    // as already lapsed.
    expect(app(Locks::class)->release($this->session, 'deploy', false))->toBe(Outcome::Applied);

    // And the coordinator path, which takes a name from whoever holds it.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->travelTo(Carbon::parse('2026-01-01 00:02:00', 'UTC'));

    app(Locks::class)->acquire($this->session, 'deploy', 300, false);

    config()->set('app.timezone', 'Pacific/Auckland');
    date_default_timezone_set('Pacific/Auckland');

    expect(app(Locks::class)->forceRelease($this->session, 'deploy'))->toBe(Outcome::Applied);

    date_default_timezone_set('UTC');
});

it('does not delete a gone session whose lease is still live when the host clock moves', function (): void {
    // **The reader of `expires_at` that lives outside `Locks`.** `SessionPresence::prune()` refuses
    // to delete a gone session still holding a live lease, because `holder_id` is `nullOnDelete`
    // and the row going would free the lock with no `lock.released` event -- the fleet would see a
    // name become available with nothing saying who gave it up.
    //
    // Its comparison binds a clock of its own, and a binding is formatted in the value's own zone,
    // so an application clock there reads UTC digits against the host's wall clock. East of UTC
    // every lease this package permits is shorter than the offset, so the guard is not weakened
    // but defeated.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->freezeTime();

    app(Locks::class)->acquire($this->session, 'deploy', 300, false);

    // Straight to the row: no store method makes a session that has gone while still holding one.
    AgentSession::query()->whereKey($this->session->getKey())->update([
        'status' => AgentSessionStatus::Gone->value,
        'last_seen_at' => PresenceClock::now()->subDays(400),
    ]);

    config()->set('app.timezone', 'Asia/Kolkata');
    date_default_timezone_set('Asia/Kolkata');

    expect(app(SessionPresence::class)->prune(PresenceClock::now()->subDays(30)))->toBe(0)
        ->and(AgentSession::query()->whereKey($this->session->getKey())->exists())->toBeTrue()
        ->and(Lock::query()->where('name', 'deploy')->value('holder_id'))->toBe($this->session->getKey());

    // The control, so this cannot pass by never pruning: once the lease has genuinely lapsed the
    // session stops being held back, with the host still on the moved clock.
    $this->travelTo(PresenceClock::now()->addSeconds(400));

    expect(app(SessionPresence::class)->prune(PresenceClock::now()->subDays(30)))->toBe(1)
        ->and(AgentSession::query()->whereKey($this->session->getKey())->exists())->toBeFalse();

    date_default_timezone_set('UTC');
});

it('stamps the retention clock on a force release, so the row ages on one clock', function (): void {
    // **`updated_at` is the retention column, and every path writes it.** The three writes that
    // set it and nothing else -- `forceRelease()`, the orphan release, and `prune()`'s own
    // takeability cutoff -- are invisible to a test that only asks whether a lease is held, so
    // reverting them to the application clock left the suite green. What they decide is when a row
    // ages out, which is what these three cases assert.
    config()->set('robot-council.retention.locks_days', 1);

    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->travelTo(Carbon::parse('2026-01-01 00:00:00', 'UTC'));

    app(Locks::class)->acquire($this->session, 'deploy', 300, false);

    // East of UTC, so the host's wall clock stamps a LATER instant than the true one and the row
    // reads as younger than it is -- it would outlive its retention by the offset.
    config()->set('app.timezone', 'Asia/Kolkata');
    date_default_timezone_set('Asia/Kolkata');

    expect(app(Locks::class)->forceRelease($this->session, 'deploy'))->toBe(Outcome::Applied);

    $this->travelTo(Carbon::parse('2026-01-02 01:00:00', 'UTC'));

    expect(app(Locks::class)->prune(PresenceClock::now()->subDays(1)))->toBe(1)
        ->and(Lock::query()->where('name', 'deploy')->exists())->toBeFalse();

    date_default_timezone_set('UTC');
});

it("stamps the retention clock when a gone session's locks are given back", function (): void {
    config()->set('robot-council.retention.locks_days', 1);

    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->travelTo(Carbon::parse('2026-01-01 00:00:00', 'UTC'));

    app(Locks::class)->acquire($this->session, 'deploy', 300, false);

    AgentSession::query()->whereKey($this->session->getKey())
        ->update(['status' => AgentSessionStatus::Gone->value]);

    config()->set('app.timezone', 'Asia/Kolkata');
    date_default_timezone_set('Asia/Kolkata');

    expect(app(Locks::class)->releaseOrphaned())->toBe(1);

    $this->travelTo(Carbon::parse('2026-01-02 01:00:00', 'UTC'));

    expect(app(Locks::class)->prune(PresenceClock::now()->subDays(1)))->toBe(1)
        ->and(Lock::query()->where('name', 'deploy')->exists())->toBeFalse();

    date_default_timezone_set('UTC');
});

it('decides a held lock is free for pruning on the fixed clock', function (): void {
    // `prune()` treats a lapsed lease as already free, and that comparison has a clock of its own.
    // West of UTC the host's wall clock is EARLIER, so a lease that lapsed within the offset reads
    // as still running and the row is kept past its retention.
    //
    // **The lapse has to be shorter than the offset, or the bug cannot show.** A first draft let
    // the lease lapse by 25 hours against a six-hour offset, where both clocks agree it is over --
    // the test passed against the defect and proved nothing.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->travelTo(Carbon::parse('2026-01-02 01:00:00', 'UTC'));

    // Written to the row, because the store will not produce this state inside one test: a lock
    // untouched for a day whose lease lapsed an hour ago would need a renewal, and a renewal
    // stamps `updated_at` too.
    Lock::query()->insert([
        'name' => 'deploy',
        'holder_id' => $this->session->getKey(),
        'fence' => 1,
        'acquired_at' => '2026-01-01 00:00:00',
        'expires_at' => '2026-01-02 00:00:00',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    config()->set('app.timezone', 'America/Chicago');
    date_default_timezone_set('America/Chicago');

    expect(app(Locks::class)->prune(PresenceClock::now()->subDays(1)))->toBe(1)
        ->and(Lock::query()->where('name', 'deploy')->exists())->toBeFalse();

    date_default_timezone_set('UTC');
});
