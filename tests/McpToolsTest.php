<?php

declare(strict_types=1);

/**
 * The coordination service as MCP tools: who may call them, what they return, and what a refusal
 * looks like from the other side of a tool call.
 *
 * Driven over HTTP rather than through the package's test helper, because half of what #31 asks
 * for is about authentication -- and the helper drives the server directly, past the guard and the
 * middleware that decide what a caller is.
 *
 * @command  vendor/bin/pest --compact tests/McpToolsTest.php
 */

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use RobotCouncil\Access\Ability;
use RobotCouncil\Http\Rules\BoundedMeta;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Lock;
use RobotCouncil\Models\Placement;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Tests\TestCase;

/**
 * Where the server is mounted.
 */
const MCP_URL = '/robot-council/api/mcp';

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);

    $this->installation = $this->approveInstallation($this->developer);

    [$this->session, $this->token] = $this->startAgentSession($this->installation);
});

/**
 * Call one tool, and hand back the decoded result.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $token  The bearer token to call with.
 * @param  string  $tool  The tool's name.
 * @param  array<array-key, mixed>  $arguments  The arguments, as the call will carry them.
 * @return array<array-key, mixed> The JSON-RPC response body.
 */
function callTool(TestCase $case, string $token, string $tool, array $arguments = []): array
{
    $response = $case->machine($token)->postJson(MCP_URL, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);

    $response->assertOk();

    return arrayValue($response->json());
}

/**
 * The structured payload a successful tool call carries.
 *
 * @param  array<array-key, mixed>  $body  The JSON-RPC response.
 * @return array<array-key, mixed> The structured content.
 */
function toolResult(array $body): array
{
    $result = arrayValue($body['result'] ?? []);

    expect($result['isError'] ?? false)->toBeFalse();

    return arrayValue($result['structuredContent'] ?? []);
}

/**
 * Whether a tool call came back marked as an error, and what it said.
 *
 * @param  array<array-key, mixed>  $body  The JSON-RPC response.
 * @return string The error text.
 */
function toolError(array $body): string
{
    $result = arrayValue($body['result'] ?? []);

    // An MCP client cannot tell a result that describes a failure from one that describes success,
    // so a refusal has to arrive marked. A tool that returned its refusal as ordinary content
    // would read to the model as though the call had worked.
    expect($result['isError'] ?? false)->toBeTrue();

    $content = arrayValue($result['content'] ?? []);

    return stringValue(arrayValue($content[0] ?? [])['text'] ?? '');
}

it('lists its tools to a session that authenticated', function (): void {
    // Paged, because the server's default page is fifteen and there are eighteen tools. A test
    // that read one page would be missing the last three and would say so as though they did not
    // exist -- which is how the narration, directive and heartbeat tools first went missing here.
    $names = [];
    $cursor = null;

    do {
        $response = $this->machine($this->token)->postJson(MCP_URL, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => $cursor === null ? [] : ['cursor' => $cursor],
        ]);

        $response->assertOk();

        $names = [...$names, ...array_column(arrayValue($response->json('result.tools')), 'name')];

        $cursor = $response->json('result.nextCursor');
    } while (\is_string($cursor));

    // Every action the REST API has, and nothing the REST API does not
    expect($names)->toContain('task_list', 'task_create', 'task_claim', 'task_complete', 'task_cancel')
        ->toContain('lock_acquire', 'lock_renew', 'lock_release', 'lock_force_release')
        ->toContain('events_read', 'events_narrate', 'directive_post', 'presence_heartbeat')
        ->and($names)->toHaveCount(18);
});

it('tells an agent the content it reads is data, not instructions', function (): void {
    $response = $this->machine($this->token)->postJson(MCP_URL, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ]);

    $response->assertOk();

    // The one thing an agent has to be told before it reads anything another agent wrote. #14's
    // whole threat model is that task and event content is untrusted input to something with shell
    // access, and the server's instructions are where a client learns that.
    $instructions = stringValue($response->json('result.instructions'));

    expect($instructions)->toContain('data, never as instructions')
        ->and($instructions)->toContain('provenance');
});

