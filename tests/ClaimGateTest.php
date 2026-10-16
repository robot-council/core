<?php

declare(strict_types=1);

/**
 * A session's own claim is gated on its developer's parking and assignment hours, as a placement
 * is (#578).
 *
 * Every refusal is asserted on the row as well as the answer: a store that refused after writing
 * would pass an answer-only test.
 *
 * @command  vendor/bin/pest --compact tests/ClaimGateTest.php
 */

use Illuminate\Support\Carbon;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\PlacementRule;
use RobotCouncil\Models\PlacementWaiver;
use RobotCouncil\Models\Seat;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\DeveloperSettings;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\PlacementRefused;
use RobotCouncil\Support\PlacementWaivers;
use RobotCouncil\Support\Seats;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $this->installation = $this->approveInstallation($this->developer);
    $this->key = HostKey::from($this->developer->getAuthIdentifier());

    $this->coordinatorDeveloper = $this->enrollDeveloper(77, login: 'coordinator');
    [$this->coordinatorSession] = $this->startCoordinatorSession(
        $this->approveInstallation($this->coordinatorDeveloper, machineLabel: 'coordinator-box')
    );

    $issued = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'a');
    $this->session = $issued->owner;
    $this->token = $issued->plainTextToken;

    // Thursday 24 September 2026, midday UTC
    Carbon::setTestNow('2026-09-24 12:00:00');
});

/**
 * A pending task the claimant's own developer filed, so the claim is one it may make.
 *
 * @param  TestCase  $case  The test case.
 * @return int The task.
 */
function claimableTask(TestCase $case): int
{
    return $case->service(Tasks::class)->create($case->session, ['title' => 'Build the thing'], false)->id;
}

/**
 * Claim a task as this test's session, through the store.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $taskId  The task.
 * @return Outcome What came of it.
 */
function claimAsLane(TestCase $case, int $taskId): Outcome
{
    return $case->service(Tasks::class)->transition($taskId, TaskTransition::Claim, $case->session, false);
}

/**
 * The rules a claim or placement was refused on, or an empty list when it was not refused.
 *
 * @param  callable(): mixed  $attempt  The attempt.
 * @return list<PlacementRule> The rules.
 */
function claimRefusedOn(callable $attempt): array
{
    try {
        $attempt();
    } catch (PlacementRefused $placementRefused) {
        return $placementRefused->rules;
    }

    return [];
}

/**
 * Whether a task is still pending with nobody holding it.
 *
 * @param  int  $taskId  The task.
 * @return bool True when untouched.
 */
function stillUnclaimed(int $taskId): bool
{
    $task = Task::query()->findOrFail($taskId);

    return $task->status === TaskStatus::Pending && $task->claimed_by === null;
}

/**
 * This test's lane's seat.
 *
 * @param  TestCase  $case  The test case.
 * @return Seat The seat.
 */
function claimantSeat(TestCase $case): Seat
{
    $case->service(Seats::class)->forDeveloper($case->key);
    $seat = $case->service(Seats::class)->of($case->session);

    if (! $seat instanceof Seat) {
        throw new RuntimeException('The lane has no seat.');
    }

    return $seat;
}

it('claims as before on an unparked seat inside its hours', function (): void {
    $this->service(DeveloperSettings::class)->setHours($this->key, 'UTC', '09:00', '17:00', false);
    $taskId = claimableTask($this);

    expect(claimAsLane($this, $taskId))->toBe(Outcome::Applied)
        ->and(Task::query()->findOrFail($taskId)->status)->toBe(TaskStatus::Claimed)
        ->and(Task::query()->findOrFail($taskId)->claimed_by)->toBe($this->session->id)
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskClaimed->value)->count())->toBe(1);
});

it('refuses a claim by a parked seat, and leaves the task pending and unclaimed', function (): void {
    $this->service(Seats::class)->park($this->key, claimantSeat($this)->id);
    $taskId = claimableTask($this);

    expect(claimRefusedOn(fn (): Outcome => claimAsLane($this, $taskId)))->toBe([PlacementRule::LaneNotParked])
        ->and(stillUnclaimed($taskId))->toBeTrue()
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskClaimed->value)->count())->toBe(0);
});

