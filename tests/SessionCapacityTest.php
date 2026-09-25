<?php

declare(strict_types=1);

/**
 * A session's capacity and a held task's sub-label (#409, from #386's decision).
 *
 * Every placement assertion reads the ROW as well as the outcome: a refusal is thrown before the
 * placement commits, and a store that answered after writing would pass an outcome-only test.
 *
 * @command  vendor/bin/pest --compact tests/SessionCapacityTest.php
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Livewire\SeatSettings;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\PlacementRule;
use RobotCouncil\Models\PlacementWaiver;
use RobotCouncil\Models\Seat;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Capacity;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\LaneBoard;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\PackageMigrations;
use RobotCouncil\Support\PlacementRefused;
use RobotCouncil\Support\PlacementWaivers;
use RobotCouncil\Support\Seats;
use RobotCouncil\Support\SubLabel;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\Fixtures\HostileContent;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $this->installation = $this->approveInstallation($this->developer);
    $this->credential = $this->installationCredential($this->installation);
    $this->key = HostKey::from($this->developer->getAuthIdentifier());

    [$this->coordinatorSession, $this->coordinatorToken] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );

    // Inside anybody's assignment hours: none are set, so nothing but the rule under test refuses
    Carbon::setTestNow('2026-09-24 12:00:00');
});

/**
 * Join through the endpoint, as the bridge does.
 *
 * @param  TestCase  $case  The test case.
 * @param  array<string, mixed>  $body  What the bridge sends besides the work identity.
 * @param  string  $location  The work location.
 * @return array{AgentSession, string, array<array-key, mixed>} The session, its token, and the response.
 */
function joinWith(TestCase $case, array $body = [], string $location = 'a'): array
{
    $response = $case->machine($case->credential)
        ->postJson(route('robot-council.sessions.start'), ['repository' => 'robot-council/core', 'work_location' => $location, ...$body])
        ->assertCreated();

    $json = arrayValue($response->json());
    $token = $json['token'] ?? null;

    if (! \is_string($token) || ! \is_int($json['session_id'] ?? null)) {
        throw new RuntimeException('The join answered without a session id and a token.');
    }

    return [AgentSession::query()->findOrFail($json['session_id']), $token, $json];
}

/**
 * The seat a place's sessions sit in, recorded as the seats page records it.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $location  The work location.
 * @return Seat The seat.
 */
function seatAt(TestCase $case, string $location = 'a'): Seat
{
    $seat = collect($case->service(Seats::class)->forDeveloper($case->key))->firstWhere('work_location', $location);

    if (! $seat instanceof Seat) {
        throw new RuntimeException('No seat was recorded at that location.');
    }

    return $seat;
}

/**
 * Place a new unticketed task on a lane, through the store.
 *
 * @param  TestCase  $case  The test case.
 * @param  AgentSession  $lane  The lane.
 * @return array{int, list<PlacementRule>} The task, and the rules the placement was refused on.
 */
function placeNew(TestCase $case, AgentSession $lane): array
{
    $task = $case->service(Tasks::class)->create($case->coordinatorSession, ['title' => 'Work'], true);

    try {
        $outcome = $case->service(Tasks::class)->transition($task->id, TaskTransition::Reassign, $case->coordinatorSession, true, $lane, directive: 'Take this.');
    } catch (PlacementRefused $placementRefused) {
        return [$task->id, $placementRefused->rules];
    }

    if ($outcome !== Outcome::Applied) {
        throw new RuntimeException(sprintf('The placement came to %s rather than a refusal or an application.', $outcome->name));
    }

    return [$task->id, []];
}

/**
 * The text of every element carrying a marker attribute.
 *
 * `LanesTest`'s `markedText()`, under its own name, so this file does not depend on that one having
 * been loaded first.
 *
 * @param  string  $html  The page.
 * @param  string  $marker  The attribute, such as `data-occupancy`.
 * @return list<string> The elements' text, trimmed.
 */
function capacityMarked(string $html, string $marker): array
{
    preg_match_all('/<([a-z]+)[^>]*\b'.preg_quote($marker, '/').'\b[^>]*>(.*?)<\/\1>/s', $html, $found);

    return array_map(static fn (string $text): string => trim((string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($text)))), $found[2]);
}

/**
 * How many tasks a lane holds, read from the rows.
 *
 * @param  AgentSession  $lane  The lane.
 * @return int The count.
 */
function heldBy(AgentSession $lane): int
{
    return Task::query()->where('claimed_by', $lane->id)->whereIn('status', TaskStatus::values(TaskStatus::held()))->count();
}

