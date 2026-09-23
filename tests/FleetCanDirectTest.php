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
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\Diagnosis;
use RobotCouncil\Support\DiagnosisStatus;
use RobotCouncil\Support\Doctor;
use RobotCouncil\Support\Installations;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);
});

/**
 * The fleet-coordination diagnosis, by name.
 *
 * @return Diagnosis The check's result.
 */
function coordinationDiagnosis(): Diagnosis
{
    foreach (app(Doctor::class)->examine() as $diagnosis) {
        if ($diagnosis->check === 'fleet coordination') {
            return $diagnosis;
        }
    }

    throw new RuntimeException('the fleet coordination check is not registered');
}

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

    expect(app(Installations::class)->anyHolds(Ability::SessionsStart))->toBeFalse()
        ->and(app(Installations::class)->anyHolds(Ability::CoordinatorDirect))->toBeFalse();

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

it('reports the same fact from the doctor, and passes either way', function (): void {
    // It reports rather than fails: a fleet whose agents only ever receive is a legitimate
    // configuration, and a check that failed on one is a check people switch off.
    $diagnosis = coordinationDiagnosis();

    expect($diagnosis->status)->toBe(DiagnosisStatus::Passed)
        ->and($diagnosis->detail)->toContain('No installation holds');

    $this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        [Ability::CoordinatorDirect->value],
        'coordinator-machine'
    );

    $granted = coordinationDiagnosis();

    expect($granted->status)->toBe(DiagnosisStatus::Passed)
        ->and($granted->detail)->toContain('At least one installation holds');
});

it('answers the store directly, so the endpoint is not the only way in', function (): void {
    // The endpoint is what a client reads, and the store is what a host can call. A bound the
    // controller enforced would protect the route and nothing else.
    expect(app(Installations::class)->anyHolds(Ability::CoordinatorDirect))->toBeFalse();

    $this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        [Ability::CoordinatorDirect->value],
        'coordinator-machine'
    );

    expect(app(Installations::class)->anyHolds(Ability::CoordinatorDirect))->toBeTrue()
        // And it answers about the ability it was asked about, rather than about any ability.
        ->and(app(Installations::class)->anyHolds(Ability::LocksAcquire))->toBeFalse();
});
