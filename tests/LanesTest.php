<?php

declare(strict_types=1);

/**
 * The lane board page (#317): five states, a separate watcher column, links only for qualified
 * references, and a dash for a count nobody measured.
 *
 * The board is asserted through `Support\LaneBoard` where the claim is about a value, and through the
 * rendered page where it is about what a developer sees -- a Livewire `assertSee` passes on text in
 * any part of the page, so the page assertions read a marked element rather than the whole body.
 *
 * @command  vendor/bin/pest --compact tests/LanesTest.php
 */

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Models\Seat;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\LaneBoard;
use RobotCouncil\Support\LaneHolds;
use RobotCouncil\Support\Seats;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Support\TicketLink;
use RobotCouncil\Tests\Fixtures\HostileContent;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $this->installation = $this->approveInstallation($this->developer);

    [$this->coordinatorSession, $this->coordinatorToken] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );
});

/**
 * A live lane in a repository.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $location  Its work location.
 * @return AgentSession The lane.
 */
function boardLane(TestCase $case, string $location): AgentSession
{
    return $case->service(AgentSessions::class)->start($case->installation, 'robot-council/core', $location)->owner;
}

/**
 * The board row for a lane.
 *
 * @param  TestCase  $case  The test case.
 * @param  AgentSession  $lane  The lane.
 * @return array<string, mixed> Its row.
 */
function boardRow(TestCase $case, AgentSession $lane): array
{
    foreach ($case->service(LaneBoard::class)->read()['lanes'] as $group) {
        foreach ($group as $row) {
            if ($row['id'] === $lane->id) {
                return $row;
            }
        }
    }

    throw new RuntimeException('The lane is not on the board.');
}

it('renders each lane in exactly one of the five states', function (): void {
    $key = HostKey::from($this->developer->getAuthIdentifier());

    $working = boardLane($this, 'a');
    $idle = boardLane($this, 'b');
    $parked = boardLane($this, 'c');
    $blocked = boardLane($this, 'd');
    $unobserved = boardLane($this, 'e');

    $task = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Work'], true);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Reassign, $this->coordinatorSession, true, $working, directive: 'Take this.');

    $seat = collect($this->service(Seats::class)->forDeveloper($key))->firstWhere('work_location', 'c');
    $this->service(Seats::class)->park($key, $seat instanceof Seat ? $seat->id : 0);

    $this->service(LaneHolds::class)->hold($this->coordinatorSession, $blocked->id, 'octodev', HoldReason::Decision);

    AgentSession::query()->whereKey($unobserved->id)->update(['status' => 'stale']);

    expect(boardRow($this, $working)['state'])->toBe('Working')
        ->and(boardRow($this, $idle)['state'])->toBe('Idle')
        ->and(boardRow($this, $parked)['state'])->toBe('Parked')
        ->and(boardRow($this, $blocked)['state'])->toBe('Blocked')
        ->and(boardRow($this, $blocked)['on_what'])->toBe(['party' => 'octodev', 'what' => 'a decision'])
        ->and(boardRow($this, $unobserved)['state'])->toBe('not observed');

    $states = [];

    foreach ($this->service(LaneBoard::class)->read()['lanes'] as $group) {
        foreach ($group as $row) {
            $states[] = $row['state'];
        }
    }

    expect($states)->each->toBeIn(LaneBoard::STATES);
});

it('never renders a lane with no task as Working, whatever else is recorded for it', function (): void {
    $lane = boardLane($this, 'a');

    // A placement recorded on a task that is no longer held: the lane holds nothing
    $task = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Done'], true);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Reassign, $this->coordinatorSession, true, $lane, directive: 'Take this.');
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Complete, $lane, false);

    expect(boardRow($this, $lane)['state'])->toBe('Idle');
});

it('keeps the watcher its own column, so a working lane with no watcher reads as exactly that', function (): void {
    $lane = boardLane($this, 'a');
    $task = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Work'], true);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Reassign, $this->coordinatorSession, true, $lane, directive: 'Take this.');

    $row = boardRow($this, $lane);

    expect($row['state'])->toBe('Working')
        ->and($row['watcher'])->toBeNull();

    $html = Livewire::actingAs($this->developer)->test(Lanes::class)->html();

    expect($html)->toContain('data-state="Working"')
        ->and($html)->toContain('not reported');
});

