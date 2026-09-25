<?php

declare(strict_types=1);

/**
 * The dashboard's shell as something a developer can move around in: what it links to, what it
 * marks as current, who it names, and how they get out.
 *
 * Before #183 the only way to reach either page was to type its path -- the dashboard and the
 * enrollment page were both mounted and neither linked to the other -- and there was no sign-out
 * route at all.
 *
 * @command  vendor/bin/pest --compact tests/DashboardNavigationTest.php
 */

use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

/**
 * Forget the resolved principal between two requests in one test.
 *
 * `Illuminate\Auth\RequestGuard::user()` caches whoever it resolved, and one test process keeps one
 * application, so a request made after a sign-out would otherwise still be answered as the
 * developer who was signed in when the first one ran -- and the test would pass with the sign-out
 * doing nothing at all.
 */
function forgetResolvedGuards(): void
{
    Auth::forgetGuards();
}

/**
 * The opening tag of the anchor pointing at one URL.
 *
 * Read as a tag rather than searched for in the whole document, because the marker this asserts on
 * sits inside one anchor and a document-wide `toContain` would be satisfied by the other one.
 *
 * @param  string|false  $html  The rendered page.
 * @param  string  $url  The href to find.
 * @return string The opening tag, or the empty string when the anchor is absent.
 */
function anchorTagFor(string|false $html, string $url): string
{
    $matched = preg_match('/<a\\s[^>]*href="'.preg_quote($url, '/').'"[^>]*>/', (string) $html, $found);

    return $matched === 1 ? $found[0] : '';
}

/**
 * Forget that a Livewire component rendered earlier in this test process.
 *
 * `SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest` is a **static**, set in
 * `dehydrate()` when a component renders and cleared on Livewire's `flush-state` event. One test
 * process keeps one application and that event does not fire between the requests a test makes, so
 * a test that rendered the dashboard leaves the flag set and the next page -- which renders no
 * component at all -- has assets injected into it by `shouldInjectLivewireAssets()`.
 *
 * Production is unaffected: the flag is per request there, and a request for the enrollment page
 * renders no component. The reset exists so this assertion measures what production does rather
 * than which order the suite happened to run in. It is the same leak `DashboardShellTest` resets
 * for `SupportDisablingBackButtonCache`.
 */
function withoutLivewiresAutoInjectedAssets(): void
{
    SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
    SupportAutoInjectedAssets::$forceAssetInjection = false;
}

/**
 * Every script and style tag a page emits.
 *
 * @param  string|false  $html  The rendered page.
 * @return list<string> The opening tags, in document order.
 */
function tagsIn(string|false $html): array
{
    preg_match_all('/<script[^>]*>|<style[^>]*>/i', (string) $html, $found);

    return $found[0];
}

it('links to every page a signed-in developer can reach', function (): void {
    $page = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk();

    // Both pages the package mounts behind the gate, reachable by clicking rather than by typing.
    //
    // Through `anchorTagFor` rather than `assertSeeHtml`, because the sections are mounted UNDER
    // the dashboard path: `.../dashboard` is a substring of `.../dashboard/agents`, so a
    // document-wide check for the dashboard's URL is satisfied by any one of its children.
    // Delete the Dashboard entry entirely and the string form still passes.
    expect(anchorTagFor($page->getContent(), route('robot-council.dashboard')))->not->toBeEmpty();

    $page->assertSeeHtml(route('robot-council.enroll.show'));
});

it('marks the page being shown, and only that page, as current', function (): void {
    $page = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk();

    // The overview is current here. The enrollment entry is rendered and must NOT be marked, which
    // is the half that fails if the marker is applied to every entry rather than to one.
    $overviewTag = anchorTagFor($page->getContent(), route('robot-council.dashboard'));
    $enrollTag = anchorTagFor($page->getContent(), route('robot-council.enroll.show'));

    expect($overviewTag)->toContain('aria-current="page"')
        ->and($overviewTag)->toContain('menu-active')
        ->and($enrollTag)->not->toContain('aria-current')
        ->and($enrollTag)->not->toContain('menu-active');
});

it('offers the administration section only to an admin', function (): void {
    // The sections are routes now, so this pins the same property against a link rather than a
    // fragment: a developer who is not an admin is not offered the page, in the sidebar or on the
    // overview. `Livewire\Administration` refuses in `mount()` regardless, which is the boundary --
    // this is what is *offered*, and a control that is not drawn was never the boundary.
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))->assertOk()
        ->assertDontSeeHtml('href="'.route('robot-council.administration').'"');

    $this->setAccessLists(developers: [4242], admins: [4242]);

    forgetResolvedGuards();

    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))->assertOk()
        ->assertSeeHtml('href="'.route('robot-council.administration').'"');
});

