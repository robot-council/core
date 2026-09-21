<?php

declare(strict_types=1);

/**
 * Who is alive in the fleet, and what they are holding.
 *
 * @command  vendor/bin/pest --compact tests/FleetPresenceTest.php
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RobotCouncil\Access\Ability;
use RobotCouncil\Livewire\FleetPresence;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Lock;
use RobotCouncil\Support\FleetPresence as Presence;
use RobotCouncil\Support\Locks;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);

    $this->installation = $this->approveInstallation($this->developer, [
        Ability::LocksAcquire->value,
    ], machineLabel: 'workbench-01');

    [$this->session, $this->token] = $this->startAgentSession($this->installation);

    $this->actingAs($this->developer, 'web');
});

it('lists a session with its developer, machine, harness and contact time', function (): void {
    Livewire::test(FleetPresence::class)
        ->assertSee('octodev')
        ->assertSee('workbench-01')
        ->assertSee('claude-code')
        ->assertSeeHtml('<span class="badge badge-sm">'.AgentSessionStatus::Active->value.'</span>');
});

it('tells a stale session from a live one, and a gone one from both', function (string $status): void {
    // Read from the row rather than derived from the contact time. #24 made the row the decision,
    // and a view that re-derived it would disagree with the sweep for as long as the sweep had not
    // run -- showing `stale` while every conditional update still treated the session as active.
    $this->session->forceFill(['status' => $status])->save();

    Livewire::test(FleetPresence::class)
        ->assertSeeHtml('<span class="badge badge-sm">'.$status.'</span>');
})->with([
    'active' => AgentSessionStatus::Active->value,
    'stale' => AgentSessionStatus::Stale->value,
    'gone' => AgentSessionStatus::Gone->value,
]);

it('keeps a gone session on the page rather than hiding it', function (): void {
    // A session that has ended is exactly what a developer is looking for when a task sits held and
    // nothing moves, which is why #24 keeps the row rather than deleting it.
    $this->session->forceFill(['status' => AgentSessionStatus::Gone->value])->save();

    Livewire::test(FleetPresence::class)->assertSee('workbench-01');
});

it('lists a held lock with its holder, fence and lease', function (): void {
    // Held by a *second* developer, because this component renders both panels and the first
    // developer's login is in the sessions table on every render -- so `assertSee('octodev')` here
    // would pass with the entire holder cell replaced by the word `nobody`.
    $other = $this->enrollDeveloper(77, login: 'somebody-else');

    $installation = $this->approveInstallation($other, [Ability::LocksAcquire->value], machineLabel: 'their-box');

    [$theirs] = $this->startAgentSession($installation);

    app(Locks::class)->acquire($theirs, 'deploy', 60, asCoordinator: false);

    Livewire::test(FleetPresence::class)
        ->assertSee('deploy')
        ->assertSee('somebody-else')
        ->assertSeeHtml('<td>1</td>')
        ->assertSee('expires in');
});

it('shows a lapsed lease as lapsed rather than hiding the row', function (): void {
    app(Locks::class)->acquire($this->session, 'deploy', 60, asCoordinator: false);

    // Past the lease without releasing it: the row still names a holder, which is the state a
    // developer is looking for and the one a filtered list would conceal
    Lock::query()->where('name', 'deploy')->update(['expires_at' => Carbon::now()->subMinute()]);

    Livewire::test(FleetPresence::class)
        ->assertSee('deploy')
        ->assertSee('lapsed')
        ->assertDontSee('expires in')
        ->assertSeeHtml('badge-warning');
});

it('renders a hostile machine label as text', function (): void {
    // Written past the endpoint's validation deliberately: `machine_label` is charset-limited and
    // this string cannot arrive through the API, so the page's escaping is its own guarantee.
    $this->installation->forceFill(['machine_label' => '<script>alert(1)</script>'])->save();

    $html = Livewire::test(FleetPresence::class)->html();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});

it('polls on the interval the dashboard resolved', function (): void {
    Livewire::test(FleetPresence::class)->assertSeeHtml('wire:poll.5s');

    Livewire::test(FleetPresence::class, ['pollSeconds' => 30])->assertSeeHtml('wire:poll.30s');
});

it('will not let a client change the polling interval', function (): void {
    Livewire::test(FleetPresence::class)->set('pollSeconds', 0);
})->throws(CannotUpdateLockedPropertyException::class);

it('shows presence through the gate, and refuses a stranger', function (): void {
    // `Livewire::test()` runs no HTTP middleware, so every case above would pass with the
    // `actingAs` in `beforeEach` deleted. This one goes through the route.
    $this->get(route('robot-council.dashboard'))->assertOk()->assertSee('workbench-01');

    $stranger = $this->enrollDeveloper(9999, login: 'stranger');

    $this->actingAs($stranger, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertForbidden()
        ->assertDontSee('workbench-01');
});

it('calls a released lock free rather than never held, and does not alarm about it', function (): void {
    // Every release path in `Support\Locks` nulls `holder_id` **and** `expires_at`, so a released
    // lock arrives with no expiry at all. Reading that as "never held" was wrong twice over: the
    // row is kept precisely because it was held, for the fence it carries, and a released lock is
    // the ordinary case rather than the one worth a warning.
    $locks = app(Locks::class);

    $locks->acquire($this->session, 'deploy', 60, asCoordinator: false);
    $locks->release($this->session, 'deploy', asCoordinator: false);

    Livewire::test(FleetPresence::class)
        ->assertSee('deploy')
        ->assertSee('free')
        ->assertDontSee('never held')
        ->assertDontSeeHtml('badge-warning');
});

it("loads each session's installation without a query per row", function (): void {
    // The harness and the machine label live on the installation, and this list hydrates many
    // sessions. `Model::preventLazyLoading()` only raises on a query that returned more than one
    // row, so the second session is what makes this able to fail at all.
    $second = $this->approveInstallation($this->developer, [], machineLabel: 'laptop');

    $this->startAgentSession($second);

    Model::preventLazyLoading();

    try {
        Livewire::test(FleetPresence::class)
            ->assertSee('workbench-01')
            ->assertSee('laptop');
    } finally {
        // Static, and it outlives the test that set it
        Model::preventLazyLoading(false);
    }
});

it('reports whole seconds since contact, not a fraction of one', function (): void {
    // Carbon 3's `diffInSeconds()` returns a float, and `last_seen_at` is a `dateTime` column with
    // no microseconds while `Carbon::now()` carries them -- so the difference is fractional on
    // every read, and rendered straight the page said `30.482913s ago`.
    $this->session->forceFill(['last_seen_at' => Carbon::now()->subSeconds(30)])->save();

    $described = app(Presence::class)->sessions(10);

    expect($described[0]['seconds_since_contact'])->toBeInt();

    // And the rendered form, because the store returning an int is only half of it
    $html = Livewire::test(FleetPresence::class)->html();

    expect($html)->toMatch('/\d+s ago/')->not->toMatch('/\d+\.\d+s ago/');
});

it('shows which checkout a session belongs to', function (): void {
    // The only thing telling two worktrees on one machine and one harness apart, which is the
    // topology robot-council/cli#20 exists for
    $this->session->forceFill(['project_id' => 'UAMS-Web/uams-statamic/a'])->save();

    Livewire::test(FleetPresence::class)->assertSee('UAMS-Web/uams-statamic/a');

    expect(app(Presence::class)->sessions(20)[0]['project_id'])->toBe('UAMS-Web/uams-statamic/a');
});

it('lists a session that named no checkout, without inventing one for it', function (): void {
    // `startAgentSession()` starts without a project, so this is the default rather than a
    // contrived state
    expect($this->session->project_id)->toBeNull()
        ->and(app(Presence::class)->sessions(20)[0]['project_id'])->toBeNull();

    $html = Livewire::test(FleetPresence::class)->html();

    // Still on the page -- a session with no label is a session all the same
    expect($html)->toContain('workbench-01')
        ->toContain('>none<');
});

it('tells two worktrees on one machine and harness apart', function (): void {
    // The whole point, asserted end to end rather than field by field: same developer, same
    // machine, same harness, and the page distinguishes them by nothing but the project
    $this->session->forceFill(['project_id' => 'UAMS-Web/uams-statamic/a'])->save();

    [$second] = $this->startAgentSession($this->installation);
    $second->forceFill(['project_id' => 'UAMS-Web/uams-statamic/ci'])->save();

    $rows = app(Presence::class)->sessions(20);

    expect(array_column($rows, 'project_id'))
        ->toContain('UAMS-Web/uams-statamic/a')
        ->toContain('UAMS-Web/uams-statamic/ci')

        // Same machine and harness on both, so the project is doing all the work
        ->and(array_unique(array_map(stringValue(...), array_column($rows, 'machine_label'))))->toHaveCount(1)
        ->and(array_unique(array_map(stringValue(...), array_column($rows, 'harness'))))->toHaveCount(1);

    Livewire::test(FleetPresence::class)
        ->assertSee('UAMS-Web/uams-statamic/a')
        ->assertSee('UAMS-Web/uams-statamic/ci');
});

it('renders a hostile project as text', function (): void {
    // Written past `ProjectId`'s charset deliberately, as the machine-label guard above is: the
    // page's escaping has to be its own guarantee rather than the validator's
    $this->session->forceFill(['project_id' => '<script>alert(2)</script>'])->save();

    $html = Livewire::test(FleetPresence::class)->html();

    expect($html)->toContain('&lt;script&gt;alert(2)&lt;/script&gt;')
        ->not->toContain('<script>alert(2)</script>');
});
