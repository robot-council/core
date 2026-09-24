<?php

declare(strict_types=1);

/**
 * What a task records about how it came to be in a lane's hands: its GitHub issue, who placed it,
 * whether it is a hand-back, and the branch the lane reports.
 *
 * `robot-council/core#316`, the model half of the lane board #314 decided. The guarantee at the
 * centre of it is that **a coordinator's placement and the directive telling the lane are one
 * write**, so the state #314 measured -- a lane shown working for 48 minutes on a placement nobody
 * told it about -- is not reachable rather than merely detected.
 *
 * The helpers are prefixed `placement` because Pest test files share one global namespace, and
 * `tests/TaskLifecycleTest.php` already declares `coordinator()` and `anotherAgent()`.
 *
 * @command  vendor/bin/pest --compact tests/TaskPlacementTest.php
 */

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\Placement;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\BranchName;
use RobotCouncil\Support\IssueReference;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\TaskList;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Support\WorkIdentity;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);

    $this->installation = $this->approveInstallation($this->developer);

    [$this->session, $this->token] = $this->startAgentSession($this->installation);
});

/**
 * A coordinator under a second developer, memoized.
 *
 * Another developer's for the reason `tests/TaskLifecycleTest.php` gives: a coordinator that could
 * only direct its own developer's agents would make every cross-developer assertion pass for the
 * wrong reason.
 *
 * @param  TestCase  $case  The test case.
 * @return array{AgentSession, string} The session and its token.
 */
function placementCoordinator(TestCase $case): array
{
    // **`TestCase`'s declared properties, not new dynamic ones.** An earlier version memoized into
    // `$case->placementCoordinatorSession`, which `TestCase` does not declare; Rector then rewrote
    // its `isset()` into `!== null`, which on an undeclared property raises "Undefined property",
    // and `failOnWarning` turned every test that reached it red. On a declared typed property that
    // is still uninitialized, `isset()` is the check that works, and Rector leaves it alone.
    if (isset($case->coordinatorSession)) {
        return [$case->coordinatorSession, $case->coordinatorToken];
    }

    $other = $case->enrollDeveloper(77, login: 'coordinator');

    $installation = $case->approveInstallation($other, machineLabel: 'coordinator-box');

    [$case->coordinatorSession, $case->coordinatorToken] = $case->startCoordinatorSession($installation);

    return [$case->coordinatorSession, $case->coordinatorToken];
}

/**
 * File one task as the ordinary agent, through the endpoint.
 *
 * @param  TestCase  $case  The test case.
 * @param  array<string, mixed>  $overrides  Fields to replace in the request body.
 * @return int The task's ID.
 */
function placementTask(TestCase $case, array $overrides = []): int
{
    $response = $case->machine($case->token)->postJson(route('robot-council.tasks.store'), [
        'title' => 'Build the lane board',
        ...$overrides,
    ]);

    $response->assertCreated();

    return intValue($response->json('task_id'));
}

/**
 * Place a task on a session, as the coordinator, through the endpoint.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $task  The task.
 * @param  AgentSession  $assignee  The session to place it on.
 * @param  array<string, mixed>  $extra  Anything else to send.
 * @return TestResponse<JsonResponse> The response.
 */
function placeTask(TestCase $case, int $task, AgentSession $assignee, array $extra = []): TestResponse
{
    [, $token] = placementCoordinator($case);

    return $case->machine($token)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'reassign']),
        ['session_id' => $assignee->getKey(), 'directive' => 'Take this task.', ...$extra]
    );
}

/**
 * A lane a placement of this issue can land on: the issue recorded as open, and the lane working in
 * its repository -- which is what #320's invariants ask of a placement that names a ticket.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $issue  The issue, `owner/name#N`.
 * @return array{AgentSession, string} The lane and its token.
 */
