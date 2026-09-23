<?php

declare(strict_types=1);

/**
 * Whether anything on this fleet can reach an agent that is waiting, right now.
 *
 * **The question is fleet-level, and the session's own looks enough like it to be mistaken for
 * it.** A bridge sitting on its sink is waiting for somebody else's directive: `coordinator:direct`
 * is what posting one needs, a session cannot ask itself into the role that carries it, and holding
 * none of it is the normal case. So a client that warned on its own `abilities` would warn on
 * almost every session, which is how a warning stops being read.
 *
 * The symptom it exists to name is an absence. A hook that finds an empty sink and a fleet that
 * genuinely has nothing to say are byte-identical from the agent's side (#159).
 *
 * **`robot-council/core#223` moved the subject from installations to live sessions**, because
 * `robot-council/core#222` made an installation's stored abilities answer nothing about any
 * session. Every property this file pinned survives the move; what changed is what counts as a
 * holder, and that the answer now flaps when a coordinator restarts.
 *
 * @command  vendor/bin/pest --compact tests/FleetCanDirectTest.php
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\FleetAbilities;
use RobotCouncil\Support\SessionPresence;

/**
 * What `robot_council_agent_sessions.role` will hold, in characters.
 *
 * Declared by `2026_09_23_000003_add_role_to_robot_council_agent_sessions.php` as
 * `string('role', 16)`, and repeated here because **the schema cannot be asked**: measured on
 * 2026-09-23, `Schema::getColumns()` reports the type as `varchar` with no length on SQLite, so a
 * derived bound would be `null` on the engine most runs use. The assertion below keeps the number
 * honest from the other end -- a column too narrow for a real role fails here rather than in
 * whatever writes one next.
 */
const ROLE_COLUMN_MAX = 16;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);
});

it('tells a session the fleet can direct when another session is coordinating', function (): void {
    // **The case the session's own answer gets wrong.** This session is `build`, which is correct
    // and normal; what decides whether its sink will ever fill is the other session.
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $this->startCoordinatorSession($this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        machineLabel: 'coordinator-machine'
    ));

    [, $token] = $this->startAgentSession($receiver);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('abilities', Role::Build->tokenAbilities())
        ->assertJsonPath('fleet_can_direct', true);
});

it('tells a session the fleet cannot direct when the coordinator has only ENROLLED', function (): void {
    // **The case that changed, and the reason this ticket exists.** An installation holding
    // `coordinator:direct` used to be the answer; since `robot-council/core#222` that column
    // decides nothing, and a machine with no session running is a machine that cannot deliver.
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
        ->assertJsonPath('fleet_can_direct', false);
});

it('stops saying the fleet can direct once the coordinator ends', function (): void {
    // The flap, asserted rather than left as a remark. The value is now transient by construction,
    // which is what makes a client that states it once at startup able to be permanently wrong.
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    [$coordinator] = $this->startCoordinatorSession($this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        machineLabel: 'coordinator-machine'
    ));

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeTrue();

    $this->service(SessionPresence::class)->revoke($coordinator);

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse();
});

it('does not count a session that has gone, nor one swept stale', function (string $status): void {
    // **Both, and apart.** `gone` is final and `stale` is one request from active, so a check
    // written against either alone leaves the other free to report a coordinator that is not there.
    // Stale is excluded deliberately: the safe direction is the one that says `false`, because a
    // bridge told `false` waits, while one told `true` about a coordinator that never comes back
    // waits forever.
    [$coordinator] = $this->startCoordinatorSession($this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        machineLabel: 'coordinator-machine'
    ));

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeTrue();

    AgentSession::query()->whereKey($coordinator->getKey())->update(['status' => $status]);

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse();
})->with([
    'gone' => AgentSessionStatus::Gone->value,
    'stale' => AgentSessionStatus::Stale->value,
]);

it('does not count a session whose role does not carry the ability', function (string $role): void {
    // The role is what decides now, so a session in one that does not carry `coordinator:direct`
    // is not a coordinator however its installation was configured.
    $installation = $this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        [Ability::CoordinatorDirect->value],
        'coordinator-machine'
    );

    [$session] = $this->startAgentSession($installation);

    AgentSession::query()->whereKey($session->getKey())->update(['role' => $role]);

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse();
})->with([
    'build' => Role::Build->value,
    'ci' => Role::Ci->value,
]);

