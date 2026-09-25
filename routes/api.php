<?php

declare(strict_types=1);

/**
 * The package's machine-facing routes. `RobotCouncilServiceProvider` applies the configured prefix
 * and middleware group, and the `robot-council.` route-name prefix.
 *
 * The two device endpoints are unauthenticated, because a machine enrolling has no credential yet.
 * What stands in for authentication there is the verifier only the helper holds, and the developer
 * at a browser who has to approve the request.
 *
 * Everything else names its principal middleware explicitly. A Sanctum guard alone does not say
 * what authenticated: it falls back to the `web` guard first, so a signed-in human in a browser
 * reaches these URLs as themselves.
 */

use Illuminate\Support\Facades\Route;
use RobotCouncil\Access\Ability;
use RobotCouncil\Http\Controllers\AgentHeartbeatController;
use RobotCouncil\Http\Controllers\AgentSessionController;
use RobotCouncil\Http\Controllers\BacklogReadingController;
use RobotCouncil\Http\Controllers\CreateTaskController;
use RobotCouncil\Http\Controllers\DeveloperSettingsController;
use RobotCouncil\Http\Controllers\DeviceCodeController;
use RobotCouncil\Http\Controllers\DeviceTokenController;
use RobotCouncil\Http\Controllers\FleetFeedController;
use RobotCouncil\Http\Controllers\GateRunController;
use RobotCouncil\Http\Controllers\GitHubWebhookController;
use RobotCouncil\Http\Controllers\LaneHoldController;
use RobotCouncil\Http\Controllers\ListTasksController;
use RobotCouncil\Http\Controllers\LockController;
use RobotCouncil\Http\Controllers\OwedItemController;
use RobotCouncil\Http\Controllers\PostDirectiveController;
use RobotCouncil\Http\Controllers\PostNarrationController;
use RobotCouncil\Http\Controllers\ReportTaskBranchController;
use RobotCouncil\Http\Controllers\RequestRoleController;
use RobotCouncil\Http\Controllers\SessionEndController;
use RobotCouncil\Http\Controllers\SessionRenewController;
use RobotCouncil\Http\Controllers\SessionStartController;
use RobotCouncil\Http\Controllers\TransitionTaskController;
use RobotCouncil\Http\Controllers\WatcherHeartbeatController;
use RobotCouncil\Http\Middleware\EnsureAgentSession;
use RobotCouncil\Http\Middleware\EnsureInstallation;
use RobotCouncil\Http\Middleware\RequireAbility;
use RobotCouncil\Http\Middleware\VerifyGitHubSignature;
use RobotCouncil\Models\LockAction;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\RobotCouncilServiceProvider;

Route::post('device/code', DeviceCodeController::class)
    ->middleware('throttle:'.RobotCouncilServiceProvider::DEVICE_CODE_LIMITER)
    ->name('device.code');

Route::post('device/token', DeviceTokenController::class)
    ->middleware('throttle:'.RobotCouncilServiceProvider::DEVICE_TOKEN_LIMITER)
    ->name('device.token');

// The limiter is declared FIRST because declaration order is what decides this. `sortMiddleware()`
// reorders only middleware that are themselves in the framework's priority list, relative to each
// other, and `EnsureInstallation` is not in it -- so with `ThrottleRequests` the only member
// present, nothing moves. Declared after the guard, the limiter never runs for a request the guard
// refuses, and an unauthenticated flood is not limited at all. The limiter resolves the
// installation through the guard, so it needs nothing the guard would have left behind.
// GitHub's deliveries (#318). Signed rather than authenticated: GitHub holds no token, only the
// shared secret. The limiter is declared first, which is what makes it run first.
Route::post('github/webhook', GitHubWebhookController::class)
    ->middleware(['throttle:'.RobotCouncilServiceProvider::GITHUB_WEBHOOK_LIMITER, VerifyGitHubSignature::class])
    ->name('github.webhook');

Route::middleware(['throttle:'.RobotCouncilServiceProvider::SESSIONS_LIMITER, EnsureInstallation::class])
    ->group(function (): void {
        Route::post('sessions', SessionStartController::class)->name('sessions.start');
        // Constrained, so an id no bigint can hold is a 404 rather than a 500. `whereNumber` is
        // `[0-9]+`, which bounds the character set and not the magnitude, and Postgres raises
        // `22P02` for a non-numeric id and `22003` for an overlong one where SQLite quietly
        // matches no rows. Eighteen digits is inside a signed 64-bit integer whatever they are.
        Route::post('sessions/{session}/renew', SessionRenewController::class)
            ->where('session', RobotCouncilServiceProvider::ROUTE_ID)
            ->name('sessions.renew');

        // Ending a session is the installation's to do, not the session's: the token belonging to
        // the process that just died is the one thing that may no longer work
        Route::delete('sessions/{session}', SessionEndController::class)
            ->where('session', RobotCouncilServiceProvider::ROUTE_ID)
            ->name('sessions.end');
    });