function laneInRepositoryOf(TestCase $case, string $issue): array
{
    [$repository, $number] = explode('#', $issue, 2);

    GitHubItem::query()->insert([
        'repository' => $repository, 'number' => (int) $number, 'is_pull_request' => false, 'state' => 'open',
        'title' => 'Issue', 'labels' => '[]', 'github_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $issued = $case->service(AgentSessions::class)->start($case->installation, $repository, 'lane');

    return [$issued->owner, $issued->plainTextToken];
}

/**
 * One task out of a page `TaskList` returned, failing loudly if it is not there.
 *
 * A missing row read as null would make every assertion that follows compare against null, and a
 * field that should be null would pass for the wrong reason.
 *
 * @param  list<array<string, mixed>>  $tasks  The page's tasks.
 * @param  int  $id  The task wanted.
 * @return array<string, mixed> Its row.
 */
function placementRow(array $tasks, int $id): array
{
    foreach ($tasks as $row) {
        if (($row['id'] ?? null) === $id) {
            return $row;
        }
    }

    throw new RuntimeException(sprintf('Task %d was not on the page.', $id));
}

// ------------------------------------------------------------------ the issue a task is for

it('stores a repository-qualified issue on the task it files', function (): void {
    $task = placementTask($this, ['issue' => 'robot-council/core#316']);

    expect(Task::query()->findOrFail($task)->issue)->toBe('robot-council/core#316');
});

it('refuses a bare issue number at the edge, since it names no repository', function (string $issue): void {
    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.store'), ['title' => 'Fine', 'issue' => $issue])
        ->assertStatus(422)
        ->assertJsonValidationErrors('issue');

    // Nothing was written: the refusal is the edge's, not a row with a bad value in it
    expect(Task::query()->count())->toBe(0);
})->with([
    'a hash and a number' => '#316',
    'a number alone' => '316',
    'a repository with no number' => 'robot-council/core',
    'a repository with a hash and nothing after it' => 'robot-council/core#',
    'a leading zero' => 'robot-council/core#0316',
    'a space' => 'robot-council/core #316',

    // No trailing-newline row here, deliberately. The framework's global `TrimStrings` middleware
    // trims input before validation, so the edge never sees one: it arrives valid and is stored
    // without it. `/D` is what guards the store, where no middleware runs -- tested below.
]);

it('refuses a trailing newline in the store, where no middleware trims it', function (): void {
    // **This is what `/D` on each pattern is for.** At the edge `TrimStrings` removes the newline
    // before validation ever runs, so a host calling the store directly is the only path a newline
    // can arrive by -- and without `/D`, `$` matches just before a final newline and lets it in.
    expect(fn () => IssueReference::ensure("robot-council/core#316\n"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => BranchName::ensure("lane-board\n"))->toThrow(InvalidArgumentException::class)

        // The control: the same values without the newline are accepted
        ->and(fn () => IssueReference::ensure('robot-council/core#316'))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => BranchName::ensure('lane-board'))->not->toThrow(InvalidArgumentException::class);
});

it('stores an issue sent with a trailing newline without it, because the edge trims first', function (): void {
    // Pinned so the division of labour above is on record rather than inferred: the endpoint
    // accepts this, and what reaches the column carries no newline
    $task = placementTask($this, ['issue' => "robot-council/core#316\n"]);

    expect(Task::query()->findOrFail($task)->issue)->toBe('robot-council/core#316');
});

it('refuses a bare issue number in the store too, where a host may call it directly', function (): void {
    expect(fn () => $this->service(Tasks::class)->create($this->session, ['title' => 'Fine', 'issue' => '#316'], false))
        ->toThrow(InvalidArgumentException::class, 'names no repository')
        ->and(Task::query()->count())->toBe(0);
});

it('refuses an issue reference one character over its bound, and accepts one at it', function (): void {
    // A repository at the longest `WorkIdentity` allows, and the longest number: exactly `MAX`
    $owner = str_repeat('a', 69);
    $name = str_repeat('b', WorkIdentity::MAX_REPOSITORY - 69 - 1);
    $atBound = sprintf('%s/%s#%s', $owner, $name, str_repeat('9', IssueReference::MAX_NUMBER_DIGITS));

    expect($atBound)->toHaveLength(IssueReference::MAX);

    // The control: the value at the bound is accepted, so the refusal below is about length
    expect(fn () => IssueReference::ensure($atBound))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => IssueReference::ensure($atBound.'9'))->toThrow(InvalidArgumentException::class);
});

it('accepts every repository a session may report as its own, qualified with a number', function (string $repository): void {
    // The repository half of the pattern is `WorkIdentity::REPOSITORY`'s, rewritten with its end
    // anchor moved to the `#`. This holds the two together: a repository a session may name is one
    // a task may name, and one a session may not is one a task may not either.
    $accepted = preg_match(WorkIdentity::REPOSITORY, $repository) === 1;

    expect(preg_match(IssueReference::PATTERN, $repository.'#1') === 1)->toBe($accepted);
})->with([
    'owner/name' => 'robot-council/core',
    'dots and underscores' => 'a_b.c/d.e_f',
    'a name of dots alone' => 'owner/..',
    'an owner of dots alone' => '../name',
    'no separator' => 'robot-council',
    'two separators' => 'a/b/c',
    'a leading hyphen' => 'owner/-name',
]);

// ------------------------------------------------------------------ placement

it('places an unclaimed task on a named lane, which a reassignment could not do before', function (): void {
    $task = placementTask($this);

    [$lane] = $this->startAgentSession($this->installation);

    placeTask($this, $task, $lane)->assertOk();

    $row = Task::query()->findOrFail($task);

    expect($row->status)->toBe(TaskStatus::Claimed)
        ->and($row->claimed_by)->toBe($lane->getKey())
        ->and($row->placed_by)->toBe(Placement::Coordinator)
        ->and($row->hand_back)->toBeFalse();
});

it('writes the directive to the placed lane in the same step, naming the task', function (): void {
    $task = placementTask($this);

    [$lane] = $this->startAgentSession($this->installation);

    placeTask($this, $task, $lane, ['directive' => 'Build the lane board from #316.'])->assertOk();

    $directive = FleetEvent::query()->where('type', FleetEventType::Directive->value)->sole();

    // Each key asserted on its own: a JSON round trip is not promised to keep key order, and a
    // whole-array `toBe` would fail on order rather than on content
    expect($directive->body)->toBe('Build the lane board from #316.')
        ->and($directive->meta['targets'] ?? null)->toBe([$lane->getKey()])
        ->and($directive->meta['task_id'] ?? null)->toBe($task)
        ->and(array_keys($directive->meta ?? []))->toEqualCanonicalizing(['targets', 'task_id']);
});

it('refuses a placement with no directive, at the edge and in the store', function (array $body): void {
    $task = placementTask($this);

    [$lane] = $this->startAgentSession($this->installation);
    [, $token] = placementCoordinator($this);

    $this->machine($token)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'reassign']),
        ['session_id' => $lane->getKey(), ...$body]
    )->assertStatus(422)->assertJsonValidationErrors('directive');

    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Pending);
})->with([
    'no directive' => [[]],
    'an empty directive' => [['directive' => '']],
]);

