<?php

declare(strict_types=1);

/**
 * Choosing which panels the dashboard mounts.
 *
 * **A section that is not selected must run no queries.** That is the whole point of the decision
 * on #187: the console stayed one page because the readings that matter cross panels, and this is
 * what gives the cost back. So the assertions here count queries rather than look for markup --
 * a panel hidden with a class is absent from neither the page nor the database, and only one of
 * those two failures is visible to `assertDontSee`.
 *
 * @command  vendor/bin/pest --compact tests/DashboardSectionsTest.php
 */

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use RobotCouncil\Access\Ability;
use RobotCouncil\Livewire\Dashboard;
use RobotCouncil\Support\DashboardSections;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

/**
 * A developer with a session, a held lock and a task, so every panel has something to read.
 *
 * @param  TestCase  $test  The test case, for its fixture helpers.
 * @param  int  $githubId  The GitHub account to enroll.
 * @return User The developer.
 */
function developerWithAFleet(TestCase $test, int $githubId = 4242): User
{
    $developer = $test->enrollDeveloper($githubId);

    $installation = $test->approveInstallation($developer, [
        Ability::TasksCreate->value,
        Ability::LocksAcquire->value,
    ]);

    [$test->session, $test->token] = $test->startAgentSession($installation);

    $test->service(Locks::class)->acquire($test->session, 'deploy', 60, false);
    $test->service(Tasks::class)->create($test->session, ['title' => 'Ship it'], withCoordinator: false);

    return $developer;
}

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77], admins: [4242]);
});

it('mounts every section when nothing has been chosen', function (): void {
    $this->actingAs(developerWithAFleet($this), 'web');

    $page = $this->get(route('robot-council.dashboard'))->assertOk();

    foreach (['presence', 'queue', 'change-feed', 'administration'] as $section) {
        $page->assertSeeHtml('id="robot-council-'.$section.'"');
    }
});

it('mounts only what was chosen', function (): void {
    $this->actingAs(developerWithAFleet($this), 'web');

    $page = $this->get(route('robot-council.dashboard', ['show' => 'queue']))->assertOk();

    $page->assertSeeHtml('id="robot-council-queue"')
        ->assertDontSeeHtml('id="robot-council-presence"')
        ->assertDontSeeHtml('id="robot-council-change-feed"')
        ->assertDontSeeHtml('id="robot-council-administration"');
});

it('runs no query for a section it did not mount', function (): void {
    $this->actingAs(developerWithAFleet($this), 'web');

    // The assertion that markup cannot make. A panel hidden with a class is absent from the page
    // and present in the query log; only this tells the two apart.
    $everything = queriesIssuedBy(fn () => $this->get(route('robot-council.dashboard'))->assertOk());

    $justTheQueue = queriesIssuedBy(fn () => $this->get(route('robot-council.dashboard', ['show' => 'queue']))->assertOk());

    expect($justTheQueue)->toBeLessThan($everything);

    // And the saving is the panels' own cost rather than a rounding difference: presence is 6
    // queries, the feed 2 and administration 6, measured on #192 and #200.
    expect($everything - $justTheQueue)->toBeGreaterThanOrEqual(10);
});

it('leaves the panels that are still mounted exactly as they were', function (): void {
    $this->actingAs(developerWithAFleet($this), 'web');

    $withEverything = $this->get(route('robot-council.dashboard'))->assertOk();
    $withJustTheQueue = $this->get(route('robot-council.dashboard', ['show' => 'queue']))->assertOk();

    // The queue shows the same task either way. Putting a sibling away changes what is on the page,
    // never what a surviving panel reads.
    $withEverything->assertSee('Ship it');
    $withJustTheQueue->assertSee('Ship it');
});

it('keeps every mounted panel on one interval, whatever is mounted', function (): void {
    $this->actingAs(developerWithAFleet($this), 'web');

    // Livewire buckets its poll timers by interval value, so two distinct intervals would mean two
    // timers and two HTTP requests per cycle. One interval is what keeps the console at one.
    $html = (string) $this->get(route('robot-council.dashboard', ['show' => 'presence,queue']))->assertOk()->getContent();

    preg_match_all('/wire:poll\.(\d+)s/', $html, $found);

    expect($found[1])->not->toBeEmpty()
        ->and(array_unique($found[1]))->toHaveCount(1)
        ->and($found[1][0])->toBe((string) Dashboard::DEFAULT_POLL_SECONDS);
});

it('refuses to put away the last section', function (): void {
    $this->actingAs(developerWithAFleet($this), 'web');

    // A page with no panels is not a state worth reaching by accident -- and with `keep: false` an
    // empty selection round-trips back to everything on the next load, so the control would look
    // broken rather than refused.
    Livewire::test(Dashboard::class, ['show' => 'queue'])
        ->set('show', 'queue')
        ->call('toggle', 'queue')
        ->assertSet('show', 'queue');
});

it('drops the selection from the URL once it is the default again', function (): void {
    $this->actingAs(developerWithAFleet($this), 'web');

    // `keep: false` keeps a shared link short, and keeps the default out of the URL entirely.
    Livewire::test(Dashboard::class)
        ->call('toggle', 'presence')
        ->assertSet('show', 'queue,feed,administration')
        ->call('toggle', 'presence')
        ->assertSet('show', null);
});

it('will not let a developer who is not an admin select the administration panel', function (): void {
    $stranger = developerWithAFleet($this, githubId: 77);

    $this->actingAs($stranger, 'web');

    // Through the query string
    $this->get(route('robot-council.dashboard', ['show' => 'administration']))
        ->assertOk()
        ->assertDontSeeHtml('id="robot-council-administration"');

    // And through the action, which a client can post whatever the page rendered
    Livewire::test(Dashboard::class)
        ->call('toggle', 'administration')
        ->assertSet('show', null);

    $this->get(route('robot-council.dashboard'))
        ->assertOk()
        ->assertDontSeeHtml('id="robot-council-administration"');
});

it('offers the administration panel to an admin', function (): void {
    $this->actingAs(developerWithAFleet($this), 'web');

    $this->get(route('robot-council.dashboard', ['show' => 'administration']))
        ->assertOk()
        ->assertSeeHtml('id="robot-council-administration"')
        ->assertDontSeeHtml('id="robot-council-presence"');
});

it('reads a selection nobody could have meant as no selection at all', function (): void {
    // A link shared after a section was renamed, or hand-edited. Showing everything is the reading
    // that loses nobody a panel; refusing would turn a stale bookmark into an error page.
    expect(DashboardSections::from('nonsense', isAdmin: true))->toBe(DashboardSections::ALL)
        ->and(DashboardSections::from('', isAdmin: true))->toBe(DashboardSections::ALL)
        ->and(DashboardSections::from(null, isAdmin: true))->toBe(DashboardSections::ALL);

    // Order is the page's, not the query string's, so two links naming the same sections in
    // different orders render identically
    expect(DashboardSections::from('feed,presence', isAdmin: true))->toBe(['presence', 'feed']);

    // And the admin panel is filtered out rather than erroring
    expect(DashboardSections::from('administration,queue', isAdmin: false))->toBe(['queue']);
});

it('keeps the sidebar jump links in step with what is mounted', function (): void {
    $this->actingAs(developerWithAFleet($this), 'web');

    $this->get(route('robot-council.dashboard', ['show' => 'queue']))
        ->assertOk()
        ->assertSeeHtml('href="#robot-council-queue"')
        ->assertDontSeeHtml('href="#robot-council-presence"');
});
