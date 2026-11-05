<?php

declare(strict_types=1);

/**
 * Retiring a seat nothing is using (#586).
 *
 * The rules are WHO may retire, WHEN, and WHAT goes with it, so each test asserts on the rows
 * afterwards rather than on the outcome alone: a refusal proved on the returned outcome would pass
 * against a store that refused and deleted anyway.
 *
 * @command  vendor/bin/pest --compact tests/SeatRetirementTest.php
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RobotCouncil\Livewire\SeatSettings;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;
use RobotCouncil\Models\PlacementRule;
use RobotCouncil\Models\PlacementWaiver;
use RobotCouncil\Models\Seat;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\PlacementWaivers;
use RobotCouncil\Support\Seats;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [5101, 5102]);

    $this->alice = $this->enrollDeveloper(5101, login: 'alice-dev');
    $this->bob = $this->enrollDeveloper(5102, login: 'bob-dev');
});

/**
 * A developer's machine with one session working in one place, and the seat it recorded.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  User  $developer  Whose machine it is.
 * @param  string  $label  The machine's label.
 * @return array{Installation, AgentSession, Seat} The installation, its session and the seat.
 */
function retirableSeat(TestCase $case, User $developer, string $label = 'office-mac'): array
{
    $installation = $case->approveInstallation($developer, $label)->refresh();
    $session = $case->service(AgentSessions::class)->start($installation, 'robot-council/core', 'a')->owner;

    $seats = $case->service(Seats::class)->forDeveloper($installation->user_id);

    expect($seats)->toHaveCount(1);

    return [$installation, $session, $seats[0]];
}

/**
 * The key a developer's rows carry.
 *
 * @param  User  $developer  The developer.
 * @return string The key.
 */
function retireKey(User $developer): string
{
    return HostKey::from($developer->getAuthIdentifier());
}

it('retires a seat with no live session, and deletes its settings and waivers with it', function (): void {
    [$installation, $session, $seat] = retirableSeat($this, $this->alice);
    $this->markSessionGone($session);

    $key = retireKey($this->alice);
    $this->service(Seats::class)->park($key, $seat->id);
    $this->service(PlacementWaivers::class)->grant($key, $seat->id, PlacementRule::cases()[0]);

    expect($this->service(Seats::class)->retire($key, $seat->id))->toBe(Outcome::Applied)
        ->and(Seat::query()->count())->toBe(0)
        ->and(PlacementWaiver::query()->count())->toBe(0)
        // The session is not the seat's to delete
        ->and(AgentSession::query()->count())->toBe(1);

    $event = FleetEvent::query()->where('type', FleetEventType::SeatRetired)->sole();

    expect($event->body)->toContain('office-mac')->toContain('robot-council/core / a')
        ->and($event->meta)->toMatchArray(['installation_id' => $installation->id, 'machine_label' => 'office-mac', 'repository' => 'robot-council/core', 'work_location' => 'a'])
        ->and($event->user_id)->toBe($key)
        ->and($event->actor_user_id)->toBe($key);
});

it('refuses to retire a seat while a session in it is active or stale, and writes nothing', function (string $status): void {
    [, $session, $seat] = retirableSeat($this, $this->alice);
    $session->forceFill(['status' => $status])->save();

    expect($this->service(Seats::class)->retire(retireKey($this->alice), $seat->id))->toBe(Outcome::Conflict)
        ->and(Seat::query()->count())->toBe(1)
        ->and(FleetEvent::query()->where('type', FleetEventType::SeatRetired)->count())->toBe(0);
})->with(['active', 'stale']);

it('refuses to retire a seat that still has a task held from it', function (): void {
    [, $session, $seat] = retirableSeat($this, $this->alice);
    $task = $this->service(Tasks::class)->create($session, ['title' => 'unfinished'], true);

    // A held task under a session that has gone, which the presence sweep would normally have released
    $this->markSessionGone($session);
    $task->forceFill(['claimed_by' => $session->id, 'status' => TaskStatus::InProgress])->save();

    expect($this->service(Seats::class)->retire(retireKey($this->alice), $seat->id))->toBe(Outcome::Conflict)
        ->and(Seat::query()->count())->toBe(1)
        ->and(Task::query()->count())->toBe(1);
});