it('refuses the administration route to a developer who is not an admin', function (): void {
    // The half the link cannot cover: not being offered a page is not the same as not being able
    // to reach it, and only this says which one protects the fleet.
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.administration'))
        ->assertForbidden();
});

it('offers a link to every section, and each one answers', function (): void {
    // **This pinned jump links into one page; it now pins routes.** The property is the same one:
    // every destination the navigation offers exists, so none of them leads nowhere.
    $page = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk();

    foreach (['agents', 'locks', 'queue', 'feed'] as $section) {
        $page->assertSeeHtml('href="'.route('robot-council.'.$section).'"');

        forgetResolvedGuards();

        $this->actingAs($this->developer, 'web')
            ->get(route('robot-council.'.$section))
            ->assertOk();
    }
});

it('names the signed-in developer in the header', function (): void {
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk()
        ->assertSee('octodev');
});

it('offers a sign-out control that posts rather than links', function (): void {
    $page = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk();

    $page->assertSeeHtml('action="'.route('robot-council.sign-out').'"')->assertSeeHtml('method="POST"')->assertSeeHtml('name="_token"');

    // Never reachable by following a link, which is the whole reason it is a POST
    expect($page->getContent())->not->toContain('href="'.route('robot-council.sign-out').'"');
});

it('refuses a GET to the sign-out route', function (): void {
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.sign-out'))
        ->assertMethodNotAllowed();
});

it('ends the session and sends the developer to the signed-out page', function (): void {
    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.sign-out'))
        ->assertRedirect(route('robot-council.signed-out'));

    forgetResolvedGuards();

    // The guard the package configured, not the host's default: signing out on the wrong one would
    // leave this session standing while appearing to have worked
    expect(auth()->guard('web')->check())->toBeFalse();
});

it('leaves the dashboard unreachable once the developer has signed out', function (): void {
    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.sign-out'))
        ->assertRedirect(route('robot-council.signed-out'));

    forgetResolvedGuards();

    $this->get(route('robot-council.dashboard'))
        ->assertRedirect(route('robot-council.auth.redirect'));
});

it('replaces the token the old session issued', function (): void {
    $this->actingAs($this->developer, 'web')->get(route('robot-council.dashboard'))->assertOk();

    $before = session()->token();

    expect($before)->not->toBeEmpty();

    $this->post(route('robot-council.sign-out'))->assertRedirect(route('robot-council.signed-out'));

    // Invalidating without regenerating leaves the token the old session issued valid against the
    // new one, which is the half of a sign-out that is easiest to leave out and hardest to see
    expect(session()->token())->not->toBe($before);
});

it('shows the signed-out page to somebody who is not signed in', function (): void {
    $this->get(route('robot-council.signed-out'))
        ->assertOk()->assertSee('Signed out')->assertSeeHtml(route('robot-council.auth.redirect'));
});

