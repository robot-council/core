<?php

declare(strict_types=1);

/**
 * Where this package mounts, and what answers at the root of it.
 *
 * `GET /robot-council` returned 404 while `GET /` redirected to the dashboard, so the prefix the
 * package is mounted under was the one path between the two that led nowhere.
 *
 * **The empty-prefix rows are not a defensive branch.** `robot-council/robot-council` sets
 * `ROBOT_COUNCIL_WEB_PREFIX` to an empty string, so the deployment mounts this package at the
 * application root. That behavior worked and nothing pinned it; these tests are the pin.
 *
 * A case that changes the prefix reboots the application, because the provider reads it at boot and
 * `config()->set()` in a test body is too late. The cases that assert the default do not, and are
 * the ones with no `rebootWith()` call.
 *
 * @command  vendor/bin/pest --compact tests/WebPrefixTest.php
 */

use Illuminate\Support\Facades\Route;

/**
 * The URI every NAMED route in this package mounts at.
 *
 * Reads `getRoutesByName()`, so a route registered without a name is invisible here. Every web route
 * this package mounts is named, so nothing is missed today -- but the bound belongs on the helper
 * rather than on the reader, because "every route" is wider than the instrument.
 *
 * @return array<string, string> Route name without the package prefix, mapped to its URI.
 */
function packageRouteUris(): array
{
    $found = [];

    foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
        if (str_starts_with($name, 'robot-council.')) {
            $found[substr($name, \strlen('robot-council.'))] = $route->uri();
        }
    }

    return $found;
}

it('sends the prefix root to the dashboard', function (): void {
    $this->get('/robot-council')
        ->assertRedirect(route('robot-council.dashboard'));
});

it('answers the prefix root with a trailing slash the same way', function (): void {
    // Laravel trims a trailing slash before matching, so this is one route rather than two -- but
    // the deployment measured both as 404 and the criterion names both, so both are asserted.
    $this->get('/robot-council/')
        ->assertRedirect(route('robot-council.dashboard'));
});

it('redirects temporarily, so a later prefix change is not fighting a cached answer', function (): void {
    // 302, not 301. A permanent redirect is cached against the path rather than against the
    // configuration behind it, and a host cannot reach into a browser to invalidate one.
    $this->get('/robot-council')->assertStatus(302);
});

it('mounts the redirect at whatever prefix the host configured', function (): void {
    $this->rebootWith('robot-council.routes.web_prefix', 'council');

    $this->get('/council')->assertRedirect(route('robot-council.dashboard'));

    // And the default is gone, which is what says the route followed the configuration rather than
    // being registered at both
    $this->get('/robot-council')->assertNotFound();
});

it('registers no redirect when the prefix is empty, because that path belongs to the host', function (): void {
    // The live configuration: `robot-council/robot-council` runs an empty prefix, so the package
    // sits at the application root and `/` is the host's own route to own.
    $this->rebootWith('robot-council.routes.web_prefix', '');

    expect(packageRouteUris())->not->toHaveKey('prefix-root');

    // Nothing of this package's answers at `/`. Testbench mounts no route there, so a 404 here is
    // the absence of a route rather than the host's page.
    $this->get('/')->assertNotFound();
});

it('registers no redirect for a prefix that resolves to the root, however it was written', function (string $prefix): void {
    // **The guard asks about the resolved PATH, not about what the host typed**, and these two are
    // where that matters: `Router::prefix()` resolves this route's URI to
    // `trim(trim($prefix, '/').'/', '/') ?: '/'`, so `''` and `'/'` produce a URI of `/` and every
    // other route in the package mounts identically under both. A guard comparing the raw value
    // admitted the second, putting a package route on the host's own home page -- where
    // `RouteCollection` keys by URI, so one of the two silently replaces the other and which one
    // wins is decided by provider boot order.
    $this->rebootWith('robot-council.routes.web_prefix', $prefix);

    expect(packageRouteUris())->not->toHaveKey('prefix-root');

    $this->get('/')->assertNotFound();
})->with([
    'an empty string, which the deployment runs' => [''],
    'a bare slash, which means the same thing' => ['/'],
    'more than one slash, which also trims to nothing' => ['//'],
]);

it('sends an unauthenticated visitor on to GitHub, exactly as the dashboard does', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // Asserted hop by hop rather than with `followingRedirects()`, because the two hops are
    // answered by different things and a single assertion on the destination would not say which
    // one broke: the first is this package's redirect, the second is the allowlist gate refusing an
    // unauthenticated visitor. Both are named so a failure says which.
    $this->get('/robot-council')
        ->assertRedirect(route('robot-council.dashboard'));

    $this->get(route('robot-council.dashboard'))
        ->assertRedirect(route('robot-council.auth.redirect'));
});

it('mounts every web route at the application root when the prefix is empty', function (): void {
    $this->rebootWith('robot-council.routes.web_prefix', '');

    // The web half, which is what an empty `web_prefix` moves. The API routes are in the same name
    // list and keep their own prefix, which the case below is about; separating them here is what
    // lets this one be an exact comparison rather than a subset.
    $web = array_filter(
        packageRouteUris(),
        static fn (string $uri): bool => ! str_starts_with($uri, 'robot-council/api')
    );

    // Sorted, because `toBe` compares order and the order here is registration order -- the
    // stylesheet is mounted in its own group before `routes/web.php` is loaded. That is incidental,
    // and pinning it would fail the next time a route moved in the file without moving in the URL.
    ksort($web);

    // Every web route the package mounts, at the root rather than under a prefix. Seventeen, not the
    // seven the issue counted: #215 split the dashboard into five pages, #308 split one of those in
    // two and kept the old path as a redirect, and sign-out and its landing page arrived with the
    // shell.
    //
    // **Exact rather than `toMatchArray`**, which asserts only that the expected keys are present
    // and would have passed with `prefix-root` mounted at `/` -- the one thing this case sits
    // beside to rule out. It also fails when a route is ADDED without being accounted for here,
    // which a subset assertion cannot do.
    expect($web)->toBe([
        'access' => 'dashboard/access',
        'administration' => 'dashboard/administration',
        'agents' => 'dashboard/agents',
        'auth.callback' => 'auth/github/callback',
        'auth.redirect' => 'auth/github/redirect',
        'dashboard' => 'dashboard',
        'dashboard.stylesheet' => 'dashboard.css',
        'enroll.approve' => 'enroll/approve',
        'enroll.deny' => 'enroll/deny',
        'enroll.show' => 'enroll',
        'feed' => 'dashboard/feed',
        'lanes' => 'dashboard/lanes',
        'locks' => 'dashboard/locks',
        'presence' => 'dashboard/presence',
        'queue' => 'dashboard/queue',
        'seats' => 'dashboard/seats',
        'sign-out' => 'sign-out',
        'signed-out' => 'signed-out',
        'waiting' => 'dashboard/waiting',
    ]);
});

it('leaves the API prefix alone at every web prefix', function (string $webPrefix): void {
    $this->rebootWith('robot-council.routes.web_prefix', $webPrefix);

    $uris = packageRouteUris();

    // **Load-bearing rather than tidy.** The command line hardcodes `/robot-council/api/...`, so a
    // web prefix that dragged the API with it would break every enrolled agent at once. The two
    // keys are separate and this is what says they stay separate.
    expect($uris['events.index'] ?? null)->toStartWith('robot-council/api/');
})->with([
    'the default' => ['robot-council'],
    'a host that chose another' => ['council'],
    'the empty prefix the deployment runs' => [''],
]);
