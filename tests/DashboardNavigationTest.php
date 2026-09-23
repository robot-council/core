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

it('links to every page a signed-in developer can reach', function (): void {
    $page = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk();

    // Both pages the package mounts behind the gate, reachable by clicking rather than by typing
    $page->assertSeeHtml(route('robot-council.dashboard'))->assertSeeHtml(route('robot-council.enroll.show'));
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

it('offers the administration jump link only to an admin', function (): void {
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))->assertOk()->assertDontSeeHtml('#robot-council-administration');

    $this->setAccessLists(developers: [4242], admins: [4242]);

    forgetResolvedGuards();

    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))->assertOk()->assertSeeHtml('#robot-council-administration');
});

it('jumps to a panel that is actually on the page', function (): void {
    $page = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk();

    // Each jump link has a target, which is what stops the sidebar offering a link to nowhere
    foreach (['presence', 'queue', 'change-feed'] as $section) {
        $page->assertSeeHtml('href="#robot-council-'.$section.'"')->assertSeeHtml('id="robot-council-'.$section.'"');
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

it('keeps every panel inside the new shell', function (): void {
    $this->setAccessLists(developers: [4242], admins: [4242]);

    $page = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertOk();

    // The four panels still render, and each sits in the section its jump link targets
    foreach (['presence', 'queue', 'change-feed', 'administration'] as $section) {
        $page->assertSeeHtml('id="robot-council-'.$section.'"');
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
