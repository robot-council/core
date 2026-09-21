<?php

declare(strict_types=1);

/**
 * The dashboard's only writing panel: who may change authorization, and what a change reaches.
 *
 * The load-bearing half is that **the gate is on the action**. A Livewire action is a POST to
 * `/livewire/update` carrying a snapshot, so a control this package declines to render is markup a
 * client can simply not need -- and `Livewire::test()` calls the action with no page and no HTTP
 * middleware at all, which makes it the right instrument for that claim rather than a weaker one.
 * `Mechanisms\PersistentMiddleware` returns early for anything that is not a real request to the
 * update endpoint, so a component that relied on middleware would pass a page test and refuse
 * nobody here.
 *
 * @command  vendor/bin/pest --compact tests/AdministrationTest.php
 */

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use RobotCouncil\Access\Ability;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\FleetFeed;
use RobotCouncil\Tests\Fixtures\HostileContent;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // Two accounts, deliberately: the allowlist admits both, and only one of them is an admin.
    // A suite where every developer is an admin cannot tell the dashboard's gate apart from the
    // admin gate, and this panel is the one place the difference decides anything.
    $this->setAccessLists(developers: [4242, 4243], admins: [4242]);

    $this->admin = $this->enrollDeveloper(4242, login: 'octoadmin');
    $this->developer = $this->enrollDeveloper(4243, login: 'octodev');
});

/**
 * An installation belonging to the plain developer, with a live session under it.
 *
 * The case and the developer are passed rather than reached for through `test()`, which widens to
 * `TestCall|HigherOrderTapProxy` and leaves the analyzer unable to see either the helpers or the
 * properties. `requestDeviceCode()` in `tests/Pest.php` takes its case for the same reason.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  User  $developer  The developer the installation belongs to.
 * @return array{Installation, AgentSession, string} The installation, its session, and the
 *                                                   session's plaintext token.
 */
function installationWithSession(TestCase $case, User $developer): array
{
    $installation = $case->approveInstallation($developer, [Ability::EventsPost->value]);

    [$session, $token] = $case->startAgentSession($installation);

    return [$installation, $session, $token];
}

it('grants an ability, and rewrites the session tokens already in flight', function (): void {
    [$installation, $session] = installationWithSession($this, $this->developer);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('grant', $installation->id, Ability::TasksCreate->value);

    // The stored row, read fresh rather than from the instance the call was handed
    expect($installation->refresh()->abilities())
        ->toContain(Ability::TasksCreate->value)
        ->toContain(Ability::EventsPost->value);

    // And the token already issued, which is the half that matters: a session token lives for an
    // hour, so a grant that only changed the installation would not reach a running process until
    // it happened to renew.
    $abilities = $session->tokens()->get()->pluck('abilities')->all();

    // `not->toBeEmpty()` first, and it is load-bearing: `each` over an empty array asserts
    // nothing and passes, so without it this test would stay green against a session holding no
    // tokens at all -- which is exactly what a broken grant would leave behind.
    expect($abilities)->not->toBeEmpty()
        ->each->toContain(Ability::TasksCreate->value);
});

it('revokes an ability, and takes it off the tokens already in flight', function (): void {
    [$installation, $session] = installationWithSession($this, $this->developer);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('revokeAbility', $installation->id, Ability::EventsPost->value);

    $carried = $session->tokens()->get()->pluck('abilities')->all();

    expect($installation->refresh()->abilities())->not->toContain(Ability::EventsPost->value)
        ->and($carried)->not->toBeEmpty()
        ->each->not->toContain(Ability::EventsPost->value);
});