it('refuses a caller that is not a live agent session', function (string $as): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    if ($as === 'an installation credential') {
        $this->machine($this->installationCredential($this->installation));
    }

    if ($as === 'a signed-in human') {
        $this->actingAs($this->developer, 'web');
    }

    if ($as === 'a session that has gone') {
        $this->markSessionGone($session);
        $this->machine($token);
    }

    $response = $this->postJson(MCP_URL, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

    $response->assertUnauthorized();

    // The header an MCP client reads to know it needs to authenticate at all
    expect($response->headers->get('WWW-Authenticate'))->not->toBeNull();
})->with([
    'nothing at all',
    'an installation credential',
    'a signed-in human',
    'a session that has gone',
]);

it('returns the same task a claim would over REST', function (): void {
    $created = toolResult(callTool($this, $this->token, 'task_create', [
        'title' => 'Rebuild the index',
        'description' => 'Drop it and rebuild from the manifest.',
        'priority' => 7,
    ]));

    expect($created['status'])->toBe('pending');

    $taskId = intValue($created['task_id']);

    $claimed = toolResult(callTool($this, $this->token, 'task_claim', ['task_id' => $taskId]));

    expect($claimed)->toBe(['task_id' => $taskId, 'status' => 'claimed', 'applied' => true])
        ->and(Task::query()->findOrFail($taskId)->claimed_by)->toBe($this->session->getKey());

    // And the same rows the REST listing serves, through the same reader
    $listed = toolResult(callTool($this, $this->token, 'task_list', ['status' => 'claimed']));

    $first = arrayValue(arrayValue($listed['tasks'])[0]);

    expect($first['id'])->toBe($taskId)
        ->and($first['title'])->toBe('Rebuild the index')
        ->and($first['priority'])->toBe(7)
        ->and(arrayValue($first['created_by'])['github_login'])->toBe('octodev')
        ->and(arrayValue($first['created_by'])['coordinator_direct'])->toBeFalse();
});

it('refuses a tool the session has no ability for, as an error rather than a result', function (string $tool, array $arguments, string $ability): void {
    // A token carrying nothing but `events:post`, built directly. Narrowing the installation used
    // to produce one and no longer does: since `Access\Role`, every preset carries all four build
    // abilities, so that setup would hand this test a token nothing refuses and every row below
    // would pass for the wrong reason.
    $narrow = $this->approveInstallation($this->developer, machineLabel: 'narrow');

    [, $narrowToken] = $this->startAgentSessionWithAbilities($narrow, [Ability::EventsPost->value]);

    $error = toolError(callTool($this, $narrowToken, $tool, $arguments));

    expect($error)->toContain($ability)
        ->and(Task::query()->count() + Lock::query()->count())->toBe(0);
})->with([
    // Every tool that names an ability, because one class serves eight transitions and another four
    // lock actions: a check that read the wrong enum member would still refuse the case the first
    // row covers, and admit the rest
    'creating a task' => ['task_create', ['title' => 'Not mine to file'], 'tasks:create'],
    'claiming one' => ['task_claim', ['task_id' => 1], 'tasks:claim'],
    'starting one' => ['task_start', ['task_id' => 1], 'tasks:claim'],
    'blocking one' => ['task_block', ['task_id' => 1], 'tasks:claim'],
    'completing one' => ['task_complete', ['task_id' => 1], 'tasks:claim'],
    'failing one' => ['task_fail', ['task_id' => 1], 'tasks:claim'],
    'releasing a task' => ['task_release', ['task_id' => 1], 'tasks:claim'],
    'cancelling a task' => ['task_cancel', ['task_id' => 1], 'coordinator:direct'],
    'reassigning one' => ['task_reassign', ['task_id' => 1, 'session_id' => 1], 'coordinator:direct'],
    'taking a lock' => ['lock_acquire', ['name' => 'deploy', 'ttl' => 60], 'locks:acquire'],
    'renewing one' => ['lock_renew', ['name' => 'deploy', 'ttl' => 60], 'locks:acquire'],
    'releasing a lock' => ['lock_release', ['name' => 'deploy'], 'locks:acquire'],
    "breaking somebody else's" => ['lock_force_release', ['name' => 'deploy'], 'coordinator:direct'],
    'directing the fleet' => ['directive_post', ['body' => 'everyone stop'], 'coordinator:direct'],
]);

