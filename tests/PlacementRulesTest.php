<?php

declare(strict_types=1);

/**
 * The invariants a coordinator's placement must hold, and the waivers that let one through (#320).
 *
 * Every refusal is asserted on the row as well as the response: the placement is refused before it
 * is written, and a store that answered 422 after writing would pass a response-only test.
 *
 * @command  vendor/bin/pest --compact tests/PlacementRulesTest.php
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Livewire\SeatSettings;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\PlacementRule;
use RobotCouncil\Models\PlacementWaiver;
use RobotCouncil\Models\Seat;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\DeveloperSettings;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\PlacementRefused;
use RobotCouncil\Support\PlacementWaivers;
use RobotCouncil\Support\Seats;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $this->installation = $this->approveInstallation($this->developer);

    [$this->coordinatorSession, $this->coordinatorToken] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );

    // A lane in the ticket's repository, which every rule but the one under test is satisfied by
    $this->session = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'a')->owner;

    // Thursday 24 September 2026, midday UTC
    Carbon::setTestNow('2026-09-24 12:00:00');
});

/**
 * An issue the fleet knows about.
 *
 * @param  int  $number  The issue.
 * @param  string  $state  `open` or `closed`.
 * @param  string  $repository  Its repository.
 */
function knownIssue(int $number, string $state = 'open', string $repository = 'robot-council/core'): void
{
    GitHubItem::query()->insert([
        'repository' => $repository, 'number' => $number, 'is_pull_request' => false, 'state' => $state,
        'title' => 'Issue '.$number, 'labels' => '[]', 'github_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * A pending task a coordinator filed, naming an issue.
 *
 * @param  TestCase  $case  The test case.
 * @param  string|null  $issue  The issue it names.
 * @param  string  $title  Its title.
 * @return int The task.
 */
function ticketTask(TestCase $case, ?string $issue = 'robot-council/core#12', string $title = 'Build the thing'): int
{
    return $case->service(Tasks::class)->create($case->coordinatorSession, array_filter(['title' => $title, 'issue' => $issue]), true)->id;
}

/**
 * Place a task on this test's lane, through the store.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $taskId  The task.
 * @param  bool  $handBack  Whether it is a hand-back.
 * @return Outcome What came of it.
 */
function placeOnLane(TestCase $case, int $taskId, bool $handBack = false, ?AgentSession $lane = null): Outcome
{
    return $case->service(Tasks::class)->transition(
        $taskId, TaskTransition::Reassign, $case->coordinatorSession, true, $lane ?? $case->session, directive: 'Take this.', handBack: $handBack
    );
}

/**
 * The rules a placement was refused on, or an empty list when it was not refused.
 *
 * @param  callable(): mixed  $placement  The placement.
 * @return list<PlacementRule> The rules.
 */
function refusedOn(callable $placement): array
{
    try {
        $placement();
    } catch (PlacementRefused $placementRefused) {
        return $placementRefused->rules;
    }

    return [];
}

/**
 * The task's row, read fresh.
 *
 * @param  int  $taskId  The task.
 * @return Task The row.
 */
function taskRow(int $taskId): Task
{
    return Task::query()->findOrFail($taskId);
}

it('places a ticket that holds every invariant', function (): void {
    knownIssue(12);

    expect(placeOnLane($this, ticketTask($this)))->toBe(Outcome::Applied);
});

it('refuses each broken invariant and writes nothing', function (string $case, PlacementRule $rule): void {
    match ($case) {
        'unknown ticket' => null,
        'closed ticket' => knownIssue(12, 'closed'),
        default => knownIssue(12),
    };

    $taskId = ticketTask($this);

    match ($case) {
        'another repository' => $this->session = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/cli', 'b')->owner,
        'lane holds work' => placeOnLane($this, (function (): int {
            knownIssue(13);

            return ticketTask($this, 'robot-council/core#13');
        })()),
        'parked seat' => (function (): void {
            $seat = $this->service(Seats::class)->forDeveloper(HostKey::from($this->developer->getAuthIdentifier()))[0];
            $this->service(Seats::class)->park(HostKey::from($this->developer->getAuthIdentifier()), $seat->id);
        })(),
        'open blocker' => DB::table('robot_council_github_blockers')->insert([
            'repository' => 'robot-council/core', 'number' => 12, 'blocker_repository' => 'robot-council/core', 'blocker_number' => 11,
        ]),
        'outside hours' => $this->service(DeveloperSettings::class)->setHours(HostKey::from($this->developer->getAuthIdentifier()), 'UTC', '13:00', '17:00', false),
        default => null,
    };

    $events = FleetEvent::query()->count();

    expect(refusedOn(fn (): Outcome => placeOnLane($this, $taskId)))->toBe([$rule]);

    $row = taskRow($taskId);

    expect($row->status)->toBe(TaskStatus::Pending)
        ->and($row->claimed_by)->toBeNull()
        ->and(FleetEvent::query()->count())->toBe($events);
})->with([
    'unknown ticket' => ['unknown ticket', PlacementRule::TicketOpen],
    'closed ticket' => ['closed ticket', PlacementRule::TicketOpen],
    'another repository' => ['another repository', PlacementRule::LaneInRepository],
    'lane holds work' => ['lane holds work', PlacementRule::LaneFree],
    'parked seat' => ['parked seat', PlacementRule::LaneNotParked],
    'open blocker' => ['open blocker', PlacementRule::TicketUnblocked],
    'outside hours' => ['outside hours', PlacementRule::AssignmentHours],
]);

it('names every broken rule, not only the first', function (): void {
    $taskId = ticketTask($this);
    $this->session = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/cli', 'b')->owner;

    expect(refusedOn(fn (): Outcome => placeOnLane($this, $taskId)))->toBe([PlacementRule::TicketOpen, PlacementRule::LaneInRepository]);
});

it('does not block on a closed blocker, and does on one the fleet has never seen', function (): void {
    knownIssue(12);
    knownIssue(11, 'closed');
    DB::table('robot_council_github_blockers')->insert([
        'repository' => 'robot-council/core', 'number' => 12, 'blocker_repository' => 'robot-council/core', 'blocker_number' => 11,
    ]);

    expect(placeOnLane($this, ticketTask($this)))->toBe(Outcome::Applied);

    DB::table('robot_council_github_blockers')->insert([
        'repository' => 'robot-council/core', 'number' => 12, 'blocker_repository' => 'someone/else', 'blocker_number' => 1,
    ]);

    expect(refusedOn(fn (): Outcome => placeOnLane($this, ticketTask($this), lane: $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'b')->owner)))
        ->toBe([PlacementRule::TicketUnblocked]);
});

it('applies no ticket rule to a task that names no issue', function (): void {
    expect(placeOnLane($this, ticketTask($this, null)))->toBe(Outcome::Applied);
});

it('reads parked from the same rule the board will, and refuses on it', function (): void {
    knownIssue(12);
    $key = HostKey::from($this->developer->getAuthIdentifier());
    $seat = $this->service(Seats::class)->forDeveloper($key)[0];
    $this->service(Seats::class)->park($key, $seat->id);

    expect($this->service(Seats::class)->of($this->session)?->isParked())->toBeTrue()
        ->and(refusedOn(fn (): Outcome => placeOnLane($this, ticketTask($this))))->toBe([PlacementRule::LaneNotParked]);
});

it('gates new placements on assignment hours, and not an exempt seat', function (): void {
    knownIssue(12);
    $key = HostKey::from($this->developer->getAuthIdentifier());
    $this->service(DeveloperSettings::class)->setHours($key, 'UTC', '13:00', '17:00', false);

    expect(refusedOn(fn (): Outcome => placeOnLane($this, ticketTask($this))))->toBe([PlacementRule::AssignmentHours]);

    // An exempt seat, on another lane of the same developer
    $other = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'b')->owner;
    $seat = collect($this->service(Seats::class)->forDeveloper($key))->firstWhere('work_location', 'b');

    expect($seat)->toBeInstanceOf(Seat::class);

    if ($seat instanceof Seat) {
        $this->service(Seats::class)->exempt($key, $seat->id, true);
    }

    expect(placeOnLane($this, ticketTask($this), lane: $other))->toBe(Outcome::Applied);
});

it('reports the seat outside its hours exactly when a placement is refused for them (#440)', function (): void {
    knownIssue(12);
    $key = HostKey::from($this->developer->getAuthIdentifier());
    $this->service(DeveloperSettings::class)->setHours($key, 'UTC', '13:00', '17:00', false);

    // Recorded the way the board and a placement record it, so the read has a seat to report
    $this->service(Seats::class)->forDeveloper($key);

    $seatOf = function (): array {
        $read = arrayValue($this->machine($this->coordinatorToken)->getJson(route('robot-council.developers.settings'))->assertOk()->json());
        $seats = array_values(array_filter(arrayValue($read['seats'] ?? []), fn (mixed $seat): bool => arrayValue($seat)['github_login'] === 'octodev'));

        return arrayValue($seats[0] ?? null);
    };

    // 12:00 UTC: outside, opening at 13:00, and a placement is refused on exactly that rule
    expect($seatOf())->toMatchArray(['inside_hours' => false, 'next_opens_at' => '2026-09-24T13:00:00+00:00'])
        ->and(refusedOn(fn (): Outcome => placeOnLane($this, ticketTask($this))))->toBe([PlacementRule::AssignmentHours]);

    // 13:00 UTC: inside, and the same placement goes through
    Carbon::setTestNow('2026-09-24 13:00:00');

    expect($seatOf())->toMatchArray(['inside_hours' => true, 'next_opens_at' => null])
        ->and(placeOnLane($this, ticketTask($this)))->toBe(Outcome::Applied);
});

it('does not gate a hand-back to the lane that started the task', function (): void {
    knownIssue(12);
    $key = HostKey::from($this->developer->getAuthIdentifier());
    $taskId = ticketTask($this);

    // Placed within hours, started by the lane, then given back to the queue
    expect(placeOnLane($this, $taskId))->toBe(Outcome::Applied);
    $this->service(Tasks::class)->transition($taskId, TaskTransition::Start, $this->session, false);
    $this->service(Tasks::class)->transition($taskId, TaskTransition::Release, $this->session, false);

    // Hours close; the gate hands the pull request back to the lane that made it
    $this->service(DeveloperSettings::class)->setHours($key, 'UTC', '13:00', '17:00', false);

    expect(placeOnLane($this, $taskId, handBack: true))->toBe(Outcome::Applied);
});

it('gates a hand-back the lane never started, since the coordinator alone cannot make work a hand-back', function (): void {
    knownIssue(12);
    $this->service(DeveloperSettings::class)->setHours(HostKey::from($this->developer->getAuthIdentifier()), 'UTC', '13:00', '17:00', false);

    // Exactly the bypass the review found: a fresh task, flagged as a hand-back
    expect(refusedOn(fn (): Outcome => placeOnLane($this, ticketTask($this), handBack: true)))->toBe([PlacementRule::AssignmentHours]);
});

it("gates held work moved to another developer's lane, and not within one developer's lanes", function (): void {
    knownIssue(12);

    // Placed within hours on this developer's lane, with nothing gating it
    $taskId = ticketTask($this);
    expect(placeOnLane($this, $taskId))->toBe(Outcome::Applied);

    // Another developer, whose hours are closed, with a lane in the same repository
    $this->setAccessLists(developers: [4242, 77, 5151]);
    $other = $this->enrollDeveloper(5151, login: 'otherdev');
    $theirLane = $this->service(AgentSessions::class)->start($this->approveInstallation($other, 'other-box'), 'robot-council/core', 'x')->owner;
    $this->service(DeveloperSettings::class)->setHours(HostKey::from($other->getAuthIdentifier()), 'UTC', '13:00', '17:00', false);

    // The task was filed with the coordinator's ability, so their lane may hold it
    expect(refusedOn(fn (): Outcome => placeOnLane($this, $taskId, lane: $theirLane)))->toBe([PlacementRule::AssignmentHours])
        ->and(taskRow($taskId)->claimed_by)->toBe($this->session->getKey());

    // This developer's own hours close too, and a move to their other lane is still not gated
    $this->service(DeveloperSettings::class)->setHours(HostKey::from($this->developer->getAuthIdentifier()), 'UTC', '13:00', '17:00', false);
    $sameDeveloper = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'b')->owner;

    expect(placeOnLane($this, $taskId, lane: $sameDeveloper))->toBe(Outcome::Applied);
});

