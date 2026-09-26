<?php

declare(strict_types=1);

/**
 * A session the fleet is not told about (#424).
 *
 * Every case runs an ordinary session beside the ephemeral one, in the same state, as its control:
 * a test that showed only that an ephemeral session announced nothing would pass just as well if no
 * session announced anything.
 *
 * @command  vendor/bin/pest --compact tests/EphemeralSessionTest.php
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RobotCouncil\Events\SessionGone;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Lock;
use RobotCouncil\Models\Seat;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\FleetPresence;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\LaneBoard;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\Scope;
use RobotCouncil\Support\Seats;
use RobotCouncil\Support\SessionPresence;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
    $this->credential = $this->installationCredential($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:00:00'));
});

/**
 * Start a session over HTTP, as a client does.
 *
 * @param  TestCase  $case  The test case.
 * @param  array<string, mixed>  $body  The request body.
 * @return array<array-key, mixed> The response body.
 */
function startEphemeralCase(TestCase $case, array $body): array
{
    $response = $case->machine(stringValue($case->credential))
        ->postJson(route('robot-council.sessions.start'), $body)
        ->assertCreated();

    return arrayValue($response->json());
}

/**
 * How many presence events of one kind the feed holds about a session.
 *
 * @param  AgentSession|int  $session  The session.
 * @param  FleetEventType  $type  The kind.
 * @return int The count.
 */
function presenceEventsAbout(AgentSession|int $session, FleetEventType $type): int
{
    return FleetEvent::query()
        ->where('type', $type->value)
        ->where('agent_session_id', $session instanceof AgentSession ? $session->id : $session)
        ->count();
}

/**
 * A session holding one task and one lock, started through the store.
 *
 * @param  TestCase  $case  The test case.
 * @param  bool  $ephemeral  Whether to start it ephemeral.
 * @param  string  $name  What to call its lock.
 * @return array{AgentSession, int} The session and its task's id.
 */
function holdingSession(TestCase $case, bool $ephemeral, string $name): array
{
    $session = $case->service(AgentSessions::class)->start($case->installation, ephemeral: $ephemeral)->owner;

    $tasks = $case->service(Tasks::class);
    $task = $tasks->create($session, ['title' => 'Held by '.$name], withCoordinator: false);
    $tasks->transition($task->id, TaskTransition::Claim, $session, asCoordinator: false);

    $case->service(Locks::class)->acquire($session, $name, 3600, false);

    return [$session, $task->id];
}

it('stores the flag and answers with the shape an ordinary start has', function (): void {
    $ephemeral = startEphemeralCase($this, ['ephemeral' => true]);
    $ordinary = startEphemeralCase($this, []);

    // The same keys, so a client written before #424 reads either response identically
    expect(array_keys($ephemeral))->toBe(array_keys($ordinary))
        ->and(AgentSession::query()->whereKey($ephemeral['session_id'])->sole()->ephemeral)->toBeTrue()
        ->and(AgentSession::query()->whereKey($ordinary['session_id'])->sole()->ephemeral)->toBeFalse();
});

it('accepts the forms a JSON boolean takes', function (mixed $value, bool $stored): void {
    $id = startEphemeralCase($this, ['ephemeral' => $value])['session_id'];

    expect(AgentSession::query()->whereKey($id)->sole()->ephemeral)->toBe($stored);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'one' => [1, true],
    'zero' => [0, false],
    'the digit one as text' => ['1', true],
    'the digit zero as text' => ['0', false],
]);

it('refuses a value that is not a boolean, naming the field, and starts nothing', function (mixed $value): void {
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), ['ephemeral' => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ephemeral']);

    expect(AgentSession::query()->count())->toBe(0);
})->with([
    'the word true' => ['true'],
    'the word false' => ['false'],
    'a word' => ['yes'],
    'two' => [2],
    'null' => [null],
    'a list' => [[true]],
]);

it('treats a start that omits the flag exactly as before', function (): void {
    $issued = startEphemeralCase($this, ['repository' => 'robot-council/core']);
    $row = AgentSession::query()->whereKey($issued['session_id'])->sole();

    $joined = FleetEvent::query()
        ->where('type', FleetEventType::SessionJoined->value)
        ->where('agent_session_id', $row->id)
        ->sole();

    // An ordinary session: stored as one, announced, and starting from its own join
    expect($row->ephemeral)->toBeFalse()
        ->and($issued['feed_cursor'])->toBe($joined->id)
        ->and($row->feed_cursor)->toBe($joined->id);
});

