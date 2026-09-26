<?php

declare(strict_types=1);

/**
 * The page that shows a developer what the fleet is waiting on them for, and nothing else (#411).
 *
 * @command  vendor/bin/pest --compact tests/WaitingOnMeTest.php
 */
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\LaneHolds;
use RobotCouncil\Support\OwedItems;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [5101, 5102, 77]);

    $this->alice = $this->enrollDeveloper(5101, login: 'alice-dev');
    $this->bob = $this->enrollDeveloper(5102, login: 'bob-dev');

    [$this->coordinatorSession] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );

    $this->owed = $this->service(OwedItems::class);
    $this->aliceItem = $this->owed->record($this->coordinatorSession, 'alice-dev', 'robot-council/core#450', 'Run the screen-reader pass', 'needs a person at VoiceOver');
    $this->owed->record($this->coordinatorSession, 'bob-dev', 'robot-council/core#451', 'Approve the release notes', 'needs the operator');
    $this->owed->record($this->coordinatorSession, null, 'robot-council/core#452', 'Anyone: check the deploy', 'no owner');
});

it("shows a developer their own owed items and none of another developer's, nor General's", function (): void {
    $this->actingAs($this->alice)->get(route('robot-council.waiting'))
        ->assertOk()
        ->assertSee('Run the screen-reader pass')
        ->assertSee('needs a person at VoiceOver')
        ->assertDontSee('Approve the release notes')
        ->assertDontSee('Anyone: check the deploy');

    // And the same page for the other developer shows theirs and not hers
    $this->actingAs($this->bob)->get(route('robot-council.waiting'))
        ->assertOk()
        ->assertSee('Approve the release notes')
        ->assertDontSee('Run the screen-reader pass');
});

it('shows the lanes held with the developer as the party, naming the seat and the reason', function (): void {
    $lane = $this->service(AgentSessions::class)->start($this->approveInstallation($this->bob, 'bob-laptop'), 'robot-council/core', 'b')->owner;
    $other = $this->service(AgentSessions::class)->start($this->approveInstallation($this->bob, 'bob-desktop'), 'robot-council/core', 'c')->owner;

    $holds = $this->service(LaneHolds::class);
    $holds->hold($this->coordinatorSession, $lane->id, 'alice-dev', HoldReason::Decision);
    $holds->hold($this->coordinatorSession, $other->id, 'robot-council/core#403', HoldReason::TicketLands);

    $this->actingAs($this->alice)->get(route('robot-council.waiting'))
        ->assertOk()
        ->assertSee('core/bob-laptop/b')
        ->assertSee('waiting on you for a decision')
        // A hold on a ticket is nobody's to answer, so it is not listed
        ->assertDontSee('core/bob-desktop/c');

    // Cleared: gone on the next read
    $holds->clear($lane->id);

    $this->actingAs($this->alice)->get(route('robot-council.waiting'))->assertDontSee('core/bob-laptop/b');
});

it('drops a settled item on the next read', function (): void {
    expect($this->owed->settle($this->aliceItem))->toBeTrue();

    $this->actingAs($this->alice)->get(route('robot-council.waiting'))
        ->assertOk()
        ->assertDontSee('Run the screen-reader pass')
        ->assertSee('Nothing is waiting on you');
});

it('says in words when nothing is waiting', function (): void {
    $carol = $this->enrollDeveloper(5103, login: 'carol-dev');
    $this->setAccessLists(developers: [5101, 5102, 5103, 77]);

    $this->actingAs($carol)->get(route('robot-council.waiting'))
        ->assertOk()
        ->assertSee('Nothing is waiting on you: no agent has asked you for a decision or an action, and no lane is held on you.');
});

it('is behind the allowlist gate, and in the sidebar', function (): void {
    $stranger = $this->enrollDeveloper(9999, login: 'stranger');

    $this->actingAs($stranger)->get(route('robot-council.waiting'))->assertForbidden();

    $this->actingAs($this->alice)->get(route('robot-council.dashboard'))->assertOk()->assertSeeHtml(route('robot-council.waiting'));
});

it('renders a question carrying markup as text', function (): void {
    $this->owed->record($this->coordinatorSession, 'alice-dev', 'robot-council/core#460', '<script>alert(1)</script>', '<b>why</b>');

    $html = (string) $this->actingAs($this->alice)->get(route('robot-council.waiting'))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain(e('<script>alert(1)</script>'))
        ->and($html)->not->toContain('<b>why</b>');
});