// ------------------------------------------------------------------ the defaults

it('gives a session that declares nothing a capacity of one, and refuses a second placement on it exactly as before', function (): void {
    [$lane, $token, $joined] = joinWith($this);

    // Declared and in effect, as the join, the row, and the session read all say
    expect([$joined['capacity'], $joined['declared_capacity']])->toBe([1, 1])
        ->and(AgentSession::query()->whereKey($lane->id)->value('declared_capacity'))->toBe(1)
        ->and($this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk()->json('capacity'))->toBe(1);

    [, $first] = placeNew($this, $lane);
    [$second, $refused] = placeNew($this, $lane);

    expect($first)->toBeEmpty()
        ->and($refused)->toBe([PlacementRule::LaneFree])
        ->and(Task::query()->findOrFail($second)->claimed_by)->toBeNull()
        ->and(heldBy($lane))->toBe(1);
});

it('holds a session to one on a seat whose cap is one, whatever it declared', function (): void {
    // A seat nobody has set, which is the state every seat is recorded in
    joinWith($this, location: 'a');
    $seat = seatAt($this);

    expect($seat->max_capacity)->toBe(1)
        ->and(Seat::query()->whereKey($seat->id)->value('max_capacity'))->toBe(1);

    [$lane, , $joined] = joinWith($this, ['capacity' => 3]);

    expect([$joined['capacity'], $joined['declared_capacity']])->toBe([1, 3])
        // What it declared rides the join event, for a coordinator reading the feed
        ->and(arrayValue(FleetEvent::query()->where('type', FleetEventType::SessionJoined->value)->where('agent_session_id', $lane->id)->sole()->meta)['declared_capacity'] ?? null)->toBe(3);

    placeNew($this, $lane);

    expect(placeNew($this, $lane)[1])->toBe([PlacementRule::LaneFree])
        ->and(heldBy($lane))->toBe(1);
});

it('holds a session to one where no seat has been recorded at all, whatever it declared', function (): void {
    [$lane, , $joined] = joinWith($this, ['capacity' => 4]);

    expect(Seat::query()->count())->toBe(0)
        ->and([$joined['capacity'], $joined['declared_capacity']])->toBe([1, 4]);

    placeNew($this, $lane);

    expect(placeNew($this, $lane)[1])->toBe([PlacementRule::LaneFree]);
});

