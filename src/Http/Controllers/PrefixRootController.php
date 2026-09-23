<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * Sends the package's own prefix root to the dashboard.
 *
 * `GET /robot-council` answered 404 while `GET /` redirected to the dashboard, so the prefix this
 * package is mounted under was the one path between the two that led nowhere. Nothing is mounted at
 * the group's root: `routes/web.php` mounts `auth/github/*`, `enroll`, `dashboard` and the rest
 * beneath it, and a prefix with no route of its own resolves to no route.
 *
 * **A controller rather than a closure, and that is not a style preference.** `Route::redirect()`
 * and a closure route both make `php artisan route:cache` fail with a closure-serialization error,
 * which would take a production deployment step away from every host that installs this package.
 *
 * **Outside the allowlist gate**, for the same reason the stylesheet is: it decides nothing and
 * reads nothing, so there is no call for `StartSession` to write a session record for a visitor who
 * is being sent somewhere else. Whoever follows it arrives at the dashboard, which is gated, and is
 * sent to GitHub from there exactly as a direct visit would be.
 *
 * Nothing from the request is read, so there is nothing here to escape.
 */
final class PrefixRootController
{
    /**
     * Redirect to the dashboard.
     *
     * @return RedirectResponse A 302 to `robot-council.dashboard`.
     */
    public function __invoke(): RedirectResponse
    {
        // 302 rather than 301. A permanent redirect is cached by the browser against the path
        // rather than against the configuration behind it, so a host that later moves its prefix --
        // or sets it to the empty string, which is what the deployment did -- would be fighting
        // copies of this answer it cannot reach to invalidate.
        return redirect()->route('robot-council.dashboard');
    }
}