it('announces neither the start nor the end of an ephemeral session, and both of an ordinary one', function (bool $ephemeral, int $announced): void {
    $id = intValue(startEphemeralCase($this, $ephemeral ? ['ephemeral' => true] : [])['session_id']);

    $this->machine($this->credential)
        ->deleteJson(route('robot-council.sessions.end', ['session' => $id]))
        ->assertOk()
        ->assertJsonPath('status', 'gone');

    expect(AgentSession::query()->whereKey($id)->sole()->status)->toBe(AgentSessionStatus::Gone)
        ->and(presenceEventsAbout($id, FleetEventType::SessionJoined))->toBe($announced)
        ->and(presenceEventsAbout($id, FleetEventType::SessionGone))->toBe($announced);
})->with([
    'ephemeral' => [true, 0],
    'ordinary' => [false, 1],
]);

it('announces nothing when an ephemeral session is revoked, and still ends it', function (bool $ephemeral, int $announced): void {
    $session = $this->service(AgentSessions::class)->start($this->installation, ephemeral: $ephemeral)->owner;

    expect($this->service(SessionPresence::class)->revoke($session, 'an-admin'))->toBe(1)
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and($session->tokens()->count())->toBe(0)
        ->and(presenceEventsAbout($session, FleetEventType::SessionGone))->toBe($announced);
})->with([
    'ephemeral' => [true, 0],
    'ordinary' => [false, 1],
]);

it('sweeps an ephemeral session without a word, and still gives back what it held', function (): void {
    [$quiet, $quietTask] = holdingSession($this, true, 'quiet-lock');
    [$loud, $loudTask] = holdingSession($this, false, 'loud-lock');

    // Past the stale threshold first, so both transitions the sweep makes are exercised
    $this->travelTo(Carbon::parse('2026-01-01 12:06:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 2, 'gone' => 0])
        ->and(presenceEventsAbout($quiet, FleetEventType::SessionStale))->toBe(0)
        ->and(presenceEventsAbout($loud, FleetEventType::SessionStale))->toBe(1);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 2])
        ->and($quiet->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and($loud->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and(presenceEventsAbout($quiet, FleetEventType::SessionGone))->toBe(0)
        ->and(presenceEventsAbout($loud, FleetEventType::SessionGone))->toBe(1);

    // Released alike, and each release is still told to the fleet: what an ephemeral session held
    // was fleet state, and giving it back is a state change like any other
    foreach ([[$quiet, $quietTask, 'quiet-lock'], [$loud, $loudTask, 'loud-lock']] as [$session, $task, $lock]) {
        $released = Task::query()->findOrFail($task);

        expect($released->status)->toBe(TaskStatus::Pending)
            ->and($released->claimed_by)->toBeNull()
            ->and(Lock::query()->where('name', $lock)->sole()->holder_id)->toBeNull()
            ->and(FleetEvent::query()->where('type', FleetEventType::LockReleased->value)->get()
                ->contains(fn (FleetEvent $event): bool => ($event->meta['released_from'] ?? null) === $session->id))->toBeTrue()
            ->and(FleetEvent::query()->where('type', FleetEventType::TaskReleased->value)->get()
                ->contains(fn (FleetEvent $event): bool => ($event->meta['task_id'] ?? null) === $task
                    && ($event->meta['released_from'] ?? null) === $session->id))->toBeTrue();
    }
});

it('still dispatches the gone signal for an ephemeral session, once', function (bool $ephemeral): void {
    Event::fake([SessionGone::class]);

    $session = $this->service(AgentSessions::class)->start($this->installation, ephemeral: $ephemeral)->owner;

    $this->service(SessionPresence::class)->end($session);
    $this->service(SessionPresence::class)->end($session);

    // What a host listens to for the end of a session is the transition, not the feed event
    Event::assertDispatchedTimes(SessionGone::class, 1);
})->with([
    'ephemeral' => [true],
    'ordinary' => [false],
]);

it('announces no resumption when a stale ephemeral session answers again', function (bool $ephemeral, int $announced): void {
    $issued = $this->service(AgentSessions::class)->start($this->installation, ephemeral: $ephemeral);
    $session = $issued->owner;
    $token = $issued->plainTextToken;

    $this->travelTo(Carbon::parse('2026-01-01 12:06:00'));
    $this->service(SessionPresence::class)->sweep();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Stale);

    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Active)
        ->and(presenceEventsAbout($session, FleetEventType::SessionResumed))->toBe($announced);
})->with([
    'ephemeral' => [true, 0],
    'ordinary' => [false, 1],
]);

