<?php

declare(strict_types=1);

/**
 * Narration addressed to named sessions, or to whoever holds a task (#315).
 *
 * #29 restricts narration to its author's own developer's sessions, which leaves no way for a seat to
 * answer another developer's coordinator, or for a CI session to tell another developer's build
 * session that its pull request was handed back. Addressing widens the audience to the sessions a
 * narration names, and to nobody else -- so every test here is bounded on both sides: the addressee
 * reads it, and a bystander belonging to the same developer does not.
 *
 * The feed is read through the HTTP route, which is the same `FleetFeed::after()` the MCP tool reads;
 * `McpToolsTest` covers the tool's own arguments.
 *
 * @command  vendor/bin/pest --compact tests/AddressedNarrationTest.php
 */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\FleetFeed;
use RobotCouncil\Support\NarrationAddressees;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77, 91]);

    $this->author = $this->enrollDeveloper(4242, login: 'authordev');
    $this->other = $this->enrollDeveloper(77, login: 'otherdev');
    $this->boss = $this->enrollDeveloper(91, login: 'bossdev');
});

/**
 * Start a session for a developer on a machine of its own.
 *
 * @param  TestCase  $case  The running test.
 * @param  User  $developer  Whose session it is.
 * @param  bool  $coordinator  Whether it holds `coordinator:direct`.
 * @return array{AgentSession, string} The session and its token.
 */
function addressedSessionFor(TestCase $case, User $developer, bool $coordinator = false): array
{
    $installation = $case->approveInstallation(
        $developer,
        machineLabel: 'm-'.keyValue($developer->getKey()).'-'.Str::random(4)
    );

    return $coordinator
        ? $case->startCoordinatorSession($installation)
        : $case->startAgentSession($installation);
}

/**
 * The narration bodies a session's feed shows, read from the start.
 *
 * @param  TestCase  $case  The running test.
 * @param  string  $token  The reading session's token.
 * @return list<string> The bodies.
 */
function narrationSeenBy(TestCase $case, string $token): array
{
    $page = $case->machine($token)
        ->getJson(route('robot-council.events.index', ['after' => 0, 'limit' => 200]))
        ->assertOk();

    $bodies = [];

    foreach (arrayValue($page->json('events')) as $event) {
        $event = arrayValue($event);

        if ($event['type'] === FleetEventType::Narration->value) {
            $bodies[] = stringValue($event['body']);
        }
    }

    return $bodies;
}

/**
 * A task created by a coordinator and claimed by a session, which is how the fleet places work.
 *
 * @param  TestCase  $case  The running test.
 * @param  AgentSession  $coordinator  The creating coordinator.
 * @param  AgentSession  $holder  The session to claim it.
 * @return Task The held task.
 */
function heldTask(TestCase $case, AgentSession $coordinator, AgentSession $holder): Task
{
    $tasks = $case->service(Tasks::class);

    $task = $tasks->create($coordinator, ['title' => 'build the thing'], true);

    expect($tasks->transition($task->id, TaskTransition::Claim, $holder, false))->toBe(Outcome::Applied);

    return $task->refresh();
}

/**
 * The one narration event on record.
 */
function theNarration(): FleetEvent
{
    return FleetEvent::query()->where('type', FleetEventType::Narration->value)->sole();
}

it('delivers a narration to a named session belonging to another developer, and to no bystander', function (): void {
    [, $posterToken] = addressedSessionFor($this, $this->author);
    [$addressee, $addresseeToken] = addressedSessionFor($this, $this->other);
    [, $bystanderToken] = addressedSessionFor($this, $this->other);

    $this->machine($posterToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'answering your question', 'to' => [$addressee->id]])
        ->assertCreated();

    expect(narrationSeenBy($this, $addresseeToken))->toBe(['answering your question'])
        // The same developer as the addressee, not named: #29's boundary still holds for it
        ->and(narrationSeenBy($this, $bystanderToken))->toBeEmpty();
});

