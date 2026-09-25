<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * That a host which has cached its routes still gets this package's Livewire components.
 *
 * `RobotCouncilServiceProvider::registerRoutes()` returns early on `routesAreCached()`, and three
 * things behind that guard are not routes: the five `Livewire::component()` registrations, the
 * persistent middleware, and the MCP server. Every dashboard page mounts at least one of those
 * components **by name**, so on such a host the page cannot render (#219).
 *
 * **The application here boots with its routes already cached**, because `TestCase::defineEnvironment()` writes one
 * for any test class whose name carries `CachedRoutes`. `refreshApplication()` cannot stand in for it:
 * rebooting mid-test leaves Livewire unable to resolve any component name at all, registered or
 * not -- measured, and caught by a negative control rather than by reading the output.
 *
 * @command  vendor/bin/pest tests/CachedRoutesRegistrationTest.php
 */

/**
 * Whether Livewire can resolve a component name, judged by which exception escapes.
 *
 * `ComponentNotFoundException` is thrown while resolving, before anything renders, so any other
 * failure means resolution already succeeded and rendering got further. That is what makes this
 * readable without a database.
 */
function componentIsRegistered(string $name): bool
{
    try {
        Livewire::test($name);

        return true;
    } catch (Throwable $throwable) {
        $root = $throwable;
        while ($root->getPrevious() instanceof Throwable) {
            $root = $root->getPrevious();
        }

        return class_basename($root) !== 'ComponentNotFoundException';
    }
}

it('boots with its routes cached, which everything below depends on', function (): void {
    // Without this the assertions would pass against an ordinary application and prove nothing.
    expect(app()->routesAreCached())->toBeTrue();
});

it('can tell a registered name from an unregistered one', function (): void {
    // The instrument's own control. An earlier version of this file used a probe that answered
    // "registered" for every name, including invented ones, and its readings meant nothing.
    expect(componentIsRegistered('robot-council-not-a-component'))->toBeFalse();
});

it('registers every component name even though the routes are cached', function (): void {
    foreach ([
        'robot-council-administration',
        'robot-council-agents',
        'robot-council-change-feed',
        'robot-council-fleet-totals',
        'robot-council-lanes',
        'robot-council-locks',
        'robot-council-seat-settings',
        'robot-council-task-board',
    ] as $name) {
        expect(componentIsRegistered($name))->toBeTrue(
            sprintf('`%s` is unresolvable, so a dashboard page mounting it cannot render', $name)
        );
    }
});

it('still declares no routes when the collection is cached', function (): void {
    // **The other half of the fix, and the one that could be traded away silently.** Lifting the
    // component registrations out of the guard is only correct if the `Route::` declarations stay
    // behind it -- re-registering them on every request is the cost the guard exists to avoid, and
    // nothing in the page would look different if that regressed.
    //
    // The cached collection this test boots with declares nothing, so any route named by the
    // package would have to have been declared during this boot.
    $named = collect(Route::getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $name): bool => str_starts_with($name, 'robot-council.'));

    expect($named)->toBeEmpty(
        'the package declared routes despite a cached collection: '.$named->implode(', ')
    );
});
