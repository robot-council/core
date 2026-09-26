<?php

declare(strict_types=1);

/**
 * A session named for a person, as `<repository>/<machine>/<slot>` (#421), and where the dashboard
 * uses it: the queue's Filed by and Held by, and the locks page's Held by.
 *
 * @command  vendor/bin/pest --compact tests/SessionLabelsTest.php
 */

use Livewire\Livewire;
use RobotCouncil\Livewire\Agents;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Livewire\Locks as LocksPage;
use RobotCouncil\Livewire\TaskBoard;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\SessionLabels;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');

    $this->actingAs($this->developer, 'web');
});

it('names a session by its repository, machine and slot', function (?string $repository, ?string $machine, ?string $workLocation, ?string $label): void {
    expect(SessionLabels::of($repository, $machine, $workLocation))->toBe($label);
})->with([
    // The `<owner>-<name>-` prefix, the maintainer's decision on #421
    'owner and name prefix' => ['robot-council/core', 'josh-office', 'robot-council-core-a', 'core/josh-office/a'],
    // The `<name>-` prefix
    'name prefix' => ['UAMS-Web/uams-statamic', 'josh-home', 'uams-statamic-a', 'uams-statamic/josh-home/a'],
    // The owner written in another case in the checkout's name
    'owner prefix in another case' => ['UAMS-Web/wordpress-importer', 'brent-agent-smith', 'uams-web-wordpress-importer-b', 'wordpress-importer/brent-agent-smith/b'],
    // An owner that begins with the name, where `<name>-` also matches and must lose to the longer prefix
    'owner beginning with the name' => ['core/core', 'josh-office', 'core-core-a', 'core/josh-office/a'],
    // Neither prefix: shown whole rather than guessed at
    'primary stays primary' => ['robot-council/coordinator', 'josh-office', 'primary', 'coordinator/josh-office/primary'],
    'no prefix, shown whole' => ['robot-council/core', 'josh-office', 'scratch-checkout', 'core/josh-office/scratch-checkout'],
    // Nothing but the prefix leaves no slot to show, so the whole value is kept
    'only the prefix' => ['robot-council/core', 'josh-office', 'core-', 'core/josh-office/core-'],
    'no work location' => ['robot-council/core', 'josh-office', null, 'core/josh-office'],
    'a repository with no owner' => ['core', 'josh-office', 'core-a', 'core/josh-office/a'],
    // No repository: no label, and the page falls back to the login
    'no repository' => [null, 'josh-office', 'robot-council-core-a', null],
    'an empty repository' => ['', 'josh-office', 'a', null],
]);

/**
 * A session of this developer's working in a repository and a checkout, on a machine.
 */
function labelledSession(TestCase $case, string $machine, ?string $repository, ?string $workLocation): AgentSession
{
    return $case->service(AgentSessions::class)->start($case->approveInstallation($case->developer, $machine), $repository, $workLocation)->owner;
}

it("reads every session's label and login in two queries, however many there are", function (int $count): void {
    $ids = [];

    foreach (range(1, $count) as $n) {
        $ids[] = labelledSession($this, 'machine-'.$n, 'robot-council/core', 'robot-council-core-'.$n)->id;
    }

    $labels = app(SessionLabels::class);

    expect(queriesIssuedBy(fn () => $labels->forSessions($ids)))->toBe(2)
        ->and($labels->forSessions($ids)[$ids[0]])->toBe(['label' => 'core/machine-1/1', 'login' => 'octodev'])
        // A session that has been deleted, and one that never existed, are absent
        ->and($labels->forSessions([999999, null]))->toBeEmpty();
})->with([1, 5, 20]);

it('shows Filed by and Held by on the queue as the session label, with the login beneath', function (): void {
    $filer = labelledSession($this, 'josh-office', 'robot-council/core', 'robot-council-core-a');
    $holder = labelledSession($this, 'josh-home', 'UAMS-Web/uams-statamic', 'uams-statamic-b');

    $task = app(Tasks::class)->create($filer, ['title' => 'Name the sessions'], false);
    app(Tasks::class)->transition($task->id, TaskTransition::Reassign, $filer, asCoordinator: true, assignee: $holder, directive: 'Take this.');

    $html = Livewire::test(TaskBoard::class)->html();

    expect($html)
        ->toContain('<div><code>core/josh-office/a</code></div>')
        ->toContain('<div><code>uams-statamic/josh-home/b</code></div>')
        // The login stays visible, not in a tooltip
        ->toContain('<div class="text-meta opacity-80">octodev</div>')
        ->not->toContain('title=');
});

