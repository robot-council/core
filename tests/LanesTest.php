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

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Livewire\Dashboard;
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

    // The watcher's own cell, found by its marker: "not reported" alone also matches a working
    // lane's "branch not reported", so asserting the text would pass with the column deleted
    expect($html)->toContain('data-state="Working"')
        ->and($html)->toMatch('/<td[^>]*data-watcher[^>]*>\s*not reported\s*<\/td>/');
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

it('escapes a hostile machine label on the board', function (string $payload, array $forbidden, ?string $escaped): void {
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

/**
 * The text of every element carrying a marker attribute.
 *
 * @param  string  $html  The page.
 * @param  string  $marker  The attribute, such as `data-pull-state`.
 * @return list<string> The elements' text, trimmed.
 */
function markedText(string $html, string $marker): array
{
    // Closed by its own tag name, so a link nested inside the element does not end the match early
    preg_match_all('/<([a-z]+)[^>]*\b'.preg_quote($marker, '/').'\b[^>]*>(.*?)<\/\1>/s', $html, $found);

    return array_map(static fn (string $text): string => trim((string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($text)))), $found[2]);
}

it('renders a parked lane and a held one as party and reason', function (): void {
    $key = HostKey::from($this->developer->getAuthIdentifier());
    boardLane($this, 'a');
    $held = boardLane($this, 'b');

    $seat = collect($this->service(Seats::class)->forDeveloper($key))->firstWhere('work_location', 'a');
    $this->service(Seats::class)->park($key, $seat instanceof Seat ? $seat->id : 0);
    $this->service(LaneHolds::class)->hold($this->coordinatorSession, $held->id, 'robot-council/core#9', HoldReason::TicketLands);

    $cells = markedText(Livewire::actingAs($this->developer)->test(Lanes::class)->html(), 'data-on-what');
    sort($cells);

    expect($cells)->toBe(['octodev — parked this seat', 'robot-council/core#9 — that ticket to land']);
});

it('marks a hand-back, and says when a lane holds more than one task', function (): void {
    $lane = boardLane($this, 'a');

    foreach ([true, false] as $handBack) {
        $task = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Work'], true);
        $this->service(Tasks::class)->transition($task->id, TaskTransition::Reassign, $this->coordinatorSession, true, $lane, directive: 'Take this.', handBack: $handBack);
    }

    $html = Livewire::actingAs($this->developer)->test(Lanes::class)->html();

    expect(markedText($html, 'data-hand-back'))->toBe(['hand-back'])
        ->and(markedText($html, 'data-also-holds'))->toBe(['and 1 more held'])
        ->and(boardRow($this, $lane)['on_what'])->toMatchArray(['also_holds' => 1]);
});

it("lists a repository's open pull requests whatever case the lane reported it in", function (): void {
    $this->service(AgentSessions::class)->start($this->installation, 'Robot-Council/Core', 'a');

    foreach ([[40, false], [41, true]] as [$number, $draft]) {
        GitHubItem::query()->insert([
            'repository' => 'robot-council/core', 'number' => $number, 'is_pull_request' => true, 'state' => 'open', 'draft' => $draft,
            'title' => 'Pull '.$number, 'labels' => '[]', 'github_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(markedText(Livewire::actingAs($this->developer)->test(Lanes::class)->html(), 'data-pull-state'))->toBe(['queued', 'draft']);
});

it('stamps the last change, not the read time', function (): void {
    Carbon::setTestNow('2026-09-24 10:00:00');
    $lane = boardLane($this, 'a');
    $this->service(LaneHolds::class)->hold($this->coordinatorSession, $lane->id, 'octodev', HoldReason::Decision);

    Carbon::setTestNow('2026-09-24 12:30:00');

    expect($this->service(LaneBoard::class)->read()['last_change']?->format('H:i'))->toBe('10:00')
        ->and(markedText(Livewire::actingAs($this->developer)->test(Lanes::class)->html(), 'data-last-change')[0] ?? '')
        ->toContain('Last change 2026-09-24 10:00 UTC');
});

it('summarizes the lanes on the overview, counted by the same reader', function (): void {
    boardLane($this, 'a');
    $working = boardLane($this, 'b');
    $task = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Work'], true);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Reassign, $this->coordinatorSession, true, $working, directive: 'Take this.');

    $summary = markedText(Livewire::actingAs($this->developer)->test(Dashboard::class)->html(), 'data-lanes-summary');

    // The coordinator's own session is a lane too, idle
    expect($summary)->toBe(['1 working, 2 idle, 0 parked, 0 blocked, 0 not observed']);
});