it('surfaces a state conflict as a tool error carrying the reason', function (): void {
    $taskId = intValue(toolResult(callTool($this, $this->token, 'task_create', ['title' => 'One']))['task_id']);

    toolResult(callTool($this, $this->token, 'task_claim', ['task_id' => $taskId]));

    // Claiming twice is the conflict the conditional update decides, and the tool has to say which
    // kind of failure it was: an agent retries a conflict and gives up on a refusal
    $error = toolError(callTool($this, $this->token, 'task_claim', ['task_id' => $taskId]));

    expect($error)->toContain('not in a status')
        ->and($error)->toContain('somebody else may have moved it');

    $missing = toolError(callTool($this, $this->token, 'task_claim', ['task_id' => 987654]));

    expect($missing)->toContain('No task with that id');
});

it('surfaces a claim-eligibility refusal as a tool error', function (): void {
    $other = $this->enrollDeveloper(77, login: 'otherdev');

    $theirs = $this->approveInstallation($other, machineLabel: 'theirs');

    [, $theirToken] = $this->startAgentSession($theirs);

    $taskId = intValue(toolResult(callTool($this, $theirToken, 'task_create', ['title' => 'Theirs']))['task_id']);

    // #16: another developer's task, created by a session without the coordinator's ability
    $error = toolError(callTool($this, $this->token, 'task_claim', ['task_id' => $taskId]));

    expect($error)->toContain('may not do that')
        ->and(Task::query()->findOrFail($taskId)->status)->toBe(TaskStatus::Pending);
});

it('takes a lock and returns its fence', function (): void {
    $taken = toolResult(callTool($this, $this->token, 'lock_acquire', [
        'name' => 'branch:feature/foo',
        'ttl' => 60,
    ]));

    expect($taken['name'])->toBe('branch:feature/foo')
        ->and($taken['held'])->toBeTrue()
        ->and($taken['fence'])->toBe(1);

    // Re-taking a lock this session already holds is a conflict, and the tool says which
    $error = toolError(callTool($this, $this->token, 'lock_acquire', ['name' => 'branch:feature/foo', 'ttl' => 60]));

    expect($error)->toContain('lease is still running');

    toolResult(callTool($this, $this->token, 'lock_release', ['name' => 'branch:feature/foo']));

    expect(Lock::query()->where('name', 'branch:feature/foo')->sole()->holder_id)->toBeNull();
});

it('applies the narration rule when it reads the feed', function (): void {
    $other = $this->enrollDeveloper(77, login: 'otherdev');

    $theirs = $this->approveInstallation($other, machineLabel: 'theirs');

    [, $theirToken] = $this->startAgentSession($theirs);

    toolResult(callTool($this, $theirToken, 'events_narrate', ['body' => 'a private thought']));

    toolResult(callTool($this, $this->token, 'events_narrate', ['body' => 'my own thought']));

    $page = toolResult(callTool($this, $this->token, 'events_read', ['after' => 0]));

    $bodies = array_column(arrayValue($page['events']), 'body');

    // #29, unchanged: another developer's narration is not this reader's to see, and the tool
    // reads through the same filter the REST feed does
    expect($bodies)->toContain('my own thought')
        ->and($bodies)->not->toContain('a private thought');

    // And every event carries who wrote it
    $mine = collect(arrayValue($page['events']))->firstWhere('body', 'my own thought');

    expect(arrayValue(arrayValue($mine)['actor'])['github_login'])->toBe('octodev');
});

