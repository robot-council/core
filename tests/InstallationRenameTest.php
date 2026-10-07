<?php

declare(strict_types=1);

/**
 * Renaming an installation's machine label in place, without the machine re-enrolling (#534).
 *
 * Every refusal is asserted on the ROW afterwards as well as on what the page said, because a
 * refusal proved on the words alone would pass against a component that refused and wrote anyway.
 *
 * @command  vendor/bin/pest --compact tests/InstallationRenameTest.php
 */

use Livewire\Livewire;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Livewire\SeatSettings;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\Installations;
use RobotCouncil\Support\LaneBoard;
use RobotCouncil\Support\MachineIdentity;
use RobotCouncil\Support\Outcome;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // Three accounts: an owner, a stranger, and an administrator who owns neither machine
    $this->setAccessLists(developers: [6101, 6102, 6103], admins: [6103]);

    $this->owner = $this->enrollDeveloper(6101, login: 'owner-dev');
    $this->stranger = $this->enrollDeveloper(6102, login: 'stranger-dev');
    $this->admin = $this->enrollDeveloper(6103, login: 'admin-dev');
});

/**
 * The renames recorded, oldest first.
 *
 * @return list<FleetEvent> The events.
 */
function renameEvents(): array
{
    return array_values(FleetEvent::query()->where('type', FleetEventType::InstallationRenamed)->orderBy('id')->get()->all());
}

it('lets the developer who owns an installation rename it from their seats page', function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    Livewire::actingAs($this->owner)
        ->test(SeatSettings::class)
        ->assertSee('unknown-machine')
        ->set('labels.'.$installation->id, 'office-mac')
        ->call('renameInstallation', $installation->id)
        ->assertSet('refused', false)
        ->assertSet('said', 'Renamed: claude-code on unknown-machine is now claude-code on office-mac. Its sessions carry on under the new name.');

    expect($installation->refresh()->machine_label)->toBe('office-mac')
        ->and($installation->revoked_at)->toBeNull();

    // Recorded, naming the old label, the new one, and who changed it
    $events = renameEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->body)->toBe('claude-code on unknown-machine was renamed to office-mac.')
        ->and($events[0]->meta)->toMatchArray(['installation_id' => $installation->id, 'old_label' => 'unknown-machine', 'new_label' => 'office-mac'])
        ->and($events[0]->actor_user_id)->toBe(HostKey::from($this->owner->getKey()))
        ->and($events[0]->user_id)->toBe(HostKey::from($this->owner->getKey()));
});

it("lets an administrator rename another developer's installation from the administration page", function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->set('labels.'.$installation->id, 'office-mac')
        ->call('renameInstallation', $installation->id)
        ->assertSet('refused', false)
        ->assertSet('saidAt', $installation->id);

    expect($installation->refresh()->machine_label)->toBe('office-mac');

    // The event is ABOUT the owner and was DONE by the administrator, as a revocation is
    $events = renameEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->actor_user_id)->toBe(HostKey::from($this->admin->getKey()))
        ->and($events[0]->user_id)->toBe(HostKey::from($this->owner->getKey()))
        ->and($events[0]->meta)->toMatchArray(['old_label' => 'unknown-machine', 'new_label' => 'office-mac']);
});

it("refuses a developer renaming somebody else's installation from their seats page", function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    // A Livewire action is an ordinary POST, so the stranger can name any id and set any field
    Livewire::actingAs($this->stranger)
        ->test(SeatSettings::class)
        ->assertDontSee('unknown-machine')
        ->set('labels.'.$installation->id, 'stolen-name')
        ->call('renameInstallation', $installation->id)
        ->assertSet('refused', true)
        ->assertDontSee('unknown-machine');

    expect($installation->refresh()->machine_label)->toBe('unknown-machine')
        ->and(renameEvents())->toBeEmpty();
});

it("refuses a developer renaming somebody else's installation through the store", function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    $outcome = $this->service(Installations::class)->rename($installation->id, 'stolen-name', HostKey::from($this->stranger->getKey()), asAdmin: false);

    expect($outcome)->toBe(Outcome::Forbidden)
        ->and($installation->refresh()->machine_label)->toBe('unknown-machine')
        ->and(renameEvents())->toBeEmpty();
});

