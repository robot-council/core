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

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\FleetAbilities;
use RobotCouncil\Support\SessionPresence;
use RobotCouncil\Tests\TestCase;

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
    //
    // **It goes through `SessionPresence::revoke()` rather than writing `gone` to the row**, which
    // is what keeps it from being a slower spelling of the `gone` dataset row below: the real path
    // deletes the session's tokens as well, and this asserts the answer moves on the path an
    // administrator actually takes.
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

it('counts a session by its role, whatever its installation was granted', function (string $role, bool $counts): void {
    // The role is what decides now, so a session in one that does not carry `coordinator:direct`
    // is not a coordinator however its installation was configured -- and one that does carry it
    // is, on the same installation.
    //
    // **The `true` row is what makes the `false` rows mean anything**, and an earlier draft of this
    // test had only the `false` ones. Every reason this fixture might stop being countable -- 77
    // dropped from the access list, a start that left the row `stale`, an `expires_at` default
    // moving into the past -- turns a `false`-only dataset green while testing nothing.
    //
    // `build` is not a row here: `Support\AgentSessions::start()` writes it unconditionally, so
    // `update(['role' => 'build'])` writes the value already there. On MySQL that update reports
    // **0** rows changed, and no implementation can tell that row from the `ci` one.
    $installation = $this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        [Ability::CoordinatorDirect->value],
        'coordinator-machine'
    );

    [$session] = $this->startAgentSession($installation);

    // Asserted, because everything below rests on the start having produced a countable row that
    // only the role keeps out.
    expect($session->role)->toBe(Role::Build)
        ->and($session->status)->toBe(AgentSessionStatus::Active);

    AgentSession::query()->whereKey($session->getKey())->update(['role' => $role]);

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBe($counts);
})->with([
    'ci does not' => [Role::Ci->value, false],
    'coordinator does' => [Role::Coordinator->value, true],
]);

it('pages past the chunk size, and the only admitted holder is on the last page', function (): void {
    // **The walk is chunked, and nothing else in this file makes it take a second page.** Every
    // other fixture here has one or two sessions, so `lazyById()` returns one short page and the
    // paging code never runs -- which left two defects invisible at once:
    //
    // 1. `select(['id', 'user_id'])` must keep `id`. `lazyById()` reads the key off the last
    //    hydrated model, and throws `The lazyById operation was aborted because the [id] column is
    //    not present in the query result` when it is missing -- but only after a page comes back
    //    FULL, so a small fleet never sees it. Measured: dropping `id` from that list passes every
    //    other test in this file.
    // 2. A walk that stopped after the first page would answer from a subset.
    //
    // **The fixture is built so both fail rather than one.** The `CHUNK` sessions on the first page
    // all belong to a developer who has been off-boarded, and the one admitted coordinator is the
    // highest id there is. So a walk that stops early collects only unadmitted holders and answers
    // `false`, and a walk that cannot page raises.
    $this->setAccessLists(developers: [4242, 77, 99]);

    $offboarded = $this->approveInstallation($this->enrollDeveloper(99, 'offboarded'), machineLabel: 'quiet-machine');

    [$seed] = $this->startCoordinatorSession($offboarded);

    // Copies of a real row rather than rows built by hand, so nothing here can drift from what the
    // store actually writes. The query builder, because a model save would put the casts back in
    // the way -- and `id` is dropped so each copy takes the next one.
    $row = (array) DB::table('robot_council_agent_sessions')->where('id', $seed->getKey())->sole();
    unset($row['id']);

    DB::table('robot_council_agent_sessions')->insert(array_fill(0, FleetAbilities::CHUNK - 1, $row));

    // The admitted coordinator, started last so its id is the highest and it lands on page two.
    [$admitted] = $this->startCoordinatorSession($this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        machineLabel: 'coordinator-machine'
    ));

    $this->setAccessLists(developers: [4242, 77]);

    // **The fixture is asserted before the subject is**, because every claim above is a claim about
    // row counts: one full page of unadmitted holders, then one more row, and the admitted one last.
    $matching = AgentSession::query()
        ->where('role', Role::Coordinator->value)
        ->where('status', AgentSessionStatus::Active->value);

    expect((clone $matching)->count())->toBe(FleetAbilities::CHUNK + 1)
        ->and((clone $matching)->max('id'))->toBe($admitted->getKey())
        ->and((clone $matching)->where('user_id', $admitted->user_id)->count())->toBe(1)
        ->and(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeTrue();

    // The other direction, so the `true` above is the walk reaching the last page rather than the
    // allowlist admitting anybody it is handed: with the admitted coordinator gone, the same
    // `CHUNK` rows answer `false`.
    $this->service(SessionPresence::class)->revoke($admitted);

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse();
});

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
        ->assertJsonPath('fleet_can_direct', false);
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

    // **And on the one engine that can be asked, the constant is checked against the schema.** The
    // two assertions above keep a future EDITOR honest about the strings; they cannot notice the
    // column being narrowed underneath them, which is the failure that put `SQLSTATE[22001]` in the
    // `postgres` job in the first place. Postgres reports a width and SQLite does not, so this runs
    // where it can answer and is skipped, visibly, where it cannot.
    if (! notPostgres()) {
        $role = collect(Schema::getColumns('robot_council_agent_sessions'))->firstWhere('name', 'role');

        expect(stringValue(arrayValue($role)['type'] ?? null))
            ->toBe('character varying('.ROLE_COLUMN_MAX.')');
    }

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
    'an ability name in the role column' => 'coordinator:dir',
    'empty' => '',

    // **`'COORDINATOR'` is deliberately NOT a row here, and the reason is worth the paragraph.**
    // The match happens in SQL, and nothing in this package case-folds -- so whether an uppercase
    // role matches is the COLLATION's answer, not this code's. Measured 2026-09-23 on MySQL 9.4.0
    // under Testbench's default `utf8mb4_unicode_ci`: it matches, `anyLiveSessionHolds()` returns
    // true, and the row fails. It passes on SQLite and Postgres, which compare case-sensitively.
    //
    // Keeping it would pin an engine property as though it were a code property, and it would go
    // green in CI forever because there is no `mysql` job. `robot-council/core#245` is whether
    // `role` belongs in the byte-exact collation list `2026_09_22_000002` maintains, which is where
    // the question actually lives.
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