it("refuses a claim outside its developer's hours, and leaves the task pending and unclaimed", function (): void {
    $this->service(DeveloperSettings::class)->setHours($this->key, 'UTC', '13:00', '17:00', false);
    $taskId = claimableTask($this);

    expect(claimRefusedOn(fn (): Outcome => claimAsLane($this, $taskId)))->toBe([PlacementRule::AssignmentHours])
        ->and(stillUnclaimed($taskId))->toBeTrue();

    // And once the window opens, the same claim goes through
    Carbon::setTestNow('2026-09-24 13:00:00');

    expect(claimAsLane($this, $taskId))->toBe(Outcome::Applied);
});

it('names both rules when a parked seat claims outside its hours', function (): void {
    $this->service(DeveloperSettings::class)->setHours($this->key, 'UTC', '13:00', '17:00', false);
    $this->service(Seats::class)->park($this->key, claimantSeat($this)->id);
    $taskId = claimableTask($this);

    expect(claimRefusedOn(fn (): Outcome => claimAsLane($this, $taskId)))->toBe([PlacementRule::LaneNotParked, PlacementRule::AssignmentHours])
        ->and(stillUnclaimed($taskId))->toBeTrue();
});

it('lets an hours-exempt seat claim outside any window', function (): void {
    $this->service(DeveloperSettings::class)->setHours($this->key, 'UTC', '13:00', '17:00', false);
    $this->service(Seats::class)->exempt($this->key, claimantSeat($this)->id, true);

    expect(claimAsLane($this, claimableTask($this)))->toBe(Outcome::Applied);
});

it('lets a developer with no hours set claim at any time', function (): void {
    expect($this->service(DeveloperSettings::class)->hours($this->key))->toBeNull();

    Carbon::setTestNow('2026-09-27 03:00:00');

    expect(claimAsLane($this, claimableTask($this)))->toBe(Outcome::Applied);
});

it('refuses a parked seat and claims once its developer lifts the park', function (): void {
    $seat = claimantSeat($this);
    $this->service(Seats::class)->park($this->key, $seat->id);
    $taskId = claimableTask($this);

    expect(claimRefusedOn(fn (): Outcome => claimAsLane($this, $taskId)))->toBe([PlacementRule::LaneNotParked]);

    $this->service(Seats::class)->lift($this->key, $seat->id);

    expect(claimAsLane($this, $taskId))->toBe(Outcome::Applied)
        ->and(Task::query()->findOrFail($taskId)->claimed_by)->toBe($this->session->id);
});

it('refuses or allows a claim exactly as it does a placement on the same seat at the same moment', function (bool $parked, bool $outside): void {
    $seat = claimantSeat($this);

    if ($parked) {
        $this->service(Seats::class)->park($this->key, $seat->id);
    }

    if ($outside) {
        $this->service(DeveloperSettings::class)->setHours($this->key, 'UTC', '13:00', '17:00', false);
    }

    // A placement of a task naming no issue on a free lane breaks only the developer's rules, so the
    // two answers compare like for like
    $placed = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Placed'], true)->id;
    $placement = claimRefusedOn(fn (): Outcome => $this->service(Tasks::class)->transition(
        $placed, TaskTransition::Reassign, $this->coordinatorSession, true, $this->session, directive: 'Take this.'
    ));

    // Undone before the claim, so the lane is free for it whichever way the placement went
    if ($placement === []) {
        $this->service(Tasks::class)->transition($placed, TaskTransition::Release, $this->session, false);
    }

    expect(claimRefusedOn(fn (): Outcome => claimAsLane($this, claimableTask($this))))->toBe($placement);
})->with([
    'free and inside' => [false, false],
    'parked' => [true, false],
    'outside hours' => [false, true],
    'both' => [true, true],
]);