it('defaults the store, the columns and the bounds to one', function (): void {
    // The store's own default, for a host that calls it without the new argument
    $issued = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'z');

    // A row written with no value for either column takes the column's default
    DB::table('robot_council_seats')->insert([
        'installation_id' => $this->installation->id, 'user_id' => $this->key, 'repository' => 'robot-council/core',
        'work_location' => 'y', 'hours_exempt' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(AgentSession::query()->whereKey($issued->owner->id)->value('declared_capacity'))->toBe(1)
        ->and(Seat::query()->where('work_location', 'y')->value('max_capacity'))->toBe(1)
        ->and(Capacity::DEFAULT)->toBe(1)
        // And a model built in memory, before anything is saved
        ->and(new AgentSession()->declared_capacity)->toBe(1)
        ->and(new Seat()->max_capacity)->toBe(1)
        ->and(Capacity::effective($issued->owner, null))->toBe(1);
});

// ------------------------------------------------------------------ the cap

it('records a declaration above the cap as the cap in effect, and says so on the join and the session read', function (): void {
    joinWith($this, location: 'a');
    expect($this->service(Seats::class)->cap($this->key, seatAt($this)->id, 2))->toBe(Outcome::Applied);

    [$lane, $token, $joined] = joinWith($this, ['capacity' => 5]);

    expect([$joined['capacity'], $joined['declared_capacity']])->toBe([2, 5])
        ->and($this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk()->json())
        ->toMatchArray(['capacity' => 2, 'declared_capacity' => 5]);

    // And placement refuses on the cap, not on the declaration
    placeNew($this, $lane);
    placeNew($this, $lane);

    expect(placeNew($this, $lane)[1])->toBe([PlacementRule::LaneFree])
        ->and(heldBy($lane))->toBe(2);
});

it('clamps a declaration past the hard maximum, and refuses one below one at the endpoint', function (): void {
    joinWith($this, location: 'a');
    $this->service(Seats::class)->cap($this->key, seatAt($this)->id, 99);

    [, , $joined] = joinWith($this, ['capacity' => 500]);

    // 16 written out, not `Capacity::MAX`, so lowering the bound fails here rather than moving with it
    expect(seatAt($this)->max_capacity)->toBe(16)
        ->and([$joined['capacity'], $joined['declared_capacity']])->toBe([16, 16]);

    foreach ([0, -3, 'three', 1.5] as $capacity) {
        $this->machine($this->credential)
            ->postJson(route('robot-council.sessions.start'), ['capacity' => $capacity])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacity']);
    }

    // And the store, which a host may call directly, clamps below as well as above
    expect($this->service(AgentSessions::class)->start($this->installation, null, null, null, null, -4)->owner->declared_capacity)->toBe(1)
        ->and($this->service(AgentSessions::class)->start($this->installation, null, null, null, null, 40)->owner->declared_capacity)->toBe(16)
        ->and(Capacity::MAX)->toBe(16);
});

it('applies a change to the cap to a session already running, in both directions', function (): void {
    [$lane, $token] = joinWith($this, ['capacity' => 3]);
    $seat = seatAt($this);

    placeNew($this, $lane);

    expect(placeNew($this, $lane)[1])->toBe([PlacementRule::LaneFree]);

    // Raised after the join: the running session takes more, with no restart
    $this->service(Seats::class)->cap($this->key, $seat->id, 3);

    expect(placeNew($this, $lane)[1])->toBeEmpty()
        ->and($this->machine($token)->getJson(route('robot-council.agent.session'))->json('capacity'))->toBe(3);

    // Lowered: nothing is taken back, and nothing more is placed
    $this->service(Seats::class)->cap($this->key, $seat->id, 1);

    expect(placeNew($this, $lane)[1])->toBe([PlacementRule::LaneFree])
        ->and(heldBy($lane))->toBe(2);
});

it("refuses a cap on another developer's seat, and a repeat is applied", function (): void {
    joinWith($this);
    $seat = seatAt($this);

    expect($this->service(Seats::class)->cap($this->key, $seat->id, 2))->toBe(Outcome::Applied)
        ->and($this->service(Seats::class)->cap($this->key, $seat->id, 2))->toBe(Outcome::Applied)
        ->and($this->service(Seats::class)->cap(HostKey::from('someone-else'), $seat->id, 5))->toBe(Outcome::Forbidden)
        ->and($this->service(Seats::class)->cap($this->key, 999_999, 2))->toBe(Outcome::NotFound)
        ->and(Seat::query()->whereKey($seat->id)->value('max_capacity'))->toBe(2);
});

it('sets the cap from the seats page, and refuses an entry outside the bound in words', function (): void {
    joinWith($this);
    $seat = seatAt($this);

    $saved = Livewire::actingAs($this->developer)->test(SeatSettings::class)
        ->assertSet('capacities.'.$seat->id, 1)
        ->set('capacities.'.$seat->id, '4')
        ->call('setCapacity', $seat->id)
        ->assertSet('notice', null)
        ->assertSet('capacityError', null)
        ->assertSeeHtml('takes up to 4 tickets at once')
        ->html();

    // Confirmed in words beside the control, and the field is not marked invalid
    expect(capacityMarked($saved, 'data-capacity-saved'))->toBe(['Saved: up to 4 tickets at once.'])
        ->and($saved)->not->toContain('aria-invalid')
        ->and(Seat::query()->whereKey($seat->id)->value('max_capacity'))->toBe(4);

    foreach (['0', '17', '2.5', '', 'many'] as $typed) {
        $html = Livewire::actingAs($this->developer)->test(SeatSettings::class)
            ->set('capacities.'.$seat->id, $typed)
            ->call('setCapacity', $seat->id)
            ->assertSet('notice', null)
            ->assertSet('capacityError', 'Enter a whole number from 1 to 16.')
            ->html();

        // Next to the field, which is marked invalid and described by the error first
        $id = sprintf('seat-%d-capacity-error', $seat->id);

        expect(capacityMarked($html, 'data-capacity-error'))->toBe(['Enter a whole number from 1 to 16.'])
            ->and($html)->toContain(sprintf('id="%s"', $id))
            ->and($html)->toMatch(sprintf('/<input[^>]*aria-invalid="true"[^>]*aria-describedby="%s seat-%d-capacity-help"/', $id, $seat->id));
    }

    expect(Seat::query()->whereKey($seat->id)->value('max_capacity'))->toBe(4);
});

it("names each seat's ticket-limit field and button for a screen reader", function (): void {
    joinWith($this, location: 'a');
    joinWith($this, location: 'b');
    seatAt($this, 'a');

    $html = Livewire::actingAs($this->developer)->test(SeatSettings::class)->html();

    expect($html)->toContain('Tickets at once<span class="sr-only"> for robot-council/core / a</span>')
        ->and($html)->toContain('Tickets at once<span class="sr-only"> for robot-council/core / b</span>')
        ->and($html)->toContain('Set tickets at once<span class="sr-only"> for robot-council/core / a</span>');
});

it("refuses a cap on another developer's seat through the page, whatever seat id the client sends", function (): void {
    joinWith($this);
    $seat = seatAt($this);

    $this->setAccessLists(developers: [4242, 77, 5152]);
    $other = $this->enrollDeveloper(5152, login: 'someoneelse');

    Livewire::actingAs($other)->test(SeatSettings::class)
        ->set('capacities.'.$seat->id, '9')
        ->call('setCapacity', $seat->id)
        ->assertSet('capacityError', "Only a seat's own developer can change how many tickets it takes at once.");

    expect(Seat::query()->whereKey($seat->id)->value('max_capacity'))->toBe(1);
});

// ------------------------------------------------------------------ placement

it('places while the lane holds fewer than its capacity, and refuses with the existing refusal once it is full', function (): void {
    joinWith($this, location: 'a');
    $this->service(Seats::class)->cap($this->key, seatAt($this)->id, 3);
    [$lane] = joinWith($this, ['capacity' => 3]);

    foreach ([1, 2, 3] as $held) {
        expect(placeNew($this, $lane)[1])->toBeEmpty()
            ->and(heldBy($lane))->toBe($held);
    }

    // The fourth, at the endpoint: the same rule and the same words as before #409
    $fourth = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'One too many'], true);

    $this->machine($this->coordinatorToken)->postJson(
        route('robot-council.tasks.transition', ['task' => $fourth->id, 'transition' => 'reassign']),
        ['session_id' => $lane->id, 'directive' => 'Take this.']
    )->assertUnprocessable()->assertExactJson([
        'task_id' => $fourth->id,
        'status' => null,
        'applied' => false,
        'refused' => [['rule' => 'lane_free', 'reason' => 'the lane already holds another task']],
    ]);

    expect(Task::query()->findOrFail($fourth->id)->claimed_by)->toBeNull()
        ->and(heldBy($lane))->toBe(3);
});