it('does not count the task being moved as other work its new lane holds', function (): void {
    knownIssue(12);
    $taskId = ticketTask($this);

    expect(placeOnLane($this, $taskId))->toBe(Outcome::Applied)
        // Placed again on the lane that already holds it: the lane holds no OTHER task
        ->and(placeOnLane($this, $taskId))->toBe(Outcome::Applied);
});

it("lets a placement through on the seat owner's waiver, once, and records it as spent", function (): void {
    knownIssue(12);
    $key = HostKey::from($this->developer->getAuthIdentifier());
    $this->service(DeveloperSettings::class)->setHours($key, 'UTC', '13:00', '17:00', false);
    $seat = $this->service(Seats::class)->forDeveloper($key)[0];

    expect($this->service(PlacementWaivers::class)->grant($key, $seat->id, PlacementRule::AssignmentHours))->toBe(Outcome::Applied);

    $first = ticketTask($this);

    expect(placeOnLane($this, $first))->toBe(Outcome::Applied);

    $waiver = PlacementWaiver::query()->sole();

    expect($waiver->consumed_by_task)->toBe($first)
        ->and($waiver->granted_by)->toBe($key)
        ->and($waiver->consumed_at)->not->toBeNull();

    // Spent: the next placement is refused again. The lane is released first so only hours break.
    $this->service(Tasks::class)->transition($first, TaskTransition::Release, $this->session, false);

    expect(refusedOn(fn (): Outcome => placeOnLane($this, ticketTask($this))))->toBe([PlacementRule::AssignmentHours]);
});

