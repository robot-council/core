<?php

declare(strict_types=1);

/**
 * Importing `blocked_by` edges an operator read with `gh api graphql` (#569).
 *
 * The webhook records an edge only when it changes, so an edge older than the webhook was never
 * recorded and its ticket read as unblocked. These pin that an imported edge blocks exactly as a
 * delivered one does, that the import only ever adds, and that it fails rather than storing part of
 * a file it cannot trust.
 *
 * @command  vendor/bin/pest --compact tests/GitHubBlockerImportTest.php
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\GitHubState;
use RobotCouncil\Support\PlacementRules;
use RobotCouncil\Support\Shortlist;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77]);

    $installation = $this->approveInstallation($this->enrollDeveloper(4242, login: 'octodev'));
    $this->service(AgentSessions::class)->start($installation, 'robot-council/core', 'a');

    [$this->coordinatorSession] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );
});

/**
 * Store an issue as the item import would.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $number  The issue.
 * @param  list<string>  $labels  Its labels.
 * @param  string  $state  `open` or `closed`.
 * @param  string  $repository  Its repository.
 */
function importedIssue(TestCase $case, int $number, array $labels = ['development'], string $state = 'open', string $repository = 'robot-council/core'): void
{
    $case->service(GitHubState::class)->import([
        'number' => $number, 'state' => $state, 'title' => 'Ticket '.$number, 'body' => null,
        'labels' => array_map(static fn (string $label): array => ['name' => $label], $labels),
        'updated_at' => '2026-10-07T12:00:00Z', 'repository_url' => 'https://api.github.com/repos/'.$repository,
    ]);
}

/**
 * One page of the query the README documents, in the shape `gh api graphql` writes it.
 *
 * @param  array<int, list<array{string, int}>>  $issues  Each open issue's blockers, as repository and number.
 * @param  bool  $hasNextPage  Whether GitHub said another page follows.
 * @param  string  $repository  The repository the page is about.
 * @param  int|null  $totalCount  What every issue says its full blocker count is, where that is not
 *                                the number listed: a list cut short.
 * @return array<string, mixed> The page.
 */
