<?php

declare(strict_types=1);

/**
 * Why a lane is idle on purpose, in a closed vocabulary (#334).
 *
 * Every refusal is asserted on the row: a store that refused and wrote anyway would pass a
 * response-only test.
 *
 * @command  vendor/bin/pest --compact tests/LaneHoldTest.php
 */

use Illuminate\Testing\TestResponse;
use RobotCouncil\Models\HoldParty;
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Models\LaneHold;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\LaneHolds;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $this->installation = $this->approveInstallation($this->developer);

    [$this->session, $this->token] = $this->startAgentSession($this->installation);
    [$this->coordinatorSession, $this->coordinatorToken] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );
});

/**
 * Hold this test's lane through the endpoint.
 *
 * @param  TestCase  $case  The test case.
 * @param  array<string, mixed>  $body  The request body.
 * @param  string|null  $token  Who asks, or the coordinator.
 * @return TestResponse<Response> The response.
 */
function holdLane(TestCase $case, array $body, ?string $token = null): TestResponse
{
    return $case->machine($token ?? $case->coordinatorToken)
        ->postJson(route('robot-council.lanes.hold', ['session' => $case->session->getKey()]), $body);
}

it('records a hold on a developer, as the board reads it, and lifts it', function (): void {
    holdLane($this, ['party' => 'Octodev', 'reason' => 'clearing_seat'])
        ->assertOk()
        ->assertJson(['applied' => true, 'on_what' => 'octodev — clearing this seat to take tickets']);

    $hold = LaneHold::query()->whereKey($this->session->getKey())->firstOrFail();

    // Stored as the fleet spells the login, whatever case the coordinator typed
    expect($hold->party)->toBe('octodev')
        ->and($hold->party_kind)->toBe(HoldParty::Developer)
        ->and($hold->reason)->toBe(HoldReason::ClearingSeat)
        ->and($hold->held_by)->toBe($this->coordinatorSession->getKey());

    $this->machine($this->coordinatorToken)
        ->deleteJson(route('robot-council.lanes.clear-hold', ['session' => $this->session->getKey()]))
        ->assertOk()
        ->assertJson(['cleared' => true]);

    expect(LaneHold::query()->count())->toBe(0);
});

it('records a hold on a ticket, and a second hold replaces the first', function (): void {
    holdLane($this, ['party' => 'octodev', 'reason' => 'decision'])->assertOk();
    holdLane($this, ['party' => 'robot-council/core#318', 'reason' => 'ticket_lands'])
        ->assertOk()
        ->assertJson(['on_what' => 'robot-council/core#318 — that ticket to land']);

    expect(LaneHold::query()->count())->toBe(1)
        ->and(LaneHold::query()->sole()->party_kind)->toBe(HoldParty::Ticket);
});

it('refuses a note, a bare number, an unknown developer, and a reason for the other kind of party', function (string $party, string $reason): void {
    holdLane($this, ['party' => $party, 'reason' => $reason])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('party');

    expect(fn () => $this->service(LaneHolds::class)->hold($this->coordinatorSession, $this->session->id, $party, HoldReason::from($reason)))
        ->toThrow(InvalidArgumentException::class)
        ->and(LaneHold::query()->count())->toBe(0);
})->with([
    'a free-text note' => ['waiting on the deploy', 'decision'],
    'a bare #N' => ['#318', 'ticket_lands'],
    'a bare number' => ['318', 'ticket_lands'],
    'a developer the fleet has never seen' => ['stranger', 'decision'],
    'a developer reason naming a ticket' => ['robot-council/core#318', 'decision'],
    'a ticket reason naming a developer' => ['octodev', 'ticket_lands'],
]);

it('refuses a reason outside the vocabulary at the edge', function (): void {
    holdLane($this, ['party' => 'octodev', 'reason' => 'lunch'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    expect(LaneHold::query()->count())->toBe(0);
});

it('refuses a session without coordinator:direct, and writes nothing', function (): void {
    holdLane($this, ['party' => 'octodev', 'reason' => 'decision'], $this->token)->assertForbidden();

    expect(LaneHold::query()->count())->toBe(0);
});

it('refuses to hold a lane that holds work, or has gone', function (string $state): void {
    if ($state === 'working') {
        $this->createClaimedTask();
    } else {
        $this->markSessionGone($this->session);
    }

    expect($this->service(LaneHolds::class)->hold($this->coordinatorSession, $this->session->id, 'octodev', HoldReason::Decision))
        ->toBe(Outcome::Conflict)
        ->and(LaneHold::query()->count())->toBe(0);
})->with(['working', 'gone']);

it('lifts the hold in the same transaction that gives the lane work', function (TaskTransition $how): void {
    holdLane($this, ['party' => 'octodev', 'reason' => 'clearing_seat'])->assertOk();

    $tasks = $this->service(Tasks::class);
    $task = $tasks->create($this->session, ['title' => 'Work'], withCoordinator: false);

    $outcome = $how === TaskTransition::Claim
        ? $tasks->transition($task->id, TaskTransition::Claim, $this->session, asCoordinator: false)
        : $tasks->transition($task->id, TaskTransition::Reassign, $this->coordinatorSession, true, $this->session, directive: 'Take this.');

    expect($outcome)->toBe(Outcome::Applied)
        ->and(LaneHold::query()->count())->toBe(0);
})->with([TaskTransition::Claim, TaskTransition::Reassign]);

it('keeps the hold when a placement on the lane is refused', function (): void {
    holdLane($this, ['party' => 'octodev', 'reason' => 'clearing_seat'])->assertOk();

    // Another developer's own task: the lane may not claim it, so a placement writes nothing
    $other = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Theirs'], withCoordinator: false);

    expect($this->service(Tasks::class)->transition($other->id, TaskTransition::Reassign, $this->coordinatorSession, true, $this->session, directive: 'Take this.'))
        ->toBe(Outcome::Forbidden)
        ->and(LaneHold::query()->count())->toBe(1);
});
