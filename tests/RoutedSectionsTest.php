<?php

declare(strict_types=1);

/**
 * Each panel on a page of its own.
 *
 * The decision on #187 kept the console on one page; it was reversed once keeping four panels
 * there needed a selection, a URL-synced property, a toggle action and a sidebar that went stale.
 * A route mounts one panel, and what that buys is asserted here by counting queries -- the same
 * property the selection tests pinned, against the shape that replaced them.
 *
 * @command  vendor/bin/pest --compact tests/RoutedSectionsTest.php
 */

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use RobotCouncil\Http\Middleware\EnsureAllowlistedDeveloper;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Livewire\ChangeFeed;
use RobotCouncil\Livewire\FleetPresence;
use RobotCouncil\Livewire\FleetTotals;
use RobotCouncil\Livewire\TaskBoard;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RobotCouncil\Models\Task;
use RobotCouncil\RobotCouncilServiceProvider;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\PollInterval;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

/**
 * Migrate, set the access lists, build a fleet with something in every panel, and sign in.
 *
 * It does its own migration rather than leaning on a `beforeEach`, because `rebootWith()` hands
 * back an application whose database is empty -- so a test that configures the package has to
 * build its fixture after the reboot, not before it.
 *
 * @param  TestCase  $test  The test case, for its fixture helpers.
 * @param  int  $githubId  The GitHub account to enroll, 4242 being the one that is also an admin.
 * @return User The signed-in developer.
 */
function signInWithAFleet(TestCase $test, int $githubId = 4242): User
{
    $test->migrateUsersTableWithPackageColumns();

    $test->setAccessLists(developers: [4242, 77], admins: [4242]);

    $developer = $test->enrollDeveloper($githubId, login: 'dev'.$githubId);

    $installation = $test->approveInstallation($developer);

    [$session] = $test->startAgentSession($installation);

    $test->service(Locks::class)->acquire($session, 'deploy', 60, false);
    $test->service(Tasks::class)->create($session, ['title' => 'Ship it'], withCoordinator: false);

    $test->actingAs($developer, 'web');

    return $developer;
}

it('mounts one panel per route, and only that one', function (string $section, string $mounted): void {
    signInWithAFleet($this);

    $html = (string) $this->get(route('robot-council.'.$section))->assertOk()->getContent();

    // Livewire names every component it mounts in the element it renders it into, so this reads
    // what the page MOUNTED rather than what its markup mentions. The distinction is the whole
    // test: the sidebar links all five sections from all five pages, so the word "Queue" is on
    // every one of them and discriminates nothing.
    preg_match_all('/wire:name="(robot-council-[a-z-]+)"/', $html, $found);

    expect(array_values(array_unique($found[1])))->toBe([$mounted]);
})->with([
    'presence' => ['presence', 'robot-council-fleet-presence'],
    'queue' => ['queue', 'robot-council-task-board'],
    'feed' => ['feed', 'robot-council-change-feed'],
    'administration' => ['administration', 'robot-council-administration'],
    'lanes' => ['lanes', 'robot-council-lanes'],
]);

it('pays for one panel per page', function (string $section, int $queries): void {
    signInWithAFleet($this);

    // **The assertion markup cannot make.** A panel absent from a page and a panel absent from the
    // database look identical in the HTML; only the count tells them apart, and the count is what
    // the selection this replaced existed to hold down. Each route is measured as the only request
    // of its case, because a second request in the same test reuses what the first resolved.
    expect(queriesIssuedBy(fn () => $this->get(route('robot-council.'.$section))->assertOk()))->toBe($queries);
})->with([
    // The fixture holds one session, one held lock and one task, so no panel reads an empty table
    // The overview's lane summary (#317) adds five: the live sessions, their installations, their
    // held tasks, their holds and their logins. A fixed five, whatever the lane count -- see the
    // lanes test below
    'the overview: the gate, the layout, three counts and the lane summary' => ['dashboard', 12],
    'the lanes: the same five, and the gate and the layout' => ['lanes', 8],
    'presence: sessions, their installations, their logins, the held locks and two summaries' => ['presence', 11],
    'the queue: the tasks, their sessions and their logins' => ['queue', 6],
    'the feed: the events and their logins' => ['feed', 5],
    'administration: the installations, their sessions, their logins and a summary' => ['administration', 9],
]);

