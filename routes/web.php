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
use RobotCouncil\Livewire\SeatSettings;
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
    //
    // **No `wire:poll` here carries `.visible`, and the condition that would bring it back is about
    // what sits BELOW the polled element, not about how many components a page mounts.** Livewire
    // pauses a poll while `theDirectiveHasVisible(directive) && theElementIsNotInTheViewport(el)`,
    // and the second half tests the polled element's own bounding box for zero intersection. Each
    // panel's `wire:poll` sits on its root element, and on these four routes nothing below that
    // element is a viewport tall -- only `<main>`'s own padding -- so it cannot be scrolled out and
    // the modifier would pause nothing. #194 proposed it when four panels shared one page and most
    // sat below the fold; splitting them onto routes is what retired it. The decision and the
    // measurement are on that ticket, which the tracker records as closed-as-completed because that
    // is what a merge does -- "refuted" is the reason, and it is written there rather than implied
    // by a status.
    //
    // **So the rule is: anything rendered below a polled element that can exceed the viewport
    // height brings the case back.** One polling component above a long static list is affected
    // exactly as much as two polling components, which is why counting them is the wrong test. The
    // overview is the live example rather than a counter-example: it mounts ONE polling component,
    // the totals row, with the sections card beneath it, so a short enough viewport scrolls that
    // row out of view and `.visible` would genuinely pause it. It was left alone because the saving
    // is three counting queries per interval on one page, not because the modifier would do nothing
    // there.
    //
    // **Add it as `wire:poll.<seconds>s.visible`, in that order.** The interval survives either way
    // -- `extractDurationFrom` finds it with `modifiers.find(mod => mod.match(/([0-9]+)s/))`, by
    // pattern rather than by position -- but this suite matches the rendered attribute as a literal
    // substring in several places, so `wire:poll.visible.5s` fails tests that the other order
    // leaves green.
    //
    // None of this touches the background-tab throttle. That is a separate `throttleWhile` on the
    // same directive and needs no modifier; `theDirectiveIsMissingKeepAlive()` is what it reads, so
    // `.keep-alive` would opt OUT of it and nothing here should carry that one.
    Route::get('dashboard/presence', FleetPresence::class)->name('presence');
    Route::get('dashboard/queue', TaskBoard::class)->name('queue');
    Route::get('dashboard/feed', ChangeFeed::class)->name('feed');
    Route::get('dashboard/administration', Administration::class)->name('administration');
    Route::get('dashboard/seats', SeatSettings::class)->name('seats');

    // Inside the gate, because signing out is something a signed-in developer does. A developer
    // whose account has left the access lists never reaches it -- the gate ends their session on
    // the way to refusing them, which is the same three steps this route performs.
    Route::post('sign-out', SignOutController::class)->name('sign-out');

    Route::middleware('throttle:'.RobotCouncilServiceProvider::VERIFICATION_LIMITER)->group(function (): void {
        Route::post('enroll/approve', [EnrollmentDecisionController::class, 'approve'])->name('enroll.approve');
        Route::post('enroll/deny', [EnrollmentDecisionController::class, 'deny'])->name('enroll.deny');
    });
});
