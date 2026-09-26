<?php

declare(strict_types=1);

/**
 * GitHub's webhook deliveries: verified, idempotent, and what they do to the lanes (#318).
 *
 * Deliveries are posted as raw bytes with the headers GitHub sends, signed the way GitHub signs
 * them, because the signature is over the exact body and a test that let the framework re-encode
 * it would verify something GitHub never sends. Every refusal is asserted on the rows as well as
 * the status: a verifier that answered 401 after writing would pass a status-only test.
 *
 * @command  vendor/bin/pest --compact tests/GitHubWebhookTest.php
 */
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\GitHubState;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The secret the tests' webhook is configured with.
 */
const WEBHOOK_SECRET = 'a-webhook-secret-long-enough-to-count';

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242]);

    $this->app?->make('config')->set('robot-council.github.webhook_secret', WEBHOOK_SECRET);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);

    [$this->session, $this->token] = $this->startAgentSession($this->installation);
});

/**
 * Post one delivery as GitHub does.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $event  The `X-GitHub-Event` header.
 * @param  array<string, mixed>  $payload  The body, encoded here.
 * @param  string|null  $delivery  The delivery id, or a fresh one.
 * @param  string|false|null  $signature  The signature to send, false for none, or null to sign
 *                                        with the configured secret.
 * @return TestResponse<Response> The response.
 */
function deliver(TestCase $case, string $event, array $payload, ?string $delivery = null, string|false|null $signature = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_GITHUB_DELIVERY' => $delivery ?? bin2hex(random_bytes(8)).'-delivery',
    ];

    if ($signature !== false) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = $signature ?? 'sha256='.hash_hmac('sha256', $body, WEBHOOK_SECRET);
    }

    return $case->call('POST', route('robot-council.github.webhook'), [], [], [], $server, $body);
}

/**
 * An `issues` delivery.
 *
 * @param  string  $action  What happened.
 * @param  int  $number  The issue.
 * @param  string  $state  `open` or `closed`.
 * @param  string  $updatedAt  When GitHub says it changed.
 * @param  string  $repository  Its repository.
 * @return array<string, mixed> The payload.
 */
function issueDelivery(string $action, int $number, string $state, string $updatedAt = '2026-09-24T12:00:00Z', string $repository = 'robot-council/core'): array
{
    return [
        'action' => $action,
        'repository' => ['full_name' => $repository],
        'issue' => [
            'number' => $number,
            'state' => $state,
            'title' => 'Issue '.$number,
            'labels' => [['name' => 'afk'], ['name' => 'fleet-facing']],
            'body' => "## Acceptance criteria\n\n- [x] one\n- [ ] two\n  * [X] nested\n\n```markdown\n- [ ] quoted, not a checkbox\n```\n",
            'updated_at' => $updatedAt,
            'repository_url' => 'https://api.github.com/repos/'.$repository,
        ],
    ];
}

/**
 * A `pull_request` delivery.
 *
 * @param  string  $action  What happened.
 * @param  int  $number  The pull request.
 * @param  string  $branch  Its head branch.
 * @param  bool  $merged  Whether it merged.
 * @param  string  $from  The repository its branch lives in.
 * @param  string  $repository  The repository it was opened against.
 * @return array<string, mixed> The payload.
 */
function pullDelivery(string $action, int $number, string $branch, bool $merged = false, string $from = 'robot-council/core', string $repository = 'robot-council/core'): array
{
    return [
        'action' => $action,
        'repository' => ['full_name' => $repository],
        'pull_request' => [
            'number' => $number,
            'state' => $action === 'closed' ? 'closed' : 'open',
            'merged' => $merged,
            'draft' => false,
            'title' => 'Pull request '.$number,
            'labels' => [],
            'body' => null,
            'updated_at' => '2026-09-24T12:00:00Z',
            'head' => ['ref' => $branch, 'repo' => ['full_name' => $from]],
            'base' => ['repo' => ['full_name' => $repository]],
        ],
    ];
}

/**
 * The issue object inside an `issues` delivery.
 *
 * @param  array<string, mixed>  $delivery  The delivery.
 * @return array<array-key, mixed> The issue.
 */
function issueOf(array $delivery): array
{
    return arrayValue($delivery['issue'] ?? null);
}

/**
 * The pull-request object inside a `pull_request` delivery.
 *
 * @param  array<string, mixed>  $delivery  The delivery.
 * @return array<array-key, mixed> The pull request.
 */
