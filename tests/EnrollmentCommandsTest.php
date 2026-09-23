<?php

declare(strict_types=1);

/**
 * The admin commands: granting and revoking one ability, revoking an installation or a session, and
 * pruning expired device codes.
 *
 * Each is checked by what happens to a live credential on the next request, not only by what the
 * rows say. A revocation that leaves a token working is not a revocation.
 *
 * @command  vendor/bin/pest --compact tests/EnrollmentCommandsTest.php
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\Installation;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);
    $this->credential = $this->installationCredential($this->installation);
});

it('grants the coordinator ability to the next session, and leaves the running one alone', function (): void {
    [, $token] = $this->startAgentSession($this->installation);

    expect(Artisan::call('robot-council:grant-ability', [
        'installation' => $this->installation->getKey(),
        'ability' => Ability::CoordinatorDirect->value,
    ]))->toBe(0)
        ->and($this->installation->refresh()->granted_abilities)->toBe([Ability::TasksCreate->value, Ability::CoordinatorDirect->value]);

    // **The session already running is untouched, deliberately.** A role is chosen when a session
    // starts, and `Installations::demoteSessions()` narrows without ever widening -- so a grant
    // cannot promote a process that is mid-run into something the developer did not start.
    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['role' => Role::Build->value, 'abilities' => Role::Build->tokenAbilities()]);

    // The next one is the coordinator, which is what the grant bought
    [, $next] = $this->startAgentSession($this->installation->refresh());

    $this->machine($next)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['role' => Role::Coordinator->value, 'abilities' => Role::Coordinator->tokenAbilities()]);
});

it('demotes the sessions already in flight when the coordinator ability is revoked', function (): void {
    Artisan::call('robot-council:grant-ability', [
        'installation' => $this->installation->getKey(),
        'ability' => Ability::CoordinatorDirect->value,
    ]);

    [$session, $token] = $this->startAgentSession($this->installation->refresh());

    expect($session->role)->toBe(Role::Coordinator)
        ->and(Artisan::call('robot-council:revoke-ability', [
            'installation' => $this->installation->getKey(),
            'ability' => Ability::CoordinatorDirect->value,
        ]))->toBe(0);

    // The running process loses it now rather than in an hour, which is what makes the revocation
    // a control rather than a note about future sessions
    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['role' => Role::Build->value, 'abilities' => Role::Build->tokenAbilities()]);

    expect($session->refresh()->role)->toBe(Role::Build)
        ->and($this->installation->refresh()->granted_abilities)->toBe([Ability::TasksCreate->value]);
});

it('leaves a running session alone when an ability outside the role gate moves', function (): void {
    // The other half of the property above, and the one that says the preset is now the source: a
    // build ability going onto or off the installation changes nothing about a live session,
    // because the installation is no longer where a session's abilities come from.
    [$session, $token] = $this->startAgentSession($this->installation);

    Artisan::call('robot-council:revoke-ability', [
        'installation' => $this->installation->getKey(),
        'ability' => Ability::TasksCreate->value,
    ]);

    expect($this->installation->refresh()->granted_abilities)->toBeEmpty();

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['role' => Role::Build->value, 'abilities' => Role::Build->tokenAbilities()]);

    expect($session->refresh()->role)->toBe(Role::Build);
});

it('refuses an ability outside the fixed list', function (string $command, string $ability): void {
    expect(Artisan::call($command, [
        'installation' => $this->installation->getKey(),
        'ability' => $ability,
    ]))->toBe(1)
        ->and($this->installation->refresh()->granted_abilities)->toBe([Ability::TasksCreate->value]);
})->with([
    'the wildcard, granted' => ['robot-council:grant-ability', '*'],
    'the wildcard, revoked' => ['robot-council:revoke-ability', '*'],
    'an invention' => ['robot-council:grant-ability', 'tasks:delete'],

    // An installation credential's own ability, which belongs to no session token
    'the installation credential' => ['robot-council:grant-ability', Ability::SessionsStart->value],
]);

it('refuses an installation that does not exist', function (string $command): void {
    expect(Artisan::call($command, ['installation' => 987654, 'ability' => Ability::EventsPost->value]))->toBe(1);
})->with(['robot-council:grant-ability', 'robot-council:revoke-ability']);

it('grants an ability the installation already holds without duplicating it', function (): void {
    Artisan::call('robot-council:grant-ability', [
        'installation' => $this->installation->getKey(),
        'ability' => Ability::TasksCreate->value,
    ]);

    expect($this->installation->refresh()->granted_abilities)->toBe([Ability::TasksCreate->value]);
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

it('cannot be outrun by a session starting at the same moment', function (): void {
    // `coordinator:direct` is the ability this can still be written about. Since #221 an
    // installation's stored abilities decide which ROLE a session starts in rather than what it
    // holds, so a race over `events:post` has no observable outcome: every preset carries it
    // whichever side wins. The race over coordinator eligibility is the same race and is real.
    Artisan::call('robot-council:grant-ability', [
        'installation' => $this->installation->getKey(),
        'ability' => Ability::CoordinatorDirect->value,
    ]);

    $fired = false;

    // The revocation lands after the request has already loaded the installation -- the guard
    // resolves a token's owner before any controller runs -- and before the role is derived. An
    // implementation that derived it from the instance it arrived with would start a coordinator
    // session for a machine that is no longer one, and live with it for the session's whole
    // lifetime, while the command reported that it had taken the ability away.
    DB::listen(function (QueryExecuted $query) use (&$fired): void {
        if ($fired || ! str_contains($query->sql, 'robot_council_installations')) {
            return;
        }

        $fired = true;

        Artisan::call('robot-council:revoke-ability', [
            'installation' => $this->installation->getKey(),
            'ability' => Ability::CoordinatorDirect->value,
        ]);
    });

    $started = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'))
        ->assertCreated();

    expect($fired)->toBeTrue()
        ->and($started->json('abilities'))->toBe(Role::Build->tokenAbilities())
        ->and(AgentSession::query()->sole()->role)->toBe(Role::Build);

    $token = stringValue($started->json('token'));

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['role' => Role::Build->value, 'abilities' => Role::Build->tokenAbilities()]);
});