/**
 * Fill the fleet with `FleetAbilities::CHUNK + 1` live coordinator sessions, exactly one admitted.
 *
 * **The unadmitted rows are copies of a real session row rather than rows assembled by hand**, for
 * the reason `it('pages past the chunk size')` gives above: nothing here can then drift from what
 * `Support\AgentSessions` and `Support\RoleRequests` actually write.
 *
 * **The admitted coordinator is started either before the crowd or after it, and that is the only
 * thing deciding which page it lands on.** The walk is ordered by `id` and by nothing else, so
 * "first page" and "last page" are one boolean apart -- which is what lets the two costs be
 * compared without a second fixture that might differ in some other way.
 *
 * The test case is taken as a parameter rather than read off `$this`, for the reason
 * `tests/PresenceQueryCostTest.php` records: a property assigned in a closure is `mixed` by the
 * time it reaches a typed parameter.
 *
 * @param  TestCase  $test  The test case, for its fixture helpers.
 * @param  bool  $onTheFirstPage  Whether the admitted coordinator takes the lowest id or the highest.
 * @return AgentSession The one admitted coordinator's session.
 */
function crowdedCoordinatorFleet(TestCase $test, bool $onTheFirstPage): AgentSession
{
    // **99 is never admitted here, and nothing needs it to have been.** `it('pages past the chunk
    // size')` above admits it while its sessions start and drops it afterwards, which walks the
    // real off-boarding transition; this fixture only needs the end state. Verified before
    // shortening it: `Support\AgentSessions::start()` bounds its inputs and writes, and the access
    // list is checked by `Http\Middleware\EnsureInstallation` on the way in -- so a fixture that
    // does not go through a route is never asked. What the walk reads is the row and the list as
    // they stand, not how either got there.
    $test->setAccessLists(developers: [4242, 77]);

    $coordinator = $test->enrollDeveloper(77, 'coordinator');

    $admitted = null;

    if ($onTheFirstPage) {
        [$admitted] = $test->startCoordinatorSession(
            $test->approveInstallation($coordinator, machineLabel: 'coordinator-machine')
        );
    }

    [$seed] = $test->startCoordinatorSession(
        $test->approveInstallation($test->enrollDeveloper(99, 'offboarded'), machineLabel: 'quiet-machine')
    );

    // The query builder rather than a model save, because a save would put the casts back in the
    // way -- and `id` is dropped so each copy takes the next one.
    $row = (array) DB::table('robot_council_agent_sessions')->where('id', $seed->getKey())->sole();
    unset($row['id']);

    DB::table('robot_council_agent_sessions')->insert(array_fill(0, FleetAbilities::CHUNK - 1, $row));

    if (! $admitted instanceof AgentSession) {
        [$admitted] = $test->startCoordinatorSession(
            $test->approveInstallation($coordinator, machineLabel: 'coordinator-machine')
        );
    }

    return $admitted;
}