// Every agent route is limited per session, so one runaway process cannot crowd out the fleet --
// and the limiter is declared ahead of the guard, for the reason the sessions group records
Route::middleware(['throttle:'.RobotCouncilServiceProvider::AGENT_LIMITER, EnsureAgentSession::class])
    ->group(function (): void {
        Route::get('agent/session', AgentSessionController::class)->name('agent.session');

        // Contact is recorded for every route in this group, so this one is for a process that has
        // nothing else to send rather than the only thing that keeps a session alive
        Route::post('agent/heartbeat', AgentHeartbeatController::class)->name('agent.heartbeat');

        // The bridge watcher's own heartbeat, recorded apart from the session's contact (#337)
        Route::post('agent/watcher', WatcherHeartbeatController::class)->name('agent.watcher');

        // **No ability, because asking is not doing**, and its own limiter on top of the group's:
        // a denied session re-asking in a loop fills an administrator's queue, which is a flood
        // aimed at a human. The limiter is declared here rather than on the group so it stacks
        // with `AGENT_LIMITER` rather than replacing it.
        Route::post('agent/role', RequestRoleController::class)
            ->middleware('throttle:'.RobotCouncilServiceProvider::ROLE_REQUEST_LIMITER)
            ->name('agent.role');

        // Reading the feed needs no ability: what a session may see is decided by whose narration
        // it is, not by what the session was granted
        Route::get('events', FleetFeedController::class)->name('events.index');

        Route::post('events', PostNarrationController::class)
            ->middleware(RequireAbility::class.':'.Ability::EventsPost->value)
            ->name('events.store');

        // The one ability enrollment can never ask for, granted only by an admin afterwards
        // A session's count of a repository's open issues, for the backlog meters (#339)
        Route::post('backlog/readings', BacklogReadingController::class)
            ->middleware(RequireAbility::class.':'.Ability::EventsPost->value)
            ->name('backlog.readings');

        // A gate's own run (#336). Behind `tasks:claim`; the store checks the session is a gate
        Route::post('gates/run', [GateRunController::class, 'store'])
            ->middleware(RequireAbility::class.':'.Ability::TasksClaim->value)
            ->name('gates.run');
        Route::delete('gates/run', [GateRunController::class, 'destroy'])
            ->middleware(RequireAbility::class.':'.Ability::TasksClaim->value)
            ->name('gates.finish');

        // What the fleet is waiting on a developer for (#335). The coordinator's record
        Route::post('owed-items', [OwedItemController::class, 'store'])
            ->middleware(RequireAbility::class.':'.Ability::CoordinatorDirect->value)
            ->name('owed.store');
        Route::delete('owed-items/{item}', [OwedItemController::class, 'destroy'])
            ->where('item', RobotCouncilServiceProvider::ROUTE_ID)
            ->middleware(RequireAbility::class.':'.Ability::CoordinatorDirect->value)
            ->name('owed.settle');

        Route::post('directives', PostDirectiveController::class)
            ->middleware(RequireAbility::class.':'.Ability::CoordinatorDirect->value)
            ->name('directives.store');

        // Read-only: the coordinator reads developers' settings and never writes them (#314). The
        // dashboard is their only writer.
        Route::get('developers/settings', DeveloperSettingsController::class)
            ->middleware(RequireAbility::class.':'.Ability::CoordinatorDirect->value)
            ->name('developers.settings');

        // Why a lane is idle on purpose (#334). The coordinator's record, so its ability alone
        Route::post('lanes/{session}/hold', [LaneHoldController::class, 'store'])
            ->where('session', RobotCouncilServiceProvider::ROUTE_ID)
            ->middleware(RequireAbility::class.':'.Ability::CoordinatorDirect->value)
            ->name('lanes.hold');
        Route::delete('lanes/{session}/hold', [LaneHoldController::class, 'destroy'])
            ->where('session', RobotCouncilServiceProvider::ROUTE_ID)
            ->middleware(RequireAbility::class.':'.Ability::CoordinatorDirect->value)
            ->name('lanes.clear-hold');

        // Every agent sees every task: an agent cannot decide whether to claim work it cannot see,
        // and a queue half the fleet is blind to is a queue that deadlocks. What narrows a task is
        // claiming it, and #16's eligibility rule rides in the claim's own conditional update.
        Route::get('tasks', ListTasksController::class)->name('tasks.index');

        Route::post('tasks', CreateTaskController::class)
            ->middleware(RequireAbility::class.':'.Ability::TasksCreate->value)
            ->name('tasks.store');

        // All eight transitions through one route, whose constraint is built from the enum, so an
        // unknown one is a 404 from the router. The ability each needs is on `TaskTransition` and
        // checked in the controller rather than declared here: `release` is allowed to the session
        // holding the task *or* to a coordinator, and no single ability names that.
        // The lock's name is in the body, never in the path: a route parameter does not match `/`,
        // and `branch:feature/foo` is exactly the kind of name worth locking. The ability each
        // action needs is on `LockAction` -- `force-release` needs the coordinator's, the rest need
        // `locks:acquire` -- and is checked in the controller so the two cannot drift apart.
        Route::post('locks/{action}', LockController::class)
            ->where('action', implode('|', LockAction::values()))
            ->name('locks.action');

        // The lane holding a task reports its branch once it has one (robot-council/cli#238). Its
        // own route rather than a transition, because it moves no status.
        Route::post('tasks/{task}/branch', ReportTaskBranchController::class)
            ->where('task', RobotCouncilServiceProvider::ROUTE_ID)
            ->middleware(RequireAbility::class.':'.Ability::TasksClaim->value)
            ->name('tasks.branch');

        Route::post('tasks/{task}/{transition}', TransitionTaskController::class)
            ->where('task', RobotCouncilServiceProvider::ROUTE_ID)
            ->where('transition', implode('|', TaskTransition::values()))
            ->name('tasks.transition');
    });