function blockerPage(array $issues, bool $hasNextPage = false, string $repository = 'robot-council/core', ?int $totalCount = null): array
{
    $nodes = [];

    foreach ($issues as $number => $blockers) {
        $nodes[] = ['number' => $number, 'blockedBy' => [
            'totalCount' => $totalCount ?? \count($blockers),
            'nodes' => array_map(static fn (array $blocker): array => ['number' => $blocker[1], 'repository' => ['nameWithOwner' => $blocker[0]]], $blockers),
        ]];
    }

    return ['data' => ['repository' => [
        'nameWithOwner' => $repository,
        'issues' => ['pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $hasNextPage ? 'Y3Vyc29y' : null], 'nodes' => $nodes],
    ]]];
}

/**
 * Write pages to a file and import it.
 *
 * @param  string  $directory  Where to write it.
 * @param  mixed  $pages  What the file holds.
 * @return array{int, string} The exit code and the output.
 */
function importBlockers(string $directory, mixed $pages): array
{
    $file = $directory.'/blockers.json';
    file_put_contents($file, json_encode($pages, JSON_THROW_ON_ERROR));

    $code = Artisan::call('robot-council:github-import-blockers', ['file' => $file]);

    return [$code, Artisan::output()];
}

/**
 * Every stored edge, as `blocked<-blocker`.
 *
 * @return list<string> The edges, sorted.
 */
function storedEdges(): array
{
    $edges = DB::table('robot_council_github_blockers')->get()
        ->map(static fn (object $edge): string => sprintf('%s#%s<-%s#%s', stringValue($edge->repository ?? null), keyValue($edge->number ?? null), stringValue($edge->blocker_repository ?? null), keyValue($edge->blocker_number ?? null)))
        ->sort()->values()->all();

    /** @var list<string> $edges */
    return $edges;
}

it('keeps a ticket blocked only by an imported edge off the shortlist and out of the documentation warning, and one whose blocker is closed on both', function (): void {
    importedIssue($this, 318, ['documentation']);
    importedIssue($this, 319);
    importedIssue($this, 321);
    importedIssue($this, 400);
    importedIssue($this, 401, state: 'closed');

    // Before: nothing recorded, so #319 reads as unblocked -- the defect #569 was filed about
    $task = $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Document it', 'issue' => 'robot-council/core#318'], true);

    expect($this->service(PlacementRules::class)->warnings($task))->toContain(
        'This is documentation, and 3 functionality ticket(s) are placeable in robot-council/core: robot-council/core#319, robot-council/core#321, robot-council/core#400. The service cannot know why they were passed over; their blind spots are on the shortlist.'
    );

    [$code] = importBlockers($this->temporaryDirectory('github-import-blockers'), [blockerPage([
        319 => [['robot-council/core', 400]],
        321 => [['robot-council/core', 401]],
    ])]);

    $listed = array_column($this->service(Shortlist::class)->read()['robot-council/core'] ?? [], 'ticket');

    expect($code)->toBe(0)
        ->and($listed)->not->toContain('robot-council/core#319')
        ->and($listed)->toContain('robot-council/core#321')
        ->and($this->service(PlacementRules::class)->warnings($task))->toContain(
            'This is documentation, and 2 functionality ticket(s) are placeable in robot-council/core: robot-council/core#321, robot-council/core#400. The service cannot know why they were passed over; their blind spots are on the shortlist.'
        );
});

it('stores an edge to another repository under that repository', function (): void {
    [$code, $output] = importBlockers($this->temporaryDirectory('github-import-blockers'), [blockerPage([546 => [['pestphp/pest', 1944], ['robot-council/core', 545]]])]);

    expect($code)->toBe(0)
        ->and(storedEdges())->toBe(['robot-council/core#546<-pestphp/pest#1944', 'robot-council/core#546<-robot-council/core#545'])
        ->and($output)->toContain('Read 1 open issue(s) on 1 page(s). Stored 2 new edge(s); 0 already recorded; 0 refused.');
});

it('reads every page of a paginated file, and a single page written without --slurp', function (): void {
    [$code] = importBlockers($this->temporaryDirectory('github-import-blockers'), [blockerPage([10 => [['robot-council/core', 1]]], hasNextPage: true), blockerPage([20 => [['robot-council/core', 2]]])]);

    expect($code)->toBe(0)
        ->and(storedEdges())->toBe(['robot-council/core#10<-robot-council/core#1', 'robot-council/core#20<-robot-council/core#2']);

    [$single] = importBlockers($this->temporaryDirectory('github-import-blockers'), blockerPage([30 => [['robot-council/core', 3]]]));

    expect($single)->toBe(0)
        ->and(storedEdges())->toContain('robot-council/core#30<-robot-council/core#3');
});

it('adds no duplicate on a second run, and says the edges were already recorded', function (): void {
    $pages = [blockerPage([319 => [['robot-council/core', 400], ['robot-council/core', 401]]])];

    importBlockers($this->temporaryDirectory('github-import-blockers'), $pages);
    [$code, $output] = importBlockers($this->temporaryDirectory('github-import-blockers'), $pages);

    expect($code)->toBe(0)
        ->and(DB::table('robot_council_github_blockers')->count())->toBe(2)
        ->and($output)->toContain('Stored 0 new edge(s); 2 already recorded; 0 refused.');
});

it('does not bring back an edge removed on GitHub, and removes nothing a file leaves out', function (): void {
    importBlockers($this->temporaryDirectory('github-import-blockers'), [blockerPage([319 => [['robot-council/core', 400], ['robot-council/core', 401]]])]);

    // The edge to #400 is removed on GitHub, and the webhook says so
    $this->service(GitHubState::class)->receive('removal', 'issue_dependencies', [
        'action' => 'blocked_by_removed',
        'blocked_issue' => ['number' => 319, 'repository_url' => 'https://api.github.com/repos/robot-council/core'],
        'blocking_issue' => ['number' => 400, 'repository_url' => 'https://api.github.com/repos/robot-council/core'],
        'repository' => ['full_name' => 'robot-council/core'],
    ]);

    // A file read afterwards lists only what GitHub still has, and one that leaves #319 out
    // altogether removes nothing: the import only adds, and a stale edge fails closed (#341)
    importBlockers($this->temporaryDirectory('github-import-blockers'), [blockerPage([319 => [['robot-council/core', 401]]])]);
    importBlockers($this->temporaryDirectory('github-import-blockers'), [blockerPage([500 => []])]);

    expect(storedEdges())->toBe(['robot-council/core#319<-robot-council/core#401']);
});

it('fails on a GraphQL refusal, which arrives as a page with errors and no data, and stores nothing from it', function (): void {
    // The refused page first, so a page after it is still read
    [$code, $output] = importBlockers($this->temporaryDirectory('github-import-blockers'), [
        ['errors' => [['type' => 'RATE_LIMITED', 'message' => 'API rate limit exceeded']]],
        blockerPage([20 => [['robot-council/core', 2]]]),
    ]);

    expect($code)->toBe(1)
        ->and(storedEdges())->toBe(['robot-council/core#20<-robot-council/core#2'])
        ->and($output)->toContain('Page 1 carries no repository, or carries errors: [{"type":"RATE_LIMITED"')
        ->and($output)->toContain('Read 1 open issue(s) on 2 page(s). Stored 1 new edge(s); 0 already recorded; 1 refused.');

    // GraphQL also answers with part of the data beside an error; that page is refused whole
    $partial = blockerPage([319 => [['robot-council/core', 400]]]);
    $partial['errors'] = [['type' => 'NOT_FOUND', 'message' => 'Could not resolve to an issue.']];

    [$partialCode] = importBlockers($this->temporaryDirectory('github-import-blockers'), [$partial]);

    expect($partialCode)->toBe(1)
        ->and(storedEdges())->toBe(['robot-council/core#20<-robot-council/core#2']);
});

it('fails on a blocker list cut short, keeping the edges it does list', function (): void {
    $page = blockerPage([319 => [['robot-council/core', 400], ['robot-council/core', 401]]], totalCount: 3);

    [$code, $output] = importBlockers($this->temporaryDirectory('github-import-blockers'), [$page]);

    expect($code)->toBe(1)
        ->and(storedEdges())->toHaveCount(2)
        ->and($output)->toContain('#319 lists 2 of its blockers')
        ->and($output)->toContain('Stored 2 new edge(s); 0 already recorded; 1 refused.');
});

it('fails on a file whose last page still has a next one', function (): void {
    [$code, $output] = importBlockers($this->temporaryDirectory('github-import-blockers'), [blockerPage([319 => [['robot-council/core', 400]]], hasNextPage: true)]);

    expect($code)->toBe(1)
        ->and($output)->toContain('The last page of `robot-council/core` has a next page, so the file was not read to the end')
        ->and($output)->toContain('Stored 1 new edge(s); 0 already recorded; 1 refused.');
});

it('refuses a blocker it cannot name, a file it cannot read, and one that is not JSON', function (): void {
    $directory = $this->temporaryDirectory('github-import-blockers');

    [$code, $output] = importBlockers($directory, [blockerPage([319 => [['not a repository', 400], ['robot-council/core', 0], ['robot-council/core', 7]]])]);

    expect($code)->toBe(1)
        ->and(storedEdges())->toBe(['robot-council/core#319<-robot-council/core#7'])
        ->and($output)->toContain('A delivery names its repository as owner/name.')
        ->and($output)->toContain('An item carries a positive number.')
        ->and($output)->toContain('Stored 1 new edge(s); 0 already recorded; 2 refused.')
        ->and(Artisan::call('robot-council:github-import-blockers', ['file' => '/definitely/not/here.json']))->toBe(1)
        ->and(Artisan::output())->toContain('Cannot read `/definitely/not/here.json`.');

    file_put_contents($directory.'/not.json', 'not json');

    expect(Artisan::call('robot-council:github-import-blockers', ['file' => $directory.'/not.json']))->toBe(1)
        ->and(Artisan::output())->toContain('The file is not JSON.');
});
