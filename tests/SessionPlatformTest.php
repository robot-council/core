<?php

declare(strict_types=1);

/**
 * The operating system and architecture a session runs on (#351).
 *
 * @command  vendor/bin/pest --compact tests/SessionPlatformTest.php
 */

use Livewire\Livewire;
use RobotCouncil\Livewire\FleetPresence;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\AgentSessions;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
    $this->credential = $this->installationCredential($this->installation);
});

it('stores what the bridge reported, and carries it on the join', function (): void {
    $id = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), ['platform' => ['os_family' => 'Windows', 'arch' => 'AMD64']])
        ->assertCreated()
        ->json('session_id');

    // Read from the row, not from anything the store handed back
    $row = AgentSession::query()->whereKey($id)->firstOrFail();
    $joined = FleetEvent::query()->where('type', FleetEventType::SessionJoined->value)->where('agent_session_id', $id)->sole();

    expect([$row->os_family, $row->arch])->toBe(['Windows', 'AMD64'])
        ->and($joined->meta)->toMatchArray(['os_family' => 'Windows', 'arch' => 'AMD64']);
});

it('starts a session from a bridge that reports nothing, storing nothing', function (): void {
    $id = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'))
        ->assertCreated()
        ->json('session_id');

    $row = AgentSession::query()->whereKey($id)->firstOrFail();

    expect([$row->os_family, $row->arch])->toBe([null, null])
        ->and(FleetEvent::query()->where('type', FleetEventType::SessionJoined->value)->where('agent_session_id', $id)->sole()->meta)
        ->toMatchArray(['os_family' => null, 'arch' => null]);
});

it('stores an OS family with no architecture', function (): void {
    $id = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), ['platform' => ['os_family' => 'Linux']])
        ->assertCreated()
        ->json('session_id');

    $row = AgentSession::query()->whereKey($id)->firstOrFail();

    expect([$row->os_family, $row->arch])->toBe(['Linux', null]);
});

it('refuses a platform outside its bound, naming the field, and starts nothing', function (array $platform, string $field): void {
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), ['platform' => $platform])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(AgentSession::query()->count())->toBe(0);
})->with([
    'an OS family PHP does not report' => [['os_family' => 'Win95', 'arch' => 'x86'], 'platform.os_family'],
    'an OS family in the wrong case' => [['os_family' => 'windows'], 'platform.os_family'],
    'an architecture with no OS family' => [['arch' => 'arm64'], 'platform.os_family'],
    'an architecture with a space' => [['os_family' => 'Linux', 'arch' => 'x86 64'], 'platform.arch'],
    'an architecture past its width' => [['os_family' => 'Linux', 'arch' => str_repeat('a', 33)], 'platform.arch'],
    'a field the platform does not have' => [['os_family' => 'Linux', 'kernel' => '6.1'], 'platform'],
]);

it('refuses the same values through the store, which a host may call directly', function (?string $osFamily, ?string $arch): void {
    expect(fn () => $this->service(AgentSessions::class)->start($this->installation, null, null, $osFamily, $arch))
        ->toThrow(InvalidArgumentException::class)
        ->and(AgentSession::query()->count())->toBe(0);
})->with([
    'an unknown OS family' => ['Win95', null],
    'an architecture past its width' => ['Linux', str_repeat('a', 33)],
    'an architecture with a newline' => ['Linux', "arm64\n"],
]);

it('shows the OS family on the Agents list, and nothing for a bridge that reported none', function (): void {
    $this->service(AgentSessions::class)->start($this->installation, null, null, 'Darwin', 'arm64');
    $this->actingAs($this->developer, 'web');

    Livewire::test(FleetPresence::class)->assertSeeHtml('<div class="text-xs opacity-60">Darwin arm64</div>');

    AgentSession::query()->update(['os_family' => null, 'arch' => null]);

    Livewire::test(FleetPresence::class)->assertDontSeeHtml('Darwin');
});

it('filters the agent read by OS family', function (): void {
    [$mac] = $this->startAgentSession($this->installation);
    $mac->forceFill(['os_family' => 'Darwin', 'arch' => 'arm64'])->save();
    [$windows, $token] = $this->startAgentSession($this->installation);
    $windows->forceFill(['os_family' => 'Windows'])->save();

    $read = fn (array $query): array => array_column(arrayValue($this->machine($token)->getJson(route('robot-council.lanes.index', $query))->assertOk()->json('sessions')), 'os_family', 'id');

    expect($read(['os_family' => 'Darwin']))->toBe([$mac->id => 'Darwin'])
        ->and($read([]))->toBe([$windows->id => 'Windows', $mac->id => 'Darwin']);

    $this->machine($token)->getJson(route('robot-council.lanes.index', ['os_family' => 'Plan9']))->assertUnprocessable();
});