it('mounts the totals and no panel on the overview', function (): void {
    signInWithAFleet($this);

    $html = (string) $this->get(route('robot-council.dashboard'))->assertOk()->getContent();

    preg_match_all('/wire:name="(robot-council-[a-z-]+)"/', $html, $found);

    // The overview is a way in, not a fifth panel: the totals row and the links, and nothing that
    // reads a session, a task or an event.
    expect(array_values(array_unique($found[1])))->toBe(['robot-council-fleet-totals'])
        ->and($html)->toContain('Live agents')
        ->and($html)->not->toContain('Ship it');
});

it('keeps every section behind the allowlist gate', function (string $section): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242], admins: [4242]);

    // Unauthenticated: sent to GitHub, exactly as the dashboard is
    $this->get(route('robot-council.'.$section))
        ->assertRedirect(route('robot-council.auth.redirect'));

    // Signed in but not on the access list: refused
    $stranger = $this->enrollDeveloper(9999, login: 'stranger');

    $this->actingAs($stranger, 'web')
        ->get(route('robot-council.'.$section))
        ->assertForbidden();
})->with(['presence', 'queue', 'feed', 'administration']);

it('refuses the administration route from the component, not the route', function (): void {
    // A developer who is allowlisted but not an admin. The route carries no gate of its own, so
    // this 403 comes from `Livewire\Administration::mount()` -- which is what makes the route safe
    // whether or not the sidebar drew the link.
    signInWithAFleet($this, githubId: 77);

    // **Shown to pass the gate first.** Without this, the 403 below could be the allowlist refusing
    // a developer who was never on it -- the opposite of what this test is named for.
    $this->get(route('robot-council.presence'))->assertOk();

    $this->get(route('robot-council.administration'))->assertForbidden();

    // And the route itself has nothing but the group's own middleware
    $middleware = collect(app('router')->getRoutes()->getRoutesByName())
        ->filter(fn ($route, string $name): bool => $name === 'robot-council.administration')
        ->flatMap(fn ($route): array => $route->gatherMiddleware())
        ->all();

    // `not->toContain()` is satisfied by an EMPTY array, so a filter that matched no route would
    // report the absence of an admin gate just as loudly as a route that genuinely carries none.
    // The control is in the same expectation: `web` and the allowlist gate must both be there.
    expect($middleware)->toContain('web')
        ->and($middleware)->toContain(EnsureAllowlistedDeveloper::class)
        ->and($middleware)->not->toContain('can:'.RobotCouncilServiceProvider::ADMIN_ABILITY);
});

it('gives every page the same validated interval, from one place', function (): void {
    $this->rebootWith('robot-council.dashboard.poll_seconds', 11);

    signInWithAFleet($this);

    // Each panel reads `Support\PollInterval` itself now that each has a route. A second copy of
    // the bounds is how two pages come to poll at different rates on one host.
    foreach (['dashboard', 'presence', 'queue', 'feed', 'administration'] as $section) {
        $html = (string) $this->get(route('robot-council.'.$section))->assertOk()->getContent();

        preg_match_all('/wire:poll\.(\d+)s/', $html, $found);

        expect($found[1])->not->toBeEmpty()
            ->and(array_unique($found[1]))->toBe(['11']);
    }
});

it('falls back to the default when a host configures something unusable', function (): void {
    $this->rebootWith('robot-council.dashboard.poll_seconds', 'fast');

    signInWithAFleet($this);

    $html = (string) $this->get(route('robot-council.presence'))->assertOk()->getContent();

    expect($html)->toContain('wire:poll.'.PollInterval::DEFAULT.'s');
});