it('leaves an unaddressed narration with exactly the reach it had before', function (): void {
    [, $posterToken] = addressedSessionFor($this, $this->author);
    [, $sibling] = addressedSessionFor($this, $this->author);
    [, $otherToken] = addressedSessionFor($this, $this->other);
    [, $coordinatorToken] = addressedSessionFor($this, $this->boss, coordinator: true);
    [, $readerToken] = addressedSessionFor($this, $this->other);

    $this->machine($posterToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'a private thought'])
        ->assertCreated();

    $this->machine($coordinatorToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'a coordinator speaking'])
        ->assertCreated();

    expect(narrationSeenBy($this, $sibling))->toBe(['a private thought', 'a coordinator speaking'])
        ->and(narrationSeenBy($this, $otherToken))->toBe(['a coordinator speaking'])
        ->and(narrationSeenBy($this, $readerToken))->toBe(['a coordinator speaking']);

    // And the event gains no key: an unaddressed narration's meta is what it was before #315
    expect(FleetEvent::query()->where('body', 'a private thought')->sole()->meta)->toBeNull()
        ->and(DB::table(FleetEvents::ADDRESSEE_TABLE)->count())->toBe(0);
});

it('delivers a narration addressed to a task to the session holding it, and records both', function (): void {
    [$coordinator] = addressedSessionFor($this, $this->boss, coordinator: true);
    [$holder, $holderToken] = addressedSessionFor($this, $this->other);
    [, $bystanderToken] = addressedSessionFor($this, $this->other);
    [, $ciToken] = addressedSessionFor($this, $this->author);

    $task = heldTask($this, $coordinator, $holder);

    $this->machine($ciToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'handed back, see the PR', 'to_tasks' => [$task->id]])
        ->assertCreated();

    expect(narrationSeenBy($this, $holderToken))->toBe(['handed back, see the PR'])
        ->and(narrationSeenBy($this, $bystanderToken))->toBeEmpty();

    // Asserted on the row, not the response: the task and the session it resolved to
    $meta = arrayValue(theNarration()->meta);

    expect($meta['to'] ?? null)->toBe([$holder->id])
        ->and($meta['to_tasks'] ?? null)->toBe([['task_id' => $task->id, 'session_id' => $holder->id]]);
});

it('follows a reassigned task to its new holder, and stops reaching the old one', function (): void {
    [$coordinator] = addressedSessionFor($this, $this->boss, coordinator: true);
    [$before, $beforeToken] = addressedSessionFor($this, $this->other);
    [$after, $afterToken] = addressedSessionFor($this, $this->other);
    [, $ciToken] = addressedSessionFor($this, $this->author);

    $task = heldTask($this, $coordinator, $before);

    $this->machine($ciToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'first note', 'to_tasks' => [$task->id]])
        ->assertCreated();

    expect($this->service(Tasks::class)->transition($task->id, TaskTransition::Reassign, $coordinator, true, $after))
        ->toBe(Outcome::Applied);

    $this->machine($ciToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'second note', 'to_tasks' => [$task->id]])
        ->assertCreated();

    // Each note reaches whoever held the task when it was posted, and the first is not re-pointed
    expect(narrationSeenBy($this, $beforeToken))->toBe(['first note'])
        ->and(narrationSeenBy($this, $afterToken))->toBe(['second note']);
});

