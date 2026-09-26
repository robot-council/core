<?php

declare(strict_types=1);

/**
 * Re-placing a task onto the lane already holding it, within the same second (#435).
 *
 * **In the `engine-semantics` group because the answer differed by engine.** Such a re-placement
 * writes byte-identical values, `claimed_at` included, since a `dateTime` is bound to the second, so
 * MySQL's update reports 0 rows changed where SQLite and Postgres report 1 -- and the store read the
 * 0 as a lost race. The clock is pinned here so the two placements land in one second on every run,
 * rather than only when a slow machine happens to split them.
 *
 * @command  vendor/bin/pest --compact tests/SameSecondReplacementTest.php
 */
use Illuminate\Support\Carbon;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\PlacementRule;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\PlacementRefused;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

pest()->group('engine-semantics');

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77]);

    Carbon::setTestNow('2026-09-24 12:00:00');

    $this->installation = $this->approveInstallation($this->enrollDeveloper(4242, login: 'octodev'));
    $this->session = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'a')->owner;

    [$this->coordinatorSession] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );

    GitHubItem::query()->insert([
        'repository' => 'robot-council/core', 'number' => 12, 'is_pull_request' => false, 'state' => 'open',
        'title' => 'Issue 12', 'labels' => '[]', 'github_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
});

/**
 * Place a task on a lane, as the coordinator.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $taskId  The task.
 * @param  AgentSession  $lane  The lane.
 * @return Outcome What came of it.
 */
function placeAgain(TestCase $case, int $taskId, AgentSession $lane): Outcome
{
    return $case->service(Tasks::class)->transition($taskId, TaskTransition::Reassign, $case->coordinatorSession, true, $lane, directive: 'Take this.');
}

it('answers Applied to a re-placement onto the lane already holding the task, in the same second', function (): void {
    $taskId = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Work', 'issue' => 'robot-council/core#12'], true)->id;

    expect(placeAgain($this, $taskId, $this->session))->toBe(Outcome::Applied)
        // No clock movement between the two: every value the second write sets is the same
        ->and(placeAgain($this, $taskId, $this->session))->toBe(Outcome::Applied);

    $task = Task::query()->findOrFail($taskId);

    expect($task->status)->toBe(TaskStatus::Claimed)
        ->and($task->claimed_by)->toBe($this->session->id);
});

it('refuses a same-second re-placement onto a full lane on the placement rule, on every engine', function (): void {
    // The lane holds two tasks at a capacity of one: its cap was lowered after both were placed,
    // which is the one way a lane holds more than it may be given
    $tasks = $this->service(Tasks::class);
    $first = $tasks->create($this->coordinatorSession, ['title' => 'First', 'issue' => 'robot-council/core#12'], true)->id;
    $second = $tasks->create($this->coordinatorSession, ['title' => 'Second', 'issue' => 'robot-council/core#12'], true)->id;

    expect(placeAgain($this, $first, $this->session))->toBe(Outcome::Applied);
    Task::query()->whereKey($second)->update([
        'status' => TaskStatus::Claimed->value, 'claimed_by' => $this->session->id, 'claimed_at' => Carbon::now(), 'placed_by' => 'coordinator',
    ]);

    // Re-placing the first: the lane already holds another task at capacity 1, so `lane_free`
    // refuses it -- where MySQL used to answer Conflict before the rules were read
    $refused = [];

    try {
        placeAgain($this, $first, $this->session);
    } catch (PlacementRefused $placementRefused) {
        $refused = $placementRefused->rules;
    }

    expect($refused)->toBe([PlacementRule::LaneFree]);
});

it('still answers Conflict when the write matched nothing, so a lost race is not read as applied', function (): void {
    $taskId = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Work', 'issue' => 'robot-council/core#12'], true)->id;

    // Finished, which no reassignment starts from: the write matches no row on any engine
    Task::query()->whereKey($taskId)->update(['status' => TaskStatus::Done->value]);

    expect(placeAgain($this, $taskId, $this->session))->toBe(Outcome::Conflict);
});