it('refuses a placement with a blank directive in the store, where the edge cannot see it', function (): void {
    $task = placementTask($this);

    [$lane] = $this->startAgentSession($this->installation);
    [$coordinator] = placementCoordinator($this);

    expect(fn () => $this->service(Tasks::class)->transition(
        $task,
        TaskTransition::Reassign,
        $coordinator,
        true,
        $lane,
        directive: '   '
    ))->toThrow(InvalidArgumentException::class, 'needs a directive')
        ->and(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Pending);
});

it('rolls the placement back when the directive cannot be written, so neither commits', function (): void {
    $task = placementTask($this);

    [$lane] = $this->startAgentSession($this->installation);
    [$coordinator] = placementCoordinator($this);

    // **A real failure, at the point the criterion names, with nothing mocked.** One character over
    // `FleetEvent::MAX_BODY` passes the store's own guard -- which checks only that there is a
    // directive -- so the placement's UPDATE and its `task.reassigned` event are both written, and
    // then `FleetEvents::record()` refuses the directive inside the same transaction.
    $tooLong = str_repeat('x', FleetEvent::MAX_BODY + 1);

    expect(fn () => $this->service(Tasks::class)->transition(
        $task,
        TaskTransition::Reassign,
        $coordinator,
        true,
        $lane,
        directive: $tooLong
    ))->toThrow(InvalidArgumentException::class);

    $row = Task::query()->findOrFail($task);

    // Asserted on the row, not on anything the call returned: the placement did not commit
    expect($row->status)->toBe(TaskStatus::Pending)
        ->and($row->claimed_by)->toBeNull()
        ->and($row->placed_by)->toBeNull()
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskReassigned->value)->count())->toBe(0)
        ->and(FleetEvent::query()->where('type', FleetEventType::Directive->value)->count())->toBe(0);
});