it('links a repository-qualified reference and never a bare number', function (): void {
    expect(TicketLink::url('robot-council/core#318'))->toBe('https://github.com/robot-council/core/issues/318')
        ->and(TicketLink::url('#318'))->toBeNull()
        ->and(TicketLink::url('318'))->toBeNull()
        ->and(TicketLink::url(null))->toBeNull()
        // A value `IssueReference` refuses is not a link either, whatever it resembles
        ->and(TicketLink::url('javascript:alert(1)#1'))->toBeNull();

    $lane = boardLane($this, 'a');
    $task = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Work', 'issue' => 'robot-council/core#318'], true);

    GitHubItem::query()->insert([
        'repository' => 'robot-council/core', 'number' => 318, 'is_pull_request' => false, 'state' => 'open',
        'title' => 'Issue', 'labels' => '[]', 'github_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->service(Tasks::class)->transition($task->id, TaskTransition::Reassign, $this->coordinatorSession, true, $lane, directive: 'Take this.');

    expect(Livewire::actingAs($this->developer)->test(Lanes::class)->html())
        ->toContain('href="https://github.com/robot-council/core/issues/318"');
});

it('renders an unmeasured count as a dash, never a number', function (): void {
    boardLane($this, 'a');

    expect($this->service(LaneBoard::class)->read()['meters'])->toBe(['robot-council/core' => ['count' => null, 'delta' => null]]);

    $html = Livewire::actingAs($this->developer)->test(Lanes::class)->html();

    // The dash path, reached: the marked element exists, and the read path's does not
    expect($html)->toContain('data-meter="unreadable"')
        ->and($html)->not->toContain('data-meter="read"')
        ->and($html)->toContain('count unreadable');
});

it('shows take-up apart from placement, the branch, and where the placement came from', function (): void {
    $lane = boardLane($this, 'a');
    $task = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Work'], true);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Reassign, $this->coordinatorSession, true, $lane, directive: 'Take this.');

    $placed = boardRow($this, $lane)['on_what'];

    expect($placed)->toMatchArray(['taken_up' => false, 'branch' => 'branch not reported', 'provenance' => 'placed and told', 'hand_back' => false]);

    $this->service(Tasks::class)->transition($task->id, TaskTransition::Start, $lane, false, branch: 'feature/lanes');

    expect(boardRow($this, $lane)['on_what'])->toMatchArray(['taken_up' => true, 'branch' => 'feature/lanes']);
});

it('escapes a hostile machine label and repository on the board', function (string $payload, array $forbidden, ?string $escaped): void {
    expect(mb_strlen($payload))->toBeLessThanOrEqual(64);

    $this->installation->forceFill(['machine_label' => $payload])->save();
    boardLane($this, 'a');

    $html = Livewire::actingAs($this->developer)->test(Lanes::class)->html();

    expect($html)->toContain($escaped ?? $payload);

    foreach ($forbidden as $live) {
        expect($html)->not->toContain($live);
    }
})->with(HostileContent::dataset());

it('costs the same queries however many lanes it lists', function (): void {
    $key = HostKey::from($this->developer->getAuthIdentifier());

    // Lanes that each hold a task naming an issue and sit in a recorded seat, so every per-lane
    // lookup the board makes is exercised
    $addWorkingLanes = function (string ...$locations) use ($key): void {
        foreach ($locations as $location) {
            $lane = boardLane($this, $location);
            $task = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Work', 'issue' => 'robot-council/core#'.(100 + ord($location))], true);
            $this->service(Tasks::class)->transition($task->id, TaskTransition::Reassign, $this->coordinatorSession, true, $lane, directive: 'Take this.');
        }

        $this->service(Seats::class)->forDeveloper($key);
    };

    $addWorkingLanes('a', 'b');
    $two = queriesIssuedBy(fn (): array => $this->service(LaneBoard::class)->read());

    $addWorkingLanes('c', 'd', 'e', 'f');
    $six = queriesIssuedBy(fn (): array => $this->service(LaneBoard::class)->read());

    expect(DB::table('robot_council_seats')->count())->toBe(6)
        ->and($six)->toBe($two);
});