it('falls back to the login for a session with no repository, and to "a session since deleted" for one that is gone', function (): void {
    $plain = labelledSession($this, 'josh-office', null, null);

    app(Tasks::class)->create($plain, ['title' => 'Filed without a repository'], false);

    $gone = labelledSession($this, 'josh-home', 'robot-council/core', 'robot-council-core-b');
    $orphan = app(Tasks::class)->create($gone, ['title' => 'Filed by a session that is gone'], false);

    // The state a deleted session leaves, forced rather than asked of the engine: the foreign key
    // nulls `created_by` on Postgres, and this suite's SQLite enforces no foreign key at all
    AgentSession::query()->whereKey($gone->id)->delete();
    Task::query()->whereKey($orphan->id)->update(['created_by' => null]);

    $html = Livewire::test(TaskBoard::class)->html();

    expect($html)->toContain('a session since deleted')
        ->not->toContain('core/josh-home/b');

    preg_match('/Filed without a repository.*?data-label="Filed by">(.*?)<\/td>/s', $html, $cell);

    expect(trim(strip_tags($cell[1] ?? '')))->toBe('octodev');
});

it("names a lock's holder and previous holder by session label, still linking to the holder", function (): void {
    $first = labelledSession($this, 'josh-office', 'robot-council/core', 'robot-council-core-a');
    $second = labelledSession($this, 'josh-home', 'robot-council/core', 'robot-council-core-b');

    $locks = app(Locks::class);
    $locks->acquire($first, 'branch:main', 300, false);
    $locks->forceRelease($second, 'branch:main');
    $locks->acquire($second, 'branch:main', 300, false);

    $html = Livewire::test(LocksPage::class)->html();

    expect($html)
        ->toContain('<a href="'.e(route('robot-council.agents', ['session' => $second->id])).'" class="link inline-flex min-h-6 items-center"><code>core/josh-home/b</code></a>')
        ->toContain('<div class="text-meta opacity-80">octodev</div>')
        ->toContain('after <code>core/josh-office/a</code>');
});

it('adds no query per row to the queue', function (int $tasks): void {
    foreach (range(1, $tasks) as $n) {
        $session = labelledSession($this, 'machine-'.$n, 'robot-council/core', 'robot-council-core-'.$n);
        app(Tasks::class)->create($session, ['title' => 'Task '.$n], false);
    }

    // The tasks, the sessions with their installations, the logins, and the hidden count
    expect(queriesIssuedBy(fn () => Livewire::test(TaskBoard::class)))->toBe(4);
})->with([1, 5, 20]);

it('cuts the slot in characters, so a letter whose lower case is shorter in bytes cannot split another', function (): void {
    // KELVIN SIGN lowers to a one-byte `k` from three bytes. Nothing a session can store reaches
    // this -- work locations are lower-case ASCII -- but the helper is public
    expect(SessionLabels::of('kit/kit', 'box', "\u{212A}it-a"))->toBe('kit/box/a');
});

it('names a session the same way on the lane board and the agents list', function (): void {
    $session = labelledSession($this, 'josh-office', 'robot-council/core', 'robot-council-core-a');

    Livewire::test(Lanes::class)
        ->assertSeeHtml('<div class="font-medium"><code>core/josh-office/a</code> &middot; octodev</div>');

    Livewire::test(Agents::class)
        ->assertSeeHtml('<th role="columnheader">Session</th>')
        ->assertSeeHtml('<div><code>core/josh-office/a</code></div>');

    // A link to one session names it rather than its id
    Livewire::withQueryParams(['session' => $session->id])->test(Agents::class)
        ->assertSeeHtml('<span>Showing one session, <code>core/josh-office/a</code>.</span>');
});

it('keeps the login on the lane board and the agents list for a session with no repository', function (): void {
    labelledSession($this, 'josh-office', null, null);

    Livewire::test(Lanes::class)->assertDontSee('josh-office/');

    $html = Livewire::test(Agents::class)->html();

    preg_match('/data-label="Session">(.*?)<\/td>/s', $html, $cell);

    expect(trim(strip_tags($cell[1] ?? '')))->toBe('octodev');
});

it('names a previous holder by login when it has no label', function (): void {
    $first = labelledSession($this, 'josh-office', null, null);
    $second = labelledSession($this, 'josh-home', 'robot-council/core', 'robot-council-core-b');

    $locks = app(Locks::class);
    $locks->acquire($first, 'branch:main', 300, false);
    $locks->forceRelease($second, 'branch:main');
    $locks->acquire($second, 'branch:main', 300, false);

    $html = Livewire::test(LocksPage::class)->html();

    // Read as text, since Livewire marks the `@if` inside the element with comments
    preg_match_all('/<div class="text-meta opacity-80">(.*?)<\/div>/s', $html, $cells);

    $after = array_values(array_filter(
        array_map(static fn (string $cell): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($cell))), $cells[1]),
        static fn (string $text): bool => str_starts_with($text, 'after '),
    ));

    expect($after)->toBe(['after octodev']);
});
