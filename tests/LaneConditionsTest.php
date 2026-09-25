<?php

declare(strict_types=1);

/**
 * The lane conditions that go quiet, raised to the coordinator (#319).
 *
 * @command  vendor/bin/pest --compact tests/LaneConditionsTest.php
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\FleetFeed;
use RobotCouncil\Support\GateRuns;
use RobotCouncil\Support\GitHubState;
use RobotCouncil\Support\LaneConditions;
use RobotCouncil\Support\LaneHolds;
use RobotCouncil\Support\RoleRequests;
use RobotCouncil\Support\SessionPresence;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77]);

    Carbon::setTestNow('2026-09-24 12:00:00');

    $this->installation = $this->approveInstallation($this->enrollDeveloper(4242, login: 'octodev'));
    $this->session = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'a')->owner;

    [$this->coordinatorSession] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );
});

/**
 * The `lane.condition` events raised for a condition.
 *
 * @param  string  $condition  The condition.
 * @return list<array<string, mixed>> Their meta.
 */
function raisedConditions(string $condition): array
{
    return array_values(FleetEvent::query()->where('type', FleetEventType::LaneCondition->value)->orderBy('id')->get()
        ->map(static fn (FleetEvent $event): array => $event->meta ?? [])
        ->filter(static fn (array $meta): bool => ($meta['condition'] ?? null) === $condition)
        ->all());
}

/**
 * Run the scheduled check some minutes after noon.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $minutes  Minutes after 12:00.
 */
function conditionsAt(TestCase $case, int $minutes): void
{
    Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC')->addMinutes($minutes));
    $case->service(LaneConditions::class)->check(Carbon::now());
}

/**
 * Place a task on the lane, as a coordinator.
 *
 * @param  TestCase  $case  The test case.
 * @param  bool  $handBack  Whether it is a hand-back.
 * @return int The task.
 */
function placeOnTheLane(TestCase $case, bool $handBack = false): int
{
    $task = $case->service(Tasks::class)->create($case->coordinatorSession, ['title' => 'Work'], true);
    $case->service(Tasks::class)->transition($task->id, TaskTransition::Reassign, $case->coordinatorSession, true, $case->session, directive: 'Take this.', handBack: $handBack);

    return $task->id;
}

it('raises a free lane once past its window, clears it when the lane works, and raises again when it recurs', function (): void {
    conditionsAt($this, 29);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toBeEmpty();

    conditionsAt($this, 31);
    conditionsAt($this, 36);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toHaveCount(1)
        ->and(raisedConditions(LaneConditions::LANE_FREE)[0]['session_id'] ?? null)->toBe($this->session->id);

    // The lane takes work: the condition clears
    $task = placeOnTheLane($this);
    $this->service(Tasks::class)->transition($task, TaskTransition::Start, $this->session, false);
    conditionsAt($this, 40);

    expect(DB::table('robot_council_lane_conditions')->where('condition', LaneConditions::LANE_FREE)->whereNull('cleared_at')->count())->toBe(0);

    // It finishes, and is free again past the window: raised a second time
    Carbon::setTestNow('2026-09-24 12:45:00');
    $this->service(Tasks::class)->transition($task, TaskTransition::Complete, $this->session, false);
    conditionsAt($this, 45 + 31);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toHaveCount(2);
});

it('does not raise a free lane that is held on purpose or parked', function (): void {
    $this->service(LaneHolds::class)->hold($this->coordinatorSession, $this->session->id, 'octodev', HoldReason::Decision);

    conditionsAt($this, 60);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toBeEmpty();
});

it('raises a placement not taken up within its window, and says when it is a hand-back', function (bool $handBack): void {
    $task = placeOnTheLane($this, $handBack);

    conditionsAt($this, 14);

    expect(raisedConditions(LaneConditions::NOT_TAKEN_UP))->toBeEmpty();

    conditionsAt($this, 16);

    $raised = raisedConditions(LaneConditions::NOT_TAKEN_UP);

    expect($raised)->toHaveCount(1)
        ->and($raised[0]['task_id'] ?? null)->toBe($task)
        ->and($raised[0]['hand_back'] ?? null)->toBe($handBack);
})->with(['a placement' => [false], 'a hand-back' => [true]]);

it('raises a working lane going stale on the presence transition itself', function (): void {
    placeOnTheLane($this);

    // No scheduled check runs: only the sweep's transition
    Carbon::setTestNow(Carbon::now()->addHours(2));
    $this->service(SessionPresence::class)->sweep();

    $raised = raisedConditions(LaneConditions::WORKING_UNOBSERVED);

    expect($raised)->not->toBeEmpty()
        ->and($raised[0]['session_id'] ?? null)->toBe($this->session->id)
        ->and($raised[0]['status'] ?? null)->toBeIn(['stale', 'gone']);
});

it('raises a ready pull request no gate picked up, and not one a gate is on', function (): void {
    foreach ([40, 41] as $number) {
        GitHubItem::query()->insert([
            'repository' => 'robot-council/core', 'number' => $number, 'is_pull_request' => true, 'state' => 'open', 'draft' => false,
            'title' => 'Pull '.$number, 'labels' => '[]', 'github_updated_at' => '2026-09-24 12:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $gate = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'gate')->owner;
    $this->service(RoleRequests::class)->impose($gate, Role::Ci, 'test-administrator');
    $this->service(GateRuns::class)->start($gate->refresh(), 'robot-council/core#41');

    conditionsAt($this, 31);

    expect(array_column(raisedConditions(LaneConditions::PULL_REQUEST_UNPICKED), 'pull_request'))->toBe(['robot-council/core#40']);
});

it('raises a merge with the live sessions in that repository, now behind', function (): void {
    $secret = 'a-webhook-secret-long-enough-to-count';
    $this->app?->make('config')->set('robot-council.github.webhook_secret', $secret);

    $this->service(GitHubState::class)->receive('merge-1', 'pull_request', [
        'action' => 'closed',
        'repository' => ['full_name' => 'robot-council/core'],
        'pull_request' => [
            'number' => 50, 'state' => 'closed', 'merged' => true, 'draft' => false, 'title' => 'Pull 50', 'labels' => [], 'body' => null,
            'updated_at' => '2026-09-24T12:00:00Z', 'head' => ['ref' => 'feature/x', 'repo' => ['full_name' => 'robot-council/core']],
        ],
    ]);

    $raised = raisedConditions(LaneConditions::MERGE_BEHIND);

    expect($raised)->toHaveCount(1)
        ->and($raised[0]['pull_request'] ?? null)->toBe('robot-council/core#50')
        ->and($raised[0]['session_ids'] ?? null)->toBe([$this->session->id]);
});

it('reaches the coordinator through the feed, and no other session', function (): void {
    conditionsAt($this, 31);

    $sees = fn (AgentSession $reader): bool => collect($this->service(FleetFeed::class)->after($reader, 0, 200)['events'])
        ->contains(static fn (array $event): bool => $event['type'] === FleetEventType::LaneCondition->value);

    expect($sees($this->coordinatorSession))->toBeTrue()
        ->and($sees($this->session))->toBeFalse();
});

it('reads its windows from configuration', function (): void {
    $this->app?->make('config')->set('robot-council.lane_conditions.free_after_minutes', 5);

    conditionsAt($this, 6);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toHaveCount(1);
});
