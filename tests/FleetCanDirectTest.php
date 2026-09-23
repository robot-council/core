<?php

declare(strict_types=1);

/**
 * Whether anything on this fleet can reach an agent that is waiting.
 *
 * **The question is fleet-level, and the session-level one looks enough like it to be mistaken for
 * it.** A bridge sitting on its sink is waiting for somebody else's directive: `coordinator:direct`
 * is what posting one needs, enrollment can never request it, and a receive-only session holding
 * none of it is the normal case. So a client that warned on its own `abilities` would warn on
 * almost every session, which is how a warning stops being read.
 *
 * The symptom it exists to name is an absence. A hook that finds an empty sink and a fleet that
 * genuinely has nothing to say are byte-identical from the agent's side (#159).
 *
 * @command  vendor/bin/pest --compact tests/FleetCanDirectTest.php
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\FleetAbilities;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);
});

it('tells a session the fleet can direct when another installation holds the ability', function (): void {
    // **The case a session-level answer gets wrong.** This session holds no `coordinator:direct`
    // and is correctly configured; what decides whether its sink will ever fill is the other
    // installation.
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        [Ability::CoordinatorDirect->value],
        'coordinator-machine'
    );

    [, $token] = $this->startAgentSession($receiver);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson([
            'abilities' => [Ability::TasksCreate->value],
            'fleet_can_direct' => true,
        ]);
});

it('tells a session the fleet cannot direct when nothing holds the ability', function (): void {
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    [, $token] = $this->startAgentSession($receiver);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['fleet_can_direct' => false]);
});

it('does not count a revoked installation, nor an expired one', function (): void {
    // **Two columns, asserted apart.** `isUsable()` reads both, and a check written against one
    // passes while the other is unguarded -- so a single test covering "not usable" would leave
    // whichever half it did not exercise free to report a fleet able to deliver when it is not.
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $coordinator = $this->enrollDeveloper(77, 'coordinator');

    $revoked = $this->approveInstallation($coordinator, [Ability::CoordinatorDirect->value], 'revoked-machine');
    Installation::query()->whereKey($revoked->getKey())->update(['revoked_at' => Carbon::now()]);

    [, $token] = $this->startAgentSession($receiver);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['fleet_can_direct' => false]);

    // The expired half, with the revoked one out of the way so only one variable moves.
    Installation::query()->whereKey($revoked->getKey())->delete();

    $expired = $this->approveInstallation($coordinator, [Ability::CoordinatorDirect->value], 'expired-machine');
    Installation::query()->whereKey($expired->getKey())->update(['expires_at' => Carbon::now()->subMinute()]);

    [, $second] = $this->startAgentSession($receiver);

    $this->machine($second)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['fleet_can_direct' => false]);

    // The control, so neither assertion above passes because nothing was ever counted: the same
    // installation, unrevoked and unexpired, reads true.
    Installation::query()->whereKey($expired->getKey())->update([
        'expires_at' => Carbon::now()->addDays(30),
    ]);

    [, $third] = $this->startAgentSession($receiver);

    $this->machine($third)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['fleet_can_direct' => true]);
});

it('reads what the fixed list still holds, not what the row happens to say', function (): void {
    // A stored row is not the authority on what an ability is. `Installation::abilities()` drops
    // anything outside the fixed list, and this reads through it rather than querying the JSON
    // column.
    //
    // **`sessions:start` is what makes this discriminate.** No ability has been retired yet, so
    // retirement cannot be staged -- but `sessions:start` is a real member of the enum that
    // `Ability::grantable()` deliberately excludes, and it is therefore exactly the shape a
    // retired name would take: present in the enum, present in a row, and dropped by the accessor.
    // A first draft only planted misspellings, which a strict `in_array` refuses either way; the
    // control passed with the accessor swapped for the raw column, so it was testing the
    // comparison rather than the filter.
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $stale = $this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        [Ability::TasksCreate->value],
        'stale-machine'
    );

    // Written straight to the row: the store refuses both of these, which is the guard being
    // tested rather than a state it will produce.
    Installation::query()->whereKey($stale->getKey())->update([
        'granted_abilities' => json_encode([
            Ability::SessionsStart->value,
            'coordinator:direkt',
            'COORDINATOR:DIRECT',
        ]),
    ]);

    expect(app(FleetAbilities::class)->anyInstallationHolds(Ability::SessionsStart))->toBeFalse()
        ->and(app(FleetAbilities::class)->anyInstallationHolds(Ability::CoordinatorDirect))->toBeFalse();

    [, $token] = $this->startAgentSession($receiver);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['fleet_can_direct' => false]);
});

it('keeps the query and the row-level answer agreeing about what is usable', function (): void {
    // `Installation::usable()` and `isUsable()` are two expressions of one rule -- a `where` cannot
    // answer for a model already in hand, and a row predicate cannot bound a read. A drift between
    // them would be invisible, because each is correct on its own terms.
    $coordinator = $this->enrollDeveloper(77, 'coordinator');

    $live = $this->approveInstallation($coordinator, [Ability::TasksCreate->value], 'live-machine');

    $revoked = $this->approveInstallation($coordinator, [Ability::TasksCreate->value], 'revoked-machine');
    Installation::query()->whereKey($revoked->getKey())->update(['revoked_at' => Carbon::now()]);

    $expired = $this->approveInstallation($coordinator, [Ability::TasksCreate->value], 'expired-machine');
    Installation::query()->whereKey($expired->getKey())->update(['expires_at' => Carbon::now()->subMinute()]);

    $byRow = Installation::query()->orderBy('id')->get()
        ->filter(static fn (Installation $installation): bool => $installation->isUsable())
        ->map(static fn (Installation $installation): mixed => $installation->getKey())
        ->values()
        ->all();

    $byQuery = Installation::usable()->orderBy('id')->pluck('id')->all();

    expect($byQuery)->toBe($byRow)
        // Named, so a run where both are empty cannot pass as agreement.
        ->and($byQuery)->toBe([$live->getKey()]);
});

it('answers directly, so the endpoint is not the only way in', function (): void {
    // The endpoint is what a client reads, and `Support\FleetAbilities` is what a host can
    // resolve and call. A rule the controller enforced would protect the route and nothing else.
    expect(app(FleetAbilities::class)->anyInstallationHolds(Ability::CoordinatorDirect))->toBeFalse();

    $this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        [Ability::CoordinatorDirect->value],
        'coordinator-machine'
    );

    expect(app(FleetAbilities::class)->anyInstallationHolds(Ability::CoordinatorDirect))->toBeTrue()
        // And it answers about the ability it was asked about, rather than about any ability.
        ->and(app(FleetAbilities::class)->anyInstallationHolds(Ability::LocksAcquire))->toBeFalse();
});

it('does not count an installation whose developer has left the access list', function (): void {
    // **The third gate, and the one a credential-lifetime answer misses.**
    // `EnsureInstallation` refuses a credential whose developer is no longer admitted, and
    // `EnsureAgentSession` refuses the session token that would actually post. Nothing revokes
    // the installation when an admin off-boards somebody -- the list is checked per request -- so
    // a row that is neither revoked nor expired can still be unable to do anything at all.
    //
    // Reporting `true` there is the reassuring direction, which is the one this whole field
    // exists to remove: the operator would read a live coordinator where there is none.
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $coordinator = $this->enrollDeveloper(77, 'coordinator');

    $this->approveInstallation($coordinator, [Ability::CoordinatorDirect->value], 'coordinator-machine');

    // The control first: while 77 is admitted, the fleet can direct.
    expect(app(FleetAbilities::class)->anyInstallationHolds(Ability::CoordinatorDirect))->toBeTrue();

    // Off-boarded exactly as an admin would, by narrowing the list. The installation row is
    // untouched: neither revoked nor expired.
    $this->setAccessLists(developers: [4242]);

    expect(Installation::usable()->count())->toBe(2)
        ->and(app(FleetAbilities::class)->anyInstallationHolds(Ability::CoordinatorDirect))->toBeFalse();

    [, $token] = $this->startAgentSession($receiver);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['fleet_can_direct' => false]);
});

it('does not count an installation whose developer has no GitHub identity', function (): void {
    // The same gate reached the other way. `EnsureInstallation` refuses when
    // `HostUsers::githubIdForKey()` answers null, which is what a host that removed the identity
    // row -- or hid the user -- leaves behind.
    $coordinator = $this->enrollDeveloper(77, 'coordinator');

    $this->approveInstallation($coordinator, [Ability::CoordinatorDirect->value], 'coordinator-machine');

    expect(app(FleetAbilities::class)->anyInstallationHolds(Ability::CoordinatorDirect))->toBeTrue();

    DB::table('robot_council_github_identities')->where('github_id', 77)->delete();

    expect(app(FleetAbilities::class)->anyInstallationHolds(Ability::CoordinatorDirect))->toBeFalse();
});

it('treats an installation expiring at this very instant as expired', function (): void {
    // **Where `>` and `>=` differ, and no mutation run can reach it**: the operator is a string
    // argument to `where()` rather than a PHP operator, so `GreaterToGreaterOrEqual` never sees
    // it. `isUsable()` uses `Carbon::isFuture()`, which is strictly greater, and `usable()` uses
    // `>`. They agree; nothing else pins that they keep agreeing.
    $this->freezeTime();

    $coordinator = $this->enrollDeveloper(77, 'coordinator');

    $edge = $this->approveInstallation($coordinator, [Ability::CoordinatorDirect->value], 'edge-machine');

    Installation::query()->whereKey($edge->getKey())->update(['expires_at' => Carbon::now()]);

    expect(Installation::query()->whereKey($edge->getKey())->sole()->isUsable())->toBeFalse()
        ->and(Installation::usable()->pluck('id')->all())->toBeEmpty()
        ->and(app(FleetAbilities::class)->anyInstallationHolds(Ability::CoordinatorDirect))->toBeFalse();

    // The control, one second the other side of the same boundary.
    Installation::query()->whereKey($edge->getKey())->update(['expires_at' => Carbon::now()->addSecond()]);

    expect(Installation::query()->whereKey($edge->getKey())->sole()->isUsable())->toBeTrue()
        ->and(Installation::usable()->pluck('id')->all())->toBe([$edge->getKey()])
        ->and(app(FleetAbilities::class)->anyInstallationHolds(Ability::CoordinatorDirect))->toBeTrue();
});

it('sends the field as a JSON boolean, not as something that merely compares equal', function (): void {
    // `assertJson` is a loose subset match: measured, `1` satisfies an asserted `true` and `null`
    // satisfies an asserted `false`. A client reading `=== true` would see neither. The return
    // type is what guarantees it today, so this is what would notice if that changed.
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    [, $token] = $this->startAgentSession($receiver);

    $response = $this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk();

    expect($response->json('fleet_can_direct'))->toBeFalse()
        ->and($response->json('fleet_can_direct'))->toBeBool();
});