it('answers false for an ability no role carries at all', function (): void {
    // `sessions:start` is the installation credential's own and is in no preset, so the roles the
    // question resolves to are none -- which is asked before the query rather than after, because
    // `whereIn` with an empty list is not the same statement on every grammar.
    $this->startCoordinatorSession($this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        machineLabel: 'coordinator-machine'
    ));

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::SessionsStart))->toBeFalse()

        // The control beside it: the same fleet answers true for an ability a role does carry, so
        // the false above is the question rather than an empty fleet.
        ->and(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeTrue();
});

it('tells a session the fleet cannot direct when nothing holds the ability', function (): void {
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    [, $token] = $this->startAgentSession($receiver);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['fleet_can_direct' => false]);
});

it('does not count a coordinator whose installation is revoked, nor one whose installation expired', function (): void {
    // **Two columns, asserted apart.** `isUsable()` reads both, and a check written against one
    // passes while the other is unguarded -- so a single test covering "not usable" would leave
    // whichever half it did not exercise free to report a fleet able to deliver when it is not.
    //
    // **This is the gate the first draft of the session-level answer left out**, and these
    // assertions are what caught it. Revoking an installation deletes its sessions' tokens and
    // leaves the rows `active`, so a count built on status alone reports a coordinator whose next
    // request is a 401.
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $coordinator = $this->enrollDeveloper(77, 'coordinator');

    $revoked = $this->approveInstallation($coordinator, machineLabel: 'revoked-machine');
    $this->startCoordinatorSession($revoked);
    Installation::query()->whereKey($revoked->getKey())->update(['revoked_at' => Carbon::now()]);

    [, $token] = $this->startAgentSession($receiver);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('fleet_can_direct', false);

    // The expired half. The revoked installation stays revoked, so only one variable moves.
    $expired = $this->approveInstallation($coordinator, machineLabel: 'expired-machine');
    $this->startCoordinatorSession($expired);
    Installation::query()->whereKey($expired->getKey())->update(['expires_at' => Carbon::now()->subMinute()]);

    [, $second] = $this->startAgentSession($receiver);

    $this->machine($second)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('fleet_can_direct', false);

    // The control, so neither assertion above passes because nothing was ever counted: the same
    // installation, unexpired, with its session untouched throughout, reads true.
    Installation::query()->whereKey($expired->getKey())->update([
        'expires_at' => Carbon::now()->addDays(30),
    ]);

    [, $third] = $this->startAgentSession($receiver);

    $this->machine($third)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('fleet_can_direct', true);
});

it('reads the roles the enum defines, not whatever string the row happens to hold', function (string $planted): void {
    // A stored row is not the authority on what a role is. The question resolves `Access\Role`'s
    // own cases and matches against those, so a value that only looks like one -- a misspelling, a
    // different case, or an ability name written into the role column -- matches nothing.
    //
    // **The store cannot produce any of these**, which is the point: they are written straight to
    // the row, because what is being pinned is that a row outside the enum cannot answer true. The
    // narrowed `select()` is what keeps this from raising instead of answering -- casting `role`
    // on a value outside the enum is a `ValueError`, and a fleet-wide read must not 500 on one bad
    // row.
    //
    // **Every planted value has to fit `varchar(16)`, and the test asserts that rather than
    // assuming it.** Writing past validation on purpose is what these fixtures are for, so nothing
    // else keeps them inside the column -- and the two engines disagree about the consequence:
    // SQLite stores an overlong value whole while Postgres answers `SQLSTATE[22001]`. Measured, on
    // the draft of this test that planted `coordinator:direct`: 18 characters, green on every
    // local run, and a hard failure in the `postgres` job alone.
    expect(mb_strlen($planted))->toBeLessThanOrEqual(ROLE_COLUMN_MAX)
        ->and(array_map(
            static fn (Role $role): int => mb_strlen($role->value),
            Role::cases()
        ))->each->toBeLessThanOrEqual(ROLE_COLUMN_MAX);

    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    [$session] = $this->startAgentSession($this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        machineLabel: 'planted-machine'
    ));

    AgentSession::query()->whereKey($session->getKey())->update(['role' => $planted]);

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse();

    [, $token] = $this->startAgentSession($receiver);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('fleet_can_direct', false);
})->with([
    'a misspelling' => 'coordinatr',
    'the wrong case' => 'COORDINATOR',
    'an ability name in the role column' => 'coordinator:dir',
    'empty' => '',
]);

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
    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse();

    $this->startCoordinatorSession($this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        machineLabel: 'coordinator-machine'
    ));

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeTrue()
        // And it answers about the ability it was asked about, rather than about any ability. The
        // coordinator role carries `locks:acquire` as well, so the discriminating pick is one no
        // role carries at all.
        ->and(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::SessionsStart))->toBeFalse();
});