it("lets one placement past a full lane on the seat owner's waiver, and spends it", function (): void {
    [$lane] = joinWith($this, ['capacity' => 2]);
    $seat = seatAt($this);
    $this->service(Seats::class)->cap($this->key, $seat->id, 2);

    placeNew($this, $lane);
    placeNew($this, $lane);

    expect($this->service(PlacementWaivers::class)->grant($this->key, $seat->id, PlacementRule::LaneFree))->toBe(Outcome::Applied);

    [$third, $refused] = placeNew($this, $lane);

    expect($refused)->toBeEmpty()
        ->and(PlacementWaiver::query()->sole()->consumed_by_task)->toBe($third)
        ->and(heldBy($lane))->toBe(3)
        // Spent: the next is refused again
        ->and(placeNew($this, $lane)[1])->toBe([PlacementRule::LaneFree]);
});

it("does not limit a lane's own claims, which capacity was never about", function (): void {
    [$lane] = joinWith($this);

    foreach ([1, 2] as $ignored) {
        $task = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Mine'], true);

        expect($this->service(Tasks::class)->transition($task->id, TaskTransition::Claim, $lane, false))->toBe(Outcome::Applied);
    }

    expect(heldBy($lane))->toBe(2);
});

// ------------------------------------------------------------------ the board and the read

it('shows occupancy against capacity, and every held task with its sub-label', function (): void {
    [$lane] = joinWith($this, ['capacity' => 3]);
    $this->service(Seats::class)->cap($this->key, seatAt($this)->id, 3);

    [$first] = placeNew($this, $lane);
    [$second] = placeNew($this, $lane);
    $this->service(Tasks::class)->transition($first, TaskTransition::Start, $lane, false, subLabel: 'wt-one');
    $this->service(Tasks::class)->transition($second, TaskTransition::Start, $lane, false, subLabel: 'wt-two');

    $row = collect($this->service(LaneBoard::class)->read()['lanes']['robot-council/core'] ?? [])->firstWhere('id', $lane->id);
    $html = Livewire::actingAs($this->developer)->test(Lanes::class)->html();

    expect(arrayValue($row)['holding'] ?? null)->toBe(2)
        ->and(arrayValue($row)['capacity'] ?? null)->toBe(3)
        ->and(array_column(arrayValue(arrayValue(arrayValue($row)['on_what'] ?? [])['tasks'] ?? []), 'sub_label', 'task_id'))
        ->toBe([$first => 'wt-one', $second => 'wt-two'])
        ->and(capacityMarked($html, 'data-occupancy'))->toContain('2 / 3')
        ->and(capacityMarked($html, 'data-sub-label'))->toBe(['wt-one', 'wt-two'])
        ->and(capacityMarked($html, 'data-held-task'))->toHaveCount(2)
        ->and($html)->toContain('<span data-occupancy>2 / 3</span> tickets held')
        // One held reads in the singular
        ->and($html)->toContain('<span data-occupancy>0 / 1</span> tickets held');

    $this->service(Tasks::class)->transition($second, TaskTransition::Release, $lane, false);

    expect(Livewire::actingAs($this->developer)->test(Lanes::class)->html())->toContain('<span data-occupancy>1 / 3</span> ticket held');
});

