<?php

declare(strict_types=1);

/**
 * The unranked shortlist of placeable tickets (#321).
 *
 * @command  vendor/bin/pest --compact tests/ShortlistTest.php
 */

use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\BacklogMembers;
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

/**
 * The shortlist's filter for each repository, without the qualifiers.
 *
 * @param  TestCase  $case  The test case.
 * @return array<string, string> Each repository's status.
 */
function filterStatuses(TestCase $case): array
{
    return array_map(static fn (array $filter): string => $filter['status'], $case->service(Shortlist::class)->report()['filters']);
}

it('lists every placeable ticket, unfiltered, while no search qualifiers are set', function (mixed $unset): void {
    config()->set('robot-council.backlog.search_qualifiers', $unset);
    ticket($this, 1);
    ticket($this, 2);
    ticket($this, 3, [], 'robot-council/cli');

    // A set stored for a repository is not read while nothing configures qualifiers for it
    $this->service(BacklogMembers::class)->record('robot-council/core', 'project:robot-council/1', [1]);

    expect(listed($this))->toBe(['robot-council/core#1', 'robot-council/core#2'])
        ->and(listed($this, 'robot-council/cli'))->toBe(['robot-council/cli#3'])
        ->and($this->service(Shortlist::class)->report()['filters'])->toBe([
            'robot-council/cli' => ['status' => 'unfiltered', 'qualifiers' => null, 'reason' => null],
            'robot-council/core' => ['status' => 'unfiltered', 'qualifiers' => null, 'reason' => null],
        ]);
})->with([
    'null' => [null],
    'an empty array' => [[]],
    'another owner only' => [['UAMS-Web' => 'project:UAMS-Web/1']],
]);

it("keeps only the tickets an owner's qualifiers matched, and names the qualifiers", function (): void {
    config()->set('robot-council.backlog.search_qualifiers', ['robot-council' => 'project:robot-council/1']);

    foreach ([1, 2, 3, 4] as $number) {
        ticket($this, $number);
    }

    // Matched but closed since, and matched but outside the mirror: neither is listed
    ticket($this, 5, ['state' => 'closed']);
    $this->service(BacklogMembers::class)->record('Robot-Council/Core', 'project:robot-council/1', [1, 3, 5, 99]);

    expect(listed($this))->toBe(['robot-council/core#1', 'robot-council/core#3'])
        ->and($this->service(Shortlist::class)->report()['filters']['robot-council/core'])->toBe(['status' => 'filtered', 'qualifiers' => 'project:robot-council/1', 'reason' => null]);
});

it("lets a repository's own entry replace its owner's", function (): void {
    config()->set('robot-council.backlog.search_qualifiers', [
        'robot-council' => 'project:robot-council/1',
        'robot-council/cli' => '',
        'robot-council/core' => 'project:robot-council/2',
    ]);

    ticket($this, 1);
    ticket($this, 2);
    ticket($this, 7, [], 'robot-council/cli');
    ticket($this, 8, [], 'robot-council/cli');
    ticket($this, 9, [], 'robot-council/app');

    $members = $this->service(BacklogMembers::class);
    $members->record('robot-council/core', 'project:robot-council/2', [2]);
    $members->record('robot-council/app', 'project:robot-council/1', [9]);

    // The cli's empty entry clears its owner's, so a set stored for it is not read
    $members->record('robot-council/cli', 'project:robot-council/1', [7]);

    expect(listed($this))->toBe(['robot-council/core#2'])
        ->and(listed($this, 'robot-council/cli'))->toBe(['robot-council/cli#7', 'robot-council/cli#8'])
        ->and(listed($this, 'robot-council/app'))->toBe(['robot-council/app#9'])
        ->and(filterStatuses($this))->toBe([
            'robot-council/app' => 'filtered',
            'robot-council/cli' => 'unfiltered',
            'robot-council/core' => 'filtered',
        ]);
});

it('lists nothing for a repository whose matches are not known, and says why, rather than every ticket as if filtered', function (mixed $configured, ?Closure $arrange, string $reason): void {
    config()->set('robot-council.backlog.search_qualifiers', $configured);
    ticket($this, 1);
    ticket($this, 2);
    ticket($this, 3, [], 'robot-council/cli');
    config()->set('robot-council.backlog.search_qualifiers', $configured);

    if ($arrange instanceof Closure) {
        $arrange($this);
    }

    $report = $this->service(Shortlist::class)->report();

    expect($report['repositories'])->not->toHaveKey('robot-council/core')
        ->and($report['filters']['robot-council/core']['status'])->toBe('unresolved')
        ->and($report['filters']['robot-council/core']['reason'])->toContain($reason)
        // The other repository is untouched by its neighbor's state
        ->and(listed($this, 'robot-council/cli'))->toBe(['robot-council/cli#3']);
})->with([
    'refused qualifiers' => [['robot-council/core' => 'is:closed'], null, 'refused'],
    'no fetch has listed them' => [['robot-council/core' => 'project:robot-council/1'], null, 'No fetch has listed'],
    'a set taken with other qualifiers' => [
        ['robot-council/core' => 'project:robot-council/1'],
        fn (TestCase $case) => $case->service(BacklogMembers::class)->record('robot-council/core', 'project:robot-council/2', [1, 2]),
        'No fetch has listed',
    ],
    'a set older than the meter reads' => [
        ['robot-council/core' => 'project:robot-council/1'],
        function (TestCase $case): void {
            $case->service(BacklogMembers::class)->record('robot-council/core', 'project:robot-council/1', [1, 2]);
            $case->travel(61)->minutes();
        },
        'too long ago',
    ],
    'more matches than a search lists' => [
        ['robot-council/core' => 'project:robot-council/1'],
        fn (TestCase $case) => $case->service(BacklogMembers::class)->record('robot-council/core', 'project:robot-council/1', null),
        'more than 1,000',
    ],
]);

it('reads a set fetched within the meter window', function (): void {
    // The control for the stale case: the same set, a minute inside the window, is read
    config()->set('robot-council.backlog.search_qualifiers', ['robot-council/core' => 'project:robot-council/1']);
    ticket($this, 1);
    ticket($this, 2);
    $this->service(BacklogMembers::class)->record('robot-council/core', 'project:robot-council/1', [2]);
    $this->travel(59)->minutes();

    expect(listed($this))->toBe(['robot-council/core#2']);
});

it('serves the filters beside the tickets through the route', function (): void {
    config()->set('robot-council.backlog.search_qualifiers', ['robot-council/cli' => 'project:robot-council/1']);
    ticket($this, 1);
    ticket($this, 3, [], 'robot-council/cli');

    $this->machine($this->coordinatorToken)->getJson(route('robot-council.shortlist'))
        ->assertOk()
        ->assertJsonPath('repositories.robot-council/core.0.ticket', 'robot-council/core#1')
        ->assertJsonMissingPath('repositories.robot-council/cli')
        ->assertJsonPath('filters.robot-council/core.status', 'unfiltered')
        ->assertJsonPath('filters.robot-council/cli.status', 'unresolved')
        ->assertJsonPath('filters.robot-council/cli.qualifiers', 'project:robot-council/1');
});