it('refuses a task that nobody holds, and records nothing', function (): void {
    [$coordinator] = addressedSessionFor($this, $this->boss, coordinator: true);
    [, $ciToken] = addressedSessionFor($this, $this->author);

    $pending = $this->service(Tasks::class)->create($coordinator, ['title' => 'unclaimed'], true);

    $this->machine($ciToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'hello?', 'to_tasks' => [$pending->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to_tasks' => (string) $pending->id]);

    expect(FleetEvent::query()->where('type', FleetEventType::Narration->value)->count())->toBe(0);
});

it('refuses a finished task, whose holder no longer holds it', function (): void {
    [$coordinator] = addressedSessionFor($this, $this->boss, coordinator: true);
    [$holder] = addressedSessionFor($this, $this->other);
    [, $ciToken] = addressedSessionFor($this, $this->author);

    $task = heldTask($this, $coordinator, $holder);

    expect($this->service(Tasks::class)->transition($task->id, TaskTransition::Complete, $holder, false, result: []))
        ->toBe(Outcome::Applied);

    $this->machine($ciToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'too late', 'to_tasks' => [$task->id]])
        ->assertUnprocessable();

    expect(FleetEvent::query()->where('type', FleetEventType::Narration->value)->count())->toBe(0);
});

it('refuses a task the poster may not read, and records nothing', function (): void {
    // Created by its own developer without a coordinator, so only that developer's sessions may
    // read it -- the audience `TaskList::page()` draws
    [$holder] = addressedSessionFor($this, $this->other);
    [, $posterToken] = addressedSessionFor($this, $this->author);

    $tasks = $this->service(Tasks::class);
    $task = $tasks->create($holder, ['title' => 'mine alone'], false);
    expect($tasks->transition($task->id, TaskTransition::Claim, $holder, false))->toBe(Outcome::Applied);

    $this->machine($posterToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'let me in', 'to_tasks' => [$task->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to_tasks');

    expect(FleetEvent::query()->where('type', FleetEventType::Narration->value)->count())->toBe(0);

    // The control: a coordinator may read it, so the same call from one is accepted
    [, $coordinatorToken] = addressedSessionFor($this, $this->boss, coordinator: true);

    $this->machine($coordinatorToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'let me in', 'to_tasks' => [$task->id]])
        ->assertCreated();
});

it('refuses an unknown or departed session by name, and records nothing', function (): void {
    [, $posterToken] = addressedSessionFor($this, $this->author);
    [$live] = addressedSessionFor($this, $this->other);
    [$departed] = addressedSessionFor($this, $this->other);

    AgentSession::query()->whereKey($departed->id)->update(['status' => AgentSessionStatus::Gone]);

    $this->machine($posterToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'anyone?', 'to' => [$live->id, $departed->id, 999999]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to' => sprintf('%d, 999999', $departed->id)]);

    expect(FleetEvent::query()->where('type', FleetEventType::Narration->value)->count())->toBe(0)
        ->and(DB::table(FleetEvents::ADDRESSEE_TABLE)->count())->toBe(0);
});

it('refuses a list longer than the bound, and accepts one at it', function (string $field): void {
    [, $posterToken] = addressedSessionFor($this, $this->author);

    $this->machine($posterToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'too many', $field => range(1, NarrationAddressees::MAX + 1)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    // The store's own bound, reached without the validator in front of it
    [$poster] = addressedSessionFor($this, $this->author);

    $call = $field === 'to'
        ? fn (): array => NarrationAddressees::resolve(range(1, NarrationAddressees::MAX + 1), null, $poster, false)
        : fn (): array => NarrationAddressees::resolve(null, range(1, NarrationAddressees::MAX + 1), $poster, false);

    expect($call)->toThrow(ValidationException::class, 'may not name more than 50');

    // At the bound the list is admitted, and what refuses it is that those ids name nothing -- so
    // the bound is exactly 50 rather than anything below it
    $atBound = $this->machine($posterToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'exactly enough', $field => range(900001, 900000 + NarrationAddressees::MAX)])
        ->assertUnprocessable();

    expect(stringValue($atBound->json('errors.'.$field.'.0')))->not->toContain('may not name more than')
        ->and(stringValue($atBound->json('errors.'.$field.'.0')))->toContain('900050');
})->with(['to', 'to_tasks']);

it('refuses a task that does not exist, naming it', function (): void {
    [, $posterToken] = addressedSessionFor($this, $this->author);

    $this->machine($posterToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'hello?', 'to_tasks' => [999999]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to_tasks' => '999999']);

    expect(FleetEvent::query()->where('type', FleetEventType::Narration->value)->count())->toBe(0);
});

it('refuses a task still held by a session that has gone', function (): void {
    // The sweep releases such a task on its next pass; until then it is held by a process that will
    // never read another page, so addressing it would reach nobody
    [$coordinator] = addressedSessionFor($this, $this->boss, coordinator: true);
    [$holder] = addressedSessionFor($this, $this->other);
    [, $ciToken] = addressedSessionFor($this, $this->author);

    $task = heldTask($this, $coordinator, $holder);

    AgentSession::query()->whereKey($holder->id)->update(['status' => AgentSessionStatus::Gone]);

    $this->machine($ciToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'anyone home?', 'to_tasks' => [$task->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to_tasks' => (string) $task->id]);

    expect(FleetEvent::query()->where('type', FleetEventType::Narration->value)->count())->toBe(0);
});

it('addresses a session named twice, directly and through its task, once', function (): void {
    [$coordinator] = addressedSessionFor($this, $this->boss, coordinator: true);
    [$holder, $holderToken] = addressedSessionFor($this, $this->other);
    [, $posterToken] = addressedSessionFor($this, $this->author);

    $task = heldTask($this, $coordinator, $holder);

    $this->machine($posterToken)
        ->postJson(route('robot-council.events.store'), [
            'body' => 'twice over',
            'to' => [$holder->id, $holder->id],
            'to_tasks' => [$task->id],
        ])
        ->assertCreated();

    expect(arrayValue(theNarration()->meta)['to'] ?? null)->toBe([$holder->id])
        ->and(DB::table(FleetEvents::ADDRESSEE_TABLE)->count())->toBe(1)
        ->and(narrationSeenBy($this, $holderToken))->toBe(['twice over']);
});

it('carries the addressees on the event a reader receives', function (): void {
    [, $posterToken] = addressedSessionFor($this, $this->author);
    [$addressee, $addresseeToken] = addressedSessionFor($this, $this->other);

    $this->machine($posterToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'for you', 'to' => [$addressee->id], 'meta' => ['pr' => 12]])
        ->assertCreated();

    $event = collect(arrayValue($this->machine($addresseeToken)
        ->getJson(route('robot-council.events.index', ['after' => 0]))
        ->json('events')))->firstWhere('body', 'for you');

    // Beside `client`, never inside it, so the reader can tell what the server resolved from what
    // the poster sent
    expect(orderedMeta(arrayValue(arrayValue($event)['meta'])))
        ->toBe(orderedMeta(['client' => ['pr' => 12], 'to' => [$addressee->id]]));
});

it('does not follow a reused session id to another developer', function (): void {
    // **The reason each addressee row carries its developer.** Session ids are reused after a
    // truncate, and nothing removes an addressee row when its session goes, so a filter matching the
    // id alone would serve this narration to whoever takes the id next. Forced here by moving the
    // addressed id onto a different developer's session, which is the state reuse produces.
    [, $posterToken] = addressedSessionFor($this, $this->author);
    [$addressee] = addressedSessionFor($this, $this->other);
    [$usurper] = addressedSessionFor($this, $this->boss);

    $this->machine($posterToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'for the original', 'to' => [$addressee->id]])
        ->assertCreated();

    $reusedId = $addressee->id;
    AgentSession::query()->whereKey($reusedId)->delete();
    AgentSession::query()->whereKey($usurper->id)->update(['id' => $reusedId]);

    $reader = AgentSession::query()->whereKey($reusedId)->sole();

    $bodies = array_column(app(FleetFeed::class)->after($reader, 0, 200)['events'], 'body');

    expect($bodies)->not->toContain('for the original');

    // The control: the same read, with the developer matching, does see it -- so the absence above
    // is the developer check and not a read that could see nothing
    AgentSession::query()->whereKey($reusedId)->update(['user_id' => keyValue($this->other->getKey())]);

    $bodies = array_column(app(FleetFeed::class)->after($reader->refresh(), 0, 200)['events'], 'body');

    expect($bodies)->toContain('for the original');
});

it('reads an addressed feed in one query', function (): void {
    [, $posterToken] = addressedSessionFor($this, $this->author);
    [$addressee] = addressedSessionFor($this, $this->other);

    foreach (range(1, 5) as $n) {
        $this->machine($posterToken)
            ->postJson(route('robot-council.events.store'), ['body' => 'note '.$n, 'to' => [$addressee->id]])
            ->assertCreated();
    }

    $reads = [];

    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')
            && (str_contains($query->sql, 'robot_council_events') || str_contains($query->sql, FleetEvents::ADDRESSEE_TABLE))) {
            $reads[] = $query->sql;
        }
    });

    $page = app(FleetFeed::class)->after(AgentSession::query()->whereKey($addressee->id)->sole(), 0, 30);

    expect($reads)->toHaveCount(1)
        ->and($reads[0])->toContain(FleetEvents::ADDRESSEE_TABLE)
        ->and(array_column($page['events'], 'body'))->toContain('note 1', 'note 5');
});

it('prunes an addressed event together with its addressees', function (): void {
    [, $posterToken] = addressedSessionFor($this, $this->author);
    [$addressee] = addressedSessionFor($this, $this->other);

    $this->machine($posterToken)
        ->postJson(route('robot-council.events.store'), ['body' => 'old news', 'to' => [$addressee->id]])
        ->assertCreated();

    expect(DB::table(FleetEvents::ADDRESSEE_TABLE)->count())->toBe(1);

    $deleted = app(FleetEvents::class)->prune(Carbon::now()->addMinute());

    expect($deleted)->toBeGreaterThan(0)
        ->and(FleetEvent::query()->where('body', 'old news')->count())->toBe(0)
        ->and(DB::table(FleetEvents::ADDRESSEE_TABLE)->count())->toBe(0);
});