it('escapes a hostile sub-label on the board', function (string $payload, array $forbidden, ?string $escaped): void {
    // The column is `varchar(64)`, which Postgres enforces and SQLite does not, so a payload that did
    // not fit would pass locally and fail only in the `postgres` job
    expect(mb_strlen($payload))->toBeLessThanOrEqual(SubLabel::MAX);

    [$lane] = joinWith($this);
    [$taskId] = placeNew($this, $lane);

    // Past the store's bound deliberately: the page's escaping has to be its own guarantee
    Task::query()->whereKey($taskId)->update(['sub_label' => $payload]);

    $html = Livewire::actingAs($this->developer)->test(Lanes::class)->html();

    expect($html)->toContain($escaped ?? $payload);

    foreach ($forbidden as $live) {
        expect($html)->not->toContain($live);
    }
})->with(HostileContent::dataset());

it('reports each session capacity and each held task sub-label on the lanes read', function (): void {
    [$lane, $token] = joinWith($this, ['capacity' => 2]);
    $this->service(Seats::class)->cap($this->key, seatAt($this)->id, 2);
    [$taskId] = placeNew($this, $lane);
    $this->service(Tasks::class)->transition($taskId, TaskTransition::Start, $lane, false, subLabel: 'wt-one');

    $sessions = collect(arrayValue($this->machine($token)->getJson(route('robot-council.lanes.index'))->assertOk()->json('sessions')));
    $mine = arrayValue($sessions->firstWhere('id', $lane->id));

    expect($mine['capacity'])->toBe(2)
        ->and(arrayValue(arrayValue($mine['tasks'])[0] ?? [])['sub_label'] ?? null)->toBe('wt-one')
        ->and(arrayValue($sessions->firstWhere('id', $this->coordinatorSession->id))['capacity'])->toBe(1);
});

// ------------------------------------------------------------------ the sub-label

it('withholds a sub-label from a reader that may not read the task, on the lanes read and sessions_list', function (): void {
    // Developer 4242's own task, filed without the coordinator's ability, so only their sessions
    // (and a coordinator) may read it -- the same rule `TaskList` applies to `branch`
    [$lane, $token] = joinWith($this);
    $task = $this->service(Tasks::class)->create($lane, ['title' => 'Private'], false);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Claim, $lane, false);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Start, $lane, false, subLabel: 'subagent-2');

    // A build session of developer 77, who may not read it
    [, $otherToken] = $this->startAgentSession($this->coordinatorSession->installation);

    // `absent` for a task entry with no `sub_label` key at all, so a key that went missing cannot
    // pass as the null this test is looking for
    $label = function (string $reader) use ($lane): mixed {
        $row = arrayValue(collect(arrayValue($this->machine($reader)->getJson(route('robot-council.lanes.index'))->assertOk()->json('sessions')))->firstWhere('id', $lane->id));
        $task = arrayValue(arrayValue($row['tasks'])[0] ?? []);

        return \array_key_exists('sub_label', $task) ? $task['sub_label'] : 'absent';
    };

    $tool = function (string $reader) use ($lane): mixed {
        $body = arrayValue($this->machine($reader)->postJson('/robot-council/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'sessions_list', 'arguments' => []],
        ])->assertOk()->json('result.structuredContent'));
        $row = arrayValue(collect(arrayValue($body['sessions'] ?? []))->firstWhere('id', $lane->id));

        $task = arrayValue(arrayValue($row['tasks'])[0] ?? []);

        return \array_key_exists('sub_label', $task) ? $task['sub_label'] : 'absent';
    };

    // The positive control: the same instruments do return it to a reader who may read the task
    expect($label($token))->toBe('subagent-2')
        ->and($tool($token))->toBe('subagent-2')
        ->and($label($this->coordinatorToken))->toBe('subagent-2')
        ->and($label($otherToken))->toBeNull()
        ->and($tool($otherToken))->toBeNull();
});

