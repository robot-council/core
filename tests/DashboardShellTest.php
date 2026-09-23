<?php

declare(strict_types=1);

/**
 * The dashboard's shell: who reaches it, what it serves, and whether the stylesheet it ships
 * actually styles what the pages render.
 *
 * @command  vendor/bin/pest --compact tests/DashboardShellTest.php
 */

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Livewire\Features\SupportDisablingBackButtonCache\SupportDisablingBackButtonCache;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use RobotCouncil\Http\Controllers\DashboardStylesheetController;
use RobotCouncil\Http\Middleware\DenyFraming;
use RobotCouncil\Http\Middleware\EnsureAllowlistedDeveloper;
use RobotCouncil\Livewire\Dashboard;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

/**
 * Livewire pushes `DisableBackButtonCacheMiddleware` onto the **global** HTTP kernel, so it runs for
 * every response in the application including this package's static-file route. It is gated on a
 * static flag set while a component renders, and its own source says it flushes that flag "to ensure
 * that unit tests still work" -- which is the leak: one test process keeps one application, so a
 * test that rendered a component can leave the flag set and the next test's response comes back with
 * `no-store, private` stamped over the caching headers this package set.
 *
 * Production is unaffected. The flag is set and cleared inside a single request, and a request for a
 * stylesheet renders no component. The reset below exists so a cache assertion does not depend on
 * which order the suite happened to run in.
 */
function withoutLivewiresBackButtonHeaders(): void
{
    SupportDisablingBackButtonCache::$disableBackButtonCache = false;
}

it('shows the dashboard to an allowlisted developer', function (): void {
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk()
        ->assertSee('Robot Council');
});

it('refuses a signed-in user who is not on the access list', function (): void {
    $stranger = $this->enrollDeveloper(9999, login: 'stranger');

    $this->actingAs($stranger, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertForbidden();
});

it('sends an unauthenticated visitor to GitHub sign-in', function (): void {
    $this->get(route('robot-council.dashboard'))
        ->assertRedirect(route('robot-council.auth.redirect'));
});

it('serves the compiled stylesheet without asking anyone to sign in', function (): void {
    // Public deliberately: a page that needed authentication to load its own styling would render
    // unstyled to exactly the people being told to sign in.
    $response = $this->get(route('robot-council.dashboard.stylesheet'));

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('css');
});

it('serves a stylesheet that carries the utilities the pages use, and not the ones they do not', function (): void {
    // Asserted against the built artifact rather than the Tailwind configuration, because the
    // configuration is a statement of intent and the artifact is what a browser receives.
    $stylesheet = (string) file_get_contents(__DIR__.'/../resources/dist/dashboard.css');

    expect($stylesheet)->not->toBeEmpty();

    // The two layers behave differently, measured rather than assumed. daisyUI's **component**
    // styles are emitted wholesale by the plugin -- `chat-bubble`, `countdown` and `table-zebra`
    // are all present although nothing here renders them -- so they cannot show whether scanning
    // works. Tailwind's **utilities** and daisyUI's **modifiers** are scan-gated, so those are what
    // this asserts on. A probe on the component layer would pass with the `@source` directives
    // deleted, which is exactly the check this is meant to be.
    // Extended for #118, which stopped scanning `src/`. That halved the artifact and made this
    // assertion the thing standing between a view and an unstyled page, so it now covers the
    // panels rather than only the shell -- the admin one especially, since it is the only page
    // whose controls change authorization and the last one anybody should have to squint at.
    $used = [
        // the shell
        'max-w-7xl', 'opacity-70', 'shadow-sm', 'antialiased', 'bg-base-200',

        // the sidebar and header #183 added. `menu-active` is what marks the page being shown, so
        // a build that dropped it would render every entry identically with nothing reporting it.
        'menu-title', 'menu-active', 'btn-square', 'truncate', 'sticky', 'min-w-0', 'grow',

        // the offset that keeps a jumped-to panel clear of the sticky header
        'scroll-mt-20',

        // every panel's frame
        'card', 'card-body', 'card-title', 'table', 'table-sm', 'badge', 'badge-sm',

        // the paging and scope controls #83 and #114 added
        'btn', 'btn-sm', 'btn-xs', 'btn-ghost', 'btn-primary', 'justify-between', 'flex-wrap',

        // the admin panel
        'btn-warning', 'badge-warning', 'divide-y', 'space-y-1', 'items-start',
    ];

    foreach ($used as $class) {
        expect($stylesheet)->toContain('.'.$class);
    }

    // Absent, and that absence is the control. These are ordinary Tailwind utilities and one
    // daisyUI modifier that no view in this package uses; if the build emitted everything, or if
    // scanning reached something it should not, they would be here.
    //
    // The modifier here has to be one no view uses, so it moves when a view starts using it:
    // `btn-primary` sat here until #77's admin panel marked a held ability with it, at which point
    // this stopped being a control and became a false failure. Check a replacement is genuinely
    // unused rather than only unfamiliar.
    foreach (['bg-red-500', 'p-96', 'grid-cols-11', 'rotate-45', 'btn-secondary'] as $unused) {
        expect($stylesheet)->not->toContain($unused);
    }
});

it('references the stylesheet from the page', function (): void {
    // Without this the `<link>` can be deleted and every other test here stays green
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))->assertOk()->assertSeeHtml(route('robot-council.dashboard.stylesheet'));
});

