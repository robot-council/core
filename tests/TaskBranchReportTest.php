<?php

declare(strict_types=1);

/**
 * The lane holding a task reports its branch after it has started (#333).
 *
 * `robot-council/cli#238` decided this: at `task_start` the branch usually does not exist yet, so
 * a value read then is `main` or a reused worktree's previous branch. The lane reports it once it
 * exists, and these assert on the row, because a refusal proved on the response alone would pass
 * against a store that refused and wrote anyway.
 *
 * @command  vendor/bin/pest --compact tests/TaskBranchReportTest.php
 */

use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);

    [$this->session, $this->token] = $this->startAgentSession($this->installation);
});

/**
 * The task's recorded branch, read fresh.
 *
 * @param  int  $taskId  The task.
 * @return string|null Its branch.
 */
function recordedBranch(int $taskId): ?string
{
    return Task::query()->findOrFail($taskId)->branch;
}

/**
 * A task this test's session holds and has started.
 *
 * @param  TestCase  $case  The test case.
 * @return int The task.
 */
function startedTask(TestCase $case): int
{
    $taskId = $case->createClaimedTask();

    expect($case->service(Tasks::class)->transition($taskId, TaskTransition::Start, $case->session, asCoordinator: false))
        ->toBe(Outcome::Applied);

    return $taskId;
}

it('records the branch the holder reports on a started task, and a second report replaces it', function (): void {
    $taskId = startedTask($this);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.branch', ['task' => $taskId]), ['branch' => 'feature/lane-board'])
        ->assertOk()
        ->assertExactJson(['task_id' => $taskId, 'branch' => 'feature/lane-board', 'sub_label' => null, 'applied' => true]);

    expect(recordedBranch($taskId))->toBe('feature/lane-board');

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.branch', ['task' => $taskId]), ['branch' => 'feature/lane-board-2'])
        ->assertOk();

    expect(recordedBranch($taskId))->toBe('feature/lane-board-2');
});

it('treats a report of the branch already recorded as applied', function (): void {
    $taskId = startedTask($this);
    $tasks = $this->service(Tasks::class);

    expect($tasks->reportBranch($taskId, $this->session, 'feature/x'))->toBe(Outcome::Applied)
        ->and($tasks->reportBranch($taskId, $this->session, 'feature/x'))->toBe(Outcome::Applied)
        ->and(recordedBranch($taskId))->toBe('feature/x');
});

it('records a branch on a blocked task', function (): void {
    $taskId = startedTask($this);
    $this->service(Tasks::class)->transition($taskId, TaskTransition::Block, $this->session, asCoordinator: false);

    expect($this->service(Tasks::class)->reportBranch($taskId, $this->session, 'feature/x'))->toBe(Outcome::Applied)
        ->and(recordedBranch($taskId))->toBe('feature/x');
});

it('refuses a session that does not hold the task, and writes nothing', function (): void {
    $taskId = startedTask($this);
    $this->service(Tasks::class)->reportBranch($taskId, $this->session, 'feature/mine');

    // The same developer's other session: the refusal is about holding the task, not about whose it is
    [, $otherToken] = $this->startAgentSession($this->installation);

    $this->machine($otherToken)
        ->postJson(route('robot-council.tasks.branch', ['task' => $taskId]), ['branch' => 'feature/theirs'])
        ->assertForbidden()
        ->assertJson(['applied' => false, 'branch' => null]);

    expect(recordedBranch($taskId))->toBe('feature/mine');
});

it('refuses a task that is not in progress or blocked, and writes nothing', function (string $state): void {
    $tasks = $this->service(Tasks::class);

    $taskId = match ($state) {
        'pending' => $tasks->create($this->session, ['title' => 'Unclaimed'], withCoordinator: false)->id,
        'claimed' => $this->createClaimedTask(),
        default => startedTask($this),
    };

    if ($state === 'done') {
        $tasks->transition($taskId, TaskTransition::Complete, $this->session, asCoordinator: false);
    }

    $outcome = $tasks->reportBranch($taskId, $this->session, 'feature/x');

    // A pending task has no holder, and is reported as the status it is in rather than as a
    // question of who holds it
    expect($outcome)->toBe(Outcome::Conflict)
        ->and(recordedBranch($taskId))->toBeNull();
})->with(['pending', 'claimed', 'done']);

it('answers not found for a task that does not exist', function (): void {
    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.branch', ['task' => 999999]), ['branch' => 'feature/x'])
        ->assertNotFound();
});

it('refuses a branch outside the bound at the edge and in the store, and writes nothing', function (string $branch): void {
    $taskId = startedTask($this);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.branch', ['task' => $taskId]), ['branch' => $branch])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch');

    expect(fn () => $this->service(Tasks::class)->reportBranch($taskId, $this->session, $branch))
        ->toThrow(InvalidArgumentException::class)
        ->and(recordedBranch($taskId))->toBeNull();
})->with([
    'a leading dash' => ['-rf'],
    'a double dot' => ['feature/../main'],
    'a shell character' => ['feature;rm'],
    'one character over the bound' => [str_repeat('b', 201)],
]);

it('refuses a missing branch at the edge', function (): void {
    $taskId = startedTask($this);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.branch', ['task' => $taskId]), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch');
});

it('refuses a session without tasks:claim before it reaches the store', function (): void {
    $taskId = startedTask($this);
    [, $narrowToken] = $this->startAgentSessionWithAbilities($this->installation, ['events:read']);

    $this->machine($narrowToken)
        ->postJson(route('robot-council.tasks.branch', ['task' => $taskId]), ['branch' => 'feature/x'])
        ->assertForbidden();

    expect(recordedBranch($taskId))->toBeNull();
});
