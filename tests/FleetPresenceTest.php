<?php

declare(strict_types=1);

/**
 * Who is alive in the fleet, and what they are holding: the Agents and Locks pages, and the store
 * both read.
 *
 * @command  vendor/bin/pest --compact tests/FleetPresenceTest.php
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RobotCouncil\Access\Role;
use RobotCouncil\Livewire\Agents as AgentsPage;
use RobotCouncil\Livewire\Locks as LocksPage;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Lock;
use RobotCouncil\Support\FleetPresence as Presence;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\Scope;
use RobotCouncil\Support\SessionPresence;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);

    $this->installation = $this->approveInstallation($this->developer, machineLabel: 'workbench-01');

    [$this->session, $this->token] = $this->startAgentSession($this->installation);

    $this->actingAs($this->developer, 'web');
});

it('lists a session with its developer, machine, harness and contact time', function (): void {
    Livewire::test(AgentsPage::class)
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

    Livewire::test(AgentsPage::class)
        ->assertSeeHtml('<span class="badge badge-sm">'.$status.'</span>');
})->with([
    'active' => AgentSessionStatus::Active->value,
    'stale' => AgentSessionStatus::Stale->value,
    'gone' => AgentSessionStatus::Gone->value,
]);

it("shows each session's own role, which its machine no longer decides", function (string $role): void {
    // Asserted as the rendered element rather than with `assertSee`, because `build` and
    // `coordinator` both appear elsewhere on the page -- in prose and in the scope controls -- so a
    // bare string match would pass with the column removed entirely.
    $this->session->forceFill(['role' => $role])->save();

    Livewire::test(AgentsPage::class)
        ->assertSeeHtml('<span class="badge badge-sm badge-outline">'.$role.'</span>');
})->with([
    'build' => Role::Build->value,
    'ci' => Role::Ci->value,
    'coordinator' => Role::Coordinator->value,
]);

it('shows where a session is working, as a repository and the checkout within it', function (): void {
    $this->session->forceFill(['repository' => 'UAMS-Web/uams-statamic', 'work_location' => 'ci'])->save();

    Livewire::test(AgentsPage::class)
        ->assertSee('UAMS-Web/uams-statamic')
        ->assertSeeHtml('<div class="opacity-60">ci</div>');
});

it('shows a session that named only a work location, rather than calling it none', function (): void {
    // Both fields are independently nullable -- an acceptance criterion -- so this is a shape the
    // endpoint accepts. An earlier version gated the whole cell on the repository and printed
    // `none` here, which is the page asserting the session named nothing.
    //
    // **There used to be a third field and a fallback to it.** `robot-council/core#285` dropped
    // `project_id`, and with it the row this panel could render from a label the split declined to
    // guess at. Such a row now reads as a session that named nothing, which is the narrow cost the
    // retirement was taken with its eyes open about.
    $this->session->forceFill([
        'repository' => null,
        'work_location' => 'primary',
    ])->save();

    Livewire::test(AgentsPage::class)
        ->assertSeeHtml('<div class="opacity-60">primary</div>')
        ->assertDontSeeHtml('<span class="opacity-60">none</span>');
});

it('keeps saying none for a session that named nothing at all', function (): void {
    // The control for the test above: the placeholder still appears where it should, so that one
    // is passing because the location renders rather than because the placeholder was removed.
    $this->session->forceFill([
        'repository' => null,
        'work_location' => null,
    ])->save();

    Livewire::test(AgentsPage::class)
        ->assertSeeHtml('<span class="opacity-60">none</span>');
});

it('renders a hostile repository as text', function (): void {
    // Written past the endpoint's validation deliberately: `repository` is charset-limited and
    // this string cannot arrive through the API, so the page's escaping is its own guarantee.
    $this->session->forceFill(['repository' => '<script>alert(1)</script>'])->save();

    $html = Livewire::test(AgentsPage::class)->html();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});

it('shows two sessions on one machine holding different roles', function (): void {
    // The case the column exists for: one harness on one machine running several checkouts, where
    // before #221 every session had identical authority and nothing on the page said so.
    [$second] = $this->startAgentSession($this->installation);

    $this->session->forceFill(['role' => Role::Coordinator])->save();
    $second->forceFill(['role' => Role::Build])->save();

    Livewire::test(AgentsPage::class)
        ->assertSeeHtml('<span class="badge badge-sm badge-outline">'.Role::Coordinator->value.'</span>')
        ->assertSeeHtml('<span class="badge badge-sm badge-outline">'.Role::Build->value.'</span>');
});

it('keeps a gone session on the page rather than hiding it', function (): void {
    // A session that has ended is exactly what a developer is looking for when a task sits held and
    // nothing moves, which is why #24 keeps the row rather than deleting it.
    $this->session->forceFill(['status' => AgentSessionStatus::Gone->value])->save();

    Livewire::test(AgentsPage::class)->assertSee('workbench-01');
});

it('lists a held lock with its holder, fence and lease', function (): void {
    // Held by a *second* developer, whose login the fixture has no other reason to print. When this
    // list shared a panel with the sessions, the first developer's login was on the page on every
    // render, and `assertSee('octodev')` passed with the holder cell replaced by `nobody`. The
    // split (#308) removed that table from this page, not the reason to hold the fixture apart.
    $other = $this->enrollDeveloper(77, login: 'somebody-else');

    $installation = $this->approveInstallation($other, machineLabel: 'their-box');

    [$theirs] = $this->startAgentSession($installation);

    app(Locks::class)->acquire($theirs, 'deploy', 60, asCoordinator: false);

    Livewire::test(LocksPage::class)
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

    Livewire::test(LocksPage::class)
        ->assertSee('deploy')
        ->assertSee('lapsed')
        ->assertDontSee('expires in')
        ->assertSeeHtml('badge-warning');
});

it('renders a hostile machine label as text', function (): void {
    // Written past the endpoint's validation deliberately: `machine_label` is charset-limited and
    // this string cannot arrive through the API, so the page's escaping is its own guarantee.
    $this->installation->forceFill(['machine_label' => '<script>alert(1)</script>'])->save();

    $html = Livewire::test(AgentsPage::class)->html();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});

it('polls on the interval the dashboard resolved', function (string $page): void {
    Livewire::test($page)->assertSeeHtml('wire:poll.5s');

    Livewire::test($page, ['pollSeconds' => 30])->assertSeeHtml('wire:poll.30s');
})->with([AgentsPage::class, LocksPage::class]);

it('will not let a client change the polling interval', function (string $page): void {
    Livewire::test($page)->set('pollSeconds', 0);
})->with([AgentsPage::class, LocksPage::class])->throws(CannotUpdateLockedPropertyException::class);

it('shows each page through the gate, and refuses a stranger', function (string $route, string $shown): void {
    // `Livewire::test()` runs no HTTP middleware, so every case above would pass with the
    // `actingAs` in `beforeEach` deleted. This one goes through the route.
    app(Locks::class)->acquire($this->session, 'deploy', 60, asCoordinator: false);

    $this->get(route($route))->assertOk()->assertSee($shown);

    $stranger = $this->enrollDeveloper(9999, login: 'stranger');

    $this->actingAs($stranger, 'web')
        ->get(route($route))
        ->assertForbidden()
        ->assertDontSee($shown);
})->with([
    'agents' => ['robot-council.agents', 'workbench-01'],
    'locks' => ['robot-council.locks', 'deploy'],
]);

it('calls a released lock free rather than never held, and does not alarm about it', function (): void {
    // Every release path in `Support\Locks` nulls `holder_id` **and** `expires_at`, so a released
    // lock arrives with no expiry at all. Reading that as "never held" was wrong twice over: the
    // row is kept precisely because it was held, for the fence it carries, and a released lock is
    // the ordinary case rather than the one worth a warning.
    $locks = app(Locks::class);

    $locks->acquire($this->session, 'deploy', 60, asCoordinator: false);
    $locks->release($this->session, 'deploy', asCoordinator: false);

    // Behind `all`, because #83 made the lock list default to the rows that still name a holder.
    // A released row explains nothing and they accumulate forever, which is the trade that issue
    // asked for -- so this asserts the label on the page that shows it rather than pretending the
    // default did not move.
    Livewire::test(LocksPage::class)
        ->call('show', Scope::All->value)
        ->assertSee('deploy')
        ->assertSee('free')
        ->assertDontSee('never held')
        ->assertDontSeeHtml('badge-warning');

    // And it is genuinely hidden by default rather than merely absent from this assertion, which
    // is the half that would otherwise go unstated
    Livewire::test(LocksPage::class)->assertDontSee('deploy');
});

it("loads each session's installation without a query per row", function (): void {
    // The harness and the machine label live on the installation, and this list hydrates many
    // sessions. `Model::preventLazyLoading()` only raises on a query that returned more than one
    // row, so the second session is what makes this able to fail at all.
    $second = $this->approveInstallation($this->developer, machineLabel: 'laptop');

    $this->startAgentSession($second);

    Model::preventLazyLoading();

    try {
        Livewire::test(AgentsPage::class)
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

    $described = app(Presence::class)->sessions(10)['sessions'];

    expect($described[0]['seconds_since_contact'])->toBeInt();

    // And the rendered form, because the store returning an int is only half of it. The column
    // now reads in words rather than as a second count -- it grows through minutes, hours and days
    // beside the queue's `Age` -- so the shape asserted here moved with it. The property is the
    // same one: no fraction of a unit reaches the page.
    $html = (string) Livewire::test(AgentsPage::class)->html();

    expect($html)->toMatch('/\d+ seconds? ago/')
        ->not->toMatch('/\d+\.\d+ \w+ ago/')

        // The old shape, asserted absent rather than merely not asserted present: both could be
        // true at once if the panel grew a second column
        ->not->toMatch('/\d+s ago/');
});

it('lists a session that named no checkout, without inventing one for it', function (): void {
    // `startAgentSession()` starts naming nowhere, so this is the default rather than a contrived
    // state
    expect($this->session->repository)->toBeNull()
        ->and(app(Presence::class)->sessions(20)['sessions'][0]['repository'])->toBeNull();

    $html = Livewire::test(AgentsPage::class)->html();

    // Still on the page -- a session with no label is a session all the same
    expect($html)->toContain('workbench-01')
        ->toContain('>none<');
});

it('tells two worktrees on one machine and harness apart', function (): void {
    // The whole point, asserted end to end rather than field by field: same developer, same
    // machine, same harness, and the page distinguishes them by nothing but the project
    $this->session->forceFill(['repository' => 'UAMS-Web/uams-statamic', 'work_location' => 'a'])->save();

    [$second] = $this->startAgentSession($this->installation);
    $second->forceFill(['repository' => 'UAMS-Web/uams-statamic', 'work_location' => 'ci'])->save();

    $rows = app(Presence::class)->sessions(20)['sessions'];

    // The repository is the same on both, which is the point: the work location is the only field
    // that can tell them apart, and it is the one the split exists for.
    expect(array_column($rows, 'work_location'))
        ->toContain('a')
        ->toContain('ci')
        ->and(array_unique(array_map(stringValue(...), array_column($rows, 'repository'))))->toHaveCount(1)

        // Same machine and harness on both, so the work location is doing all the work
        ->and(array_unique(array_map(stringValue(...), array_column($rows, 'machine_label'))))->toHaveCount(1)
        ->and(array_unique(array_map(stringValue(...), array_column($rows, 'harness'))))->toHaveCount(1);

    Livewire::test(AgentsPage::class)
        ->assertSeeHtml('<div class="opacity-60">a</div>')
        ->assertSeeHtml('<div class="opacity-60">ci</div>');
});

// **The hostile-repository guard this file used to hold twice now lives once, above.** It was a
// hostile `project_id` until `robot-council/core#285` retired that field, and rewriting it in terms
// of `repository` made it a second copy of the test at the top of this file rather than a second
// guard. The machine-label and lock-name guards below are the ones that cover distinct fields.

it('renders a hostile lock name as text', function (): void {
    // The fourth subject robot-council/core#30's escaping criterion names, and the one its other
    // three guards did not cover. Written past `Locks::NAME`'s charset deliberately, as the
    // machine-label guard is: a string that cannot be acquired through the API is exactly how the
    // page's escaping is shown to be its own guarantee rather than the validator's.
    $other = $this->enrollDeveloper(77, login: 'somebody-else');

    $installation = $this->approveInstallation($other, machineLabel: 'their-box');

    [$theirs] = $this->startAgentSession($installation);

    app(Locks::class)->acquire($theirs, 'deploy', 60, asCoordinator: false);

    Lock::query()->where('name', 'deploy')->update(['name' => '<script>alert(3)</script>']);

    $html = Livewire::test(LocksPage::class)->html();

    expect($html)->toContain('&lt;script&gt;alert(3)&lt;/script&gt;')
        ->not->toContain('<script>alert(3)</script>');
});

it('shows no credential of any kind on the page', function (): void {
    // robot-council/core#30's sixth criterion, which had no test. The dashboard renders sessions,
    // locks and events, every one of which hangs off something that owns a credential -- so this
    // asserts on the rendered page rather than reasoning from which columns the components select.
    $requested = requestDeviceCode($this);

    app(Locks::class)->acquire($this->session, 'deploy', 60, asCoordinator: false);

    $html = Livewire::test(AgentsPage::class)->html().Livewire::test(LocksPage::class)->html();

    // Both pages rendered something to search, or the absences below are about an empty string
    expect($html)->toContain('workbench-01')->toContain('deploy');

    expect($html)->not->toContain($this->token)
        ->not->toContain($requested['device_code'])
        ->not->toContain($requested['verifier'])
        ->not->toContain(stringValue($requested['response']['user_code'] ?? ''));

    // The control. These are live values, not strings that were never anywhere: the token
    // authenticates this machine and the device code is a pending enrollment.
    expect($this->token)->not->toBeEmpty()
        ->and($requested['device_code'])->not->toBeEmpty()
        ->and($requested['verifier'])->not->toBeEmpty()
        ->and(PersonalAccessToken::query()->count())->toBeGreaterThan(0);
});

it('reaches a lapsed lock whose name sorts past the page', function (): void {
    // #83's sharpest criterion, and the reason the lock list needed a cursor rather than only a
    // filter. Locks are ordered by name, a released row is kept forever for its fence, and a live
    // or lapsed lock whose name sorts late was invisible with nothing saying so.
    // **One session may hold only `locks.max_per_session` live leases**, twenty by default, and
    // `acquire()` answers `Outcome::Conflict` rather than throwing when it is at the ceiling. A
    // seeding loop that ignores the return value therefore reports fifty-six successes and leaves
    // twenty rows -- which is how this fixture was wrong the first time. Raised here, and the row
    // count is asserted below rather than inferred from the loop.
    config()->set('robot-council.locks.max_per_session', 500);

    $locks = app(Locks::class);

    // Seeded past the page, all held, so the target cannot be on the first page by luck
    $size = LocksPage::LOCKS;

    foreach (range(1, $size + 5) as $n) {
        $locks->acquire($this->session, sprintf('a-%03d', $n), 60, asCoordinator: false);
    }

    expect(Lock::query()->whereNotNull('holder_id')->count())->toBe($size + 5);

    // The one being hunted: a lease that has run out while the row still names a holder, with a
    // name that sorts after every one above
    $locks->acquire($this->session, 'zz-lapsed', 60, asCoordinator: false);

    Lock::query()->where('name', 'zz-lapsed')
        ->update(['expires_at' => Carbon::now()->subMinutes(5)]);

    $presence = app(Presence::class);

    $first = $presence->locks($size);

    // It is genuinely absent from the first page, or the paging below proves nothing
    expect(array_column($first['locks'], 'name'))->not->toContain('zz-lapsed')
        ->and($first['more'])->toBeTrue();

    // Walk until it appears, exactly as the button does, bounded so a broken cursor fails the test
    // rather than hanging it
    $names = [];
    $cursor = $first['cursor'];
    $page = $first;

    for ($i = 0; $i < 10 && $page['more']; $i++) {
        $page = $presence->locks($size, Scope::Live, $cursor);
        $names = [...$names, ...array_column($page['locks'], 'name')];
        $cursor = $page['cursor'];
    }

    expect($names)->toContain('zz-lapsed');

    // And it is reported as lapsed rather than free, which is what makes it worth reaching
    $lapsed = collect($presence->locks($size, Scope::Live, $first['cursor'])['locks'])
        ->firstWhere('name', 'zz-lapsed');

    expect($lapsed['held'] ?? null)->toBeFalse()
        ->and($lapsed['holder'] ?? null)->not->toBeNull();
});

it('reaches a session that has gone, and says how many there are', function (): void {
    // #75 decided a gone session stays listed, so sessions default to `all` rather than to the
    // narrower scope the lock list uses. This asserts both halves: it is on the page, and it is
    // still reachable once the fleet is larger than one page.
    $size = AgentsPage::SESSIONS;

    foreach (range(1, $size + 3) as $ignored) {
        $this->startAgentSession($this->installation);
    }

    // The oldest session is the one that has gone, so it sorts last under `id desc`
    app(SessionPresence::class)->revoke($this->session);

    $presence = app(Presence::class);

    $first = $presence->sessions($size);

    expect($first['more'])->toBeTrue()
        ->and($first['gone'])->toBe(1)
        ->and(array_column($first['sessions'], 'id'))->not->toContain($this->session->id);

    $next = $presence->sessions($size, Scope::All, $first['cursor']);

    expect(array_column($next['sessions'], 'id'))->toContain($this->session->id);

    // Narrowing to the live scope drops it, and the count is what says so rather than the page
    // simply being shorter
    $live = $presence->sessions($size, Scope::Live);

    expect($live['gone'])->toBe(1)
        ->and($live['live'])->toBe($size + 3);
});

it('does not skip a session because its contact time moved', function (): void {
    // **The reason sessions are ordered by `id` and not by `last_seen_at`.** A keyset built on
    // contact time walks an ordering that moves underneath the reader: a session on the second
    // page that makes a request jumps ahead of the cursor and the next page never returns it. The
    // reader sees a shorter fleet than exists and nothing says so -- this issue's own defect,
    // reintroduced by its fix.
    $size = 2;

    $sessions = [$this->session];

    foreach (range(1, 4) as $ignored) {
        [$started] = $this->startAgentSession($this->installation);
        $sessions[] = $started;
    }

    $presence = app(Presence::class);

    $first = $presence->sessions($size);

    // Everything still on a later page now makes contact, which under a contact-time ordering
    // would move it ahead of the cursor
    foreach ($sessions as $session) {
        $session->forceFill(['last_seen_at' => Carbon::now()->addMinute()])->save();
    }

    $seen = array_column($first['sessions'], 'id');
    $cursor = $first['cursor'];
    $page = $first;

    for ($i = 0; $i < 10 && $page['more']; $i++) {
        $page = $presence->sessions($size, Scope::All, $cursor);
        $seen = [...$seen, ...array_column($page['sessions'], 'id')];
        $cursor = $page['cursor'];
    }

    // Every session, once each. A skip shows as a missing id and a repeat as a duplicate, and the
    // ordering key is what rules out both.
    // Narrowed to ints before comparing, because `array_unique` compares as strings and the
    // analyzer will not take a `list<mixed>` for that
    $ids = array_map(intValue(...), $seen);

    expect(array_unique($ids))->toHaveSameSize($sessions)
        ->and($ids)->toHaveSameSize($sessions);
});

it('does not offer a next page when the set is an exact multiple of the page', function (): void {
    // The off-by-one both lists live on. `more` comes from fetching one row beyond the page, and
    // the two ways to get it wrong are opposite: fetching `$size` rather than `$size + 1` never
    // reports a next page and strands everything past the first, while `>=` rather than `>`
    // reports one on an exactly-full page and lands the reader on an empty one.
    //
    // Every other paging test here seeds size+3 or size+5, so neither mutation changes their
    // outcome. An exact multiple is the only shape that separates them.
    $size = 2;

    // One session exists from `beforeEach`, so three more makes four -- two full pages
    foreach (range(1, 3) as $ignored) {
        $this->startAgentSession($this->installation);
    }

    $presence = app(Presence::class);

    $first = $presence->sessions($size);

    expect($first['sessions'])->toHaveCount($size)
        ->and($first['more'])->toBeTrue();

    $second = $presence->sessions($size, Scope::All, $first['cursor']);

    // Full, and the last: a reader offered a third page would find it empty
    expect($second['sessions'])->toHaveCount($size)
        ->and($second['more'])->toBeFalse();
});

it('does not offer a next page of locks when the set is an exact multiple', function (): void {
    config()->set('robot-council.locks.max_per_session', 500);

    $size = 2;

    $locks = app(Locks::class);

    foreach (range(1, 4) as $n) {
        $locks->acquire($this->session, sprintf('m-%03d', $n), 60, asCoordinator: false);
    }

    expect(Lock::query()->whereNotNull('holder_id')->count())->toBe(4);

    $presence = app(Presence::class);

    $first = $presence->locks($size);

    expect($first['locks'])->toHaveCount($size)
        ->and($first['more'])->toBeTrue();

    $second = $presence->locks($size, Scope::Live, $first['cursor']);

    expect($second['locks'])->toHaveCount($size)
        ->and($second['more'])->toBeFalse();
});

it('clamps a page size that makes no sense, on both lists', function (): void {
    // `max(1, min($limit, MAX_PAGE))` has both ends, and neither had a test: every other call here
    // passes a sensible size, so the floor and the ceiling are the same expression for all of them.
    $presence = app(Presence::class);

    // **More rows than the clamp**, or the assertion cannot see the clamp move. With one session
    // in the table, `max(1, …)` and `max(2, …)` both return that one row and the test passes
    // either way -- which is what it did before this line was added.
    $this->startAgentSession($this->installation);
    $this->startAgentSession($this->installation);

    config()->set('robot-council.locks.max_per_session', 500);

    $locks = app(Locks::class);

    foreach (['clamped-a', 'clamped-b', 'clamped-c'] as $name) {
        $locks->acquire($this->session, $name, 60, asCoordinator: false);
    }

    // The floor. A caller asking for nothing gets exactly one row rather than an empty page that
    // would read as an empty fleet.
    expect($presence->sessions(0)['sessions'])->toHaveCount(1)
        ->and($presence->sessions(-5)['sessions'])->toHaveCount(1)
        ->and($presence->locks(0)['locks'])->toHaveCount(1);

    // The ceiling is asserted on the value rather than by seeding two hundred rows, which would
    // buy nothing this does not
    expect(Presence::MAX_PAGE)->toBe(200);
});