it('refuses to frame the signed-out page', function (): void {
    $this->get(route('robot-council.signed-out'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('renders every panel inside the shell, each on its own page', function (): void {
    $this->setAccessLists(developers: [4242], admins: [4242]);

    // The panels moved off the index; what this pins is unchanged -- every one of them renders
    // inside the shell rather than as a bare page, which is what the sidebar and sign-out depend on.
    foreach (['agents', 'locks', 'queue', 'feed', 'administration'] as $section) {
        forgetResolvedGuards();

        $this->actingAs($this->developer, 'web')
            ->get(route('robot-council.'.$section))
            ->assertOk()
            ->assertSeeHtml('drawer-toggle')
            ->assertSeeHtml(route('robot-council.dashboard.stylesheet'))
            ->assertSee('Sign out');
    }
});

it('collapses the sidebar below the breakpoint and keeps it open at and above', function (): void {
    $page = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk();

    // The checkbox is what daisyUI styles against, so the shell opens and closes with no script
    $page->assertSeeHtml('class="drawer-toggle"')->assertSeeHtml('lg:drawer-open')->assertSeeHtml('drawer-button lg:hidden');

    // And the artifact has to carry the rule, or the markup above styles nothing
    $stylesheet = (string) file_get_contents(__DIR__.'/../resources/dist/dashboard.css');

    expect($stylesheet)->toContain('.drawer-toggle')
        ->and($stylesheet)->toContain('drawer-open');
});

it('cannot assert the CSRF refusal, and says so rather than passing for the wrong reason', function (): void {
    // `PreventRequestForgery::handle()` returns early when `runningUnitTests()` is true, and that
    // method is `runningInConsole() && runningUnitTests()` -- both true for every test in this
    // suite. So a test posting without a token would be admitted by the framework rather than by
    // this package, and would pass identically with the middleware removed.
    //
    // What can be asserted is that the route runs in the group carrying that middleware, which is
    // what the test above pairs with the rendered `_token` field.
    $middleware = collect(app('router')->getRoutes()->getRoutesByName())
        ->filter(fn ($route, string $name): bool => $name === 'robot-council.sign-out')
        ->flatMap(fn ($route): array => $route->gatherMiddleware())
        ->all();

    expect($middleware)->toContain('web')
        ->and(app()->runningUnitTests())->toBeTrue('If this is ever false, the refusal itself becomes assertable and this test should be replaced.');
});

it('mounts the sign-out route under the configured prefix', function (): void {
    // A host mounts this package wherever it likes, so nothing in the shell may assume the default
    $this->rebootWith('robot-council.routes.web_prefix', 'council');

    expect(route('robot-council.sign-out', absolute: false))->toBe('/council/sign-out')
        ->and(route('robot-council.signed-out', absolute: false))->toBe('/council/signed-out');
});

it('remembers where an unauthenticated visitor was going, but only when it can resume', function (): void {
    // A GET is resumable, so it is worth remembering
    $this->get(route('robot-council.dashboard'))
        ->assertRedirect(route('robot-council.auth.redirect'));

    expect(session('url.intended'))->toBe('/robot-council/dashboard');

    session()->forget('url.intended');

    // A POST is not. Laravel resumes an intended URL with a redirect, which the browser follows as
    // a GET -- so remembering this one sends the developer, after a round trip through GitHub, to a
    // 405 on a route that accepts POST only.
    $this->post(route('robot-council.sign-out'))
        ->assertRedirect(route('robot-council.auth.redirect'));

    expect(session('url.intended'))->toBeNull();
});

it('renders the enrollment page inside the same shell', function (): void {
    $page = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show'))
        ->assertOk();

    // The shell's own furniture, not the page's: before #189 this page was a standalone document
    // with its own inline `<style>` and no way back to anywhere.
    //
    // The way back is read as an anchor, for the reason given on the test above: the dashboard's
    // URL is a prefix of all four section URLs, so the string form cannot tell "there is a link
    // home" from "there is a link to one of its sections".
    expect(anchorTagFor($page->getContent(), route('robot-council.dashboard')))->not->toBeEmpty();

    $page->assertSeeHtml(route('robot-council.dashboard.stylesheet'))
        ->assertSeeHtml('drawer-toggle')
        ->assertSeeHtml('Sign out');

    // And the page's own content is still there
    $page->assertSee('Approve a machine')
        ->assertSee('The code shown on that machine');
});

it('marks the enrollment page as current when that is the page being shown', function (): void {
    $page = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show'))
        ->assertOk();

    // The mirror of the dashboard's case, and only testable now that this page uses the layout
    $enrollTag = anchorTagFor($page->getContent(), route('robot-council.enroll.show'));
    $overviewTag = anchorTagFor($page->getContent(), route('robot-council.dashboard'));

    expect($enrollTag)->toContain('aria-current="page"')
        ->and($enrollTag)->toContain('menu-active')
        ->and($overviewTag)->not->toContain('aria-current')
        ->and($overviewTag)->not->toContain('menu-active');
});

it('loads no script on the page whose whole job is a human decision', function (): void {
    // The enrollment page mounts no Livewire component, so it declines Livewire's assets. Asserted
    // as "no script or style tag at all" rather than against an asset's name: Livewire 4 serves its
    // script from a per-application randomized path (`/livewire-<hex>/livewire.min.js`) and the
    // filename itself changes with debug mode, so a name-shaped assertion is a trap -- the first
    // draft of this test asserted `livewire.js` and failed against `livewire.min.js`.
    //
    // The dashboard is asserted in the same test, because "no script here" means nothing beside a
    // page that loads none either: without the second half, a broken check reads as a pass.
    withoutLivewiresAutoInjectedAssets();

    $enroll = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show'))
        ->assertOk();

    expect(tagsIn($enroll->getContent()))->toBeEmpty();

    forgetResolvedGuards();

    $dashboard = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk();

    expect(tagsIn($dashboard->getContent()))->not->toBeEmpty();
});