it('reports the session its own presence', function (): void {
    $beat = toolResult(callTool($this, $this->token, 'presence_heartbeat'));

    expect($beat)->toBe([
        'session_id' => $this->session->getKey(),
        'status' => 'active',
        'stale_in' => 300,
        'gone_in' => 1800,
    ]);
});

it('writes a narration event that the feed records', function (): void {
    $posted = toolResult(callTool($this, $this->token, 'events_narrate', [
        'body' => 'rebuilding the index',
        'meta' => ['step' => 2],
    ]));

    $event = FleetEvent::query()->whereKey(intValue($posted['event_id']))->sole();

    expect($event->type)->toBe(FleetEventType::Narration)
        ->and($event->body)->toBe('rebuilding the index')
        ->and(orderedMeta($event->meta))->toBe(orderedMeta(['client' => ['step' => 2]]))
        ->and($event->agent_session_id)->toBe($this->session->getKey())
        ->and($event->posted_with_coordinator)->toBeFalse();
});

/**
 * A session holding `coordinator:direct`, under a second developer.
 *
 * @param  TestCase  $case  The test case.
 * @return array{AgentSession, string} The session and its token.
 */
function mcpCoordinator(TestCase $case): array
{
    if (isset($case->coordinatorSession)) {
        return [$case->coordinatorSession, $case->coordinatorToken];
    }

    $other = $case->enrollDeveloper(77, login: 'coordinator');

    $installation = $case->approveInstallation($other, machineLabel: 'coordinator-box');

    // **A coordinator is made by an administrator, not by a grant.** Since
    // `robot-council/core#222` a session starts as `build` whatever its installation holds, so a
    // fixture that only granted the ability would hand back a session that cannot direct.
    [$case->coordinatorSession, $case->coordinatorToken] = $case->startCoordinatorSession($installation);

    return [$case->coordinatorSession, $case->coordinatorToken];
}

it('refuses through a tool exactly what the REST endpoint refuses', function (string $tool, array $arguments, string $method, string $path, array $body, int $status): void {
    // `laravel/mcp` advertises a tool's schema and never enforces it: `Server\ToolInvoker` calls
    // `handle()` with whatever the client sent. So every bound the REST endpoint carries has to be
    // carried again by the tool, and this asserts the pair rather than either side -- a rule added
    // to one door and not the other is the failure it exists to catch.
    //
    // The two doors refuse at different layers, which is why the expected status is per row rather
    // than a constant 422: REST carries the task id in a path segment bounded by `ROUTE_ID`, so a
    // non-numeric one never reaches validation, while a tool carries it as an argument. What has to
    // match is that neither accepts the input, not where each one stops it.
    $held = $this->createClaimedTask();

    $inflate = static fn (array $values): array => array_map(
        static fn (mixed $value): mixed => $value === 'OVERSIZED' ? str_repeat('A', BoundedMeta::MAX_BYTES) : $value,
        $values
    );

    $body = array_map(
        static fn (mixed $value): mixed => \is_array($value) ? $inflate($value) : $value,
        $body
    );

    $arguments = array_map(
        static fn (mixed $value): mixed => \is_array($value) ? $inflate($value) : $value,
        $arguments
    );

    $this->machine($this->token)
        ->json($method, str_replace('{task}', (string) $held, $path), $body)
        ->assertStatus($status);

    $arguments = array_map(
        static fn (mixed $value): mixed => $value === '{task}' ? $held : $value,
        $arguments
    );

    // Asserted on the log rather than only on the message, because `InteractsWithResponses`
    // returns the exception's own text when `app.debug` is on -- under which a bare comparison
    // against the generic string passes for a tool that threw. `report()` runs either way.
    $reported = [];

    Log::listen(static function (MessageLogged $entry) use (&$reported): void {
        $reported[] = $entry->message;
    });

    $error = toolError(callTool($this, $this->token, $tool, $arguments));

    // The generic text `ToolInvoker` returns for an uncaught exception, after reporting it to the
    // host's log. A refusal names what was wrong; this text means the tool threw
    expect($error)->not->toBe('An internal server error occurred.')
        ->and($reported)->toBeEmpty();
})->with([
    // Unbounded structure. `payload` and `result` are `json` columns nothing prunes, and a full
    // page of the task list returns up to a hundred rows with their content
    'an oversized task payload' => [
        'task_create', ['title' => 'Big', 'payload' => ['blob' => 'OVERSIZED']],
        'POST', '/robot-council/api/tasks', ['title' => 'Big', 'payload' => ['blob' => 'OVERSIZED']], 422,
    ],
    'an oversized task result' => [
        'task_complete', ['task_id' => '{task}', 'result' => ['blob' => 'OVERSIZED']],
        'POST', '/robot-council/api/tasks/{task}/complete', ['result' => ['blob' => 'OVERSIZED']], 422,
    ],

    // Half a cursor names no position, and an absent one is not a request for zero
    'half a task cursor' => [
        'task_list', ['after_id' => 5],
        'GET', '/robot-council/api/tasks?after_id=5', [], 422,
    ],

    // A wrong-typed argument. Unvalidated these reach `Arguments::integer()`, which throws -- and
    // `ToolInvoker` reports the exception to the host's log before answering the agent with a
    // generic internal error, so an authenticated agent can drive stack traces at the rate the
    // limiter allows
    'a task id that is not a number' => [
        'task_claim', ['task_id' => 'abc'],
        'POST', '/robot-council/api/tasks/abc/claim', [], 404,
    ],
]);

