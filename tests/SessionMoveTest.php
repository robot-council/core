<?php

declare(strict_types=1);

/**
 * A joined session changing where it works without leaving (#535): through the tool, through the
 * endpoint, and through the store a host could call directly.
 *
 * @command  vendor/bin/pest --compact tests/SessionMoveTest.php
 */

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use RobotCouncil\Access\Role;
use RobotCouncil\Mcp\Tools\SessionMoveTool;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Models\LaneHold;
use RobotCouncil\Models\Lock;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\LaneBoard;
use RobotCouncil\Support\LaneHolds;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);

    $this->installation = $this->approveInstallation($this->developer);

    [$this->session, $this->token] = $this->startAgentSession($this->installation);

    $this->session->forceFill(['repository' => 'robot-council/core', 'work_location' => 'a'])->save();
});

/**
 * Call `session_move` over the MCP endpoint, as a bridge would.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $token  The session token to call with.
 * @param  array<array-key, mixed>  $arguments  The arguments.
 * @return array<array-key, mixed> The tool call's `result`.
 */
function moveThroughTool(TestCase $case, string $token, array $arguments): array
{
    $response = $case->machine($token)->postJson('/robot-council/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'session_move', 'arguments' => $arguments],
    ]);

    $response->assertOk();

    return arrayValue(arrayValue($response->json())['result'] ?? []);
}

/**
 * The text of a tool call's first content block.
 *
 * @param  array<array-key, mixed>  $result  The tool call's `result`.
 * @return string The text.
 */
function moveText(array $result): string
{
    return stringValue(arrayValue(arrayValue($result['content'] ?? [])[0] ?? [])['text'] ?? '');
}

/**
 * The move events recorded so far.
 *
 * @return list<FleetEvent> Oldest first.
 */
function moveEvents(): array
{
    return array_values(FleetEvent::query()->where('type', FleetEventType::SessionMoved->value)->orderBy('id')->get()->all());
}

it('moves the session to another work location, keeping its id and role, and records the old and new place', function (): void {
    $result = moveThroughTool($this, $this->token, ['work_location' => 'b']);

    expect($result['isError'] ?? false)->toBeFalse()
        ->and(arrayValue($result['structuredContent'] ?? []))->toBe([
            'session_id' => $this->session->id,
            'repository' => 'robot-council/core',
            'work_location' => 'b',
            'applied' => true,
        ]);

    $row = AgentSession::query()->findOrFail($this->session->id);

    expect($row->work_location)->toBe('b')
        ->and($row->repository)->toBe('robot-council/core')
        ->and($row->role)->toBe(Role::Build)
        ->and($row->status)->toBe(AgentSessionStatus::Active);

    $events = moveEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->agent_session_id)->toBe($this->session->id)
        ->and($events[0]->user_id)->toEqual($this->developer->getKey())
        ->and($events[0]->body)->toBe(sprintf('session %d moved from robot-council/core at a to robot-council/core at b.', $this->session->id))
        ->and($events[0]->meta)->toBe([
            'installation_id' => $this->installation->id,
            'from_repository' => 'robot-council/core',
            'from_work_location' => 'a',
            'to_repository' => 'robot-council/core',
            'to_work_location' => 'b',
        ]);
});

it('moves the repository and the location together through the endpoint', function (): void {
    $this->machine($this->token)
        ->patchJson(route('robot-council.agent.session.move'), ['repository' => 'robot-council/cli', 'work_location' => 'ci'])
        ->assertOk()
        ->assertExactJson([
            'session_id' => $this->session->id,
            'repository' => 'robot-council/cli',
            'work_location' => 'ci',
            'applied' => true,
        ]);

    expect(moveEvents()[0]->meta)->toMatchArray(['to_repository' => 'robot-council/cli', 'to_work_location' => 'ci']);
});

it('leaves a field that was not sent as it was', function (): void {
    $this->machine($this->token)
        ->patchJson(route('robot-council.agent.session.move'), ['repository' => 'robot-council/cli'])
        ->assertOk();

    $row = AgentSession::query()->findOrFail($this->session->id);

    expect($row->repository)->toBe('robot-council/cli')
        ->and($row->work_location)->toBe('a');
});

it('shows the new place on sessions_list, the session endpoint and the lane board at once', function (): void {
    moveThroughTool($this, $this->token, ['work_location' => 'primary']);

    $listed = $this->machine($this->token)->postJson('/robot-council/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'sessions_list', 'arguments' => []],
    ])->json('result.structuredContent.sessions');

    $mine = collect(arrayValue($listed))->firstWhere('id', $this->session->id);

    expect(arrayValue($mine)['work_location'])->toBe('primary');

    $this->machine($this->token)->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('work_location', 'primary');

    $lanes = arrayValue($this->service(LaneBoard::class)->read()['lanes']['robot-council/core'] ?? []);

    expect(arrayValue($lanes[0] ?? [])['slot'])->toBe('primary');
});

