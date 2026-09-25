<?php

declare(strict_types=1);

/**
 * The unranked shortlist of placeable tickets (#321).
 *
 * @command  vendor/bin/pest --compact tests/ShortlistTest.php
 */

use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\GitHubState;
use RobotCouncil\Support\Shortlist;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77]);

    $installation = $this->approveInstallation($this->enrollDeveloper(4242, login: 'octodev'));
    [$this->session, $this->token] = $this->startAgentSession($installation);

    [$this->coordinatorSession, $this->coordinatorToken] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );
});

/**
 * Store an issue as the operator's import would.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $number  The issue.
 * @param  array<string, mixed>  $extra  Fields to override.
 * @param  string  $repository  Its repository.
 */
function ticket(TestCase $case, int $number, array $extra = [], string $repository = 'robot-council/core'): void
{
    $case->service(GitHubState::class)->import([
        'number' => $number, 'state' => 'open', 'title' => 'Ticket '.$number, 'labels' => [], 'body' => null,
        'updated_at' => '2026-09-24T12:00:00Z', 'repository_url' => 'https://api.github.com/repos/'.$repository,
        ...$extra,
    ]);
}

/**
 * The shortlist's tickets for a repository, in order.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $repository  The repository.
 * @return list<string> The references.
 */
function listed(TestCase $case, string $repository = 'robot-council/core'): array
{
    return array_column($case->service(Shortlist::class)->read()[$repository] ?? [], 'ticket');
}

it('groups by repository and orders by number, not by anything a priority could move', function (): void {
    // The higher number is labeled the more urgent, so an order that tracked priority would invert
    ticket($this, 5, ['labels' => [['name' => 'priority: low']]]);
    ticket($this, 9, ['labels' => [['name' => 'priority: critical'], ['name' => 'fleet-facing']]]);
    ticket($this, 3, [], 'robot-council/cli');

    $read = $this->service(Shortlist::class)->read();

    expect(array_keys($read))->toBe(['robot-council/cli', 'robot-council/core'])
        ->and(listed($this))->toBe(['robot-council/core#5', 'robot-council/core#9']);
});

it('leaves out a ticket a placement would refuse, and one a lane already holds', function (): void {
    ticket($this, 1);
    ticket($this, 2, ['state' => 'closed']);
    ticket($this, 3);
    DB::table('robot_council_github_blockers')->insert(['repository' => 'robot-council/core', 'number' => 3, 'blocker_repository' => 'robot-council/core', 'blocker_number' => 1]);
    ticket($this, 4);

    $task = $this->service(Tasks::class)->create($this->session, ['title' => 'Held', 'issue' => 'robot-council/core#4'], false);
    $this->service(Tasks::class)->transition($task->id, TaskTransition::Claim, $this->session, false);

    // #1 is open and unblocked; #2 closed; #3 blocked by the open #1; #4 held
    expect(listed($this))->toBe(['robot-council/core#1']);
});

it('shows each entry with its blind spots', function (): void {
    ticket($this, 7, [
        'title' => 'Rotate the signing key',
        'labels' => [['name' => 'hitl']],
        'body' => "Touches `src/Support/Tasks.php`.\n\n- [x] one\n- [x] two\n",
    ]);

    $entry = $this->service(Shortlist::class)->read()['robot-council/core'][0];

    expect($entry['ticket'])->toBe('robot-council/core#7')
        ->and($entry['mentioned_paths'])->toBe(['src/Support/Tasks.php'])
        ->and($entry['blind_spots'])->toBe([
            'It is labeled hitl: completing it needs a human decision or action.',
            'The title says "rotate", which needs a human whatever the labels say.',
            'Every acceptance criterion is ticked and the ticket is still open.',
            'The paths it mentions are unverified: they say nothing about whether it collides with other work.',
        ]);
});

it('lists two tickets that mention the same path, and reports no collision between them', function (): void {
    ticket($this, 10, ['body' => 'Edits README-adjacent `docs/setup.md`.']);
    ticket($this, 11, ['body' => 'Also mentions docs/setup.md, under Out of scope.']);

    $read = $this->service(Shortlist::class)->read()['robot-council/core'];

    expect(array_column($read, 'ticket'))->toBe(['robot-council/core#10', 'robot-council/core#11'])
        ->and(array_column($read, 'mentioned_paths'))->toBe([['docs/setup.md'], ['docs/setup.md']])
        // Nothing in an entry compares it with another
        ->and(array_keys($read[0]))->toBe(['ticket', 'title', 'labels', 'mentioned_paths', 'blind_spots']);
});

it('extracts paths from a body, but not from a URL, and not a bare file name', function (): void {
    ticket($this, 12, ['body' => 'See https://github.com/robot-council/core/blob/main/README.md, README.md, and `tests/ShortlistTest.php`.']);

    expect($this->service(Shortlist::class)->read()['robot-council/core'][0]['mentioned_paths'])->toBe(['tests/ShortlistTest.php']);
});

it('serves the shortlist to a coordinator and refuses anyone else', function (): void {
    ticket($this, 1);

    $this->machine($this->coordinatorToken)->getJson(route('robot-council.shortlist'))
        ->assertOk()
        ->assertJsonPath('repositories.robot-council/core.0.ticket', 'robot-council/core#1');

    $this->machine($this->token)->getJson(route('robot-council.shortlist'))->assertForbidden();
});