it('stores a sub-label from a start and from a report, and carries it in task.* meta', function (): void {
    [$lane, $token] = joinWith($this);
    [$taskId] = placeNew($this, $lane);

    $this->machine($token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $taskId, 'transition' => 'start']), ['sub_label' => 'wt-alpha'])
        ->assertOk();

    expect(Task::query()->findOrFail($taskId)->sub_label)->toBe('wt-alpha');

    // Reported alone, it leaves the branch; the branch reported alone leaves it
    $this->machine($token)
        ->postJson(route('robot-council.tasks.branch', ['task' => $taskId]), ['branch' => 'feature/x'])
        ->assertOk()
        ->assertExactJson(['task_id' => $taskId, 'branch' => 'feature/x', 'sub_label' => null, 'applied' => true]);
    $this->machine($token)
        ->postJson(route('robot-council.tasks.branch', ['task' => $taskId]), ['sub_label' => 'wt-beta'])
        ->assertOk()
        ->assertExactJson(['task_id' => $taskId, 'branch' => null, 'sub_label' => 'wt-beta', 'applied' => true]);

    $row = Task::query()->findOrFail($taskId);

    expect([$row->branch, $row->sub_label])->toBe(['feature/x', 'wt-beta']);

    $this->service(Tasks::class)->transition($taskId, TaskTransition::Block, $lane, false);
    $this->service(Tasks::class)->transition($taskId, TaskTransition::Release, $lane, false);

    $meta = static fn (FleetEventType $type): mixed => arrayValue(FleetEvent::query()->where('type', $type->value)->latest('id')->firstOrFail()->meta)['sub_label'] ?? null;

    expect($meta(FleetEventType::TaskStarted))->toBe('wt-alpha')
        ->and($meta(FleetEventType::TaskBlocked))->toBe('wt-beta')
        // A release clears the row and still says whose ticket went back
        ->and($meta(FleetEventType::TaskReleased))->toBe('wt-beta')
        // A placement is a new holder, which has not labelled anything yet
        ->and($meta(FleetEventType::TaskReassigned))->toBeNull()
        ->and(Task::query()->findOrFail($taskId)->sub_label)->toBeNull();
});

it("clears the sub-label when the task moves to another lane, and records the previous lane's label", function (): void {
    [$lane] = joinWith($this);
    [$other] = joinWith($this, location: 'b');
    [$taskId] = placeNew($this, $lane);
    $this->service(Tasks::class)->transition($taskId, TaskTransition::Start, $lane, false, subLabel: 'wt-alpha');

    $this->service(Tasks::class)->transition($taskId, TaskTransition::Reassign, $this->coordinatorSession, true, $other, directive: 'Yours now.');

    $moved = FleetEvent::query()->where('type', FleetEventType::TaskReassigned->value)->latest('id')->firstOrFail();

    expect(Task::query()->findOrFail($taskId)->sub_label)->toBeNull()
        ->and(arrayValue($moved->meta))->toMatchArray(['assigned_to' => $other->id, 'sub_label' => 'wt-alpha']);
});

it('keeps the sub-label when a task is placed again on the lane already holding it', function (): void {
    [$lane] = joinWith($this);
    [$taskId] = placeNew($this, $lane);
    $this->service(Tasks::class)->transition($taskId, TaskTransition::Start, $lane, false, subLabel: 'wt-alpha');

    expect($this->service(Tasks::class)->transition($taskId, TaskTransition::Reassign, $this->coordinatorSession, true, $lane, directive: 'Keep going.'))
        ->toBe(Outcome::Applied);

    $again = FleetEvent::query()->where('type', FleetEventType::TaskReassigned->value)->latest('id')->firstOrFail();

    expect(Task::query()->findOrFail($taskId)->sub_label)->toBe('wt-alpha')
        ->and(arrayValue($again->meta)['sub_label'] ?? null)->toBe('wt-alpha');
});