it('refuses a signed-in developer who is not an admin', function (string $action, array $arguments): void {
    [$installation, $session] = installationWithSession($this, $this->developer);

    // The ids are resolved here rather than passed in, because a dataset is built before the
    // database has anything in it
    $arguments = array_map(static fn (mixed $name): int|string => match ($name) {
        'installation' => $installation->id,
        'session' => $session->id,
        default => stringValue($name),
    }, $arguments);

    // Mounted as the admin, so the component exists and holds a valid snapshot -- then the same
    // account loses its admin rights and calls the action. That is the real shape of this: a
    // developer whose admin status is taken away is still holding a page that renders every
    // control, and nothing re-runs the dashboard's route middleware on `/livewire/update`.
    $component = Livewire::actingAs($this->admin)->test(Administration::class);

    $this->setAccessLists(developers: [4242, 4243], admins: []);

    // A status rather than a thrown exception, because Livewire's test harness renders both
    // `AuthorizationException` and `HttpException` into a response instead of propagating them
    // (`SupportTesting\RequestBroker` excepts exactly those two from `withoutExceptionHandling`).
    // A test written to expect a throw fails on a component that refuses correctly, and
    // `Testable::__call` forwards these assertions to the response underneath.
    $component->call($action, ...$arguments)->assertForbidden();

    // And the refusal was a refusal: nothing moved
    expect($installation->refresh()->revoked_at)->toBeNull()
        ->and($installation->abilities())->toBe([Ability::EventsPost->value])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Active);
})
    ->with([
        'grant' => ['grant', ['installation', 'tasks:create']],
        'revoke an ability' => ['revokeAbility', ['installation', 'events:post']],
        'revoke an installation' => ['revokeInstallation', ['installation']],
        'revoke a session' => ['revokeSession', ['session']],
        'render' => ['$refresh', []],
    ]);

it('refuses to mount for a developer who was never an admin', function (): void {
    // The control for the test above, and a different claim: that one shows the action re-checks,
    // this one shows a non-admin cannot get a component in the first place. Without it, a
    // component that authorized only in `mount()` would pass the test above for the wrong reason.
    Livewire::actingAs($this->developer)->test(Administration::class)->assertForbidden();

    // The other half of the pair: the same mount for the admin is not forbidden, so the assertion
    // above is about who is asking rather than about the component being broken for everyone
    Livewire::actingAs($this->admin)->test(Administration::class)->assertOk();
});

it('refuses an ability outside the grantable list, and changes nothing', function (string $ability): void {
    [$installation] = installationWithSession($this, $this->developer);

    $before = $installation->abilities();

    // 422 rather than a throw, for the reason the refusal test above records: Livewire's harness
    // renders an `HttpException` into a response. 422 rather than 403 deliberately -- the caller
    // is an admin and is allowed here; the value they sent is the thing being refused.
    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('grant', $installation->id, $ability)
        ->assertStatus(422);

    expect($installation->refresh()->abilities())->toBe($before);
})->with([
    // Sanctum reads this as every ability, so it is the one value that must never be stored
    'the wildcard' => ['*'],

    // A real case, and not grantable: it belongs to an installation credential rather than to a
    // session token, so granting it would write a value no guard ever checks
    'the installation credential' => ['sessions:start'],

    'an unknown name' => ['tasks:destroy'],
    'an empty string' => [''],
]);

it('grants every ability the panel offers, so the refusal above is not refusing everything', function (): void {
    // The negative control for the test above. Four refusals prove nothing on their own: a `grant`
    // that threw for every input would satisfy them and be entirely broken.
    [$installation] = installationWithSession($this, $this->developer);

    $component = Livewire::actingAs($this->admin)->test(Administration::class);

    foreach (Ability::grantable() as $ability) {
        $component->call('grant', $installation->id, $ability->value);
    }

    // Compared as a set: `events:post` was granted before the loop ran, so it keeps its position
    // in the stored array and the list is not in `grantable()` order
    $held = $installation->refresh()->abilities();

    sort($held);

    $expected = Ability::values(Ability::grantable());

    sort($expected);

    expect($held)->toBe($expected);
});

it('revokes an installation, and its credential stops working on the next request', function (): void {
    /** @var Installation $installation */
    $installation = $this->approveInstallation($this->developer);

    $credential = $this->installationCredential($installation);

    // It works first, or the assertion below passes against a credential that never worked
    $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertCreated();

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('revokeInstallation', $installation->id);

    $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertUnauthorized();

    expect($installation->refresh()->revoked_at)->not->toBeNull();
});

