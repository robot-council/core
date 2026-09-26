<?php

declare(strict_types=1);

/**
 * The lane board's On what column for a task that names no issue (#422): the ticket its title
 * begins with, or else the title itself, never `task #N, no ticket`.
 *
 * @command  vendor/bin/pest --compact tests/TicketFromTitleTest.php
 */

use Livewire\Livewire;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\GitHubState;
use RobotCouncil\Support\IssueReference;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Support\TicketLink;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');

    $this->actingAs($this->developer, 'web');
});

it('reads the reference a title begins with, and nothing else', function (?string $title, ?string $reference): void {
    expect(IssueReference::leading($title))->toBe($reference);
})->with([
    "the coordinators' form" => ['UAMS-Web/wordpress-importer#1175: resolve only the referenced attachments', 'UAMS-Web/wordpress-importer#1175'],
    'with leading space' => ['  robot-council/core#422 show the ticket', 'robot-council/core#422'],
    'the whole title' => ['robot-council/core#422', 'robot-council/core#422'],
    'followed by a comma' => ['robot-council/core#422, then the rest', 'robot-council/core#422'],
    // Named later in the title: not the task's ticket
    'mentioned later' => ['Fix robot-council/core#422 on the board', null],
    // A bare number names no repository
    'a bare number' => ['#422: show the ticket', null],
    // Starts like a reference but is not one
    'letters after the number' => ['robot-council/core#422abc do it', null],
    'number zero' => ['robot-council/core#0: nothing', null],
    'no number' => ['robot-council/core: something', null],
    'a dot-only owner' => ['../core#1: escape', null],
    'too long' => [str_repeat('a', 200).'/core#1: long', null],
    // Missed on purpose: the title is shown instead, and nothing is invented
    'a trailing dot' => ['robot-council/core#422. Fix it', null],
    'in backticks' => ['`robot-council/core#422`: show it', null],
    'no title' => [null, null],
    'an empty title' => ['', null],
]);

/**
 * A lane working one task: the session started in a repository, the task claimed and started.
 *
 * @param  array<string, mixed>  $attributes  The task's attributes.
 */
function laneWorking(TestCase $case, array $attributes): int
{
    $session = $case->service(AgentSessions::class)->start($case->approveInstallation($case->developer, 'josh-office'), 'robot-council/core', 'robot-council-core-a')->owner;

    $tasks = $case->service(Tasks::class);
    $task = $tasks->create($session, $attributes, false);
    $tasks->transition($task->id, TaskTransition::Claim, $session, asCoordinator: false);
    $tasks->transition($task->id, TaskTransition::Start, $session, asCoordinator: false, branch: 'work');

    return $task->id;
}

/**
 * What the lane board's On what cell says for the one held task, as text and markup.
 *
 * @return array{text: string, html: string}
 */
function onWhat(): array
{
    $html = Livewire::test(Lanes::class)->html();

    preg_match('/<li [^>]*data-held-task>\s*<div>(.*?)<\/div>/s', $html, $cell);

    return [
        'text' => trim((string) preg_replace('/\s+/', ' ', strip_tags($cell[1] ?? ''))),
        'html' => $cell[1] ?? '',
    ];
}

it("shows the task's issue, linked, when it has one", function (): void {
    laneWorking($this, ['title' => 'robot-council/core#1: a title that also names one', 'issue' => 'robot-council/core#422']);

    $cell = onWhat();

    expect($cell['text'])->toBe('robot-council/core#422')
        ->and($cell['html'])->toContain('href="'.TicketLink::url('robot-council/core#422').'"');
});

it('shows the ticket a title begins with, linked, when the task has no issue', function (): void {
    laneWorking($this, ['title' => 'UAMS-Web/wordpress-importer#1175: resolve only the referenced attachments']);

    $cell = onWhat();

    expect($cell['text'])->toBe('UAMS-Web/wordpress-importer#1175')
        ->and($cell['html'])->toContain('href="'.TicketLink::url('UAMS-Web/wordpress-importer#1175').'"')
        ->not->toContain('no ticket');
});

it('shows the title, whole, with the task number beside it, when neither names a ticket', function (string $title): void {
    $id = laneWorking($this, ['title' => $title]);

    $cell = onWhat();

    expect($cell['text'])->toBe($title.' (task #'.$id.')')
        ->and($cell['html'])->not->toContain('href=')
        ->not->toContain('no ticket');
})->with([
    'a reference mentioned later' => ['Fix robot-council/core#422 on the board'],
    'a bare number' => ['#422: show the ticket'],
    'a reference the pattern refuses' => ['robot-council/core#422abc: not one'],
    'no reference at all' => ['Tidy the release notes'],
]);

it('escapes a title it shows', function (): void {
    laneWorking($this, ['title' => '<script>alert(1)</script> title']);

    expect(onWhat()['html'])->toContain('&lt;script&gt;alert(1)&lt;/script&gt; title')
        ->not->toContain('<script>alert(1)</script>');
});

it("treats a title's ticket as a packet when its stored issue is a decision fork", function (): void {
    // A decision needs no branch, so the board says so rather than that one was not reported;
    // the title's ticket has to be in the one query that reads stored issues for that to hold
    app(GitHubState::class)->import([
        'repository_url' => 'https://api.github.com/repos/robot-council/core',
        'number' => 430,
        'state' => 'open',
        'title' => 'Decide the thing',
        'labels' => [['name' => 'decision-fork']],
        'updated_at' => '2026-09-26T08:00:00Z',
    ]);

    $session = $this->service(AgentSessions::class)->start($this->approveInstallation($this->developer, 'josh-office'), 'robot-council/core', 'robot-council-core-a')->owner;

    $tasks = $this->service(Tasks::class);
    $task = $tasks->create($session, ['title' => 'robot-council/core#430: decide the thing'], false);
    $tasks->transition($task->id, TaskTransition::Claim, $session, asCoordinator: false);
    $tasks->transition($task->id, TaskTransition::Start, $session, asCoordinator: false);

    Livewire::test(Lanes::class)
        ->assertSee('packet, no branch expected')
        ->assertDontSee('branch not reported');
});