it('keeps the tasks and the locks the session holds', function (): void {
    $taskId = $this->createClaimedTask();

    $this->machine($this->token)
        ->postJson(route('robot-council.locks.action', ['action' => 'acquire']), ['name' => 'branch:feature/move', 'ttl' => 60])
        ->assertOk();

    moveThroughTool($this, $this->token, ['work_location' => 'b']);

    $task = Task::query()->findOrFail($taskId);
    $lock = Lock::query()->where('name', 'branch:feature/move')->sole();

    expect($task->claimed_by)->toBe($this->session->id)
        ->and($task->status)->toBe(TaskStatus::Claimed)
        ->and($lock->holder_id)->toBe($this->session->id);

    // And it goes on working as the same session afterwards
    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $taskId, 'transition' => 'start']))
        ->assertOk();
});

it('refuses a value the join refuses, with the reason the join gives', function (string $field, string $value): void {
    $credential = $this->installationCredential($this->installation);

    $atJoin = $this->machine($credential)
        ->postJson(route('robot-council.sessions.start'), [$field => $value])
        ->assertUnprocessable()
        ->json('errors.'.$field.'.0');

    $atMove = $this->machine($this->token)
        ->patchJson(route('robot-council.agent.session.move'), [$field => $value])
        ->assertUnprocessable()
        ->json('errors.'.$field.'.0');

    $throughTool = moveThroughTool($this, $this->token, [$field => $value]);

    expect($atJoin)->toBeString()->not->toBe('')
        ->and($atMove)->toBe($atJoin)
        ->and($throughTool['isError'] ?? false)->toBeTrue()
        ->and(moveText($throughTool))->toBe($atJoin);

    // And the store a host could call directly refuses it with the message `start()` throws
    $place = $field === 'repository' ? [$value, null] : [null, $value];

    $joinReason = null;

    try {
        $this->service(AgentSessions::class)->start($this->installation, ...$place);
    } catch (InvalidArgumentException $invalidArgumentException) {
        $joinReason = $invalidArgumentException->getMessage();
    }

    expect($joinReason)->toBeString()
        ->and(fn () => $this->service(AgentSessions::class)->move($this->session, $field === 'repository' ? ['repository' => $value] : ['work_location' => $value]))
        ->toThrow(InvalidArgumentException::class, (string) $joinReason);

    $row = AgentSession::query()->findOrFail($this->session->id);

    expect($row->repository)->toBe('robot-council/core')
        ->and($row->work_location)->toBe('a')
        ->and(moveEvents())->toBeEmpty();
})->with([
    'a path for a location' => ['work_location', '/Users/dev/robot-council-core-a'],
    'an upper-case location' => ['work_location', 'A'],
    'a location of dots' => ['work_location', '..'],
    'a location read as a flag' => ['work_location', '-rf'],
    'a location past its length' => ['work_location', str_repeat('a', 33)],
    'a repository with no owner' => ['repository', 'core'],
    'a repository with a space' => ['repository', 'robot council/core'],
]);

it('refuses a blank value on both surfaces rather than failing inside the store', function (string $blank): void {
    $this->machine($this->token)
        ->patchJson(route('robot-council.agent.session.move'), ['repository' => 'robot-council/cli', 'work_location' => $blank])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['work_location']);

    // The tool reads the raw body, so no middleware turns the blank into null first: this is the
    // surface where it would otherwise reach the store and come back as an internal error
    $result = moveThroughTool($this, $this->token, ['repository' => 'robot-council/cli', 'work_location' => $blank]);

    expect($result['isError'] ?? false)->toBeTrue()
        ->and(moveText($result))->toContain('work location')
        ->and(AgentSession::query()->findOrFail($this->session->id)->only(['repository', 'work_location']))
        ->toBe(['repository' => 'robot-council/core', 'work_location' => 'a'])
        ->and(moveEvents())->toBeEmpty();
})->with(['empty' => '', 'spaces' => '   ', 'a tab' => "\t"]);

it('refuses a blank at the endpoint by its own rule, for a host without the empty-string middleware', function (string $blank): void {
    // A default host turns a blank into null before validation, which the `string` rule then
    // refuses; a host that dropped those two middleware relies on `filled` alone
    $this->withoutMiddleware([TrimStrings::class, ConvertEmptyStringsToNull::class]);

    $this->machine($this->token)
        ->patchJson(route('robot-council.agent.session.move'), ['repository' => 'robot-council/cli', 'work_location' => $blank])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['work_location']);

    expect(AgentSession::query()->findOrFail($this->session->id)->repository)->toBe('robot-council/core');
})->with(['empty' => '', 'spaces' => '   ']);

it('refuses a key the store does not know beside one it does, rather than dropping it', function (): void {
    expect(fn () => $this->service(AgentSessions::class)->move($this->session, ['repository' => 'robot-council/cli', 'work_locaton' => 'b']))
        ->toThrow(InvalidArgumentException::class, 'only a repository and a work location')
        ->and(AgentSession::query()->findOrFail($this->session->id)->repository)->toBe('robot-council/core');
});