function pullOf(array $delivery): array
{
    return arrayValue($delivery['pull_request'] ?? null);
}

/**
 * A task this test's session holds and has started, naming an issue and a branch.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $issue  The issue it names.
 * @param  string  $branch  The branch its lane works on.
 * @return int The task.
 */
function laneWorkingOn(TestCase $case, string $issue = 'robot-council/core#12', string $branch = 'feature/lane-board'): int
{
    $tasks = $case->service(Tasks::class);
    $task = $tasks->create($case->session, ['title' => 'Work', 'issue' => $issue], withCoordinator: false);

    $tasks->transition($task->id, TaskTransition::Claim, $case->session, asCoordinator: false);
    $tasks->transition($task->id, TaskTransition::Start, $case->session, asCoordinator: false, branch: $branch);

    return $task->id;
}

/**
 * The task's status, read fresh.
 *
 * @param  int  $taskId  The task.
 * @return TaskStatus Its status.
 */
function taskStatus(int $taskId): TaskStatus
{
    return Task::query()->findOrFail($taskId)->status;
}

/**
 * How much the webhook has written: deliveries, items, and edges.
 *
 * @return array{int, int, int} The three counts.
 */
function webhookRows(): array
{
    return [
        DB::table('robot_council_github_deliveries')->count(),
        GitHubItem::query()->count(),
        DB::table('robot_council_github_blockers')->count(),
    ];
}

// --- Verification -------------------------------------------------------------------------------

it('refuses an unsigned delivery, a wrong secret, and a signature one byte off, and changes nothing', function (string $case): void {
    $taskId = laneWorkingOn($this);
    $payload = issueDelivery('closed', 12, 'closed');
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $right = 'sha256='.hash_hmac('sha256', $body, WEBHOOK_SECRET);

    $signature = match ($case) {
        'unsigned' => false,
        'wrong secret' => 'sha256='.hash_hmac('sha256', $body, 'some-other-secret-entirely'),
        // The last hex digit changed, so every byte but one matches
        default => substr($right, 0, -1).($right[-1] === '0' ? '1' : '0'),
    };

    expect($signature)->not->toBe($right);

    deliver($this, 'issues', $payload, signature: $signature)->assertUnauthorized();

    expect(webhookRows())->toBe([0, 0, 0])
        ->and(taskStatus($taskId))->toBe(TaskStatus::InProgress);
})->with(['unsigned', 'wrong secret', 'last byte']);

it('does not exist until a usable secret is configured', function (?string $secret): void {
    $this->app?->make('config')->set('robot-council.github.webhook_secret', $secret);

    // Signed with that same secret, so the refusal is about the configuration and not the signature
    $body = json_encode(issueDelivery('opened', 1, 'open'), JSON_THROW_ON_ERROR);

    $this->call('POST', route('robot-council.github.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'issues',
        'HTTP_X_GITHUB_DELIVERY' => 'd-1',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
    ], $body)->assertNotFound();

    expect(webhookRows())->toBe([0, 0, 0]);
})->with(['unset' => [null], 'empty' => [''], 'fifteen characters' => [str_repeat('s', 15)]]);