it('clears the sub-label on a release GitHub drives, carries it in meta, and keeps it on a completion', function (): void {
    [$lane] = joinWith($this);
    [$released] = placeNew($this, $lane);
    $this->service(Tasks::class)->transition($released, TaskTransition::Start, $lane, false, subLabel: 'wt-alpha');

    expect($this->service(Tasks::class)->finishFromGitHub($released, false, 'its pull request closed unmerged'))->toBeTrue();

    $event = FleetEvent::query()->where('type', FleetEventType::TaskReleased->value)->latest('id')->firstOrFail();
    $row = Task::query()->findOrFail($released);

    expect([$row->status, $row->sub_label])->toBe([TaskStatus::Pending, null])
        ->and(arrayValue($event->meta))->toMatchArray(['source' => 'github', 'released_from' => $lane->id, 'sub_label' => 'wt-alpha']);

    [$completed] = placeNew($this, $lane);
    $this->service(Tasks::class)->transition($completed, TaskTransition::Start, $lane, false, subLabel: 'wt-beta');

    expect($this->service(Tasks::class)->finishFromGitHub($completed, true, 'its issue closed'))->toBeTrue()
        ->and(arrayValue(FleetEvent::query()->where('type', FleetEventType::TaskCompleted->value)->latest('id')->firstOrFail()->meta)['sub_label'] ?? null)->toBe('wt-beta')
        ->and(Task::query()->findOrFail($completed)->sub_label)->toBe('wt-beta');
});

it('refuses a sub-label outside its bound at both endpoints, and writes nothing', function (mixed $label): void {
    [$lane, $token] = joinWith($this);
    [$taskId] = placeNew($this, $lane);

    $this->machine($token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $taskId, 'transition' => 'start']), ['sub_label' => $label])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sub_label']);

    $this->service(Tasks::class)->transition($taskId, TaskTransition::Start, $lane, false);

    $this->machine($token)
        ->postJson(route('robot-council.tasks.branch', ['task' => $taskId]), ['sub_label' => $label])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sub_label']);

    $row = Task::query()->findOrFail($taskId);

    expect($row->sub_label)->toBeNull()
        ->and($row->status)->toBe(TaskStatus::InProgress);
})->with([
    // Written out rather than read from `SubLabel::MAX`: the column is `varchar(64)`, and a
    // bound that moved with the constant would move past the column without failing here
    'past its width' => [str_repeat('a', 65)],
    'a space' => ['wt one'],
    'a slash' => ['wt/one'],
    'a leading dot' => ['.hidden'],
    'a traversal' => ['..'],
    'a leading dash' => ['-rf'],
    'markup' => ['<b>x</b>'],
    'not a string' => [['wt']],
]);

it('refuses the same values through the store, which a host may call directly', function (string $label): void {
    [$lane] = joinWith($this);
    [$taskId] = placeNew($this, $lane);

    expect(fn (): Outcome => $this->service(Tasks::class)->transition($taskId, TaskTransition::Start, $lane, false, subLabel: $label))
        ->toThrow(InvalidArgumentException::class);

    $this->service(Tasks::class)->transition($taskId, TaskTransition::Start, $lane, false);

    expect(fn (): Outcome => $this->service(Tasks::class)->reportBranch($taskId, $lane, null, $label))
        ->toThrow(InvalidArgumentException::class)
        ->and(Task::query()->findOrFail($taskId)->sub_label)->toBeNull();
})->with([
    // Written out rather than read from `SubLabel::MAX`: the column is `varchar(64)`, and a
    // bound that moved with the constant would move past the column without failing here
    'past its width' => [str_repeat('a', 65)],
    'a trailing newline' => ["wt-one\n"],
    'a leading dot' => ['.git'],
    'empty' => [''],
]);

it('stores a sub-label exactly as wide as its bound, and reads it back whole', function (): void {
    [$lane] = joinWith($this);
    [$taskId] = placeNew($this, $lane);
    // 64, the column's width, written out for the reason the refusal datasets give
    $label = 'A'.str_repeat('b', 63);

    $this->service(Tasks::class)->transition($taskId, TaskTransition::Start, $lane, false, subLabel: $label);

    expect(Task::query()->whereKey($taskId)->value('sub_label'))->toBe($label);
});

