<?php

declare(strict_types=1);

/**
 * What one render of the presence panel costs, and along which axes that cost can grow.
 *
 * The panel was the most expensive of the four and nothing said where its queries went. This pins
 * the numbers so a later change that adds one fails here rather than shipping.
 *
 * **There are two numbers, not one.** A fleet with no lock held costs six; one with any lock held
 * costs eight, because resolving a lock's holder is the one hop that cannot read a row already in
 * hand. The first draft of this file measured only the six, on a fixture that acquired no locks,
 * and called it the panel's cost.
 *
 * @command  vendor/bin/pest --compact tests/PresenceQueryCostTest.php
 */

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use RobotCouncil\Access\Ability;
use RobotCouncil\Livewire\FleetPresence as PresencePanel;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Support\FleetPresence as PresenceStore;
use RobotCouncil\Support\Locks;
use RobotCouncil\Tests\TestCase;

/**
 * Enroll a developer, grow the fleet, and optionally have it hold some locks.
 *
 * The developer is returned rather than stashed on the test case so the analyzer can see its type:
 * a property assigned in `beforeEach` and read inside an `it()` closure is `mixed` by the time it
 * reaches a typed parameter.
 *
 * @param  TestCase  $test  The test case, for its fixture helpers.
 * @param  int  $sessions  How many agent sessions the table should hold.
 * @param  int  $locks  How many locks the last session should hold.
 * @param  int  $githubId  The GitHub account the developer signs in as.
 * @param  string  $login  That account's login.
 * @return User The developer every installation was approved by.
 */
function fleetOf(TestCase $test, int $sessions, int $locks = 0, int $githubId = 4242, string $login = 'octodev'): User
{
    $developer = $test->enrollDeveloper($githubId, login: $login);

    $session = null;

    while (AgentSession::query()->count() < $sessions) {
        $existing = AgentSession::query()->count();

        $installation = $test->approveInstallation($developer, [
            Ability::TasksCreate->value,
            Ability::LocksAcquire->value,
        ], 'box-'.$githubId.'-'.$existing);

        [$session, $test->token] = $test->startAgentSession($installation);

        $test->session = $session;
    }

    for ($i = 0; $i < $locks; $i++) {
        if ($session instanceof AgentSession) {
            $test->service(Locks::class)->acquire($session, 'lock-'.$githubId.'-'.$i, 60, false);
        }
    }

    return $developer;
}

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);
});

it('renders on six queries when no lock is held', function (): void {
    $this->actingAs(fleetOf($this, 3), 'web');

    // The six, in the order they are issued:
    //
    //   1  the page of sessions, with `installation` eager-loaded
    //   2  that eager load
    //   3  the GitHub logins, by the `user_id` already on each loaded row
    //   4  the session totals, live and overall in one pass
    //   5  the page of locks
    //   6  the lock totals, held and overall in one pass
    //
    // Three were removed to get here. `AgentLogins::forSessions()` asked the session table for the
    // `user_id` of rows this method had just loaded in full, which cost two queries where
    // `forUsers()` costs one; and `gone` and `free` were each a second `count()` over a table the
    // line above had already counted.
    expect(queriesIssuedBy(fn () => Livewire::test(PresencePanel::class)))->toBe(6);
});

it('renders on eight queries when a lock is held', function (): void {
    $this->actingAs(fleetOf($this, 3, locks: 1), 'web');

    // The two extra are `AgentLogins::forSessions()` resolving the lock's holder: one read of the
    // session table for its `user_id`, then one of the identities table. They are **not** avoidable
    // the way the session-side pair was -- `locks()` maps holder ids belonging to sessions it never
    // loaded, so there is no row in hand to take a key off.
    //
    // This is the realistic figure. The panel's default lock scope is `Scope::Live`, which is
    // exactly the rows that still name a holder, so any fleet with a lock at all pays it.
    expect(queriesIssuedBy(fn () => Livewire::test(PresencePanel::class)))->toBe(8);
});

it('costs the same on a poll as it does on a mount', function (): void {
    $this->actingAs(fleetOf($this, 3, locks: 1), 'web');

    // `Livewire::test()` mounts AND renders; `->call('$refresh')` renders a second time. A poll is
    // one render, so the difference between the two is what a poll costs -- and reading the
    // combined figure as one cycle is how an earlier measurement reported double.
    $mountOnly = queriesIssuedBy(fn () => Livewire::test(PresencePanel::class));
    $mountAndRefresh = queriesIssuedBy(fn () => Livewire::test(PresencePanel::class)->call('$refresh'));

    expect($mountAndRefresh - $mountOnly)->toBe(8)
        ->and($mountAndRefresh)->toBe(16);
});

it('does not grow with the number of sessions', function (int $sessions): void {
    $this->actingAs(fleetOf($this, $sessions, locks: 1), 'web');

    expect(AgentSession::query()->count())->toBe($sessions)
        ->and(queriesIssuedBy(fn () => Livewire::test(PresencePanel::class)))->toBe(8);
})->with([1, 5, 20]);

it('does not grow with the number of locks', function (int $locks): void {
    // The axis the first draft never varied, and the only one along which this panel still makes a
    // multi-query hop. An N+1 introduced in the lock-holder lookup would be invisible to a test
    // that varies sessions alone.
    $this->actingAs(fleetOf($this, 2, locks: $locks), 'web');

    expect(queriesIssuedBy(fn () => Livewire::test(PresencePanel::class)))->toBe(8);
})->with([1, 5, 20]);

it('serves each developer their own login, not whichever the map happens to hold', function (): void {
    // The control this assertion needs. With one developer enrolled, `assertSee('octodev')` passes
    // for "the right login" and for "the only login there is" alike -- and the whole point of the
    // change under test is which key that lookup uses. `tests/FleetPresenceTest.php` spells out the
    // same trap for the lock holder column.
    $first = fleetOf($this, 1, githubId: 4242, login: 'octodev');
    fleetOf($this, 2, githubId: 77, login: 'otherdev');

    $this->actingAs($first, 'web');

    Livewire::test(PresencePanel::class)
        ->assertOk()
        ->assertSee('octodev')
        ->assertSee('otherdev')

        // Each machine label belongs to one of them, so a swapped key shows the wrong pairing
        ->assertSee('box-4242-0')
        ->assertSee('box-77-1');
});

it('counts held and free locks without a second scan', function (): void {
    $this->actingAs(fleetOf($this, 2, locks: 2), 'web');

    $store = $this->service(PresenceStore::class);

    // Both halves of the rewritten aggregate. Nothing else in the suite reads these, so without
    // this a swapped `held`/`free`, or `wholeNumber()`'s zero fallback becoming one, ships green.
    $locks = $store->locks(50);

    expect($locks['held'])->toBe(2)
        ->and($locks['free'])->toBe(0);
});

it('answers zero for both when the lock table is empty', function (): void {
    $this->actingAs(fleetOf($this, 1), 'web');

    // `sum()` answers NULL over an empty table on every engine, which is the branch
    // `wholeNumber()` exists for. Reachable on most runs and asserted on none until now.
    $locks = $this->service(PresenceStore::class)->locks(50);

    expect($locks['held'])->toBe(0)
        ->and($locks['free'])->toBe(0);
});

it('counts live and gone sessions without a second scan', function (): void {
    $this->actingAs(fleetOf($this, 3), 'web');

    $sessions = $this->service(PresenceStore::class)->sessions(50);

    expect($sessions['live'])->toBe(3)
        ->and($sessions['gone'])->toBe(0);
});