it('does not count a coordinator whose developer has left the access list', function (): void {
    // **The third gate, and the one a session-status answer misses.** `EnsureAgentSession` refuses
    // the token that would actually post when its developer is no longer admitted. Nothing touches
    // the session row when an admin off-boards somebody -- the list is checked per request -- so a
    // session that is `active` under a usable installation can still be unable to do anything.
    //
    // Reporting `true` there is the reassuring direction, which is the one this whole field
    // exists to remove: the operator would read a live coordinator where there is none.
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $coordinator = $this->enrollDeveloper(77, 'coordinator');

    $this->startCoordinatorSession($this->approveInstallation($coordinator, machineLabel: 'coordinator-machine'));

    // The control first: while 77 is admitted, the fleet can direct.
    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeTrue();

    // Off-boarded exactly as an admin would, by narrowing the list. Neither the session row nor the
    // installation row is touched: the session is still `active` and the installation still usable.
    $this->setAccessLists(developers: [4242]);

    expect(Installation::usable()->count())->toBe(2)
        ->and(AgentSession::query()->where('status', AgentSessionStatus::Active->value)->count())->toBe(1)
        ->and(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse();

    [, $token] = $this->startAgentSession($receiver);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('fleet_can_direct', false);
});

it('does not count a coordinator whose developer has no GitHub identity', function (): void {
    // The same gate reached the other way. `EnsureAgentSession` refuses when
    // `HostUsers::githubIdForKey()` answers null, which is what a host that removed the identity
    // row -- or hid the user -- leaves behind.
    $coordinator = $this->enrollDeveloper(77, 'coordinator');

    $this->startCoordinatorSession($this->approveInstallation($coordinator, machineLabel: 'coordinator-machine'));

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeTrue();

    DB::table('robot_council_github_identities')->where('github_id', 77)->delete();

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse();
});

it('treats an installation expiring at this very instant as expired', function (): void {
    // **Where `>` and `>=` differ, and no mutation run can reach it**: the operator is a string
    // argument to `where()` rather than a PHP operator, so `GreaterToGreaterOrEqual` never sees
    // it. `isUsable()` uses `Carbon::isFuture()`, which is strictly greater, and `usable()` uses
    // `>`. They agree; nothing else pins that they keep agreeing.
    $this->freezeTime();

    $coordinator = $this->enrollDeveloper(77, 'coordinator');

    $edge = $this->approveInstallation($coordinator, machineLabel: 'edge-machine');

    $this->startCoordinatorSession($edge);

    Installation::query()->whereKey($edge->getKey())->update(['expires_at' => Carbon::now()]);

    expect(Installation::query()->whereKey($edge->getKey())->sole()->isUsable())->toBeFalse()
        ->and(Installation::usable()->pluck('id')->all())->toBeEmpty()
        ->and(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse();

    // The control, one second the other side of the same boundary. The session row does not move.
    Installation::query()->whereKey($edge->getKey())->update(['expires_at' => Carbon::now()->addSecond()]);

    expect(Installation::query()->whereKey($edge->getKey())->sole()->isUsable())->toBeTrue()
        ->and(Installation::usable()->pluck('id')->all())->toBe([$edge->getKey()])
        ->and(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeTrue();
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