it('revokes one session, and its token stops working on the next request', function (): void {
    [$installation, $session, $token] = installationWithSession($this, $this->developer);

    $this->machine($token)->getJson(route('robot-council.events.index'))->assertOk();

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('revokeSession', $session->id);

    $this->machine($token)->getJson(route('robot-council.events.index'))->assertUnauthorized();

    // The session, not the installation: revoking one process must not take the machine with it
    expect($session->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and($installation->refresh()->revoked_at)->toBeNull();
});

it('writes an event for every change, and the change feed shows it', function (): void {
    [$installation, $session] = installationWithSession($this, $this->developer);

    $component = Livewire::actingAs($this->admin)->test(Administration::class);

    $component->call('grant', $installation->id, Ability::TasksCreate->value);
    $component->call('revokeAbility', $installation->id, Ability::EventsPost->value);
    $component->call('revokeSession', $session->id);
    $component->call('revokeInstallation', $installation->id);

    $recorded = FleetEvent::query()->orderBy('id')->get();

    $types = $recorded->pluck('type')->all();

    expect($types)->toContain(FleetEventType::InstallationAbilityGranted)
        ->toContain(FleetEventType::InstallationAbilityRevoked)
        ->toContain(FleetEventType::SessionGone)
        ->toContain(FleetEventType::InstallationRevoked);

    // The feed the dashboard reads, not the table: #29's visibility rule decides what reaches a
    // reader, and an authorization change nobody can see is not "visible in the same feed as
    // everything else".
    $shown = $this->service(FleetFeed::class)->latest(50);

    $shownTypes = array_map(static fn (array $event): mixed => $event['type'], $shown);

    foreach ([
        FleetEventType::InstallationAbilityGranted->value,
        FleetEventType::InstallationAbilityRevoked->value,
        FleetEventType::InstallationRevoked->value,
    ] as $type) {
        expect($shownTypes)->toContain($type);
    }
});

it('names the admin who made the change, rather than leaving it unattributed', function (): void {
    [$installation] = installationWithSession($this, $this->developer);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('grant', $installation->id, Ability::TasksCreate->value);

    $event = FleetEvent::query()
        ->where('type', FleetEventType::InstallationAbilityGranted)
        ->firstOrFail();

    // The admin's key, not the installation owner's. Those are different accounts here precisely
    // so that a copy of the wrong one cannot pass.
    expect($event->user_id)->toBe(keyValue($this->admin->getKey()))
        ->and($event->user_id)->not->toBe(keyValue($this->developer->getKey()));

    // And the feed resolves it to a login, which is what the dashboard renders
    $shown = collect($this->service(FleetFeed::class)->latest(50))
        ->firstWhere('type', FleetEventType::InstallationAbilityGranted->value);

    expect(arrayValue($shown['actor'] ?? [])['github_login'] ?? null)->toBe('octoadmin');
});

it('shows no credential, device code, or verifier on the page', function (): void {
    /** @var Installation $installation */
    $installation = $this->approveInstallation($this->developer);

    $credential = $this->installationCredential($installation);

    [, $sessionToken] = $this->startAgentSession($installation);

    $html = Livewire::actingAs($this->admin)->test(Administration::class)->html();

    // The page rendered the installation at all, so the absences below are absences rather than an
    // empty render
    expect($html)->toContain('workbench');

    // Both halves of each plaintext token: Sanctum's form is `<id>|<plain>`, and the id alone is
    // not a secret, so asserting on the whole string only would miss a page that printed the
    // hashed half or the tail.
    foreach ([$credential, $sessionToken] as $secret) {
        expect($html)->not->toContain($secret)
            ->not->toContain(explode('|', $secret)[1] ?? $secret);
    }

    // Nothing hashed either, which is what a naive `$installation->tokens` render would print
    $hashes = $installation->tokens()->get()->pluck('token')->all();

    expect($hashes)->not->toBeEmpty();

    foreach ($hashes as $hash) {
        expect($html)->not->toContain(stringValue($hash));
    }
});

it('renders a hostile machine label inert on the admin panel', function (string $payload, array $forbidden, ?string $escaped): void {
    // `machine_label` is `varchar(64)`, and Postgres refuses an overlong value where SQLite stores
    // it whole -- so a payload that does not fit would pass locally and fail only in the `postgres`
    // job. Asserted rather than left to chance.
    expect(mb_strlen($payload))->toBeLessThanOrEqual(64);

    /** @var Installation $installation */
    $installation = $this->approveInstallation($this->developer);

    // Written past the endpoint's validation deliberately: `MachineIdentity` charset-limits this
    // field, so the string cannot arrive through the API and the page's escaping has to be its own
    // guarantee rather than the validator's.
    $installation->forceFill(['machine_label' => $payload])->save();

    $html = Livewire::actingAs($this->admin)->test(Administration::class)->html();

    expect($html)->toContain($escaped ?? $payload);

    foreach ($forbidden as $live) {
        expect($html)->not->toContain($live);
    }
})->with(HostileContent::dataset());
