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
            'body' => "## Acceptance criteria\n\n- [x] one\n- [ ] two\n  * [X] nested\n",
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

it('releases the task when its pull request closes without merging', function (): void {
    $taskId = laneWorkingOn($this);

    deliver($this, 'pull_request', pullDelivery('closed', 40, 'feature/lane-board'))->assertOk();

    $task = Task::query()->findOrFail($taskId);

    expect($task->status)->toBe(TaskStatus::Pending)
        ->and($task->claimed_by)->toBeNull()
        ->and($task->branch)->toBeNull()
        // The issue is what the task is for, and it is still open
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
        ->and(Artisan::output())->toContain('Stored 2 item(s); 0 refused; 1 already newer.');

    expect(GitHubItem::query()->where('number', 12)->value('state'))->toBe('open')
        ->and(GitHubItem::query()->where('number', 40)->value('head_ref'))->toBe('feature/x')
        ->and(taskStatus($taskId))->toBe(TaskStatus::InProgress);
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