it("lets a claim through on the seat owner's waiver, once", function (): void {
    $this->service(DeveloperSettings::class)->setHours($this->key, 'UTC', '13:00', '17:00', false);
    $seat = claimantSeat($this);

    expect($this->service(PlacementWaivers::class)->grant($this->key, $seat->id, PlacementRule::AssignmentHours))->toBe(Outcome::Applied);

    $first = claimableTask($this);

    expect(claimAsLane($this, $first))->toBe(Outcome::Applied)
        ->and(PlacementWaiver::query()->sole()->consumed_by_task)->toBe($first)
        ->and(claimRefusedOn(fn (): Outcome => claimAsLane($this, claimableTask($this))))->toBe([PlacementRule::AssignmentHours]);
});

it('answers a task already taken as a conflict, not a refusal, whatever the seat', function (): void {
    $taskId = claimableTask($this);
    $other = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'b')->owner;

    expect($this->service(Tasks::class)->transition($taskId, TaskTransition::Claim, $other, false))->toBe(Outcome::Applied);

    $this->service(Seats::class)->park($this->key, claimantSeat($this)->id);

    // The diagnosis says the task is taken, which is the answer that tells the lane to move on
    expect(claimAsLane($this, $taskId))->toBe(Outcome::Conflict);
});

it('answers a refused claim at the endpoint with every rule and its reason', function (): void {
    $this->service(Seats::class)->park($this->key, claimantSeat($this)->id);
    $taskId = claimableTask($this);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $taskId, 'transition' => 'claim']))
        ->assertUnprocessable()
        ->assertExactJson([
            'task_id' => $taskId,
            'status' => null,
            'applied' => false,
            'refused' => [['rule' => 'lane_not_parked', 'reason' => PlacementRule::LaneNotParked->reads()]],
        ]);

    expect(stillUnclaimed($taskId))->toBeTrue();
});

it('answers a refused claim from the tool as an error naming the rule, worded as the placement refusal is', function (): void {
    $this->service(DeveloperSettings::class)->setHours($this->key, 'UTC', '13:00', '17:00', false);
    $taskId = claimableTask($this);

    $response = $this->machine($this->token)->postJson('/robot-council/api/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'task_claim', 'arguments' => ['task_id' => $taskId]],
    ]);

    $text = (string) json_encode($response->json('result.content'));

    expect($response->json('result.isError'))->toBeTrue()
        ->and($text)->toContain('The claim was refused: '.PlacementRule::AssignmentHours->reads().'.')
        ->and($text)->toContain('waive one of these for a single claim')
        ->and(stillUnclaimed($taskId))->toBeTrue();
});

it('spends no claim waiver when a waived rule is refused alongside one it does not cover', function (): void {
    $this->service(DeveloperSettings::class)->setHours($this->key, 'UTC', '13:00', '17:00', false);
    $seat = claimantSeat($this);
    $this->service(Seats::class)->park($this->key, $seat->id);
    $this->service(PlacementWaivers::class)->grant($this->key, $seat->id, PlacementRule::LaneNotParked);
    $taskId = claimableTask($this);

    // Refused on the rule the waiver does not cover, and the claim's write rolls back the spend
    expect(claimRefusedOn(fn (): Outcome => claimAsLane($this, $taskId)))->toBe([PlacementRule::AssignmentHours])
        ->and(stillUnclaimed($taskId))->toBeTrue()
        ->and(PlacementWaiver::query()->sole()->consumed_at)->toBeNull();
});

it('holds a session with no recorded seat to its hours, with no seat to waive on', function (): void {
    $this->service(DeveloperSettings::class)->setHours($this->key, 'UTC', '13:00', '17:00', false);

    // No repository, so no seat: never parked, and nothing a waiver could be recorded against
    [$seatless] = $this->startAgentSession($this->installation);
    $taskId = $this->service(Tasks::class)->create($seatless, ['title' => 'Build the thing'], false)->id;

    expect($this->service(Seats::class)->of($seatless))->toBeNull()
        ->and(claimRefusedOn(fn (): Outcome => $this->service(Tasks::class)->transition($taskId, TaskTransition::Claim, $seatless, false)))->toBe([PlacementRule::AssignmentHours])
        ->and(stillUnclaimed($taskId))->toBeTrue();

    Carbon::setTestNow('2026-09-24 13:00:00');

    expect($this->service(Tasks::class)->transition($taskId, TaskTransition::Claim, $seatless, false))->toBe(Outcome::Applied);
});