it('refuses a reassignment that names no session, rather than reporting an internal error', function (): void {
    [, $coordinatorToken] = mcpCoordinator($this);

    $taskId = intValue(toolResult(callTool($this, $this->token, 'task_create', ['title' => 'Unowned']))['task_id']);

    $error = toolError(callTool($this, $coordinatorToken, 'task_reassign', ['task_id' => $taskId]));

    expect($error)->not->toBe('An internal server error occurred.')
        ->and($error)->toContain('session');
});

it('bounds a payload at the same size the rule does, and stores one just inside it', function (): void {
    // The bound is on the encoded bytes, so the control is the same shape one byte-run shorter:
    // without it a refusal could come from the field being rejected outright and would read the same
    $inside = ['blob' => str_repeat('A', 2048)];

    $result = toolResult(callTool($this, $this->token, 'task_create', ['title' => 'Held', 'payload' => $inside]));

    $stored = Task::query()->findOrFail(intValue($result['task_id']));

    expect($stored->payload)->toBe($inside);
});

it('keeps the two methods the transport answers behind the same guard as the tools', function (string $method): void {
    // `Mcp::web()` registers GET and DELETE to answer a constant 405 for the transport spec, and
    // returns only the POST route. Middleware applied to that return value reaches POST alone,
    // leaving two endpoints a host cannot put its own perimeter in front of.
    $this->machine('not-a-token')->json($method, MCP_URL)->assertStatus(401);

    $this->machine($this->token)->json($method, MCP_URL)->assertStatus(405);
})->with(['GET', 'DELETE']);

it('tells a lock holder how long it has, not only when the lease ends', function (): void {
    // The same field the REST response carries, for the same reason: a bridge whose clock is wrong
    // can schedule a renewal from a duration and cannot from an instant
    $result = toolResult(callTool($this, $this->token, 'lock_acquire', ['name' => 'deploy', 'ttl' => 60]));

    expect($result['expires_in'])->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($result['fence'])->toBe(1);
});