it('commits both when the directive is exactly at its bound, which is the control for the rollback', function (): void {
    $task = placementTask($this);

    [$lane] = $this->startAgentSession($this->installation);
    [$coordinator] = placementCoordinator($this);

    // Without this, the test above would pass against a placement that never commits at all
    $outcome = $this->service(Tasks::class)->transition(
        $task,
        TaskTransition::Reassign,
        $coordinator,
        true,
        $lane,
        directive: str_repeat('x', FleetEvent::MAX_BODY)
    );

    expect($outcome)->toBe(Outcome::Applied)
        ->and(Task::query()->findOrFail($task)->claimed_by)->toBe($lane->getKey())
        ->and(FleetEvent::query()->where('type', FleetEventType::Directive->value)->count())->toBe(1);
});

it('refuses to place a task on a lane that could not have claimed it itself', function (): void {
    // Filed by developer 4242 with no coordinator's ability, so only 4242's own sessions may claim it
    $task = placementTask($this);

    // A plain session under the coordinator's developer, 77: not eligible for 4242's task
    $otherInstallation = placementCoordinator($this)[0]->installation;
    [$ineligible] = $this->startAgentSession($otherInstallation);

    placeTask($this, $task, $ineligible)->assertForbidden();

    $row = Task::query()->findOrFail($task);

    // Before #316 this placed it, and the lane then held a task `TaskList` would not let it read
    expect($row->status)->toBe(TaskStatus::Pending)
        ->and($row->claimed_by)->toBeNull()
        ->and(FleetEvent::query()->where('type', FleetEventType::Directive->value)->count())->toBe(0);
});

it('diagnoses an ineligible assignee against the assignee, not against the coordinator', function (): void {
    // **The case that tells the two apart**, which the test above cannot: there the coordinator and
    // the lane both belong to developer 77, so both are ineligible and a diagnosis tested against
    // the coordinator would still answer 403. Here the coordinator belongs to 4242, the task's own
    // developer, so it IS eligible -- and only a diagnosis of the assignee answers 403. Tested
    // against the coordinator, this becomes a 409, which invites a retry that can never succeed.
    [, $coordinatorToken] = $this->startCoordinatorSession($this->installation);

    $task = placementTask($this);

    $otherInstallation = placementCoordinator($this)[0]->installation;
    [$ineligible] = $this->startAgentSession($otherInstallation);

    $this->machine($coordinatorToken)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'reassign']),
        ['session_id' => $ineligible->getKey(), 'directive' => 'Take this task.']
    )->assertForbidden();

    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Pending);
});

it("gives a reassigned held task a clean slate: the last lane's branch and hand-back do not carry over", function (): void {
    $task = placementTask($this);

    [$first, $firstToken] = $this->startAgentSession($this->installation);
    [$second] = $this->startAgentSession($this->installation);

    // Placed as a hand-back, then taken up on a branch
    placeTask($this, $task, $first, ['hand_back' => true])->assertOk();

    $this->machine($firstToken)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'start']),
        ['branch' => 'first-lane']
    )->assertOk();

    // **From a held status, which is where these two can be wrong.** A task arriving from `pending`
    // has no branch and no hand-back -- release and the sweep already cleared them -- so only a
    // reassignment of held work can show a column carried over from the previous holder.
    placeTask($this, $task, $second)->assertOk();

    $row = Task::query()->findOrFail($task);

    expect($row->claimed_by)->toBe($second->getKey())
        ->and($row->status)->toBe(TaskStatus::Claimed)
        ->and($row->placed_by)->toBe(Placement::Coordinator)
        ->and($row->branch)->toBeNull()
        ->and($row->hand_back)->toBeFalse();
});

it('moves a task a lane already holds when a placement does not insist, and tells the lane that lost it', function (): void {
    // **Without `expect`, the coordinator's word wins over a lane that claimed first**, as a
    // reassignment of held work always has, and the lane that lost it is told through
    // `task.reassigned`. #328 decided a placement may insist instead; the next test is that form.
    $task = placementTask($this);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'claim']))
        ->assertOk();

    [$lane] = $this->startAgentSession($this->installation);

    placeTask($this, $task, $lane)->assertOk();

    $row = Task::query()->findOrFail($task);

    expect($row->claimed_by)->toBe($lane->getKey())
        ->and($row->placed_by)->toBe(Placement::Coordinator)
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskReassigned->value)->count())->toBe(1);
});