it('refuses a null through the store, which would otherwise clear the field', function (): void {
    expect(fn () => $this->service(AgentSessions::class)->move($this->session, ['repository' => null]))
        ->toThrow(InvalidArgumentException::class, 'does not clear one')
        ->and(AgentSession::query()->findOrFail($this->session->id)->repository)->toBe('robot-council/core')
        ->and(moveEvents())->toBeEmpty();
});

it('drops a hold naming the repository the lane leaves, and keeps any other', function (array $place, HoldReason $reason, string $party, bool $kept): void {
    [$coordinator] = $this->startCoordinatorSession($this->installation);

    expect($this->service(LaneHolds::class)->hold($coordinator, $this->session->id, $party, $reason))->toBe(Outcome::Applied)
        ->and($this->service(AgentSessions::class)->move($this->session, $place))->toBe(Outcome::Applied)
        ->and(LaneHold::query()->whereKey($this->session->id)->exists())->toBe($kept);
})->with([
    'nothing startable, and the repository changes' => [['repository' => 'robot-council/cli'], HoldReason::NothingStartable, 'robot-council/core', false],
    'nothing startable, and only the location changes' => [['work_location' => 'b'], HoldReason::NothingStartable, 'robot-council/core', true],
    'waiting on a developer, and the repository changes' => [['repository' => 'robot-council/cli'], HoldReason::Decision, 'octodev', true],
]);

it('refuses a move that names neither field, and records nothing', function (): void {
    $this->machine($this->token)
        ->patchJson(route('robot-council.agent.session.move'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['repository', 'work_location']);

    expect(moveThroughTool($this, $this->token, [])['isError'] ?? false)->toBeTrue()
        ->and(fn () => $this->service(AgentSessions::class)->move($this->session, []))->toThrow(InvalidArgumentException::class)
        ->and(moveEvents())->toBeEmpty();
});

it("cannot move another session: neither surface names one, and a call moves only the caller's own", function (): void {
    $otherDeveloper = $this->enrollDeveloper(77, login: 'otherdev');
    [$other, $otherToken] = $this->startAgentSession($this->approveInstallation($otherDeveloper, 'elsewhere'));

    $other->forceFill(['repository' => 'robot-council/core', 'work_location' => 'b'])->save();

    // Nothing in the tool's arguments can name a target
    $schema = (new SessionMoveTool)->toArray()['inputSchema'] ?? [];

    expect(array_keys(arrayValue(arrayValue($schema)['properties'] ?? [])))->toBe(['work_location', 'repository']);

    // A session id slipped into either surface is not read: the caller moves, the other does not
    moveThroughTool($this, $otherToken, ['work_location' => 'ci', 'session_id' => $this->session->id]);

    $this->machine($otherToken)
        ->patchJson(route('robot-council.agent.session.move'), ['repository' => 'robot-council/cli', 'session_id' => $this->session->id])
        ->assertOk();

    expect(AgentSession::query()->findOrFail($this->session->id)->only(['repository', 'work_location']))
        ->toBe(['repository' => 'robot-council/core', 'work_location' => 'a'])
        ->and(AgentSession::query()->findOrFail($other->id)->only(['repository', 'work_location']))
        ->toBe(['repository' => 'robot-council/cli', 'work_location' => 'ci'])
        ->and(collect(moveEvents())->pluck('agent_session_id')->unique()->all())->toBe([$other->id]);
});

it('refuses to move a session that has ended, even to where it already is', function (string $location): void {
    $this->session->forceFill(['status' => AgentSessionStatus::Gone])->save();

    expect($this->service(AgentSessions::class)->move($this->session, ['work_location' => $location]))->toBe(Outcome::Conflict)
        ->and(AgentSession::query()->findOrFail($this->session->id)->work_location)->toBe('a')
        ->and(moveEvents())->toBeEmpty();
})->with(['somewhere else' => 'b', 'where it is' => 'a']);

it('answers a session whose row is gone as not found', function (): void {
    AgentSession::query()->whereKey($this->session->id)->delete();

    expect($this->service(AgentSessions::class)->move($this->session, ['work_location' => 'b']))->toBe(Outcome::NotFound);
});

it('answers a move to where the session already is without recording one', function (): void {
    expect($this->service(AgentSessions::class)->move($this->session, ['repository' => 'robot-council/core', 'work_location' => 'a']))->toBe(Outcome::Applied)
        ->and(moveEvents())->toBeEmpty();
});

it('moves an ephemeral session without telling the fleet', function (): void {
    $this->session->forceFill(['ephemeral' => true])->save();

    expect($this->service(AgentSessions::class)->move($this->session, ['work_location' => 'b']))->toBe(Outcome::Applied)
        ->and(AgentSession::query()->findOrFail($this->session->id)->work_location)->toBe('b')
        ->and(moveEvents())->toBeEmpty();
});

it('says in its description to pass a short label and never a path', function (): void {
    $description = (new SessionMoveTool)->description();

    expect($description)->toContain('`a`, `ci` or `primary`')
        ->and($description)->toContain('never a path');
});