it('refuses narration from a session holding no ability at all', function (): void {
    // Not a row in the dataset above, because the session there holds `events:post` -- which is
    // exactly the ability this tool needs, so that session could never demonstrate its refusal
    // A token holding literally nothing. `[]` on the installation no longer produces one -- since
    // `Access\Role` an installation's abilities decide which ROLES it may run, not what its
    // sessions carry, and the floor role still carries four.
    $silent = $this->approveInstallation($this->developer, machineLabel: 'silent');

    [, $silentToken] = $this->startAgentSessionWithAbilities($silent, []);

    $error = toolError(callTool($this, $silentToken, 'events_narrate', ['body' => 'working on it']));

    expect($error)->toContain('events:post')
        ->and(FleetEvent::query()->where('type', FleetEventType::Narration)->count())->toBe(0);
});

it('never puts a credential in a tool result or in the host log', function (): void {
    [, $coordinatorToken] = mcpCoordinator($this);

    $logged = [];

    Log::listen(static function (MessageLogged $entry) use (&$logged): void {
        $logged[] = $entry->message.' '.json_encode($entry->context);
    });

    $taskId = intValue(toolResult(callTool($this, $this->token, 'task_create', ['title' => 'Work']))['task_id']);

    $calls = [
        ['task_claim', ['task_id' => $taskId]],
        ['task_start', ['task_id' => $taskId]],
        ['task_complete', ['task_id' => $taskId, 'result' => ['ok' => true]]],
        ['task_list', []],
        ['lock_acquire', ['name' => 'deploy', 'ttl' => 60]],
        ['lock_release', ['name' => 'deploy']],
        ['events_narrate', ['body' => 'done']],
        ['events_read', []],
        ['presence_heartbeat', []],

        // The refusal paths too: an error message is assembled from different parts than a result,
        // and is the half a caller sees when something went wrong
        ['task_claim', ['task_id' => $taskId]],
        ['task_reassign', ['task_id' => $taskId, 'session_id' => 999999]],
    ];

    $transcript = '';

    foreach ($calls as [$tool, $arguments]) {
        $transcript .= json_encode(callTool($this, $this->token, $tool, $arguments));
        $transcript .= json_encode(callTool($this, $coordinatorToken, $tool, $arguments));
    }

    $transcript .= implode(' ', $logged);

    // The plaintext credential is `<id>|<secret>`; the half after the pipe is the part that would
    // let a holder act, and it is the half a substring search for the whole string would miss if
    // anything logged only the tail
    $secrets = [
        $this->token,
        substr($this->token, (int) strpos($this->token, '|') + 1),
        $coordinatorToken,
        substr($coordinatorToken, (int) strpos($coordinatorToken, '|') + 1),
    ];

    foreach ($secrets as $secret) {
        expect(\strlen($secret))->toBeGreaterThan(20)
            ->and($transcript)->not->toContain($secret);
    }

    // Positive control. The search above is a substring test over a transcript that has to be
    // non-empty and has to be searchable the same way -- an empty transcript, or a transcript the
    // tokens could never appear in, would pass every assertion above while proving nothing
    expect($transcript)->toContain('"task_id"')
        ->and($transcript.$this->token)->toContain($this->token);
});

