<?php

declare(strict_types=1);

/**
 * The placement warnings #320 deferred (#344): work already started on a branch, and documentation
 * placed ahead of functionality.
 *
 * @command  vendor/bin/pest --compact tests/PlacementWarningsTest.php
 */

use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\GitHubState;
use RobotCouncil\Support\PlacementRules;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77]);

    $installation = $this->approveInstallation($this->enrollDeveloper(4242, login: 'octodev'));
    $this->lane = $this->service(AgentSessions::class)->start($installation, 'robot-council/core', 'a')->owner;

    [$this->coordinatorSession, $this->coordinatorToken] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );
});

/**
 * A `create` or `delete` delivery applied to the store.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $event  `create` or `delete`.
 * @param  string  $ref  The ref's name.
 * @param  string  $type  `branch` or `tag`.
 * @param  string  $repository  The repository.
 */
function refDelivery(TestCase $case, string $event, string $ref, string $type = 'branch', string $repository = 'robot-council/core'): void
{
    $case->service(GitHubState::class)->receive(bin2hex(random_bytes(8)), $event, [
        'ref' => $ref, 'ref_type' => $type, 'repository' => ['full_name' => $repository],
    ]);
}

/**
 * An issue or pull request the fleet knows about.
 *
 * @param  int  $number  Its number.
 * @param  array<string, mixed>  $extra  Fields to override.
 */
function knownItem(int $number, array $extra = []): void
{
    GitHubItem::query()->insert([
        'repository' => 'robot-council/core', 'number' => $number, 'is_pull_request' => false, 'state' => 'open',
        'title' => 'Item '.$number, 'labels' => '[]', 'github_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ...$extra,
    ]);
}

/**
 * The warnings a placement of a ticket returns.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $issue  The ticket.
 * @return list<string> The warnings.
 */
function warningsFor(TestCase $case, string $issue = 'robot-council/core#318'): array
{
    $task = $case->service(Tasks::class)->create($case->coordinatorSession, ['title' => 'Build it', 'issue' => $issue], true);

    return $case->service(PlacementRules::class)->warnings($task);
}

it('records a branch from a create delivery and forgets it on delete, and ignores tags and unusable names', function (): void {
    refDelivery($this, 'create', '318-webhook');
    refDelivery($this, 'create', 'v1.0.0', 'tag');
    refDelivery($this, 'create', 'bad..name');

    expect(DB::table('robot_council_github_branches')->pluck('name')->all())->toBe(['318-webhook']);

    refDelivery($this, 'delete', '318-webhook');

    expect(DB::table('robot_council_github_branches')->count())->toBe(0);
});

it('warns when a branch carrying the ticket number exists with no open pull request', function (): void {
    knownItem(318);
    refDelivery($this, 'create', 'feature/318-webhook');

    expect(warningsFor($this))->toContain(
        "A branch whose name carries this ticket's number exists with no open pull request -- the work may already have started: feature/318-webhook. Matched by name, so it may be unrelated."
    );
});

it('does not warn once a pull request is open from the branch', function (): void {
    knownItem(318);
    refDelivery($this, 'create', '318-webhook');
    knownItem(400, ['is_pull_request' => true, 'head_ref' => '318-webhook']);

    expect(warningsFor($this))->toBeEmpty();
});

it("matches the number as a whole token, and only in the ticket's repository", function (): void {
    knownItem(318);
    refDelivery($this, 'create', '3180-other');
    refDelivery($this, 'create', 'x-1318');
    refDelivery($this, 'create', '318-elsewhere', repository: 'robot-council/cli');

    expect(warningsFor($this))->toBeEmpty();
});

it('warns when documentation is placed while functionality tickets are placeable in the repository', function (): void {
    knownItem(318, ['labels' => json_encode(['documentation'])]);
    knownItem(319, ['labels' => json_encode(['development'])]);
    knownItem(320, ['labels' => json_encode(['documentation'])]);
    knownItem(321, ['state' => 'closed']);

    expect(warningsFor($this))->toContain(
        'This is documentation, and 1 functionality ticket(s) are placeable in robot-council/core: robot-council/core#319. The service cannot know why they were passed over; their blind spots are on the shortlist.'
    );
});

it('does not warn about documentation when nothing else is placeable, or when the ticket is not documentation', function (): void {
    knownItem(318, ['labels' => json_encode(['documentation'])]);

    expect(warningsFor($this))->toBeEmpty();

    knownItem(319, ['labels' => json_encode(['development'])]);
    knownItem(320, ['labels' => json_encode(['development'])]);

    // Another functionality ticket is placeable, so only the label check keeps this quiet
    expect(warningsFor($this, 'robot-council/core#319'))->toBeEmpty();
});