it("refuses another developer's seat, and one that does not exist", function (): void {
    [, $session, $seat] = retirableSeat($this, $this->alice);
    $this->markSessionGone($session);

    expect($this->service(Seats::class)->retire(retireKey($this->bob), $seat->id))->toBe(Outcome::Forbidden)
        ->and(Seat::query()->count())->toBe(1)
        ->and($this->service(Seats::class)->retire(retireKey($this->alice), $seat->id + 1000))->toBe(Outcome::NotFound)
        ->and(FleetEvent::query()->where('type', FleetEventType::SeatRetired)->count())->toBe(0);
});

it('answers a repeat as not found, and records one event', function (): void {
    [, $session, $seat] = retirableSeat($this, $this->alice);
    $this->markSessionGone($session);
    $key = retireKey($this->alice);

    expect($this->service(Seats::class)->retire($key, $seat->id))->toBe(Outcome::Applied)
        ->and($this->service(Seats::class)->retire($key, $seat->id))->toBe(Outcome::NotFound)
        ->and(FleetEvent::query()->where('type', FleetEventType::SeatRetired)->count())->toBe(1);
});

it('records a fresh seat with the defaults when a session reports the place again', function (): void {
    [$installation, $session, $seat] = retirableSeat($this, $this->alice);
    $this->markSessionGone($session);
    $key = retireKey($this->alice);
    $this->service(Seats::class)->park($key, $seat->id);
    $this->service(Seats::class)->retire($key, $seat->id);

    $this->service(AgentSessions::class)->start($installation, 'robot-council/core', 'a');
    $again = $this->service(Seats::class)->forDeveloper($key);

    expect($again)->toHaveCount(1)->and($again[0]->isParked())->toBeFalse();
});

it('names why a seat is offered: no installation uses its label, or a newer one has it', function (): void {
    [$installation, $session, $seat] = retirableSeat($this, $this->alice);
    $this->markSessionGone($session);
    $seats = $this->service(Seats::class);

    expect($seats->orphanReason($seat->load('installation')))->toBeNull();

    $installation->forceFill(['revoked_at' => Carbon::now()])->save();

    expect($seats->orphanReason($seat->load('installation')))->toBe('No installation uses this machine label.');

    $this->approveInstallation($this->alice, 'office-mac');

    expect($seats->orphanReason($seat->load('installation')))->toContain('a newer one has the same machine label');
});

it('offers the control on the page only while the seat is idle, and asks before it deletes', function (): void {
    [, $session, $seat] = retirableSeat($this, $this->alice);

    $component = Livewire::actingAs($this->alice)->test(SeatSettings::class);
    expect($component->html())->not->toContain('data-seat-retire');

    $this->markSessionGone($session);

    $component = Livewire::actingAs($this->alice)->test(SeatSettings::class);
    expect($component->html())->toContain('data-seat-retire')->not->toContain('data-seat-retire-confirm');

    $component->call('askToRetire', $seat->id);
    expect($component->html())->toContain('data-seat-retire-confirm')->toContain('cannot be undone')->toContain('waivers')
        ->and(Seat::query()->count())->toBe(1);

    $component->call('keepSeat')->assertSet('retiring', null);
    expect(Seat::query()->count())->toBe(1);

    $component->call('askToRetire', $seat->id)->call('retire', $seat->id)->assertSet('refused', false)->assertSet('retiring', null);
    expect(Seat::query()->count())->toBe(0);
});

it("does not retire another developer's seat from the page, or say what it was", function (): void {
    [, $session, $seat] = retirableSeat($this, $this->alice);
    $this->markSessionGone($session);

    Livewire::actingAs($this->bob)->test(SeatSettings::class)
        ->call('retire', $seat->id)
        ->assertSet('refused', true)
        ->assertDontSee('robot-council/core');

    expect(Seat::query()->count())->toBe(1);
});

it('says a seat is in use rather than retiring it when a session arrives after the page rendered', function (): void {
    [, $session, $seat] = retirableSeat($this, $this->alice);
    $this->markSessionGone($session);

    $component = Livewire::actingAs($this->alice)->test(SeatSettings::class)->call('askToRetire', $seat->id);

    $session->forceFill(['status' => 'active'])->save();

    $component->call('retire', $seat->id)->assertSet('refused', true);
    expect(Seat::query()->count())->toBe(1);
});