it('takes a whole number however JSON spelled it, and refuses one that is not whole', function (): void {
    $taskId = intValue(toolResult(callTool($this, $this->token, 'task_create', ['title' => 'Spelled']))['task_id']);

    // Sent as raw JSON rather than through `postJson`, because `json_encode(12.0)` emits `12` --
    // PHP drops the fractional zero, so a test that passes a PHP float sends an integer and never
    // reaches the seam this covers. A client writing the literal `12.0` does send a float, and
    // `json_decode` hands the tool `float(12)`.
    $call = static fn (string $tool, string $rawId): array => [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => ['task_id' => $rawId]],
    ];

    // Put the unquoted number back where `json_encode` would have normalized it away
    $raw = static fn (array $envelope, string $rawId): string => str_replace(
        '"task_id":"'.$rawId.'"',
        '"task_id":'.$rawId,
        (string) json_encode($envelope)
    );

    // `call()` is the only helper that sends a body verbatim, and it does not apply the default
    // headers the verb methods do, so the credential goes on as a server variable
    $post = fn (string $body): array => arrayValue($this->machine($this->token)->call(
        'POST',
        MCP_URL,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ],
        $body
    )->json());

    // Laravel's `integer` rule accepts a float carrying a whole number, and `Arguments::integer()`
    // used to reject it -- so the argument passed validation and then threw, which `ToolInvoker`
    // reports to the host's log before answering with a generic internal error
    $whole = $taskId.'.0';

    $claimed = toolResult($post($raw($call('task_claim', $whole), $whole)));

    expect($claimed['task_id'])->toBe($taskId);

    // And one that is genuinely not a whole number is a refusal naming the field, not a throw.
    // Laravel humanizes the attribute, so the message names `task id`
    $fractional = $taskId.'.5';

    $error = toolError($post($raw($call('task_start', $fractional), $fractional)));

    expect($error)->toContain('task id')
        ->and($error)->not->toBe('An internal server error occurred.');
});