it('refuses a report naming neither a branch nor a sub-label', function (): void {
    [$lane, $token] = joinWith($this);
    [$taskId] = placeNew($this, $lane);
    $this->service(Tasks::class)->transition($taskId, TaskTransition::Start, $lane, false);

    $this->machine($token)->postJson(route('robot-council.tasks.branch', ['task' => $taskId]), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch']);

    expect(fn (): Outcome => $this->service(Tasks::class)->reportBranch($taskId, $lane, null))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a sub-label from a session that does not hold the task', function (): void {
    [$lane] = joinWith($this);
    [$other] = joinWith($this, location: 'b');
    [$taskId] = placeNew($this, $lane);
    $this->service(Tasks::class)->transition($taskId, TaskTransition::Start, $lane, false);

    expect($this->service(Tasks::class)->reportBranch($taskId, $other, null, 'wt-mine'))->toBe(Outcome::Forbidden)
        ->and(Task::query()->findOrFail($taskId)->sub_label)->toBeNull();
});

// ------------------------------------------------------------------ gone

it('releases every task a gone session held through the existing sweep, labels and all', function (): void {
    [$lane] = joinWith($this, ['capacity' => 3]);
    $this->service(Seats::class)->cap($this->key, seatAt($this)->id, 3);

    $held = [];

    foreach (['wt-one', 'wt-two', 'wt-three'] as $label) {
        [$taskId] = placeNew($this, $lane);
        $this->service(Tasks::class)->transition($taskId, TaskTransition::Start, $lane, false, branch: 'feature/'.$label, subLabel: $label);
        $held[$taskId] = $label;
    }

    $lane->forceFill(['status' => AgentSessionStatus::Gone])->save();

    expect($this->service(Tasks::class)->releaseOrphaned())->toBe(3);

    foreach ($held as $taskId => $label) {
        $row = Task::query()->findOrFail($taskId);

        expect([$row->status, $row->claimed_by, $row->branch, $row->sub_label])->toBe([TaskStatus::Pending, null, null, null]);
    }

    $released = FleetEvent::query()->where('type', FleetEventType::TaskReleased->value)->orderBy('id')->get();

    expect($released->map(static fn (FleetEvent $event): mixed => arrayValue($event->meta)['sub_label'] ?? null)->all())->toBe(array_values($held))
        ->and($released->map(static fn (FleetEvent $event): mixed => arrayValue($event->meta)['released_from'] ?? null)->unique()->all())->toBe([$lane->id])
        // Idempotent, as it always was
        ->and($this->service(Tasks::class)->releaseOrphaned())->toBe(0);
});

// ------------------------------------------------------------------ the migration

it('adds the columns as wide as the bounds the package enforces', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite records no varchar length to compare.');
    }

    $types = [];

    foreach (Schema::getColumns('robot_council_tasks') as $column) {
        if (\is_array($column) && \is_string($column['name'] ?? null) && \is_string($column['type'] ?? null)) {
            $types[$column['name']] = $column['type'];
        }
    }

    // The control: a reading that found no columns would pass nothing below
    expect($types)->toHaveKey('sub_label')
        ->and($types['sub_label'])->toContain('('.SubLabel::MAX.')');
});

it('runs its migration again without error, completes one that stopped part-way, and rolls it back', function (): void {
    $migration = require PackageMigrations::directory().'/2026_09_25_000004_add_capacity_to_robot_council_sessions_seats_and_tasks.php';

    $up = [$migration, 'up'];
    $down = [$migration, 'down'];

    if (! \is_callable($up) || ! \is_callable($down)) {
        throw new RuntimeException('The migration file did not return something with an up() and a down().');
    }

    // Again, on a schema that already has them
    $up();

    // **Rows that exist when the column arrives take its default**, which is every live session and
    // seat on a deployed fleet, and the reason the default has to be one: the store always writes the
    // value, so nothing else exercises the column's own default
    $existing = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'a')->owner;
    $seat = seatAt($this);
    Schema::table('robot_council_agent_sessions', static fn (Blueprint $table) => $table->dropColumn('declared_capacity'));
    Schema::table('robot_council_seats', static fn (Blueprint $table) => $table->dropColumn('max_capacity'));

    $up();

    expect(AgentSession::query()->whereKey($existing->id)->value('declared_capacity'))->toBe(1)
        ->and(Seat::query()->whereKey($seat->id)->value('max_capacity'))->toBe(1);

    // The state a deploy killed between MySQL's statements leaves behind
    Schema::table('robot_council_tasks', static fn (Blueprint $table) => $table->dropColumn('sub_label'));

    $up();

    expect(Schema::hasColumn('robot_council_agent_sessions', 'declared_capacity'))->toBeTrue()
        ->and(Schema::hasColumn('robot_council_seats', 'max_capacity'))->toBeTrue()
        ->and(Schema::hasColumn('robot_council_tasks', 'sub_label'))->toBeTrue();

    Schema::table('robot_council_seats', static fn (Blueprint $table) => $table->dropColumn('max_capacity'));

    $down();

    expect(Schema::hasColumn('robot_council_agent_sessions', 'declared_capacity'))->toBeFalse()
        ->and(Schema::hasColumn('robot_council_seats', 'max_capacity'))->toBeFalse()
        ->and(Schema::hasColumn('robot_council_tasks', 'sub_label'))->toBeFalse();
});