it('spends no waiver when a waived rule is broken alongside one it does not cover', function (): void {
    $key = HostKey::from($this->developer->getAuthIdentifier());
    $this->service(DeveloperSettings::class)->setHours($key, 'UTC', '13:00', '17:00', false);
    $seat = $this->service(Seats::class)->forDeveloper($key)[0];
    $this->service(PlacementWaivers::class)->grant($key, $seat->id, PlacementRule::AssignmentHours);

    // The ticket is unknown, which the waiver does not cover
    expect(refusedOn(fn (): Outcome => placeOnLane($this, ticketTask($this))))->toBe([PlacementRule::TicketOpen])
        ->and(PlacementWaiver::query()->sole()->consumed_at)->toBeNull();
});

it("refuses a waiver from anyone but the seat's own developer", function (): void {
    $key = HostKey::from($this->developer->getAuthIdentifier());
    $seat = $this->service(Seats::class)->forDeveloper($key)[0];

    // The coordinator's developer, through the store
    expect($this->service(PlacementWaivers::class)->grant('77', $seat->id, PlacementRule::AssignmentHours))->toBe(Outcome::Forbidden)
        ->and(PlacementWaiver::query()->count())->toBe(0);

    // And nothing an agent session reaches calls `grant()`: its only caller in `src/` is the
    // dashboard's seat page, which reads the developer off the web guard. The scan is shown able to
    // find a caller -- the page itself -- so its finding nothing else is evidence.
    $callers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src')) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php'
            && str_contains((string) file_get_contents($file->getPathname()), 'PlacementWaivers::class)->grant(')) {
            $callers[] = basename($file->getPathname());
        }
    }

    expect($callers)->toBe(['SeatSettings.php']);
});