it('exists with a secret of exactly sixteen characters', function (): void {
    $secret = str_repeat('s', 16);
    $this->app?->make('config')->set('robot-council.github.webhook_secret', $secret);
    $body = json_encode(issueDelivery('opened', 1, 'open'), JSON_THROW_ON_ERROR);

    $this->call('POST', route('robot-council.github.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'issues',
        'HTTP_X_GITHUB_DELIVERY' => 'd-16',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body)->assertOk();
});

it('refuses a delivery id or event name outside what GitHub sends, and keeps nothing', function (string $delivery, string $event): void {
    deliver($this, $event, issueDelivery('opened', 1, 'open'), delivery: $delivery)->assertUnprocessable();

    expect(webhookRows())->toBe([0, 0, 0]);
})->with([
    'a delivery id with a space' => ['not an id', 'issues'],
    'a delivery id past 64 characters' => [str_repeat('a', 65), 'issues'],
    'an event name in capitals' => ['d-1', 'Issues'],
]);

it('limits deliveries before verifying them, so an unsigned flood is limited too', function (): void {
    $this->rebootWith('robot-council.rate_limits.github_webhook_per_minute', 2);
    $this->app?->make('config')->set('robot-council.github.webhook_secret', WEBHOOK_SECRET);

    $statuses = [];

    for ($i = 0; $i < 4; $i++) {
        $statuses[] = deliver($this, 'issues', issueDelivery('opened', 1, 'open'), signature: false)->status();
    }

    expect($statuses)->toBe([401, 401, 429, 429]);
});

it('answers a ping, and accepts an event it does not use without acting on it', function (): void {
    deliver($this, 'ping', ['zen' => 'Keep it logically awesome.'])->assertOk();

    deliver($this, 'star', ['action' => 'created'], delivery: 'd-star')->assertAccepted()->assertJson(['outcome' => 'ignored']);

    expect(webhookRows())->toBe([1, 0, 0]);
});

it('accepts a delivery configured as form-encoded', function (): void {
    $body = http_build_query(['payload' => json_encode(issueDelivery('opened', 7, 'open'), JSON_THROW_ON_ERROR)]);

    $this->call('POST', route('robot-council.github.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_X_GITHUB_EVENT' => 'issues',
        'HTTP_X_GITHUB_DELIVERY' => 'd-form',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, WEBHOOK_SECRET),
    ], $body)->assertOk();

    expect(GitHubItem::query()->where('number', 7)->exists())->toBeTrue();
});

it('refuses a delivery carrying what GitHub would not send, and keeps none of it', function (): void {
    $payload = issueDelivery('opened', 3, 'open');
    $payload['issue'] = [...issueOf($payload), 'title' => str_repeat('t', 257)];

    deliver($this, 'issues', $payload)->assertUnprocessable();

    // The delivery id is not kept either, so GitHub's redelivery of a fixed payload is not a duplicate
    expect(webhookRows())->toBe([0, 0, 0]);
});

// --- Idempotency and order ----------------------------------------------------------------------

it('applies a replayed delivery id once', function (): void {
    $taskId = laneWorkingOn($this);
    $payload = issueDelivery('closed', 12, 'closed');

    deliver($this, 'issues', $payload, delivery: 'same-id')->assertOk()->assertJson(['outcome' => 'applied']);

    // Reopen the task by hand, so a second application would be visible as a second completion
    Task::query()->whereKey($taskId)->update(['status' => TaskStatus::InProgress->value]);

    deliver($this, 'issues', $payload, delivery: 'same-id')->assertOk()->assertJson(['outcome' => 'duplicate']);

    expect(taskStatus($taskId))->toBe(TaskStatus::InProgress)
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskCompleted->value)->count())->toBe(1);
});

it('frees the lane from a close that arrives after a later edit, since the issue ends closed', function (): void {
    $taskId = laneWorkingOn($this);

    // Labeled at 12:05 while closed, delivered first; the 12:00 close then loses the newer-wins write
    $labeled = issueDelivery('labeled', 12, 'closed', '2026-09-24T12:05:00Z');

    deliver($this, 'issues', $labeled)->assertOk();

    expect(taskStatus($taskId))->toBe(TaskStatus::InProgress);

    deliver($this, 'issues', issueDelivery('closed', 12, 'closed', '2026-09-24T12:00:00Z'))->assertOk();

    expect(taskStatus($taskId))->toBe(TaskStatus::Done);
});

it('completes the task from a merge that arrives after a later edit of the pull request', function (): void {
    $taskId = laneWorkingOn($this);

    $edited = pullDelivery('edited', 40, 'feature/lane-board', merged: true);
    $edited['pull_request'] = [...pullOf($edited), 'state' => 'closed', 'updated_at' => '2026-09-24T12:05:00Z'];

    deliver($this, 'pull_request', $edited)->assertOk();
    deliver($this, 'pull_request', pullDelivery('closed', 40, 'feature/lane-board', merged: true))->assertOk();

    expect(taskStatus($taskId))->toBe(TaskStatus::Done);
});

it('frees nobody from a close that arrives after a later reopen', function (): void {
    $taskId = laneWorkingOn($this);

    deliver($this, 'issues', issueDelivery('reopened', 12, 'open', '2026-09-24T12:05:00Z'))->assertOk();
    deliver($this, 'issues', issueDelivery('closed', 12, 'closed', '2026-09-24T12:00:00Z'))->assertOk();

    expect(GitHubItem::query()->where('number', 12)->value('state'))->toBe('open')
        ->and(taskStatus($taskId))->toBe(TaskStatus::InProgress);
});