it('leaves a task with the lane that claimed it first when the placement insists on pending', function (): void {
    $task = placementTask($this);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'claim']))
        ->assertOk();

    [$lane] = $this->startAgentSession($this->installation);

    // The coordinator first, so its own joining is not counted as something the placement wrote
    placementCoordinator($this);
    $events = FleetEvent::query()->count();

    placeTask($this, $task, $lane, ['expect' => 'pending'])->assertConflict()->assertJson(['applied' => false]);

    $row = Task::query()->findOrFail($task);

    // Still the first lane's, placed by nobody, and nothing written to the feed -- no reassignment
    // event and no directive
    expect($row->claimed_by)->toBe($this->session->getKey())
        ->and($row->placed_by)->toBe(Placement::Lane)
        ->and(FleetEvent::query()->count())->toBe($events);
});

it('places a pending task when the placement insists on pending', function (): void {
    $task = placementTask($this);
    [$lane] = $this->startAgentSession($this->installation);

    placeTask($this, $task, $lane, ['expect' => 'pending'])->assertOk();

    expect(Task::query()->findOrFail($task)->claimed_by)->toBe($lane->getKey());
});

it('refuses an expectation a reassignment cannot have, or on another transition', function (string $transition, array $body): void {
    $task = placementTask($this);
    [, $coordinatorToken] = placementCoordinator($this);
    [$lane] = $this->startAgentSession($this->installation);

    $this->machine($transition === 'reassign' ? $coordinatorToken : $this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => $transition]), [
            'session_id' => $lane->getKey(), 'directive' => 'Take this task.', ...$body,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expect');

    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Pending);
})->with([
    'a finished status' => ['reassign', ['expect' => 'done']],
    'not a status' => ['reassign', ['expect' => 'unclaimed']],
    'on a claim' => ['claim', ['expect' => 'pending']],
]);

it('refuses `expect` on anything but a reassignment at the store', function (): void {
    $task = placementTask($this);

    expect(fn () => $this->service(Tasks::class)->transition($task, TaskTransition::Claim, $this->session, false, expect: TaskStatus::Pending))
        ->toThrow(InvalidArgumentException::class, 'Only a reassignment takes `expect`')
        ->and(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Pending);
});

it("places a task a coordinator filed on any developer's lane", function (): void {
    // The other half of the eligibility rule: filed with the coordinator's ability, so open to all
    [, $coordinatorToken] = placementCoordinator($this);

    $task = intValue($this->machine($coordinatorToken)
        ->postJson(route('robot-council.tasks.store'), ['title' => 'Anyone may take this'])
        ->assertCreated()
        ->json('task_id'));

    [$lane] = $this->startAgentSession($this->installation);

    placeTask($this, $task, $lane)->assertOk();

    expect(Task::query()->findOrFail($task)->claimed_by)->toBe($lane->getKey());
});

it('marks a placement as a hand-back only when asked', function (mixed $handBack, bool $expected): void {
    $task = placementTask($this);

    [$lane] = $this->startAgentSession($this->installation);

    placeTask($this, $task, $lane, $handBack === null ? [] : ['hand_back' => $handBack])->assertOk();

    expect(Task::query()->findOrFail($task)->hand_back)->toBe($expected);
})->with([
    'not sent' => [null, false],
    'false' => [false, false],
    'true' => [true, true],
    'the integer 1, which the boolean rule admits' => [1, true],
]);

// ------------------------------------------------------------------ take-up and the branch

it("records a lane claiming a task itself as the lane's own placement", function (): void {
    $task = placementTask($this);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'claim']))
        ->assertOk();

    expect(Task::query()->findOrFail($task)->placed_by)->toBe(Placement::Lane);
});

it('keeps take-up apart from placement: placed is claimed, taken up is in progress', function (): void {
    $task = placementTask($this);

    [$lane, $laneToken] = $this->startAgentSession($this->installation);

    placeTask($this, $task, $lane)->assertOk();

    // Placed and not taken up: the state the board shows as "placed, not started"
    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Claimed);

    $this->machine($laneToken)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'start']),
        ['branch' => 'lane-board']
    )->assertOk();

    $row = Task::query()->findOrFail($task);

    expect($row->status)->toBe(TaskStatus::InProgress)
        ->and($row->branch)->toBe('lane-board')
        ->and($row->placed_by)->toBe(Placement::Coordinator);
});