it("grants and withdraws a waiver from the seat's own page", function (): void {
    $key = HostKey::from($this->developer->getAuthIdentifier());
    $seat = $this->service(Seats::class)->forDeveloper($key)[0];

    Livewire::actingAs($this->developer)->test(SeatSettings::class)
        ->call('waive', $seat->id, 'lane_not_parked')
        ->assertSet('notice', null)
        ->assertSee('Withdraw waiver');

    expect($this->service(PlacementWaivers::class)->waivedOn($seat->id))->toBe([PlacementRule::LaneNotParked]);

    Livewire::actingAs($this->developer)->test(SeatSettings::class)->call('withdrawWaiver', $seat->id, 'lane_not_parked');

    expect($this->service(PlacementWaivers::class)->waivedOn($seat->id))->toBeEmpty();

    Livewire::actingAs($this->developer)->test(SeatSettings::class)->call('waive', $seat->id, 'no_such_rule')->assertStatus(422);
});

it("refuses a waiver on another developer's seat through the page, whatever seat id the client sends", function (): void {
    $seat = $this->service(Seats::class)->forDeveloper(HostKey::from($this->developer->getAuthIdentifier()))[0];

    $this->setAccessLists(developers: [4242, 77, 5152]);
    $other = $this->enrollDeveloper(5152, login: 'someoneelse');

    Livewire::actingAs($other)->test(SeatSettings::class)
        ->call('waive', $seat->id, 'assignment_hours')
        ->assertSet('notice', "Only the developer who parked a seat can lift it, and only a seat's own developer can change it.");

    expect(PlacementWaiver::query()->count())->toBe(0);
});