it('refuses a developer who is not an administrator on the administration page', function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    // Mounted as an administrator with a new label typed, then the rights go: the snapshot still
    // holds every control and the field
    $component = Livewire::actingAs($this->admin)->test(Administration::class)->set('labels.'.$installation->id, 'stolen-name');

    $this->setAccessLists(developers: [6101, 6102, 6103], admins: []);

    $component->call('renameInstallation', $installation->id)->assertForbidden();

    expect($installation->refresh()->machine_label)->toBe('unknown-machine')
        ->and(renameEvents())->toBeEmpty();
});

it('refuses a label enrollment would refuse, with the reason enrollment gives', function (string $label): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    // The reason enrollment gives for the same label, read from the same check
    $reason = null;

    try {
        MachineIdentity::ensure('claude-code', $label);
    } catch (InvalidArgumentException $invalidArgumentException) {
        $reason = $invalidArgumentException->getMessage();
    }

    expect($reason)->toBeString();

    Livewire::actingAs($this->owner)
        ->test(SeatSettings::class)
        ->set('labels.'.$installation->id, $label)
        ->call('renameInstallation', $installation->id)
        ->assertSet('refused', true)
        ->assertSet('said', 'Not renamed: '.$reason);

    expect($installation->refresh()->machine_label)->toBe('unknown-machine')
        ->and(renameEvents())->toBeEmpty();
})->with([
    'empty' => [''],
    'a space' => ['office mac'],

    // Not trimmed into an accepted label, since enrollment refuses it as typed
    'a leading space' => [' office-mac'],
    'a trailing newline' => ["office-mac\n"],
    'markup' => ['<b>box</b>'],
    'one past the limit' => [str_repeat('a', MachineIdentity::MAX_LABEL + 1)],
    'non-ASCII' => ['büro'],
]);

it('accepts a label exactly at the limit', function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');
    $label = str_repeat('a', MachineIdentity::MAX_LABEL);

    expect($this->service(Installations::class)->rename($installation->id, $label, HostKey::from($this->owner->getKey()), asAdmin: false))->toBe(Outcome::Applied)
        ->and($installation->refresh()->machine_label)->toBe($label);
});

it('refuses a label another live installation of the same developer and harness holds', function (string $taken): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');
    $this->approveInstallation($this->owner, 'office-mac');

    Livewire::actingAs($this->owner)
        ->test(SeatSettings::class)
        ->set('labels.'.$installation->id, $taken)
        ->call('renameInstallation', $installation->id)
        ->assertSet('refused', true)
        ->assertSee('already called');

    expect($installation->refresh()->machine_label)->toBe('unknown-machine')
        ->and(Installation::query()->where('machine_label', 'office-mac')->count())->toBe(1)
        ->and(renameEvents())->toBeEmpty();
})->with([
    'exactly' => ['office-mac'],

    // MySQL's default collation compares this column without case, so its supersede would treat the
    // two as one identity
    'differing only in case' => ['Office-Mac'],
]);

it('allows a label that only a revoked installation, another harness, or another developer holds', function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    $revoked = $this->approveInstallation($this->owner, 'old-name');
    $this->service(Installations::class)->revoke($revoked);

    $otherHarness = $this->approveInstallation($this->owner, 'shared-name');
    $otherHarness->forceFill(['harness' => 'cursor'])->save();

    $this->approveInstallation($this->stranger, 'their-name');

    $owner = HostKey::from($this->owner->getKey());
    $store = $this->service(Installations::class);

    expect($store->rename($installation->id, 'old-name', $owner, asAdmin: false))->toBe(Outcome::Applied)
        ->and($store->rename($installation->id, 'shared-name', $owner, asAdmin: false))->toBe(Outcome::Applied)
        ->and($store->rename($installation->id, 'their-name', $owner, asAdmin: false))->toBe(Outcome::Applied)
        ->and($installation->refresh()->machine_label)->toBe('their-name');
});

it('changes nothing and records nothing for a rename to the label it already has', function (): void {
    $installation = $this->approveInstallation($this->owner, 'office-mac');

    expect($this->service(Installations::class)->rename($installation->id, 'office-mac', HostKey::from($this->owner->getKey()), asAdmin: false))->toBe(Outcome::Conflict)
        ->and(renameEvents())->toBeEmpty();
});