it('keeps the branch when a blocked task is resumed without naming it again', function (): void {
    $task = placementTask($this);

    $move = fn (string $transition, array $body = []) => $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => $transition]), $body)
        ->assertOk();

    $move('claim');
    $move('start', ['branch' => 'lane-board']);
    $move('block');
    $move('start');

    expect(Task::query()->findOrFail($task)->branch)->toBe('lane-board');
});

it('refuses a branch outside its shape at the edge', function (string $branch): void {
    $task = placementTask($this);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'claim']))
        ->assertOk();

    $this->machine($this->token)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'start']),
        ['branch' => $branch]
    )->assertStatus(422)->assertJsonValidationErrors('branch');

    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Claimed);
})->with([
    'a leading hyphen' => '-branch',
    'a leading separator' => '/branch',
    'a leading dot' => '.branch',
    'two dots' => 'a..b',
    'a double separator' => 'a//b',
    'a trailing separator' => 'branch/',
    'a trailing dot' => 'branch.',
    'a component starting with a dot' => 'feature/.hidden',
    'a .lock suffix' => 'feature.lock',
    'a space' => 'lane board',
    'a shell character' => 'lane;rm',

    // No trailing-newline row, for the `TrimStrings` reason the issue dataset gives
]);

it('refuses a branch one character over its bound, and accepts one at it', function (): void {
    $atBound = str_repeat('a', BranchName::MAX);

    expect(fn () => BranchName::ensure($atBound))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => BranchName::ensure($atBound.'a'))->toThrow(InvalidArgumentException::class);
});

it('ignores a branch passed to a transition that does not take one', function (): void {
    $task = placementTask($this);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'claim']))
        ->assertOk();
    $this->machine($this->token)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'start']),
        ['branch' => 'lane-board']
    )->assertOk();

    // **A block, not a claim, because a claim resets the branch anyway** and so would pass this
    // with the store's filtering deleted. A block writes the column only if it is handed a branch,
    // so a store that let one through would overwrite `lane-board` here.
    $this->service(Tasks::class)->transition($task, TaskTransition::Block, $this->session, false, branch: 'stray');

    $row = Task::query()->findOrFail($task);

    expect($row->status)->toBe(TaskStatus::Blocked)
        ->and($row->branch)->toBe('lane-board');
});

// ------------------------------------------------------------------ what a release clears

it('clears the placement, the hand-back and the branch when a task is given back', function (string $how): void {
    $task = placementTask($this, ['issue' => 'robot-council/core#316']);

    [$lane, $laneToken] = laneInRepositoryOf($this, 'robot-council/core#316');

    placeTask($this, $task, $lane, ['hand_back' => true])->assertOk();

    $this->machine($laneToken)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'start']),
        ['branch' => 'lane-board']
    )->assertOk();

    if ($how === 'release') {
        $this->machine($laneToken)
            ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'release']))
            ->assertOk();
    } else {
        // The sweep's path, which writes its own UPDATE rather than going through `transition()`
        $this->markSessionGone($lane);
        $this->service(Tasks::class)->releaseOrphaned();
    }

    $row = Task::query()->findOrFail($task);

    expect($row->status)->toBe(TaskStatus::Pending)
        ->and($row->placed_by)->toBeNull()
        ->and($row->hand_back)->toBeFalse()
        ->and($row->branch)->toBeNull()

        // The issue is what the task is about, not how it was held, so it survives
        ->and($row->issue)->toBe('robot-council/core#316');
})->with(['release', 'the sweep']);

it('keeps the placement and the branch on a finished task, as its history', function (): void {
    $task = placementTask($this, ['issue' => 'robot-council/core#316']);

    [$lane, $laneToken] = laneInRepositoryOf($this, 'robot-council/core#316');

    placeTask($this, $task, $lane)->assertOk();

    $this->machine($laneToken)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'start']),
        ['branch' => 'lane-board']
    )->assertOk();

    $this->machine($laneToken)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'complete']))
        ->assertOk();

    $row = Task::query()->findOrFail($task);

    expect($row->status)->toBe(TaskStatus::Done)
        ->and($row->placed_by)->toBe(Placement::Coordinator)
        ->and($row->branch)->toBe('lane-board')
        ->and($row->issue)->toBe('robot-council/core#316');
});