it('leaves an ephemeral session out of the agents list and the lanes, and lists an ordinary one', function (): void {
    $ordinary = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'a')->owner;
    $issued = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'b', ephemeral: true);
    $ephemeral = $issued->owner;

    // The reader is the ephemeral session itself, which reads the list as any session does
    $listed = array_column(arrayValue($this->machine($issued->plainTextToken)
        ->getJson(route('robot-council.lanes.index'))
        ->assertOk()
        ->json('sessions')), 'id');

    $board = [];

    foreach ($this->service(LaneBoard::class)->read()['lanes'] as $group) {
        foreach ($group as $row) {
            $board[] = $row['id'];
        }
    }

    expect($listed)->toBe([$ordinary->id])
        ->and($board)->toBe([$ordinary->id]);
});

it('leaves an ephemeral session out of the dashboard sessions and its totals, live or gone', function (): void {
    $ordinary = $this->service(AgentSessions::class)->start($this->installation)->owner;
    $ephemeral = $this->service(AgentSessions::class)->start($this->installation, ephemeral: true)->owner;
    $ordinaryGone = $this->service(AgentSessions::class)->start($this->installation)->owner;
    $ephemeralGone = $this->service(AgentSessions::class)->start($this->installation, ephemeral: true)->owner;

    $this->service(SessionPresence::class)->end($ordinaryGone);
    $this->service(SessionPresence::class)->end($ephemeralGone);

    $presence = $this->service(FleetPresence::class);
    $every = $presence->sessions(50, Scope::All);
    $live = $presence->sessions(50, Scope::Live);

    expect(array_column($every['sessions'], 'id'))->toBe([$ordinaryGone->id, $ordinary->id])
        ->and(array_column($live['sessions'], 'id'))->toBe([$ordinary->id])
        ->and([$every['live'], $every['gone']])->toBe([1, 1])
        ->and($presence->liveSessions())->toBe(1)
        ->and($ephemeral->refresh()->status)->toBe(AgentSessionStatus::Active);
});

it('records no seat for an ephemeral session, and one for an ordinary session in the same place', function (): void {
    $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'read', ephemeral: true);
    $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'lane');

    $seats = $this->service(Seats::class)->forDeveloper(HostKey::from($this->developer->getAuthIdentifier()));

    expect(array_map(static fn (Seat $seat): string => $seat->work_location, $seats))->toBe(['lane'])
        ->and(Seat::query()->count())->toBe(1);
});

it('starts an ephemeral reader at the head of the feed, so it sees what follows and nothing before', function (): void {
    $other = $this->service(AgentSessions::class)->start($this->installation)->owner;
    $feed = $this->service(FleetEvents::class);

    $before = $feed->record(FleetEventType::Narration, $other, 'Written before the read began.');

    $issued = $this->service(AgentSessions::class)->start($this->installation, ephemeral: true);

    $after = $feed->record(FleetEventType::Narration, $other, 'Written after the read began.');

    // The newest event at the moment it started, stated and stored alike
    expect($issued->feedCursor)->toBe($before->id)
        ->and(AgentSession::query()->whereKey($issued->owner->id)->value('feed_cursor'))->toBe($before->id);

    // A read that names no position resumes from the stored one
    $page = $this->machine($issued->plainTextToken)->getJson(route('robot-council.events.index'))->assertOk();

    expect(array_column(arrayValue($page->json('events')), 'id'))->toBe([$after->id]);
});

it('starts an ordinary reader at its own join, which the ephemeral change leaves alone', function (): void {
    $other = $this->service(AgentSessions::class)->start($this->installation)->owner;
    $feed = $this->service(FleetEvents::class);

    $feed->record(FleetEventType::Narration, $other, 'Written before the read began.');

    $issued = $this->service(AgentSessions::class)->start($this->installation);
    $joined = FleetEvent::query()->where('type', FleetEventType::SessionJoined->value)->where('agent_session_id', $issued->owner->id)->sole();

    $after = $feed->record(FleetEventType::Narration, $other, 'Written after the read began.');

    $page = $this->machine($issued->plainTextToken)->getJson(route('robot-council.events.index'))->assertOk();

    expect($issued->feedCursor)->toBe($joined->id)
        ->and(array_column(arrayValue($page->json('events')), 'id'))->toBe([$after->id]);
});

it('starts an ephemeral reader at zero on a feed with no events', function (): void {
    FleetEvent::query()->delete();

    $issued = $this->service(AgentSessions::class)->start($this->installation, ephemeral: true);

    expect($issued->feedCursor)->toBe(0)
        ->and(FleetEvent::query()->count())->toBe(0);
});