it('refuses to rename a revoked installation', function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');
    $this->service(Installations::class)->revoke($installation);

    expect($this->service(Installations::class)->rename($installation->id, 'office-mac', HostKey::from($this->admin->getKey()), asAdmin: true))->toBe(Outcome::NotFound)
        ->and($installation->refresh()->machine_label)->toBe('unknown-machine')
        ->and(renameEvents())->toBeEmpty();
});

it('shows the new label everywhere at once, while events written before keep the old one', function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    [$session, $token] = $this->startAgentSession($installation);

    $joinedBefore = FleetEvent::query()->where('type', FleetEventType::SessionJoined)->sole();

    expect($joinedBefore->body)->toContain('unknown-machine');

    $this->service(Installations::class)->rename($installation->id, 'office-mac', HostKey::from($this->owner->getKey()), asAdmin: false);

    // The session it already had keeps working: nothing re-enrolled and no token changed
    $listed = $this->machine($token)->getJson(route('robot-council.lanes.index'))->assertOk()->json('sessions');

    expect(array_column(arrayValue($listed), 'machine_label', 'id'))->toMatchArray([$session->id => 'office-mac']);

    // The lane board reads the row, so it agrees without the session doing anything
    $board = (string) json_encode($this->service(LaneBoard::class)->read());

    expect($board)->toContain('office-mac')
        ->not->toContain('unknown-machine');

    // A presence event written from now on names the new label
    $this->startAgentSession($installation->refresh());

    $joined = FleetEvent::query()->where('type', FleetEventType::SessionJoined)->orderBy('id')->get();

    expect($joined)->toHaveCount(2)
        ->and($joined->last()?->body)->toContain('office-mac')
        ->not->toContain('unknown-machine')

        // And the event written before the rename keeps the label it was written with
        ->and($joined->first()?->body)->toBe($joinedBefore->body);
});

it('renders a rename field per machine with only the id in its wire expression', function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    Livewire::actingAs($this->owner)
        ->test(SeatSettings::class)
        ->assertSeeHtml('wire:submit="renameInstallation('.$installation->id.')"')
        ->assertSeeHtml('wire:model="labels.'.$installation->id.'"')
        ->assertSet('labels.'.$installation->id, 'unknown-machine');

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->assertSeeHtml('wire:submit="renameInstallation('.$installation->id.')"')
        ->assertSet('labels.'.$installation->id, 'unknown-machine');
});

it('marks the field invalid and reads the refusal with it', function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    Livewire::actingAs($this->owner)
        ->test(SeatSettings::class)
        ->assertDontSeeHtml('aria-invalid="true"')
        ->set('labels.'.$installation->id, 'office mac')
        ->call('renameInstallation', $installation->id)
        ->assertSeeHtml('aria-invalid="true" aria-describedby="machine-'.$installation->id.'-said installation-'.$installation->id.'-rename-help"')
        ->assertSeeHtml('id="machine-'.$installation->id.'-said"');
});

it('sets no length on the field, so a long label is refused in words rather than cut short', function (): void {
    $this->approveInstallation($this->owner, 'unknown-machine');

    Livewire::actingAs($this->owner)
        ->test(SeatSettings::class)
        ->assertDontSeeHtml('maxlength');
});

it('refills an untouched field when somebody else renames the machine, so it cannot undo them', function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    $page = Livewire::actingAs($this->admin)->test(Administration::class)
        ->assertSet('labels.'.$installation->id, 'unknown-machine');

    // The owner renames it while the administrator's page is open
    $this->service(Installations::class)->rename($installation->id, 'office-mac', HostKey::from($this->owner->getKey()), asAdmin: false);

    $page->call('$refresh')->assertSet('labels.'.$installation->id, 'office-mac');

    // Pressing Rename now changes nothing, rather than putting the old name back
    $page->call('renameInstallation', $installation->id)->assertSet('refused', true);

    expect($installation->refresh()->machine_label)->toBe('office-mac');
});

it('leaves a field alone while somebody is typing in it', function (): void {
    $installation = $this->approveInstallation($this->owner, 'unknown-machine');

    $page = Livewire::actingAs($this->owner)->test(SeatSettings::class)
        ->set('labels.'.$installation->id, 'half-typ');

    $this->service(Installations::class)->rename($installation->id, 'office-mac', HostKey::from($this->admin->getKey()), asAdmin: true);

    $page->call('$refresh')->assertSet('labels.'.$installation->id, 'half-typ');
});
