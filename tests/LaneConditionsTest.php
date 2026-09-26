<?php

declare(strict_types=1);

/**
 * The lane conditions that go quiet, raised to the coordinator (#319).
 *
 * @command  vendor/bin/pest --compact tests/LaneConditionsTest.php
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\FleetFeed;
use RobotCouncil\Support\GateRuns;
use RobotCouncil\Support\GitHubState;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\LaneConditions;
use RobotCouncil\Support\LaneHolds;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\RoleRequests;
use RobotCouncil\Support\Seats;
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

/**
 * An open, ready pull request, last changed at noon.
 *
 * @param  int  $number  Its number.
 * @param  string  $repository  Its repository.
 */
function readyPull(int $number, string $repository = 'robot-council/core'): void
{
    GitHubItem::query()->insert([
        'repository' => $repository, 'number' => $number, 'is_pull_request' => true, 'state' => 'open', 'draft' => false,
        'title' => 'Pull '.$number, 'labels' => '[]', 'github_updated_at' => '2026-09-24 12:00:00', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * A gate working in a repository.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $repository  Its repository.
 * @return AgentSession The gate.
 */
function gateIn(TestCase $case, string $repository = 'robot-council/core'): AgentSession
{
    $gate = $case->service(AgentSessions::class)->start($case->installation, $repository, 'gate')->owner;
    $case->service(RoleRequests::class)->impose($gate, Role::Ci, 'test-administrator');

    return $gate->refresh();
}

/**
 * Deliver a merged pull request from a branch.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $delivery  The delivery id.
 * @param  int  $number  The pull request.
 * @param  string  $branch  Its head branch.
 */
function deliverMerge(TestCase $case, string $delivery, int $number = 50, string $branch = 'feature/x'): void
{
    $case->service(GitHubState::class)->receive($delivery, 'pull_request', [
        'action' => 'closed',
        'repository' => ['full_name' => 'robot-council/core'],
        'pull_request' => [
            'number' => $number, 'state' => 'closed', 'merged' => true, 'draft' => false, 'title' => 'Pull '.$number, 'labels' => [], 'body' => null,
            'updated_at' => Carbon::now()->toIso8601ZuluString(), 'head' => ['ref' => $branch, 'repo' => ['full_name' => 'robot-council/core']],
        ],
    ]);
}

it('raises a free lane once it has been seen free past its window, clears it when the lane works, and raises again when it recurs', function (): void {
    conditionsAt($this, 0);
    conditionsAt($this, 29);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toBeEmpty();

    conditionsAt($this, 31);
    conditionsAt($this, 36);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toHaveCount(1)
        ->and(raisedConditions(LaneConditions::LANE_FREE)[0]['session_id'] ?? null)->toBe($this->session->id)
        ->and(raisedConditions(LaneConditions::LANE_FREE)[0]['free_since'] ?? null)->toBe('2026-09-24T12:00:00+00:00');

    // The lane takes work: the condition clears
    $task = placeOnTheLane($this);
    $this->service(Tasks::class)->transition($task, TaskTransition::Start, $this->session, false);
    conditionsAt($this, 40);

    expect(DB::table('robot_council_lane_conditions')->where('condition', LaneConditions::LANE_FREE)->whereNull('cleared_at')->count())->toBe(0);

    // It finishes, and is seen free again past the window: raised a second time
    Carbon::setTestNow('2026-09-24 12:45:00');
    $this->service(Tasks::class)->transition($task, TaskTransition::Complete, $this->session, false);
    conditionsAt($this, 46);
    conditionsAt($this, 46 + 29);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toHaveCount(1);

    conditionsAt($this, 46 + 31);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toHaveCount(2);
});

it('does not start the free clock at what the lane last did itself, when a merge freed it', function (): void {
    $task = $this->service(Tasks::class)->create($this->session, ['title' => 'Work', 'issue' => 'robot-council/core#7'], false);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Claim, $this->session, false);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Start, $this->session, false);
    $this->service(Tasks::class)->reportBranch($task->id, $this->session, 'feature/x');

    conditionsAt($this, 5);

    // Freed by GitHub two hours in, which records no event naming the lane
    Carbon::setTestNow('2026-09-24 14:00:00');
    deliverMerge($this, 'merge-late');

    expect(Task::query()->findOrFail($task->id)->status)->toBe(TaskStatus::Done);

    conditionsAt($this, 121);
    conditionsAt($this, 140);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toBeEmpty();

    conditionsAt($this, 152);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toHaveCount(1);
});

/**
 * Give the lane room for three tickets: declared at 3, and its seat capped at 3.
 *
 * @param  TestCase  $case  The test case.
 */
function laneOfThree(TestCase $case): void
{
    AgentSession::query()->whereKey($case->session->id)->update(['declared_capacity' => 3]);

    $key = HostKey::from($case->installation->user_id);
    $seat = $case->service(Seats::class)->forDeveloper($key)[0];

    expect($case->service(Seats::class)->cap($key, $seat->id, 3))->toBe(Outcome::Applied);

    // The placement reads the lane it is handed, as the endpoint hands it one read fresh
    $case->session->refresh();
}

/**
 * The one `lane_free` event raised, whole.
 *
 * @return FleetEvent The event.
 */
function laneFreeEvent(): FleetEvent
{
    return FleetEvent::query()->where('type', FleetEventType::LaneCondition->value)->get()
        ->filter(static fn (FleetEvent $event): bool => ($event->meta['condition'] ?? null) === LaneConditions::LANE_FREE)
        ->sole();
}

it('reads a lane of capacity 1 holding nothing exactly as before capacity existed (#436)', function (): void {
    conditionsAt($this, 0);
    conditionsAt($this, 31);

    $event = laneFreeEvent();

    expect($event->body)->toBe(sprintf('Session #%d has been free, with no stated hold, for at least 31 minutes.', $this->session->id))
        ->and($event->meta)->toBe([
            'condition' => LaneConditions::LANE_FREE,
            'subject' => 'session:'.$this->session->id,
            'session_id' => $this->session->id,
            'free_since' => '2026-09-24T12:00:00+00:00',
        ]);
});

it('reports a lane holding fewer tickets than its capacity as having room, with its occupancy (#436)', function (): void {
    laneOfThree($this);
    $first = placeOnTheLane($this);
    $this->service(Tasks::class)->transition($first, TaskTransition::Start, $this->session, false);

    conditionsAt($this, 0);
    conditionsAt($this, 31);

    $event = laneFreeEvent();

    expect($event->body)->toBe(sprintf('Session #%d has had room for more work, holding 1 of 3, with no stated hold, for at least 31 minutes.', $this->session->id))
        ->and($event->meta)->toMatchArray(['session_id' => $this->session->id, 'holding' => 1, 'capacity' => 3]);

    // Filled to its capacity: no longer free, and the condition clears
    placeOnTheLane($this);
    placeOnTheLane($this);
    conditionsAt($this, 35);

    expect(DB::table('robot_council_lane_conditions')->where('condition', LaneConditions::LANE_FREE)->whereNull('cleared_at')->count())->toBe(0);
});

it('reports a lane with room for three holding nothing as free, with its occupancy', function (): void {
    laneOfThree($this);

    conditionsAt($this, 0);
    conditionsAt($this, 31);

    $event = laneFreeEvent();

    expect($event->body)->toBe(sprintf('Session #%d has been free, with no stated hold, for at least 31 minutes.', $this->session->id))
        ->and($event->meta)->toMatchArray(['holding' => 0, 'capacity' => 3]);
});

it('reports a lane whose declared room its seat has not granted as full once it holds one', function (): void {
    // Declared 3, but the seat's cap is still 1, so one ticket fills it
    AgentSession::query()->whereKey($this->session->id)->update(['declared_capacity' => 3]);
    $task = placeOnTheLane($this);
    $this->service(Tasks::class)->transition($task, TaskTransition::Start, $this->session, false);

    conditionsAt($this, 0);
    conditionsAt($this, 60);

    expect(raisedConditions(LaneConditions::LANE_FREE))->toBeEmpty();
});

it('does not raise a free lane that is held on purpose or parked', function (): void {
    $this->service(LaneHolds::class)->hold($this->coordinatorSession, $this->session->id, 'octodev', HoldReason::Decision);

    conditionsAt($this, 0);
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

    // Taken up: cleared, and not raised again
    $this->service(Tasks::class)->transition($task, TaskTransition::Start, $this->session, false);
    conditionsAt($this, 17);

    expect(DB::table('robot_council_lane_conditions')->where('condition', LaneConditions::NOT_TAKEN_UP)->whereNull('cleared_at')->count())->toBe(0);
})->with(['a placement' => [false], 'a hand-back' => [true]]);

it('measures the take-up window on the clock the placement was written on', function (): void {
    $zone = date_default_timezone_get();

    try {
        date_default_timezone_set('America/Chicago');

        placeOnTheLane($this);

        // Ten minutes after a placement written as Chicago digits: inside a fifteen-minute window
        conditionsAt($this, 10);

        expect(raisedConditions(LaneConditions::NOT_TAKEN_UP))->toBeEmpty();

        conditionsAt($this, 16);

        expect(raisedConditions(LaneConditions::NOT_TAKEN_UP))->toHaveCount(1);
    } finally {
        date_default_timezone_set($zone);
    }
});

it('raises a working lane going stale on the presence transition itself', function (): void {
    placeOnTheLane($this);

    // Past the stale threshold and well short of gone. No scheduled check runs: only the sweep
    Carbon::setTestNow(Carbon::now()->addMinutes(10));
    $this->service(SessionPresence::class)->sweep();

    $raised = raisedConditions(LaneConditions::WORKING_UNOBSERVED);

    expect($this->session->refresh()->status)->toBe(AgentSessionStatus::Stale)
        ->and($raised)->toHaveCount(1)
        ->and($raised[0]['session_id'] ?? null)->toBe($this->session->id)
        ->and($raised[0]['status'] ?? null)->toBe('stale');
});

it('tells a lane that went stale and then ended, both times', function (): void {
    placeOnTheLane($this);

    Carbon::setTestNow('2026-09-24 12:10:00');
    $this->service(SessionPresence::class)->sweep();

    Carbon::setTestNow('2026-09-24 12:40:00');
    $this->service(SessionPresence::class)->sighted($this->coordinatorSession->refresh());
    $this->service(SessionPresence::class)->sweep();

    expect($this->session->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and(array_column(raisedConditions(LaneConditions::WORKING_UNOBSERVED), 'status'))->toBe(['stale', 'gone']);
});

it('raises a lane that goes stale again after it answered', function (): void {
    placeOnTheLane($this);

    Carbon::setTestNow('2026-09-24 12:10:00');
    $this->service(SessionPresence::class)->sweep();

    Carbon::setTestNow('2026-09-24 12:11:00');
    $this->service(SessionPresence::class)->sighted($this->session->refresh());
    conditionsAt($this, 12);

    Carbon::setTestNow('2026-09-24 12:20:00');
    $this->service(SessionPresence::class)->sweep();

    expect(array_column(raisedConditions(LaneConditions::WORKING_UNOBSERVED), 'status'))->toBe(['stale', 'stale']);
});

it('tells a coordinator that was not live when the lane went stale, on the next check', function (): void {
    placeOnTheLane($this);
    $this->markSessionGone($this->coordinatorSession);

    Carbon::setTestNow('2026-09-24 12:10:00');
    $this->service(SessionPresence::class)->sweep();

    expect(raisedConditions(LaneConditions::WORKING_UNOBSERVED))->toBeEmpty();

    [$coordinator] = $this->startCoordinatorSession($this->installation);
    conditionsAt($this, 11);

    $event = FleetEvent::query()->where('type', FleetEventType::LaneCondition->value)->sole();

    expect($event->meta['condition'] ?? null)->toBe(LaneConditions::WORKING_UNOBSERVED)
        ->and(DB::table('robot_council_event_addressees')->where('event_id', $event->id)->pluck('agent_session_id')->all())->toBe([$coordinator->id]);
});

it('lets the presence transition commit when the raise cannot be made', function (): void {
    Exceptions::fake();
    placeOnTheLane($this);
    Schema::drop('robot_council_lane_conditions');

    Carbon::setTestNow(Carbon::now()->addMinutes(10));
    $this->service(SessionPresence::class)->sweep();

    expect($this->session->refresh()->status)->toBe(AgentSessionStatus::Stale)
        ->and(FleetEvent::query()->where('type', FleetEventType::SessionStale->value)->where('agent_session_id', $this->session->id)->exists())->toBeTrue();

    Exceptions::assertReported(QueryException::class);
});

it('raises a ready pull request no gate picked up, not one a gate is on, and not one in a repository no gate serves', function (): void {
    readyPull(40);
    readyPull(41);
    readyPull(42, 'robot-council/cli');

    $this->service(GateRuns::class)->start(gateIn($this), 'robot-council/core#41');

    conditionsAt($this, 31);

    expect(array_column(raisedConditions(LaneConditions::PULL_REQUEST_UNPICKED), 'pull_request'))->toBe(['robot-council/core#40']);

    // Picked up: cleared
    $this->service(GateRuns::class)->start(gateIn($this), 'robot-council/core#40');
    conditionsAt($this, 32);

    expect(DB::table('robot_council_lane_conditions')->where('condition', LaneConditions::PULL_REQUEST_UNPICKED)->whereNull('cleared_at')->count())->toBe(0);
});

it('raises a merge with the live sessions in that repository, to the coordinator, once, while the lane task finishes', function (): void {
    $this->app?->make('config')->set('robot-council.github.webhook_secret', 'a-webhook-secret-long-enough-to-count');

    $task = $this->service(Tasks::class)->create($this->session, ['title' => 'Work', 'issue' => 'robot-council/core#7'], false);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Claim, $this->session, false);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Start, $this->session, false);
    $this->service(Tasks::class)->reportBranch($task->id, $this->session, 'feature/x');

    deliverMerge($this, 'merge-1');
    deliverMerge($this, 'merge-1-replayed-under-a-new-id');

    $raised = raisedConditions(LaneConditions::MERGE_BEHIND);
    $event = FleetEvent::query()->where('type', FleetEventType::LaneCondition->value)->sole();

    expect($raised)->toHaveCount(1)
        ->and($raised[0]['pull_request'] ?? null)->toBe('robot-council/core#50')
        ->and($raised[0]['session_ids'] ?? null)->toBe([$this->session->id])
        ->and(DB::table('robot_council_event_addressees')->where('event_id', $event->id)->pluck('agent_session_id')->all())->toBe([$this->coordinatorSession->id])
        ->and(Task::query()->findOrFail($task->id)->status)->toBe(TaskStatus::Done);
});