// --- What it stores -----------------------------------------------------------------------------

it('stores an issue with its labels and a count of its checkboxes, not its body', function (): void {
    deliver($this, 'issues', issueDelivery('opened', 5, 'open'))->assertOk();

    $item = GitHubItem::query()->where('repository', 'robot-council/core')->where('number', 5)->firstOrFail();

    expect($item->reference())->toBe('robot-council/core#5')
        ->and($item->isOpen())->toBeTrue()
        ->and($item->is_pull_request)->toBeFalse()
        ->and($item->labels)->toBe(['afk', 'fleet-facing'])
        ->and($item->checkboxes)->toBe(3)
        ->and($item->checkboxes_ticked)->toBe(2)
        ->and($item->head_ref)->toBeNull();
});

it("stores a pull request's branch only when it comes from the same repository", function (): void {
    deliver($this, 'pull_request', pullDelivery('opened', 40, 'feature/a'))->assertOk();
    deliver($this, 'pull_request', pullDelivery('opened', 41, 'feature/a', from: 'someone/fork'))->assertOk();
    deliver($this, 'pull_request', pullDelivery('opened', 42, 'feature;rm'))->assertOk();

    expect(GitHubItem::query()->orderBy('number')->pluck('head_ref')->all())->toBe(['feature/a', null, null]);
});

it('records and removes a blocked_by edge across repositories', function (): void {
    $edge = [
        'blocked_issue' => ['number' => 318, 'repository_url' => 'https://api.github.com/repos/robot-council/core'],
        'blocking_issue' => ['number' => 9, 'repository_url' => 'https://api.github.com/repos/robot-council/cli'],
        'repository' => ['full_name' => 'robot-council/core'],
    ];

    deliver($this, 'issue_dependencies', ['action' => 'blocked_by_added', ...$edge])->assertOk();
    deliver($this, 'issue_dependencies', ['action' => 'blocked_by_added', ...$edge])->assertOk();

    expect(DB::table('robot_council_github_blockers')->get()->map(fn ($row): array => (array) $row)->all())->toBe([[
        'repository' => 'robot-council/core', 'number' => 318, 'blocker_repository' => 'robot-council/cli', 'blocker_number' => 9,
    ]]);

    deliver($this, 'issue_dependencies', ['action' => 'blocked_by_removed', ...$edge])->assertOk();

    expect(DB::table('robot_council_github_blockers')->count())->toBe(0);
});

it('logs a blocked_by removal that finds no stored edge, and nothing for one that does', function (): void {
    $edge = [
        'blocked_issue' => ['number' => 318, 'repository_url' => 'https://api.github.com/repos/robot-council/core'],
        'blocking_issue' => ['number' => 9, 'repository_url' => 'https://api.github.com/repos/robot-council/cli'],
        'repository' => ['full_name' => 'robot-council/core'],
    ];

    $log = Log::spy();

    // Stored, then removed: the ordinary case, which is not worth a line
    deliver($this, 'issue_dependencies', ['action' => 'blocked_by_added', ...$edge])->assertOk();
    deliver($this, 'issue_dependencies', ['action' => 'blocked_by_removed', ...$edge])->assertOk();

    $log->shouldNotHaveReceived('notice');

    // A removal with nothing to remove: the trace of one delivered ahead of its add
    deliver($this, 'issue_dependencies', ['action' => 'blocked_by_removed', ...$edge])->assertOk();

    $log->shouldHaveReceived('notice')->once()->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'found no stored edge')
        && $context === ['repository' => 'robot-council/core', 'number' => 318, 'blocker_repository' => 'robot-council/cli', 'blocker_number' => 9]);
});

// --- What it does to the lanes ------------------------------------------------------------------

it('completes the task holding an issue when the issue closes, naming the task and not the issue', function (): void {
    $taskId = laneWorkingOn($this);

    deliver($this, 'issues', issueDelivery('closed', 12, 'closed'))->assertOk();

    $event = FleetEvent::query()->where('type', FleetEventType::TaskCompleted->value)->sole();

    expect(taskStatus($taskId))->toBe(TaskStatus::Done)
        ->and($event->agent_session_id)->toBeNull()
        ->and($event->body)->toBe(sprintf('Task #%d completed: its issue closed on GitHub.', $taskId))
        ->and($event->body)->not->toContain('#12')
        ->and($event->meta)->toMatchArray(['task_id' => $taskId, 'to' => 'done', 'source' => 'github']);
});

