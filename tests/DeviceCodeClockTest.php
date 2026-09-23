<?php

declare(strict_types=1);

/**
 * A device code's lifetime measures elapsed time, not wall-clock difference.
 *
 * **The third instance of the same defect, and the last one that could be fixed.** `#51` moved
 * presence onto `Support\PresenceClock` and `#149` moved a lock's lease; each left this one alone,
 * and `#149`'s first draft then described the whole remainder as Sanctum-bound, which was wrong.
 * Nothing outside this package reads a device code's `expires_at`: `Support\Credentials` writes it
 * and `Support\DeviceCodes` is its only reader, so there was never a second side to keep in step.
 *
 * `credentials.device_code_ttl_seconds` defaults to **600** and is clamped there, an order of
 * magnitude shorter than a daylight-saving transition. At spring forward every outstanding code
 * read as expired at once, which is recoverable and wrong; at fall back a code stayed claimable
 * for an hour past the ten minutes the config calls this package's ceiling, which is the direction
 * that matters for an unclaimed enrollment code (#160).
 *
 * Laravel's default `app.timezone` is `UTC`, so none of this is observable on a default host --
 * which is why these tests move the timezone rather than trusting the default.
 *
 * @command  vendor/bin/pest --compact tests/DeviceCodeClockTest.php
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\DeviceCodeError;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\PresenceClock;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);
});

it('writes an expiry whose digits do not follow the host clock', function (string $zone): void {
    $this->freezeTime();

    config()->set('app.timezone', $zone);
    date_default_timezone_set($zone);

    $this->service(DeviceCodes::class)->issue(['tasks:create'], 'claude-code', 'workbench', hash('sha256', 'v'), null);

    // **The RAW column, never the hydrated model.** `PresenceTimestamp::get()` labels every value
    // it returns with the presence clock unconditionally, so asking the model for its timezone
    // answers `UTC` whatever clock wrote the row. What the PRE-#160 defect changed is the digits
    // stored, and this reads those.
    //
    // **It discriminates neither half of the fix on its own, which is worth naming rather than
    // leaving for somebody to discover.** Revert the write alone and `PresenceTimestamp::set()`
    // still converts to UTC; revert the cast alone and `setAttribute()` takes the date branch,
    // whose `fromDateTime()` preserves the Carbon's own zone -- already UTC. Both give these same
    // digits. The write is controlled by the label assertion at the end of this file and the cast
    // by the read-back test above it.
    $stored = DB::table('robot_council_device_codes')->value('expires_at');

    expect($stored)->toBeString()
        ->and(substr(\is_string($stored) ? $stored : '', 0, 19))
        ->toBe(PresenceClock::now()->addSeconds(600)->format('Y-m-d H:i:s'));

    date_default_timezone_set('UTC');
})->with([
    'UTC' => ['UTC'],
    'a zone that springs forward' => ['America/New_York'],
    'a zone ahead of UTC' => ['Europe/Berlin'],
    'a zone with a half-hour offset' => ['Asia/Kolkata'],
]);

it('keeps a code claimable when only the timezone moves and no time passes', function (): void {
    // **The assertion this ticket is really about.** No time passes at all -- only the zone the
    // application reads its clock in. A ten-minute lifetime measured on `app.timezone` would see an
    // hour appear out of nowhere, and a developer part-way through `robot-council enroll` would be
    // told their code had lapsed.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->freezeTime();

    $issued = $this->service(DeviceCodes::class)
        ->issue(['tasks:create'], 'claude-code', 'workbench', hash('sha256', 'verifier'), null);

    $this->service(DeviceCodes::class)->approve($issued->record, '4242', ['tasks:create']);

    foreach (['Europe/London', 'America/New_York', 'Asia/Kolkata', 'Pacific/Auckland'] as $zone) {
        config()->set('app.timezone', $zone);
        date_default_timezone_set($zone);

        // Asked the way the defect would answer it -- can a live code still be found and consumed --
        // rather than by reading a column and judging for itself.
        expect($this->service(DeviceCodes::class)->findByUserCode($issued->record->user_code))
            ->not->toBeNull("moving to {$zone} should not expire a live code");
    }

    // And the exchange itself, which is the half a lookup cannot cover: `consume()` compares the
    // expiry in SQL and then re-reads the row and asks `isPast()` in PHP.
    expect($this->service(DeviceCodes::class)->consume($issued->deviceCode, 'verifier'))
        ->toBeInstanceOf(DeviceCode::class);

    date_default_timezone_set('UTC');
});

it('still expires a code that genuinely elapsed, so the fix cannot pass by never expiring', function (): void {
    // **The control for the test above.** A clock that never expired anything would satisfy it
    // completely, and an enrollment code that outlives its ceiling is a worse defect than the one
    // being fixed -- the ceiling is the bound on how long an unclaimed code is worth stealing.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->travelTo(Carbon::parse('2026-01-01 12:00:00', 'UTC'));

    $issued = $this->service(DeviceCodes::class)
        ->issue(['tasks:create'], 'claude-code', 'workbench', hash('sha256', 'verifier'), null);

    $this->service(DeviceCodes::class)->approve($issued->record, '4242', ['tasks:create']);

    // Past the 600-second lifetime, with the zone held still, so elapsed time is the only variable.
    $this->travelTo(Carbon::parse('2026-01-01 12:11:00', 'UTC'));

    expect($this->service(DeviceCodes::class)->findByUserCode($issued->record->user_code))->toBeNull()
        ->and($this->service(DeviceCodes::class)->consume($issued->deviceCode, 'verifier'))
        ->toBe(DeviceCodeError::ExpiredToken);
});

it('prunes on the clock the column is written on', function (): void {
    // `robot-council:prune-device-codes` is the other reader of this column, and it compares a
    // clock of its own. West of UTC an application clock reads a lapsed code as still live and the
    // row is kept; east of it, a live code is deleted out from under a developer mid-enrollment.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->travelTo(Carbon::parse('2026-01-01 12:00:00', 'UTC'));

    $this->service(DeviceCodes::class)->issue(['tasks:create'], 'claude-code', 'workbench', hash('sha256', 'v'), null);

    // Nine minutes on: one minute of life left, which is well inside every offset below.
    $this->travelTo(Carbon::parse('2026-01-01 12:09:00', 'UTC'));

    config()->set('app.timezone', 'Pacific/Auckland');
    date_default_timezone_set('Pacific/Auckland');

    expect($this->service(DeviceCodes::class)->prune())->toBe(0)
        ->and(DeviceCode::query()->count())->toBe(1);

    // The control, so this cannot pass by never pruning: past the lifetime it goes, with the host
    // still on the moved clock.
    $this->travelTo(Carbon::parse('2026-01-01 12:11:00', 'UTC'));

    expect($this->service(DeviceCodes::class)->prune())->toBe(1)
        ->and(DeviceCode::query()->count())->toBe(0);

    date_default_timezone_set('UTC');
});

it('reads an expiry back on the same clock it was written on', function (): void {
    // The cast, which is the half a `where` cannot cover: `consume()` re-reads the row and asks
    // `expires_at->isPast()` in PHP, so a value relabelled on hydration is wrong in exactly the
    // path that decides whether an enrollment still works.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $this->freezeTime();

    $issued = $this->service(DeviceCodes::class)
        ->issue(['tasks:create'], 'claude-code', 'workbench', hash('sha256', 'v'), null);

    $writtenAt = PresenceClock::now();

    config()->set('app.timezone', 'Pacific/Auckland');
    date_default_timezone_set('Pacific/Auckland');

    $code = DeviceCode::query()->whereKey($issued->record->getKey())->sole();

    // **Compared as timestamps, not with `equalTo`.** The column is a `dateTime`, which stores
    // whole seconds, while a frozen clock carries microseconds -- and Carbon's `equalTo` compares
    // those too, so it answers false for two values naming the same second.
    expect($code->expires_at->getTimestamp())->toBe($writtenAt->copy()->addSeconds(600)->getTimestamp())
        ->and($code->expires_at->getTimezone()->getName())->toBe(PresenceClock::ZONE)
        ->and($code->expires_at->isPast())->toBeFalse();

    date_default_timezone_set('UTC');
});

it('hands back an expiry already labelled with the clock it belongs to', function (): void {
    // **The comment on `deviceCodeExpiry()` used to say no test could tell this from
    // `Carbon::now()`. That was wrong, and the refuting pattern was sixty lines away in this same
    // suite**: `PresenceClockTest` asserts exactly this on `staleCutoff()` and `goneCutoff()`, the
    // two structurally identical siblings.
    //
    // The instants are the same either way -- "now plus the TTL" -- so the STORED digits cannot
    // discriminate, which is the narrow claim that was true. The label can, and it is not a
    // tautology: it is the guarantee the method's return type advertises, and it is what a
    // non-Eloquent consumer would depend on. `Support\Locks` is already one, writing through the
    // query builder where the label decides the digits.
    config()->set('app.timezone', 'Asia/Kolkata');
    date_default_timezone_set('Asia/Kolkata');

    $this->freezeTime();

    $credentials = app(Credentials::class);

    expect($credentials->deviceCodeExpiry()->getTimezone()->getName())->toBe(PresenceClock::ZONE)
        // And it is the same instant the application clock would have named, so the label is the
        // only thing that moved -- a fix that shifted the moment would be a different defect.
        ->and($credentials->deviceCodeExpiry()->getTimestamp())
        ->toBe(Carbon::now()->addSeconds(600)->getTimestamp());

    date_default_timezone_set('UTC');
});
