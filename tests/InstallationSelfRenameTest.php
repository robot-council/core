<?php

declare(strict_types=1);

/**
 * An installation renames itself with its own credential (#590).
 *
 * The rules are WHO may call it, WHAT it renames, and HOW each refusal reads, so each test asserts on
 * the rows afterwards as well as on the response: a refusal proved on the status alone would pass
 * against a route that refused and renamed anyway.
 *
 * @command  vendor/bin/pest --compact tests/InstallationSelfRenameTest.php
 */

use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [6101, 6102]);

    $this->alice = $this->enrollDeveloper(6101, login: 'alice-dev');
    $this->bob = $this->enrollDeveloper(6102, login: 'bob-dev');

    $this->office = $this->approveInstallation($this->alice, 'office-mac');
    $this->credential = $this->installationCredential($this->office);
});

it('renames its own installation, and names the old and new labels', function (): void {
    $this->machine($this->credential)
        ->patchJson(route('robot-council.installation.rename'), ['machine_label' => 'studio-mac'])
        ->assertOk()
        ->assertExactJson([
            'installation_id' => $this->office->id,
            'harness' => 'claude-code',
            'old_label' => 'office-mac',
            'new_label' => 'studio-mac',
        ]);

    expect($this->office->refresh()->machine_label)->toBe('studio-mac');

    $event = FleetEvent::query()->where('type', FleetEventType::InstallationRenamed)->sole();

    expect($event->meta)->toMatchArray(['old_label' => 'office-mac', 'new_label' => 'studio-mac'])
        ->and($event->actor_user_id)->toBe($event->user_id);
});

it('renames only the caller, whatever else the request names', function (): void {
    $other = $this->approveInstallation($this->alice, 'laptop');

    $this->machine($this->credential)
        ->patchJson(route('robot-council.installation.rename'), ['machine_label' => 'studio-mac', 'installation_id' => $other->id, 'id' => $other->id])
        ->assertOk();

    expect($this->office->refresh()->machine_label)->toBe('studio-mac')
        ->and($other->refresh()->machine_label)->toBe('laptop');
});

it('refuses a session token, and renames nothing', function (): void {
    [, $token] = $this->startAgentSession($this->office);

    $this->machine($token)
        ->patchJson(route('robot-council.installation.rename'), ['machine_label' => 'studio-mac'])
        ->assertUnauthorized();

    expect($this->office->refresh()->machine_label)->toBe('office-mac')
        ->and(FleetEvent::query()->where('type', FleetEventType::InstallationRenamed)->count())->toBe(0);
});

it('refuses a request with no credential, and renames nothing', function (): void {
    $this->patchJson(route('robot-council.installation.rename'), ['machine_label' => 'studio-mac'])->assertUnauthorized();

    expect($this->office->refresh()->machine_label)->toBe('office-mac');
});

it("renames another developer's installation only through that installation's own credential", function (): void {
    $theirs = $this->approveInstallation($this->bob, 'bob-mac');

    $this->machine($this->installationCredential($theirs))
        ->patchJson(route('robot-council.installation.rename'), ['machine_label' => 'office-mac'])
        ->assertOk()
        ->assertJsonPath('old_label', 'bob-mac');

    // Alice's machine carries the same label as Bob's now, which are different developers'
    expect($this->office->refresh()->machine_label)->toBe('office-mac')
        ->and($theirs->refresh()->machine_label)->toBe('office-mac');
});

it('refuses a label that enrollment would refuse, in words', function (string $label): void {
    $response = $this->machine($this->credential)
        ->patchJson(route('robot-council.installation.rename'), ['machine_label' => $label])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('machine_label');

    expect($response->json('errors.machine_label.0'))->toBeString()->not->toBeEmpty()
        ->and($this->office->refresh()->machine_label)->toBe('office-mac');
})->with([
    'a space' => 'my mac',
    'a slash' => 'my/mac',
    'too long' => str_repeat('a', 65),
    'empty' => '',
]);

it('refuses a missing label', function (): void {
    $this->machine($this->credential)
        ->patchJson(route('robot-council.installation.rename'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('machine_label');
});

it('refuses a label another live installation of the same developer and harness holds, and says which', function (): void {
    $this->approveInstallation($this->alice, 'laptop');

    $response = $this->machine($this->credential)
        ->patchJson(route('robot-council.installation.rename'), ['machine_label' => 'laptop'])
        ->assertUnprocessable();

    expect($response->json('errors.machine_label.0'))->toContain('already called laptop')
        ->and($this->office->refresh()->machine_label)->toBe('office-mac')
        ->and(FleetEvent::query()->where('type', FleetEventType::InstallationRenamed)->count())->toBe(0);
});

it('answers a label it already carries as a conflict, in words, and records nothing', function (): void {
    $response = $this->machine($this->credential)
        ->patchJson(route('robot-council.installation.rename'), ['machine_label' => 'office-mac'])
        ->assertStatus(409);

    expect($response->json('message'))->toContain('already called office-mac')
        ->and(FleetEvent::query()->where('type', FleetEventType::InstallationRenamed)->count())->toBe(0);
});

it('stops answering once the installation is revoked', function (): void {
    $this->office->forceFill(['revoked_at' => now()])->save();

    $this->machine($this->credential)
        ->patchJson(route('robot-council.installation.rename'), ['machine_label' => 'studio-mac'])
        ->assertUnauthorized();

    expect(Installation::query()->whereKey($this->office->id)->value('machine_label'))->toBe('office-mac');
});
