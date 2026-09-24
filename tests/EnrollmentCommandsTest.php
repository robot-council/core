<?php

declare(strict_types=1);

/**
 * The admin commands: revoking an installation or a session, and pruning expired device codes.
 *
 * **The per-ability grant and revoke commands were retired by `robot-council/core#231`**, with the
 * tests that covered them. One of those is worth naming rather than just deleting: a test asserting
 * that revoking `coordinator:direct` mid-request could not be outrun by a session starting at the
 * same moment. That race stopped existing when `robot-council/core#222` made
 * `Support\AgentSessions::start()` write `Access\Role::Build` unconditionally -- both sides of its
 * assertion became `Build`, so no implementation could fail it.
 *
 * Each is checked by what happens to a live credential on the next request, not only by what the
 * rows say. A revocation that leaves a token working is not a revocation.
 *
 * @command  vendor/bin/pest --compact tests/EnrollmentCommandsTest.php
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\Installation;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
    $this->credential = $this->installationCredential($this->installation);
});

it('revoking an installation stops its credential and every session token it issued', function (): void {
    [, $first] = $this->startAgentSession($this->installation);
    [, $second] = $this->startAgentSession($this->installation);

    expect(Artisan::call('robot-council:revoke-installation', [
        'installation' => $this->installation->getKey(),
    ]))->toBe(0);

    $this->machine($this->credential)->postJson(route('robot-council.sessions.start'))->assertUnauthorized();
    $this->machine($first)->getJson(route('robot-council.agent.session'))->assertUnauthorized();
    $this->machine($second)->getJson(route('robot-council.agent.session'))->assertUnauthorized();

    expect($this->installation->refresh()->revoked_at)->not->toBeNull()
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('revoking an installation leaves another installation alone', function (): void {
    $other = $this->approveInstallation($this->developer, machineLabel: 'laptop');
    $otherCredential = $this->installationCredential($other);

    Artisan::call('robot-council:revoke-installation', ['installation' => $this->installation->getKey()]);

    $this->machine($otherCredential)->postJson(route('robot-council.sessions.start'))->assertCreated();
});

it('revoking a session ends only that session, and it can never be renewed', function (): void {
    [$revoked, $revokedToken] = $this->startAgentSession($this->installation);
    [, $keptToken] = $this->startAgentSession($this->installation);

    expect(Artisan::call('robot-council:revoke-session', ['session' => $revoked->getKey()]))->toBe(0);

    $this->machine($revokedToken)->getJson(route('robot-council.agent.session'))->assertUnauthorized();
    $this->machine($keptToken)->getJson(route('robot-council.agent.session'))->assertOk();

    // Marked gone as well, so the installation's credential cannot renew it back into service
    expect($revoked->refresh()->hasGone())->toBeTrue();

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $revoked->getKey()]))
        ->assertStatus(409);
});

it('refuses a session that does not exist', function (): void {
    expect(Artisan::call('robot-council:revoke-session', ['session' => 987654]))->toBe(1);
});

it('prunes expired device codes and leaves live ones alone', function (): void {
    $live = requestDeviceCode($this);

    $this->travelTo(now()->addSeconds(601));

    $stillLive = requestDeviceCode($this);

    expect(DeviceCode::query()->count())->toBe(2)
        ->and(Artisan::call('robot-council:prune-device-codes'))->toBe(0)
        ->and(DeviceCode::query()->pluck('id')->all())->toBe([$stillLive['record']->id])
        ->and(DeviceCode::query()->whereKey($live['record']->id)->exists())->toBeFalse();
});

it('prunes an expired code whether or not it was decided', function (): void {
    $decided = requestDeviceCode($this);

    $this->actingAs($this->developer, 'web')->post(route('robot-council.enroll.approve'), [
        'user_code' => $decided['record']->user_code,
        'confirmed' => '1',
    ])->assertRedirect();

    $this->travelTo(now()->addSeconds(601));

    Artisan::call('robot-council:prune-device-codes');

    // Nothing beyond the fixture: an approval alone creates no installation, because only an
    // exchange does, and the code expired before one happened
    expect(DeviceCode::query()->count())->toBe(0)
        ->and(Installation::query()->whereKeyNot($this->installation->getKey())->count())->toBe(0);
});

it('schedules the prune', function (): void {
    $scheduled = collect($this->service(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'robot-council:prune-device-codes'))
        ->values();

    expect($scheduled)->toHaveCount(1);

    $prune = $scheduled->first();

    expect($prune)->toBeInstanceOf(Event::class)
        ->and($prune instanceof Event ? $prune->expression : null)->toBe('0 * * * *');
});

it('ends a session through the store without touching its installation', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    Artisan::call('robot-council:revoke-session', ['session' => $session->getKey()]);

    expect($this->installation->refresh()->revoked_at)->toBeNull()
        ->and(AgentSession::query()->count())->toBe(1);

    // The installation can still start a new session, which is what the process does next
    $this->machine($this->credential)->postJson(route('robot-council.sessions.start'))->assertCreated();
});