it('completes the task when its pull request merges, matched on the branch the lane reported', function (): void {
    $taskId = laneWorkingOn($this);

    deliver($this, 'pull_request', pullDelivery('closed', 40, 'feature/lane-board', merged: true))->assertOk();

    expect(taskStatus($taskId))->toBe(TaskStatus::Done);
});

/**
 * The SHA a merged pull request in these tests landed as.
 */
const MERGE_SHA = '0123456789abcdef0123456789abcdef01234567';

/**
 * Complete a task over HTTP, as its lane's `task_complete` does.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $token  The session's token.
 * @param  int  $taskId  The task.
 * @param  array<string, mixed>  $result  What the lane reports.
 * @return TestResponse<Response> The response.
 */
function completeOverHttp(TestCase $case, string $token, int $taskId, array $result): TestResponse
{
    return $case->machine($token)->postJson(
        route('robot-council.tasks.transition', ['task' => $taskId, 'transition' => 'complete']),
        ['result' => $result]
    );
}

/**
 * A task this test's session was working on, finished by its pull request merging.
 *
 * @param  TestCase  $case  The test case.
 * @return int The task.
 */
function mergedUnderTheLane(TestCase $case): int
{
    $taskId = laneWorkingOn($case);

    $delivery = pullDelivery('closed', 40, 'feature/lane-board', merged: true);
    $delivery['pull_request'] = [...pullOf($delivery), 'merge_commit_sha' => MERGE_SHA];

    deliver($case, 'pull_request', $delivery)->assertOk();

    return $taskId;
}

it('records the merged pull request and its merge commit on the task it completes (#433)', function (): void {
    $taskId = mergedUnderTheLane($this);

    $task = Task::query()->findOrFail($taskId);

    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->result)->toBe(['github' => [
            'reason' => 'its pull request merged on GitHub',
            'pull_request' => 'robot-council/core#40',
            'merged' => true,
            'merge_commit_sha' => MERGE_SHA,
        ]])
        ->and($task->github_finished_at)->not->toBeNull();
});

it('records no merge commit that is not a hex object name', function (mixed $sha): void {
    $taskId = laneWorkingOn($this);

    $delivery = pullDelivery('closed', 40, 'feature/lane-board', merged: true);
    $delivery['pull_request'] = [...pullOf($delivery), 'merge_commit_sha' => $sha];

    deliver($this, 'pull_request', $delivery)->assertOk();

    $github = arrayValue(arrayValue(Task::query()->findOrFail($taskId)->result)['github'] ?? null);

    expect($github)->toHaveKey('merge_commit_sha')
        ->and($github['merge_commit_sha'])->toBeNull();
})->with([
    'absent' => [null],
    'one short' => [substr(MERGE_SHA, 1)],
    'upper case' => [strtoupper(MERGE_SHA)],
    'a trailing newline' => [MERGE_SHA."\n"],
    'markup' => ['<b>'.MERGE_SHA.'</b>'],
]);

it('records the closed issue and why GitHub says it closed on the task it completes (#433)', function (mixed $given, ?string $recorded): void {
    $taskId = laneWorkingOn($this);

    $delivery = issueDelivery('closed', 12, 'closed');
    $delivery['issue'] = [...issueOf($delivery), 'state_reason' => $given];

    deliver($this, 'issues', $delivery)->assertOk();

    expect(Task::query()->findOrFail($taskId)->result)->toBe(['github' => [
        'reason' => 'its issue closed on GitHub',
        'issue' => 'robot-council/core#12',
        'state_reason' => $recorded,
    ]]);
})->with([
    'completed' => ['completed', 'completed'],
    'not planned' => ['not_planned', 'not_planned'],
    'absent' => [null, null],
    'something GitHub does not send' => ['<script>', null],
]);

it('records nothing on a task released by a pull request closed without merging', function (): void {
    $taskId = laneWorkingOn($this);

    deliver($this, 'pull_request', pullDelivery('closed', 40, 'feature/lane-board'))->assertOk();

    $task = Task::query()->findOrFail($taskId);

    expect($task->result)->toBeNull()
        ->and($task->github_finished_at)->toBeNull();
});

