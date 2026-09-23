<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * That the package declares its routes on an ordinary host.
 *
 * **The control for `CachedRoutesRegistrationTest`.** That file asserts the package declares no
 * routes when the collection is cached, and an empty list there would be equally consistent with
 * the package having stopped declaring routes at all. This is the other half, and it lives in its
 * own file because every test in that one boots with a cached collection by construction.
 *
 * @command  vendor/bin/pest tests/RouteRegistrationTest.php
 */
it('declares its routes when the collection is not cached', function (): void {
    expect(app()->routesAreCached())->toBeFalse();

    $named = collect(Route::getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $name): bool => str_starts_with($name, 'robot-council.'));

    expect($named)->not->toBeEmpty()
        ->and($named)->toContain('robot-council.dashboard');
});
