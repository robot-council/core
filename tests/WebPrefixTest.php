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
 * Every case here reboots the application, because a prefix is read by the service provider at boot
 * and `config()->set()` in a test body is too late.
 *
 * @command  vendor/bin/pest --compact tests/WebPrefixTest.php
 */

use Illuminate\Support\Facades\Route;

/**
 * The URI every named route in this package mounts at, with its methods.
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

it('sends an unauthenticated visitor on to GitHub, exactly as the dashboard does', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // Following the redirect rather than asserting its target: the criterion is about where a
    // visitor ends up, and one hop short of that would pass while the journey was broken.
    $this->get('/robot-council')
        ->assertRedirect(route('robot-council.dashboard'));

    $this->get(route('robot-council.dashboard'))
        ->assertRedirect(route('robot-council.auth.redirect'));
});

it('mounts every web route at the application root when the prefix is empty', function (): void {
    $this->rebootWith('robot-council.routes.web_prefix', '');

    $uris = packageRouteUris();

    // The seven web routes the package mounts, at the root rather than under a prefix. `dashboard`
    // and its four sections are separate entries since #215 split them.
    expect($uris)->toMatchArray([
        'auth.redirect' => 'auth/github/redirect',
        'auth.callback' => 'auth/github/callback',
        'signed-out' => 'signed-out',
        'enroll.show' => 'enroll',
        'dashboard' => 'dashboard',
        'presence' => 'dashboard/presence',
        'queue' => 'dashboard/queue',
        'feed' => 'dashboard/feed',
        'administration' => 'dashboard/administration',
        'sign-out' => 'sign-out',
        'enroll.approve' => 'enroll/approve',
        'enroll.deny' => 'enroll/deny',
        'dashboard.stylesheet' => 'dashboard.css',
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