/**
 * The coordinator sessions the walk will match, as a fresh builder each time.
 *
 * @return Builder<AgentSession> A query over every live coordinator session.
 */
function matchingCoordinators(): Builder
{
    return AgentSession::query()
        ->where('role', Role::Coordinator->value)
        ->where('status', AgentSessionStatus::Active->value);
}

it('stops on the page the admitted coordinator is on, rather than reading the rest', function (): void {
    // **The acceptance criterion this file exists to meet.** Before, the walk collected every match
    // and asked the identities table once at the end, so a fleet of `CHUNK + 1` coordinators cost
    // three reads whatever the answer was and wherever the answer lived. Measured on this fixture
    // against `main` at `5d73f8f`: 3.
    $admitted = crowdedCoordinatorFleet($this, onTheFirstPage: true);

    $fleet = app(FleetAbilities::class);

    // **The fixture is asserted before the cost is**, because every claim below is a claim about
    // which rows are where: one full page and one row more, with the admitted coordinator at the
    // LOWEST id rather than the highest.
    expect(matchingCoordinators()->count())->toBe(FleetAbilities::CHUNK + 1)
        ->and(matchingCoordinators()->min('id'))->toBe($admitted->getKey())
        ->and(matchingCoordinators()->where('user_id', $admitted->user_id)->count())->toBe(1);

    // Two: the first page, and the one identity read that settles it. The second page is never
    // asked for, because the callback has already said to stop.
    expect(queriesIssuedBy(fn () => $fleet->anyLiveSessionHolds(Ability::CoordinatorDirect)))->toBe(2)
        ->and($fleet->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeTrue();
});

it('reads every page when no holder is admitted, which is what makes that early return mean anything', function (): void {
    // **The negative control the count above cannot do without.** Two reads is also what a fleet
    // holding ONE coordinator costs, and what a fixture that quietly built nothing costs -- so the
    // number says "the walk stopped" only beside the same fixture not stopping. One variable moves
    // between the two tests, and it is the access list.
    crowdedCoordinatorFleet($this, onTheFirstPage: true);

    $fleet = app(FleetAbilities::class);

    $this->setAccessLists(developers: [4242]);

    expect(matchingCoordinators()->count())->toBe(FleetAbilities::CHUNK + 1)
        ->and($fleet->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse()

        // Four: a page and an identity read each, twice. This is the cost side of the trade, and it
        // is asserted rather than described -- the `false` answer now pays one identity read per
        // page where it used to pay one in total.
        ->and(queriesIssuedBy(fn () => $fleet->anyLiveSessionHolds(Ability::CoordinatorDirect)))->toBe(4);
});

it('pays for every page when the admitted coordinator is on the last one', function (): void {
    // The same shape as `it('pages past the chunk size')` above, costed. An early return cannot
    // help here, and the per-page identity read makes it one dearer than before -- 4 against 3.
    // Recorded rather than glossed, because a reader who saw only the 2 would take the wrong
    // number away.
    $admitted = crowdedCoordinatorFleet($this, onTheFirstPage: false);

    $fleet = app(FleetAbilities::class);

    expect(matchingCoordinators()->max('id'))->toBe($admitted->getKey())
        ->and($fleet->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeTrue()
        ->and(queriesIssuedBy(fn () => $fleet->anyLiveSessionHolds(Ability::CoordinatorDirect)))->toBe(4);
});

it('costs one read on a fleet where nothing holds the role', function (): void {
    // **The expected state, and the cheapest one.** `Support\Doctor` says in its own text that a
    // fleet whose agents only receive is a legitimate configuration, so no coordinator running is
    // the normal reading of this question -- one page that comes back empty, and no identity read
    // at all, because there is no holder to resolve. Unchanged by this ticket, and asserted so that
    // a future change to the walk cannot make the common answer dearer unnoticed.
    $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $fleet = app(FleetAbilities::class);

    expect(matchingCoordinators()->count())->toBe(0)
        ->and($fleet->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse()
        ->and(queriesIssuedBy(fn () => $fleet->anyLiveSessionHolds(Ability::CoordinatorDirect)))->toBe(1);
});
