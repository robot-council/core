<?php

declare(strict_types=1);

/**
 * What one render of the administration panel costs, and whether `live` and `retired` agree with
 * the table for every shape it can take.
 *
 * The panel counted the installation table twice to answer one question -- the same shape #192
 * removed from the presence panel. This pins what it costs now.
 *
 * @command  vendor/bin/pest --compact tests/AdministrationQueryCostTest.php
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\InstallationList;
use RobotCouncil\Tests\TestCase;

/**
 * Approve some installations, each holding some sessions.
 *
 * Ownership alternates between the two developers, so a login mapped by the wrong key shows the
 * wrong name against a machine label rather than being invisible.
 *
 * @param  TestCase  $test  The test case, for its fixture helpers.
 * @param  User  $first  The developer who owns the even-numbered installations.
 * @param  User  $second  The developer who owns the odd-numbered ones.
 * @param  int  $installations  How many to approve.
 * @param  int  $sessionsEach  How many sessions each should hold.
 */
function approveInstallations(TestCase $test, User $first, User $second, int $installations, int $sessionsEach = 1): void
{
    for ($i = 0; $i < $installations; $i++) {
        $installation = $test->approveInstallation(
            $i % 2 === 0 ? $first : $second,
            'box-'.$i
        );

        for ($session = 0; $session < $sessionsEach; $session++) {
            [$test->session, $test->token] = $test->startAgentSession($installation);
        }
    }
}

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77], admins: [4242]);
});

it('polls on five queries plus the allowlist read, and mounts on seven', function (): void {
    $admin = $this->enrollDeveloper(4242);
    approveInstallations($this, $admin, $this->enrollDeveloper(77, login: 'otherdev'), 3);
    $this->actingAs($admin, 'web');

    // The five a poll issues:
    //
    //   1  `authorizeAdmin()` resolving the signed-in developer's GitHub identity
    //   2  the page of installations, with `sessions` eager-loaded
    //   3  that eager load
    //   4  the GitHub logins, by the `user_id` on each loaded row
    //   5  the installation totals, live and overall in one pass
    //
    // The removed one was `retired`, which was only ever `count(*) - $live`.
    //
    // A mount costs one more, and it is not this panel's doing: `authorizeAdmin()` runs in both
    // `mount()` and `render()`, and a poll re-renders without re-mounting.
    //
    // **And one read of the added allowlist entries (#406), once per request.** `Access\Allowlist`
    // is scoped, so in production a poll -- its own request -- pays it as a sixth query. Here the
    // mount and the refresh share one application, so the mount pays it and the difference below
    // does not include it: 5 - 1 = 4 measured, 5 + 1 = 6 in production.
    $mountOnly = queriesIssuedBy(fn () => Livewire::test(Administration::class));
    $mountAndRefresh = queriesIssuedBy(fn () => Livewire::test(Administration::class)->call('$refresh'));

    expect($mountAndRefresh - $mountOnly)->toBe(4)
        ->and($mountOnly)->toBe(7);
});

it('does not grow with the number of installations', function (int $installations): void {
    $admin = $this->enrollDeveloper(4242);
    approveInstallations($this, $admin, $this->enrollDeveloper(77, login: 'otherdev'), $installations);
    $this->actingAs($admin, 'web');

    expect(Installation::query()->count())->toBe($installations)
        ->and(queriesIssuedBy(fn () => Livewire::test(Administration::class)))->toBe(7);
})->with([1, 5, 20]);

it('does not grow with the number of sessions behind each installation', function (int $sessionsEach): void {
    $admin = $this->enrollDeveloper(4242);
    approveInstallations($this, $admin, $this->enrollDeveloper(77, login: 'otherdev'), 2, $sessionsEach);
    $this->actingAs($admin, 'web');

    expect(AgentSession::query()->count())->toBe($sessionsEach * 2)
        ->and(queriesIssuedBy(fn () => Livewire::test(Administration::class)))->toBe(7);
})->with([1, 3, 10]);

it('counts live and retired for every shape the table can take', function (): void {
    $admin = $this->enrollDeveloper(4242);
    $this->actingAs($admin, 'web');

    $store = $this->service(InstallationList::class);

    // Neither: `sum()` answers NULL over an empty table on every engine, which is the branch
    // `AggregateCount::from()` exists for.
    expect($store->everything(50)['live'])->toBe(0)
        ->and($store->everything(50)['retired'])->toBe(0);

    $this->approveInstallation($admin, 'box-live');

    expect($store->everything(50)['live'])->toBe(1)
        ->and($store->everything(50)['retired'])->toBe(0);

    // Revoked: retired by a decision somebody made
    $revoked = $this->approveInstallation($admin, 'box-revoked');
    $revoked->forceFill(['revoked_at' => Carbon::now()])->save();

    expect($store->everything(50)['live'])->toBe(1)
        ->and($store->everything(50)['retired'])->toBe(1);

    // Expired: retired by the clock alone, which is the other half of the `where` the aggregate
    // has to reproduce. A `sum(case when)` that dropped the date term would call this one live.
    $expired = $this->approveInstallation($admin, 'box-expired');
    $expired->forceFill(['expires_at' => Carbon::now()->subDay()])->save();

    expect($store->everything(50)['live'])->toBe(1)
        ->and($store->everything(50)['retired'])->toBe(2);

    // All of them retired, so `live` reaches zero from above rather than never having left it
    Installation::query()->update(['revoked_at' => Carbon::now()]);

    expect($store->everything(50)['live'])->toBe(0)
        ->and($store->everything(50)['retired'])->toBe(3);
});

it('shows each installation its own owner', function (): void {
    $admin = $this->enrollDeveloper(4242);
    approveInstallations($this, $admin, $this->enrollDeveloper(77, login: 'otherdev'), 2);
    $this->actingAs($admin, 'web');

    // Two developers, so a login mapped by the wrong key shows the wrong name rather than the only
    // name there is. `box-0` belongs to the first and `box-1` to the second.
    Livewire::test(Administration::class)
        ->assertOk()
        ->assertSee('octodev')
        ->assertSee('otherdev')
        ->assertSee('box-0')
        ->assertSee('box-1');
});