it('lets the lane GitHub beat to it add its result once, keeping the status and what GitHub recorded (#433)', function (): void {
    $taskId = mergedUnderTheLane($this);

    completeOverHttp($this, $this->token, $taskId, ['summary' => 'shipped', 'github' => 'the lane may not rewrite this'])
        ->assertOk()
        ->assertExactJson(['task_id' => $taskId, 'status' => 'done', 'applied' => false, 'result_added' => true]);

    $task = Task::query()->findOrFail($taskId);
    $added = FleetEvent::query()->where('type', FleetEventType::TaskResultAdded->value)->sole();

    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->result)->toBe([
            'summary' => 'shipped',
            'github' => [
                'reason' => 'its pull request merged on GitHub',
                'pull_request' => 'robot-council/core#40',
                'merged' => true,
                'merge_commit_sha' => MERGE_SHA,
            ],
        ])
        ->and($task->result_added_at)->not->toBeNull()
        ->and($added->agent_session_id)->toBe($this->session->getKey())
        ->and($added->body)->toBe(sprintf('Task #%d: its holder added a result after GitHub finished it.', $taskId))
        ->and($added->meta)->toBe(['task_id' => $taskId, 'to' => 'done'])
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskCompleted->value)->count())->toBe(1);

    // Once: the second is the ordinary refusal, and changes nothing
    completeOverHttp($this, $this->token, $taskId, ['summary' => 'again'])->assertConflict();

    expect(arrayValue(Task::query()->findOrFail($taskId)->result)['summary'] ?? null)->toBe('shipped')
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskResultAdded->value)->count())->toBe(1);
});

it('refuses a result from a session that did not hold the task GitHub finished', function (): void {
    $taskId = mergedUnderTheLane($this);
    [, $otherToken] = $this->startAgentSession($this->installation);

    completeOverHttp($this, $otherToken, $taskId, ['summary' => 'not mine'])->assertConflict();

    expect(Task::query()->findOrFail($taskId)->result_added_at)->toBeNull()
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskResultAdded->value)->exists())->toBeFalse();
});

it('takes the result up to the end of the window and refuses it after', function (int $minutes, bool $added): void {
    $taskId = mergedUnderTheLane($this);

    // The finish is moved back rather than the clock forward, which would also age out the
    // session's own token and answer 401 before the window was ever asked about
    Task::query()->whereKey($taskId)->update(['github_finished_at' => Carbon::now()->subMinutes($minutes)]);

    $response = completeOverHttp($this, $this->token, $taskId, ['summary' => 'late']);

    $added ? $response->assertOk() : $response->assertConflict();

    expect(Task::query()->findOrFail($taskId)->result_added_at !== null)->toBe($added);
})->with([
    // A minute inside rather than on the boundary, which a second ticking between the backdate
    // and the request would move
    'a minute before the end' => [Tasks::RESULT_WINDOW_MINUTES - 1, true],
    'a minute after' => [Tasks::RESULT_WINDOW_MINUTES + 1, false],
]);

it('refuses a second completion of a task its lane finished itself', function (): void {
    $taskId = laneWorkingOn($this);

    completeOverHttp($this, $this->token, $taskId, ['summary' => 'done'])->assertOk()->assertJson(['applied' => true]);
    completeOverHttp($this, $this->token, $taskId, ['summary' => 'again'])->assertConflict();

    expect(Task::query()->findOrFail($taskId)->result)->toBe(['summary' => 'done']);
});

it('refuses a completion with no result on a task GitHub finished, adding nothing', function (): void {
    $taskId = mergedUnderTheLane($this);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $taskId, 'transition' => 'complete']))
        ->assertConflict();

    expect(Task::query()->findOrFail($taskId)->result_added_at)->toBeNull();
});

it('releases the task when its pull request closes without merging', function (): void {
    $taskId = laneWorkingOn($this);

    deliver($this, 'pull_request', pullDelivery('closed', 40, 'feature/lane-board'))->assertOk();

    $task = Task::query()->findOrFail($taskId);

    $event = FleetEvent::query()->where('type', FleetEventType::TaskReleased->value)->sole();

    expect($event->agent_session_id)->toBeNull()
        ->and($event->body)->toBe(sprintf('Task #%d released: its pull request closed on GitHub without merging.', $taskId))
        ->and($event->meta)->toMatchArray(['task_id' => $taskId, 'to' => 'pending', 'source' => 'github', 'released_from' => $this->session->getKey()])
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->claimed_by)->toBeNull()
        ->and($task->branch)->toBeNull()
        ->and($task->issue)->toBe('robot-council/core#12');
});