it('answers a refusal in JSON whichever order the client listed its accepted types', function (string $accept): void {
    // The transport specification asks a client to accept both `application/json` and
    // `text/event-stream` and fixes no order, while `Request::wantsJson()` reads only the first
    // acceptable type. `ReorderJsonAccept` exists to normalize that, so it has to run before
    // anything can refuse the request -- ahead of the guard and the limiter, not behind them.
    $response = $this->machine('not-a-token')
        ->withHeaders(['Accept' => $accept, 'Content-Type' => 'application/json'])
        ->post(MCP_URL, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

    $response->assertStatus(401);

    expect($response->headers->get('Content-Type'))->toContain('json');
})->with([
    'json first' => 'application/json, text/event-stream',
    'the event stream first' => 'text/event-stream, application/json',
]);

it('limits the MCP route per session, and limits an unauthenticated flood by address', function (): void {
    // The MCP group's middleware is declared in `RobotCouncilServiceProvider::registerMcpServer()`
    // rather than in `routes/api.php`, so the flood test covering the REST groups leaves this one
    // unguarded: reverting only this group's order left the whole suite green.
    config()->set('robot-council.rate_limits.agent_per_session', 2);

    for ($refused = 0; $refused < 2; $refused++) {
        $this->machine('not-a-token')->postJson(MCP_URL, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertStatus(401);
    }

    $this->machine('not-a-token')->postJson(MCP_URL, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertStatus(429);

    // And a session that authenticates is limited on its own key, not the address
    $this->machine($this->token)->postJson(MCP_URL, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk();
});

it('records the sessions a directive names, through the tool as well as the endpoint', function (): void {
    // **The tool and the endpoint are two doors to one store, and #135 asks for both.** A tool's
    // schema is advertised and never enforced, so the bound that matters is the one the store
    // holds -- but the argument still has to reach it, and only a call through the tool shows that.
    [, $coordinator] = mcpCoordinator($this);

    $result = toolResult(callTool($this, $coordinator, 'directive_post', [
        'body' => 'rebase your branch',
        'targets' => [$this->session->id, $this->session->id],
    ]));

    $event = FleetEvent::query()->whereKey(intValue($result['event_id']))->sole();

    expect($event->type)->toBe(FleetEventType::Directive)
        ->and(arrayValue($event->meta)['targets'] ?? null)->toBe([$this->session->id]);
});

it('refuses a directive through the tool when it names a session that has gone', function (): void {
    [, $coordinator] = mcpCoordinator($this);

    $this->markSessionGone($this->session);

    $error = toolError(callTool($this, $coordinator, 'directive_post', [
        'body' => 'you there',
        'targets' => [$this->session->id],
    ]));

    expect($error)->toContain('sessions that can still be worked')
        ->and(FleetEvent::query()->where('type', FleetEventType::Directive->value)->count())->toBe(0);
});

/**
 * A coordinator under a second developer, for the placement tools, memoized on `TestCase`'s declared
 * properties so the check stays an `isset()` Rector leaves alone.
 *
 * @param  TestCase  $case  The test case.
 * @return string The coordinator's token.
 */
function mcpCoordinatorToken(TestCase $case): string
{
    if (! isset($case->coordinatorToken)) {
        $installation = $case->approveInstallation($case->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box');

        [$case->coordinatorSession, $case->coordinatorToken] = $case->startCoordinatorSession($installation);
    }

    return $case->coordinatorToken;
}

it('places an unclaimed task through the tool, reading every true form the boolean rule admits', function (mixed $handBack): void {
    $taskId = intValue(toolResult(callTool($this, $this->token, 'task_create', ['title' => 'Place me']))['task_id']);

    [$lane] = $this->startAgentSession($this->installation);

    // **The integer 1 is the case that matters.** It passes the `boolean` rule, and a check written
    // as `=== true` would read it as false and quietly place new work where a hand-back was meant.
    toolResult(callTool($this, mcpCoordinatorToken($this), 'task_reassign', [
        'task_id' => $taskId,
        'session_id' => $lane->getKey(),
        'directive' => 'Take this task.',
        'hand_back' => $handBack,
    ]));

    $row = Task::query()->findOrFail($taskId);

    expect($row->claimed_by)->toBe($lane->getKey())
        ->and($row->placed_by)->toBe(Placement::Coordinator)
        ->and($row->hand_back)->toBeTrue();
})->with(['true' => true, 'the integer 1' => 1, 'the string 1' => '1']);

it('records the branch a lane reports when it starts through the tool', function (): void {
    $taskId = intValue(toolResult(callTool($this, $this->token, 'task_create', ['title' => 'Start me']))['task_id']);

    toolResult(callTool($this, $this->token, 'task_claim', ['task_id' => $taskId]));
    toolResult(callTool($this, $this->token, 'task_start', ['task_id' => $taskId, 'branch' => 'lane-board']));

    expect(Task::query()->findOrFail($taskId)->branch)->toBe('lane-board');
});

it('refuses a placement argument on a transition that does not take it', function (string $tool, array $arguments, string $field): void {
    $taskId = intValue(toolResult(callTool($this, $this->token, 'task_create', ['title' => 'Mine']))['task_id']);

    $error = toolError(callTool($this, $this->token, $tool, ['task_id' => $taskId, ...$arguments]));

    // The control is that the same tool, without the field, would have worked on this task
    expect($error)->toContain($field)
        ->and(Task::query()->findOrFail($taskId)->status)->toBe(TaskStatus::Pending);
})->with([
    'a directive on a claim' => ['task_claim', ['directive' => 'Take it.'], 'directive'],
    'a hand-back on a claim' => ['task_claim', ['hand_back' => true], 'hand back'],
    'a branch on a claim' => ['task_claim', ['branch' => 'lane-board'], 'branch'],
]);

it('says the assignee could not have claimed the task, not that the coordinator may not act', function (): void {
    // Developer 4242's own task, filed with no coordinator's ability
    $taskId = intValue(toolResult(callTool($this, $this->token, 'task_create', ['title' => 'Mine']))['task_id']);

    // A plain lane under the coordinator's developer, 77, who is not eligible for it
    mcpCoordinatorToken($this);
    [$ineligible] = $this->startAgentSession($this->coordinatorSession->installation);

    $error = toolError(callTool($this, mcpCoordinatorToken($this), 'task_reassign', [
        'task_id' => $taskId,
        'session_id' => $ineligible->getKey(),
        'directive' => 'Take this task.',
    ]));

    expect($error)->toContain('could not have claimed this task itself')
        ->and($error)->not->toContain('This session may not');
});