it('answers a refused placement from the tool as an error naming the rule', function (): void {
    $taskId = ticketTask($this);

    $response = $this->machine($this->coordinatorToken)->postJson('/robot-council/api/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'task_reassign', 'arguments' => ['task_id' => $taskId, 'session_id' => $this->session->getKey(), 'directive' => 'Take this.']],
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and((string) json_encode($response->json('result.content')))->toContain(PlacementRule::TicketOpen->reads())
        ->and(taskRow($taskId)->claimed_by)->toBeNull();
});

it('warns without blocking, and says why', function (): void {
    knownIssue(12);
    GitHubItem::query()->where('number', 12)->update(['checkboxes' => 2, 'checkboxes_ticked' => 2]);
    $taskId = ticketTask($this, title: 'Rotate the signing key and publish it');

    $response = $this->machine($this->coordinatorToken)->postJson(
        route('robot-council.tasks.transition', ['task' => $taskId, 'transition' => 'reassign']),
        ['session_id' => $this->session->getKey(), 'directive' => 'Take this.']
    )->assertOk();

    expect($response->json('warnings'))->toBe([
        'The title says "publish", which needs a human whatever the labels say.',
        'The title says "rotate", which needs a human whatever the labels say.',
        'Every acceptance criterion is ticked and the ticket is still open. Read it before placing it.',
    ])->and(taskRow($taskId)->claimed_by)->toBe($this->session->getKey());
});

it('answers a refusal at the endpoint with every rule and its reason', function (): void {
    $taskId = ticketTask($this);

    $this->machine($this->coordinatorToken)->postJson(
        route('robot-council.tasks.transition', ['task' => $taskId, 'transition' => 'reassign']),
        ['session_id' => $this->session->getKey(), 'directive' => 'Take this.']
    )->assertUnprocessable()->assertExactJson([
        'task_id' => $taskId,
        'status' => null,
        'applied' => false,
        'refused' => [['rule' => 'ticket_open', 'reason' => PlacementRule::TicketOpen->reads()]],
    ]);

    expect(taskRow($taskId)->status)->toBe(TaskStatus::Pending);
});

it('re-reads presence at placement, refusing a lane that went between the read and the write', function (): void {
    knownIssue(12);
    $taskId = ticketTask($this);

    // The caller still holds the model it read while the session was live
    $stale = $this->session;
    $this->markSessionGone(AgentSession::query()->whereKey($stale->getKey())->firstOrFail());

    expect(placeOnLane($this, $taskId, lane: $stale))->toBe(Outcome::Conflict)
        ->and(taskRow($taskId)->claimed_by)->toBeNull();
});