it('refuses to be framed', function (): void {
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('starts no session for the stylesheet', function (): void {
    // The route is deliberately outside the web group. Inside it, `StartSession` would write a
    // session record for every anonymous request for a static file, run the garbage-collection
    // lottery, and attach a `Set-Cookie` to a response this package tells shared caches they may
    // store for a year.
    withoutLivewiresBackButtonHeaders();

    $response = $this->get(route('robot-council.dashboard.stylesheet'));

    $response->assertOk();

    expect($response->headers->getCookies())->toBeEmpty()
        ->and($response->headers->get('Cache-Control'))->toContain('public');
});

it('refuses a poll interval a host could not have meant', function (mixed $configured, int $expected): void {
    // The value becomes a `wire:poll` interval. A zero asks the browser to poll as fast as it can,
    // and a non-integer renders an attribute the browser ignores, leaving a page that never
    // refreshes and never says so.
    $this->rebootWith('robot-council.dashboard.poll_seconds', $configured);

    $component = new Dashboard;

    $component->mount(app(Repository::class));

    expect($component->pollSeconds)->toBe($expected);
})->with([
    'the configured value' => [30, 30],
    'zero' => [0, Dashboard::DEFAULT_POLL_SECONDS],
    'negative' => [-5, Dashboard::DEFAULT_POLL_SECONDS],
    'absurd' => [99999, Dashboard::DEFAULT_POLL_SECONDS],
    'a string' => ['fast', Dashboard::DEFAULT_POLL_SECONDS],
    'null' => [null, Dashboard::DEFAULT_POLL_SECONDS],
]);

it('tells a browser it may keep the stylesheet, and answers a revalidation cheaply', function (): void {
    withoutLivewiresBackButtonHeaders();

    $first = $this->get(route('robot-council.dashboard.stylesheet'));

    $first->assertOk();

    $etag = $first->headers->get('ETag');

    expect($etag)->not->toBeNull()
        ->and($first->headers->get('Cache-Control'))->toContain('max-age='.DashboardStylesheetController::MAX_AGE);

    // The same file, asked for again with what the browser was given
    $this->withHeaders(['If-None-Match' => (string) $etag])
        ->get(route('robot-council.dashboard.stylesheet'))
        ->assertStatus(304);
});

it("keeps the allowlist gate on Livewire's update endpoint", function (): void {
    // Livewire strips every middleware from `POST /livewire/update` that is not on its own fixed
    // persistent list -- `PersistentMiddleware::$persistentMiddleware`, a hardcoded static array
    // holding Sanctum's, Jetstream's and the framework's own. `EnsureAllowlistedDeveloper` is not
    // in it, so without registering it as persistent a developer removed from the access list
    // keeps driving components from a page already open, and every later dashboard slice mounts
    // inside one of those components.
    //
    // Asserted on the registration rather than by posting a snapshot, because `Livewire::test()`
    // does not run HTTP middleware at all and a hand-built snapshot would be testing Livewire's
    // checksum rather than this package's registration.
    $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

    expect($persistent)->toContain(EnsureAllowlistedDeveloper::class)
        ->toContain(DenyFraming::class);

    // The control: the list is Livewire's own and is not empty by construction, so a registration
    // that silently did nothing would still have to get past this
    expect($persistent)->toContain(SubstituteBindings::class);
});

it('will not let a client set the polling interval', function (): void {
    // `mount()` runs once; every later request goes through `hydrate()`, and `updateProperty()`
    // accepts any public property that is not `#[Locked]`. A client setting this to zero would ask
    // the browser to poll as fast as it can.
    Livewire::test(Dashboard::class)
        ->assertSet('pollSeconds', 5)
        ->set('pollSeconds', 0);
})->throws(Exception::class);