it('frees no lane for a pull request from a fork, or from another repository, on the same branch name', function (string $case): void {
    $taskId = laneWorkingOn($this);

    $payload = match ($case) {
        'a fork' => pullDelivery('closed', 40, 'feature/lane-board', merged: true, from: 'someone/core'),
        default => pullDelivery('closed', 40, 'feature/lane-board', merged: true, from: 'robot-council/cli', repository: 'robot-council/cli'),
    };

    deliver($this, 'pull_request', $payload)->assertOk();

    expect(taskStatus($taskId))->toBe(TaskStatus::InProgress);
})->with(['a fork', 'another repository']);

it('frees nobody on a pull request that is opened, pushed to, or edited', function (string $action): void {
    $taskId = laneWorkingOn($this);

    deliver($this, 'pull_request', pullDelivery($action, 40, 'feature/lane-board'))->assertOk();

    $task = Task::query()->findOrFail($taskId);

    expect($task->status)->toBe(TaskStatus::InProgress)
        ->and($task->branch)->toBe('feature/lane-board');
})->with(['opened', 'synchronize', 'edited', 'reopened']);

it('frees nobody when an already-closed pull request is edited, though a lane now uses its branch', function (): void {
    // The old pull request closed unmerged before any lane took the branch name...
    deliver($this, 'pull_request', pullDelivery('closed', 40, 'feature/lane-board'))->assertOk();

    // ...a lane then works on a branch of the same name...
    $taskId = laneWorkingOn($this);

    // ...and somebody labels the old one. Only a `closed` delivery frees a lane.
    $labeled = pullDelivery('labeled', 40, 'feature/lane-board');
    $labeled['pull_request'] = [...pullOf($labeled), 'state' => 'closed', 'updated_at' => '2026-09-24T12:05:00Z'];

    deliver($this, 'pull_request', $labeled)->assertOk();

    expect(taskStatus($taskId))->toBe(TaskStatus::InProgress);
});

it('frees nobody when an issues delivery closes a pull request', function (): void {
    $taskId = laneWorkingOn($this);

    $payload = issueDelivery('closed', 12, 'closed');
    $payload['issue'] = [...issueOf($payload), 'pull_request' => ['merged_at' => null]];

    deliver($this, 'issues', $payload)->assertOk();

    expect(taskStatus($taskId))->toBe(TaskStatus::InProgress);
});

it('frees nobody when the same number closes in another repository', function (): void {
    $taskId = laneWorkingOn($this);

    deliver($this, 'issues', issueDelivery('closed', 12, 'closed', repository: 'robot-council/cli'))->assertOk();

    expect(taskStatus($taskId))->toBe(TaskStatus::InProgress);
});

it('matches an issue whatever case its repository was written in, as GitHub does', function (): void {
    $taskId = laneWorkingOn($this, issue: 'Robot-Council/Core#12');

    deliver($this, 'issues', issueDelivery('closed', 12, 'closed'))->assertOk();

    expect(taskStatus($taskId))->toBe(TaskStatus::Done);
});

it('matches a branch exactly, since git branch names are case-sensitive', function (): void {
    $taskId = laneWorkingOn($this, branch: 'feature/Lane-Board');

    deliver($this, 'pull_request', pullDelivery('closed', 40, 'feature/lane-board', merged: true))->assertOk();

    expect(taskStatus($taskId))->toBe(TaskStatus::InProgress);
});

it('does not finish a task that no longer holds what matched it', function (): void {
    $taskId = laneWorkingOn($this);

    // The window the review found: a coordinator re-places the task, clearing its branch, between
    // the read that matched it and the write
    Task::query()->whereKey($taskId)->update(['branch' => null]);

    expect($this->service(Tasks::class)->finishFromGitHub($taskId, completed: true, why: 'test', still: ['branch' => 'feature/lane-board']))->toBeFalse()
        ->and(taskStatus($taskId))->toBe(TaskStatus::InProgress);
});

it('stores a deleted or transferred issue as closed and frees nobody', function (string $action): void {
    $taskId = laneWorkingOn($this);

    deliver($this, 'issues', issueDelivery($action, 12, 'open'))->assertOk();

    expect(GitHubItem::query()->where('number', 12)->value('state'))->toBe('closed')
        ->and(taskStatus($taskId))->toBe(TaskStatus::InProgress);
})->with(['deleted', 'transferred']);

