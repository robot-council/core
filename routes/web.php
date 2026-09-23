<?php

declare(strict_types=1);

/**
 * The package's human-facing web routes: GitHub sign-in and sign-out, the dashboard, and the page
 * where a developer approves or denies a machine's enrollment. `RobotCouncilServiceProvider`
 * applies the configured prefix and middleware group, and the `robot-council.` route-name prefix.
 *
 * Approve, deny and sign-out accept POST only. A decision that could be made by following a link is
 * a decision an attacker can have a developer make for them, and a link prefetcher can follow one
 * with nobody clicking anything.
 */

use Illuminate\Support\Facades\Route;
use RobotCouncil\Http\Controllers\EnrollmentDecisionController;
use RobotCouncil\Http\Controllers\EnrollmentPageController;
use RobotCouncil\Http\Controllers\GitHubCallbackController;
use RobotCouncil\Http\Controllers\GitHubRedirectController;
use RobotCouncil\Http\Controllers\SignedOutController;
use RobotCouncil\Http\Controllers\SignOutController;
use RobotCouncil\Http\Middleware\DenyFraming;
use RobotCouncil\Http\Middleware\EnsureAllowlistedDeveloper;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Livewire\ChangeFeed;
use RobotCouncil\Livewire\Dashboard;
use RobotCouncil\Livewire\FleetPresence;
use RobotCouncil\Livewire\TaskBoard;
use RobotCouncil\RobotCouncilServiceProvider;

Route::get('auth/github/redirect', GitHubRedirectController::class)->name('auth.redirect');
Route::get('auth/github/callback', GitHubCallbackController::class)->name('auth.callback');

// Outside the allowlist gate, necessarily: whoever reaches it is not signed in, so mounting it
// behind the gate would send them to GitHub and undo what they just asked for.
Route::get('signed-out', SignedOutController::class)
    ->middleware(DenyFraming::class)
    ->name('signed-out');

Route::middleware([EnsureAllowlistedDeveloper::class, DenyFraming::class])->group(function (): void {
    Route::get('enroll', EnrollmentPageController::class)->name('enroll.show');

    // The dashboard. Behind the same allowlist gate and framing refusal as the verification page,
    // because it displays the whole fleet's state to whoever reaches it.
    Route::get('dashboard', Dashboard::class)->name('dashboard');

    // One page per panel, the decision on #187 as reversed there. Each is a routable Livewire
    // component carrying its own layout, so nothing decides which panels a page mounts -- the
    // route does, and there is no selection to keep in step.
    //
    // Under `dashboard/` rather than at the prefix root: a host may mount this package with an
    // EMPTY `robot-council.routes.web_prefix`, as this package's own deployment does, and a
    // top-level `queue` or `feed` would then sit directly in that host's own namespace.
    //
    // `administration` needs no gate of its own. `Livewire\Administration::mount()` refuses a
    // developer who is not an admin, so a direct visit answers 403 from the component; a route
    // middleware would be a second place to get the same rule right.
    Route::get('dashboard/presence', FleetPresence::class)->name('presence');
    Route::get('dashboard/queue', TaskBoard::class)->name('queue');
    Route::get('dashboard/feed', ChangeFeed::class)->name('feed');
    Route::get('dashboard/administration', Administration::class)->name('administration');

    // Inside the gate, because signing out is something a signed-in developer does. A developer
    // whose account has left the access lists never reaches it -- the gate ends their session on
    // the way to refusing them, which is the same three steps this route performs.
    Route::post('sign-out', SignOutController::class)->name('sign-out');

    Route::middleware('throttle:'.RobotCouncilServiceProvider::VERIFICATION_LIMITER)->group(function (): void {
        Route::post('enroll/approve', [EnrollmentDecisionController::class, 'approve'])->name('enroll.approve');
        Route::post('enroll/deny', [EnrollmentDecisionController::class, 'deny'])->name('enroll.deny');
    });
});