it('mounts every section under the configured web prefix', function (): void {
    // A host mounts this package wherever it likes, and the sections went under `dashboard/` for
    // that reason: at an empty prefix a top-level `queue` would sit in the host's own namespace.
    $this->rebootWith('robot-council.routes.web_prefix', 'council');

    expect(route('robot-council.presence', absolute: false))->toBe('/council/dashboard/presence')
        ->and(route('robot-council.queue', absolute: false))->toBe('/council/dashboard/queue')
        ->and(route('robot-council.feed', absolute: false))->toBe('/council/dashboard/feed')
        ->and(route('robot-council.administration', absolute: false))->toBe('/council/dashboard/administration');
});

it('takes a scope filter from the query string on the page that owns it', function (string $section, string $query, ?string $appears, ?string $vanishes): void {
    $developer = signInWithAFleet($this);

    // A second installation and a second session, each in the state every panel's default scope
    // leaves out: revoked, gone, and a task that is finished.
    $retired = $this->approveInstallation($developer, machineLabel: 'retired-box');

    [$goneSession] = $this->startAgentSession($retired);

    AgentSession::query()->whereKey($goneSession->getKey())->update(['status' => 'gone']);
    Installation::query()->whereKey($retired->getKey())->update(['revoked_at' => now()]);

    Task::query()->where('title', 'Ship it')->update(['title' => 'Open work']);

    $this->service(Tasks::class)->create($goneSession, ['title' => 'Finished work'], withCoordinator: false);
    Task::query()->where('title', 'Finished work')->update(['status' => 'done']);

    // A lock nobody holds any more, which the locks list leaves out until asked for all of them
    $this->service(Locks::class)->acquire($goneSession, 'migrate', 60, false);
    $this->service(Locks::class)->release($goneSession, 'migrate', false);

    $plain = $this->get(route('robot-council.'.$section))->assertOk();
    $filtered = $this->get(route('robot-council.'.$section).'?'.$query)->assertOk();

    // **The criterion.** Each filter is a `#[Url]` property Livewire reads from the query string.
    // Mounted as a child of the dashboard a panel shared one query string with three others;
    // mounted by a route it owns the whole of it, and these assert the read still happens. Both
    // directions are here because a filter that widens and one that narrows fail differently: a
    // widening filter that never ran shows too little, a narrowing one shows too much.
    if ($appears === null && $vanishes === null) {
        throw new RuntimeException('A row asserting neither direction would pass with the filter deleted.');
    }

    if ($appears !== null) {
        $plain->assertDontSee($appears);
        $filtered->assertSee($appears);
    }

    if ($vanishes !== null) {
        $plain->assertSee($vanishes);
        $filtered->assertDontSee($vanishes);
    }
})->with([
    // All four `#[Url]` filters the routable panels carry. Presence defaults to every session and
    // to live locks only, so its two filters are asserted in opposite directions.
    'presence, narrowed to live sessions' => ['presence', 'sessions=live', null, 'retired-box'],
    'presence, widened to every lock' => ['presence', 'locks=all', 'migrate', null],
    'the queue, narrowed to what is done' => ['queue', 'status=done', null, 'Open work'],
    'administration, widened to every installation' => ['administration', 'installations=all', 'retired-box', null],
]);

it('bounds an interval a parent passed, not just the one a host configured', function (string $component): void {
    signInWithAFleet($this);

    // A host may embed any of these components in a page of its own and pass what it likes. The
    // parameter is the one path `fromConfig()` never saw, and `Wire::of()`'s charset admits a
    // leading minus, so an unbounded value would reach the browser as `wire:poll.-1s` -- an
    // attribute it ignores, leaving a panel that never refreshes and says nothing about it.
    $rendered = Livewire::test($component, ['pollSeconds' => -1])->html();

    expect($rendered)->toContain('wire:poll.'.PollInterval::DEFAULT.'s')
        ->and($rendered)->not->toContain('wire:poll.-1s');
})->with([
    FleetPresence::class,
    TaskBoard::class,
    ChangeFeed::class,
    FleetTotals::class,
    Administration::class,
]);