it('leaves a task nobody holds alone when its issue closes', function (): void {
    $tasks = $this->service(Tasks::class);
    $pending = $tasks->create($this->session, ['title' => 'Unclaimed', 'issue' => 'robot-council/core#12'], withCoordinator: false);

    deliver($this, 'issues', issueDelivery('closed', 12, 'closed'))->assertOk();

    expect(taskStatus($pending->id))->toBe(TaskStatus::Pending)
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskCompleted->value)->count())->toBe(0);
});

// --- Retention and the operator's import --------------------------------------------------------

it('prunes delivery ids older than the redelivery window as new ones arrive', function (): void {
    DB::table('robot_council_github_deliveries')->insert([
        'delivery_id' => 'ancient', 'event' => 'issues', 'received_at' => Carbon::now('UTC')->subDays(GitHubState::DELIVERY_RETENTION_DAYS + 1),
    ]);
    DB::table('robot_council_github_deliveries')->insert([
        'delivery_id' => 'recent', 'event' => 'issues', 'received_at' => Carbon::now('UTC')->subDays(GitHubState::DELIVERY_RETENTION_DAYS - 1),
    ]);

    deliver($this, 'issues', issueDelivery('opened', 1, 'open'), delivery: 'new')->assertOk();

    expect(DB::table('robot_council_github_deliveries')->orderBy('delivery_id')->pluck('delivery_id')->all())->toBe(['new', 'recent']);
});

it("imports an operator's `gh api --slurp` file, moving no lane and keeping what is newer", function (): void {
    $taskId = laneWorkingOn($this);

    // A newer report already stored for #12, which the import must not overwrite
    deliver($this, 'issues', issueDelivery('reopened', 12, 'open', '2026-09-25T00:00:00Z'))->assertOk();

    $closedTwelve = issueOf(issueDelivery('closed', 12, 'closed', '2026-09-24T00:00:00Z'));
    $pull = pullOf(pullDelivery('opened', 40, 'feature/x'));

    $file = $this->temporaryDirectory('github-import').'/open.json';
    file_put_contents($file, json_encode([[issueOf(issueDelivery('opened', 13, 'open')), $closedTwelve], [$pull]], JSON_THROW_ON_ERROR));

    expect(Artisan::call('robot-council:github-import', ['file' => $file]))->toBe(0)
        ->and(Artisan::output())->toContain('Stored 2 item(s); 0 refused; 1 already newer.')
        ->and(GitHubItem::query()->where('number', 12)->value('state'))->toBe('open')
        ->and(GitHubItem::query()->where('number', 40)->value('head_ref'))->toBe('feature/x')
        ->and(taskStatus($taskId))->toBe(TaskStatus::InProgress);
});

it("keeps a pull request's branch when the issues file is imported after the pulls file", function (): void {
    $directory = $this->temporaryDirectory('github-import');
    $pull = pullOf(pullDelivery('opened', 40, 'feature/x'));

    // The same pull request in the issues endpoint's shape: a `pull_request` key and no `head`
    $asIssue = [
        'number' => 40, 'state' => 'open', 'title' => 'Pull request 40', 'labels' => [], 'body' => null,
        'updated_at' => '2026-09-24T12:00:00Z', 'repository_url' => 'https://api.github.com/repos/robot-council/core',
        'pull_request' => ['merged_at' => null],
    ];

    file_put_contents($directory.'/pulls.json', json_encode([$pull], JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/issues.json', json_encode([$asIssue], JSON_THROW_ON_ERROR));

    expect(Artisan::call('robot-council:github-import', ['file' => $directory.'/pulls.json']))->toBe(0)
        ->and(Artisan::call('robot-council:github-import', ['file' => $directory.'/issues.json']))->toBe(0)
        ->and(GitHubItem::query()->where('number', 40)->value('head_ref'))->toBe('feature/x');
});

it('fails an import it cannot read, and one with a refused item', function (): void {
    expect(Artisan::call('robot-council:github-import', ['file' => '/definitely/not/here.json']))->toBe(1);

    $bad = issueOf(issueDelivery('opened', 1, 'open'));
    unset($bad['repository_url']);

    $file = $this->temporaryDirectory('github-import').'/bad.json';
    file_put_contents($file, json_encode([$bad], JSON_THROW_ON_ERROR));

    expect(Artisan::call('robot-council:github-import', ['file' => $file]))->toBe(1)
        ->and(GitHubItem::query()->count())->toBe(0);
});