// ------------------------------------------------------------------ who may read it

it('shows the issue and the branch only to a reader who may read the task', function (): void {
    // Developer 4242's own task, filed with no coordinator's ability
    $task = placementTask($this, ['issue' => 'robot-council/core#316']);

    $move = fn (string $transition, array $body = []) => $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => $transition]), $body)
        ->assertOk();

    $move('claim');
    $move('start', ['branch' => 'lane-board']);

    $list = $this->service(TaskList::class);

    // A plain session of another developer: told the task exists, not what it is about
    $otherInstallation = placementCoordinator($this)[0]->installation;
    [$stranger] = $this->startAgentSession($otherInstallation);

    $unreadable = placementRow($list->page(null, 50, $stranger, false)['tasks'], $task);
    $readable = placementRow($list->page(null, 50, $this->session, false)['tasks'], $task);

    // The control is the second reader: the same task, the same fields, and the values are there
    expect($readable['readable'])->toBeTrue()
        ->and($readable['issue'])->toBe('robot-council/core#316')
        ->and($readable['branch'])->toBe('lane-board')
        ->and($unreadable['readable'])->toBeFalse()
        ->and($unreadable['issue'])->toBeNull()
        ->and($unreadable['branch'])->toBeNull()

        // How it came to be held is state, not content, so every reader sees it
        ->and($unreadable['placed_by'])->toBe('lane')
        ->and($unreadable['hand_back'])->toBeFalse();
});

// ------------------------------------------------------------------ the migration

it('adds columns as wide as the bounds the package enforces', function (): void {
    // **Postgres and MySQL only.** SQLite's grammar emits a bare `varchar` with no length at all, so
    // there is nothing to read back there; the `postgres` job is where this runs in CI. The
    // migration writes its widths out rather than reading these constants, for the rename-safety
    // reason its docblock gives, and this is what keeps the two from drifting.
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite records no varchar length to compare.');
    }

    $widths = [];

    foreach (Schema::getColumns('robot_council_tasks') as $column) {
        if (\is_array($column) && \is_string($column['name'] ?? null) && \is_string($column['type'] ?? null)) {
            $widths[$column['name']] = $column['type'];
        }
    }

    // The control for the loop above: a reading that found no columns would pass nothing below
    expect($widths)->toHaveKeys(['issue', 'branch', 'placed_by', 'hand_back']);

    expect($widths['issue'])->toContain('('.IssueReference::MAX.')')
        ->and($widths['branch'])->toContain('('.BranchName::MAX.')')
        ->and($widths['placed_by'])->toContain('(16)');

    // The longest `Placement` value has to fit the column it is written to
    $longest = max(array_map(static fn (Placement $placement): int => mb_strlen($placement->value), Placement::cases()));

    expect($longest)->toBeLessThanOrEqual(16);
});

it('runs its migration again without error, and rolls it back cleanly', function (): void {
    // Three populations run the file: installed before the columns, after them, and rolled back
    // then migrated again. Guarded on the schema rather than on assumed presence.
    $migration = require __DIR__.'/../database/migrations/2026_09_24_000004_add_placement_to_robot_council_tasks.php';

    if (! \is_object($migration) || ! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
        throw new RuntimeException('The migration file did not return a migration.');
    }

    expect(Schema::hasColumns('robot_council_tasks', ['issue', 'branch', 'placed_by', 'hand_back']))->toBeTrue();

    // Again, on a schema that already has them: a no-op rather than a duplicate-column error
    $migration->up();

    $migration->down();

    expect(Schema::hasColumn('robot_council_tasks', 'issue'))->toBeFalse()
        ->and(Schema::hasColumn('robot_council_tasks', 'hand_back'))->toBeFalse();

    // Down again, on a schema without them
    $migration->down();

    $migration->up();

    expect(Schema::hasColumns('robot_council_tasks', ['issue', 'branch', 'placed_by', 'hand_back']))->toBeTrue();
});