it('raises no free lane and names no session behind for an ephemeral session, which is not a lane (#424)', function (): void {
    $this->app?->make('config')->set('robot-council.github.webhook_secret', 'a-webhook-secret-long-enough-to-count');

    // In the same repository and the same state as the lane from `beforeEach`, which is the control
    $ephemeral = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'b', ephemeral: true)->owner;

    conditionsAt($this, 0);
    conditionsAt($this, 31);

    deliverMerge($this, 'merge-ephemeral');

    expect(array_column(raisedConditions(LaneConditions::LANE_FREE), 'session_id'))->toBe([$this->session->id])
        ->and(array_column(raisedConditions(LaneConditions::MERGE_BEHIND), 'session_ids'))->toBe([[$this->session->id]])
        ->and($ephemeral->refresh()->status)->toBe(AgentSessionStatus::Active);
});

it('reaches the coordinator through the feed, and no other session', function (): void {
    conditionsAt($this, 0);
    conditionsAt($this, 31);

    $sees = fn (AgentSession $reader): bool => collect($this->service(FleetFeed::class)->after($reader, 0, 200)['events'])
        ->contains(static fn (array $event): bool => $event['type'] === FleetEventType::LaneCondition->value);

    expect($sees($this->coordinatorSession))->toBeTrue()
        ->and($sees($this->session))->toBeFalse();
});

it('reads each window from configuration', function (string $key, Closure $arrange, string $condition): void {
    $this->app?->make('config')->set('robot-council.lane_conditions.'.$key, 3);

    $arrange($this);

    conditionsAt($this, 0);
    conditionsAt($this, 4);

    expect(raisedConditions($condition))->toHaveCount(1);
})->with([
    'the free window' => ['free_after_minutes', static function (): void {}, LaneConditions::LANE_FREE],
    'the take-up window' => ['take_up_within_minutes', static fn (TestCase $case): int => placeOnTheLane($case), LaneConditions::NOT_TAKEN_UP],
    'the gate pickup window' => ['gate_pickup_within_minutes', static function (TestCase $case): void {
        readyPull(40);
        gateIn($case);
    }, LaneConditions::PULL_REQUEST_UNPICKED],
]);
