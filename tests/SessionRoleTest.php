<?php

declare(strict_types=1);

/**
 * What a session is for, and therefore what it may do.
 *
 * `robot-council/core#221` moved that decision off the installation and onto the session: the
 * abilities a token carries come from `Access\Role`'s preset, and an installation's
 * `granted_abilities` decides only which roles the machine is eligible to run. The tests here are
 * the ones that would go quiet if either half were reverted -- the preset shapes themselves, the
 * gate that still refuses `coordinator:direct`, and the backfill that keeps a machine an admin made
 * a coordinator working across the upgrade.
 *
 * @command  vendor/bin/pest --compact tests/SessionRoleTest.php
 */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Installations;
use RobotCouncil\Support\PresenceClock;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
    $this->credential = $this->installationCredential($this->installation);
});

it('resolves build and ci to the same abilities, so a divergence is a deliberate edit', function (): void {
    // `Role::abilities()` writes the two lists out separately rather than sharing one, precisely so
    // this assertion can fail. Made identical by construction it would be a description of nothing:
    // no edit to either arm could ever turn it red.
    expect(Role::Ci->abilities())->toBe(Role::Build->abilities())
        ->and(Role::Ci->tokenAbilities())->toBe(Role::Build->tokenAbilities());
});

it('gives the build preset exactly the four an enrollment may request', function (): void {
    expect(Role::Build->tokenAbilities())->toBe(Ability::values(Ability::requestable()))
        ->and(Role::Build->tokenAbilities())->toBe([
            Ability::TasksCreate->value,
            Ability::TasksClaim->value,
            Ability::LocksAcquire->value,
            Ability::EventsPost->value,
        ]);

    // Spelled out as well as compared, because the comparison alone would keep passing if both
    // sides moved together -- which is the one way this could stop meaning what it says.
});

it('gives the coordinator preset the build preset plus the one ability enrollment cannot ask for', function (): void {
    expect(Role::Coordinator->tokenAbilities())
        ->toBe([...Role::Build->tokenAbilities(), Ability::CoordinatorDirect->value])
        ->and(Role::Build->holds(Ability::CoordinatorDirect))->toBeFalse()
        ->and(Role::Ci->holds(Ability::CoordinatorDirect))->toBeFalse()
        ->and(Role::Coordinator->holds(Ability::CoordinatorDirect))->toBeTrue();
});

it('never puts sessions:start in any preset, whatever the role', function (): void {
    // The installation credential's own ability. A session token carrying it could start further
    // sessions, which is the escalation the split between the two credentials exists to prevent.
    // Asserted over every case rather than the three by name, so a fourth role is covered the day
    // it is added rather than the day somebody remembers.
    expect(Role::cases())->not->toBeEmpty();

    foreach (Role::cases() as $role) {
        expect($role->tokenAbilities())->not->toContain(Ability::SessionsStart->value);
    }
});

it('takes the build preset for a row whose role column was never written', function (): void {
    // Inserted with the query builder, naming no role, so the DEFAULT on the column is what
    // decides -- which is the state of every row the backfill did not reach and of any row a host
    // or a seeder writes directly.
    $id = DB::table('robot_council_agent_sessions')->insertGetId([
        'installation_id' => $this->installation->getKey(),
        'user_id' => $this->developer->getKey(),
        'status' => AgentSessionStatus::Active->value,
        'last_seen_at' => PresenceClock::now(),
        'created_at' => PresenceClock::now(),
        'updated_at' => PresenceClock::now(),
    ]);

    $session = AgentSession::query()->findOrFail($id);

    expect($session->role)->toBe(Role::Build);

    // And renewing it mints the preset, which is the path a running process would take
    $renewed = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $id]))
        ->assertOk();

    expect($renewed->json('abilities'))->toBe(Role::Build->tokenAbilities());
});

it('lets a coordinator session post a directive and refuses every other role', function (string $role, int $status): void {
    [$session] = $this->startAgentSession($this->installation);

    // Forced onto the row, because nothing asks for a role yet -- requesting one is #222 -- and
    // `ci` in particular is reachable by no other path today. The renewal below is what turns the
    // row into a token, so the token under test is minted by the code under test rather than here.
    $session->forceFill(['role' => Role::from($role)])->save();

    $token = stringValue($this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertOk()
        ->json('token'));

    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'everyone stop'])
        ->assertStatus($status);

    expect(FleetEvent::query()->where('type', FleetEventType::Directive->value)->count())
        ->toBe($status === 201 ? 1 : 0);
})->with([
    'coordinator' => ['coordinator', 201],
    'build' => ['build', 403],
    'ci' => ['ci', 403],
]);

it('reports the role to the session itself', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $session->forceFill(['role' => Role::Ci])->save();

    $token = stringValue($this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertOk()
        ->json('token'));

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('role', Role::Ci->value)
        ->assertJsonPath('abilities', Role::Ci->tokenAbilities());
});

it('mints a renewal from the role on the row, not from the instance the request arrived with', function (): void {
    $coordinatorInstallation = $this->approveInstallation($this->developer, [
        Ability::CoordinatorDirect->value,
    ], machineLabel: 'coordinator-machine');

    [$session, $first] = $this->startCoordinatorSession($coordinatorInstallation);

    expect(Tokens::abilities($session->tokens()->sole()))->toBe(Role::Coordinator->tokenAbilities());

    // Demoted on the row by something else -- an admin, or the sweep in a later slice -- while the
    // caller still holds a session instance that says `coordinator`
    DB::table('robot_council_agent_sessions')
        ->where('id', $session->getKey())
        ->update(['role' => Role::Build->value]);

    $renewed = $this->machine($this->installationCredential($coordinatorInstallation))
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertOk();

    expect($renewed->json('abilities'))->toBe(Role::Build->tokenAbilities())
        ->and($renewed->json('abilities'))->not->toContain(Ability::CoordinatorDirect->value);

    // The control: the first token really did carry it, so the assertion above is the re-read
    // rather than a coordinator session that never had the ability
    expect($first)->not->toBe(stringValue($renewed->json('token')));
});

it('renews from the row even when the caller holds an instance that disagrees', function (): void {
    // **Through the store, not the endpoint, and that is the whole point.** Route model binding
    // hands the controller a session it has just loaded, so the instance and the row always agree
    // there and no request can tell `roleOf()`'s re-read from a read of `$session->role`. A host
    // may call this method with an instance of any age, and `Installations::demoteSessions()`
    // writes the column under exactly such an instance.
    $coordinatorInstallation = $this->approveInstallation($this->developer, [
        Ability::CoordinatorDirect->value,
    ], machineLabel: 'coordinator-machine');

    [$session] = $this->startCoordinatorSession($coordinatorInstallation);

    expect($session->role)->toBe(Role::Coordinator);

    DB::table('robot_council_agent_sessions')
        ->where('id', $session->getKey())
        ->update(['role' => Role::Build->value]);

    // The instance still says coordinator, because nothing refreshed it
    expect($session->role)->toBe(Role::Coordinator);

    $issued = $this->service(AgentSessions::class)->renew($coordinatorInstallation, $session);

    expect($issued->abilities)->toBe(Role::Build->tokenAbilities())
        ->and($issued->abilities)->not->toContain(Ability::CoordinatorDirect->value);
});

it('falls back to the instance when the session row has gone, rather than failing the renewal', function (): void {
    // The branch that exists so a renewal for a pruned session ends the way it did before roles.
    // `value('role')` answers null for a row that is not there, and a fallback that returned that
    // null would be a `TypeError` out of a method declared to return a `Role`.
    //
    // **A COORDINATOR session, so the fallback is distinguishable from the floor.** Under a
    // `build` session the instance's role and `Role::Build` are the same value, and a fallback
    // rewritten to return the floor outright would pass.
    $coordinatorInstallation = $this->approveInstallation($this->developer, [
        Ability::CoordinatorDirect->value,
    ], machineLabel: 'coordinator-machine');

    [$session] = $this->startCoordinatorSession($coordinatorInstallation);

    expect($session->role)->toBe(Role::Coordinator);

    DB::table('robot_council_agent_sessions')->where('id', $session->getKey())->delete();

    $issued = $this->service(AgentSessions::class)->renew($coordinatorInstallation, $session);

    expect($issued->abilities)->toBe(Role::Coordinator->tokenAbilities());
});

/**
 * Write a `granted_abilities` payload straight onto a row, past every narrowing in the package.
 *
 * **Named apart from `MalformedAbilitiesTest`'s `plantAbilities()` deliberately.** A function
 * declared in a test file is global once that file loads, so sharing the name would redeclare it on
 * a full run, and calling the other file's copy would fail when this file runs on its own.
 *
 * The query builder rather than the model, because a save would put the `array` cast back in the
 * way -- which is the narrowing being stepped around.
 */
function plantStoredAbilities(Installation $installation, string $json): void
{
    DB::table('robot_council_installations')
        ->where('id', $installation->getKey())
        ->update(['granted_abilities' => $json]);
}

it('reads no stored ability anywhere in the authorization path, in either direction', function (): void {
    // **The acceptance criterion of `robot-council/core#231`, asserted rather than reviewed.**
    // `robot-council/core#221` let an ability change demote a machine's coordinator sessions;
    // `robot-council/core#222` took that away, and #231 removed the last writer. A role is `build`
    // at start and an administrator's decision after that.
    //
    // **Written straight to the row, which is what makes this outlive the method it replaced.**
    // The version before it drove `Support\Installations::setAbility()` and so could only say that
    // one method reached nothing; deleting that method would have deleted the coverage with it.
    // Planting the column directly asks the question the criterion actually poses -- does anything
    // in the authorization path read this -- and keeps asking it after every writer is gone.
    $installation = $this->approveInstallation($this->developer, [
        Ability::EventsPost->value,
        Ability::CoordinatorDirect->value,
    ], machineLabel: 'coordinator-machine');

    [$session] = $this->startCoordinatorSession($installation);

    expect($session->role)->toBe(Role::Coordinator);

    // Taking away the very ability the role carries, which is the sharpest case.
    plantStoredAbilities($installation, (string) json_encode([Ability::EventsPost->value]));

    expect($session->refresh()->role)->toBe(Role::Coordinator)
        ->and(Tokens::abilities($session->tokens()->sole()))->toBe(Role::Coordinator->tokenAbilities());

    // Renewal is the other door: it re-mints from the row's role, and an implementation that
    // re-derived from the column would narrow the token here instead.
    $renewed = $this->service(AgentSessions::class)->renew($installation->refresh(), $session);

    expect($renewed->abilities)->toBe(Role::Coordinator->tokenAbilities());

    // And putting it back promotes nothing. **Started under `$installation`, which holds
    // `coordinator:direct` again at this point** -- an earlier version started it under a fresh
    // installation that never held the ability, so the assertion was equally true with the
    // derivation restored and could not fail.
    plantStoredAbilities($installation, (string) json_encode([
        Ability::EventsPost->value,
        Ability::CoordinatorDirect->value,
    ]));

    expect($installation->refresh()->abilities())->toContain(Ability::CoordinatorDirect->value);

    [$next] = $this->startAgentSession($installation);

    expect($next->role)->toBe(Role::Build)
        ->and(Tokens::abilities($next->tokens()->sole()))->not->toContain(Ability::CoordinatorDirect->value);
});

it('takes the installation row while it renews, which is the package lock order', function (): void {
    // `renew()` no longer needs the installation's abilities, so the call that locks its row reads
    // as removable. It is not: it is the first row in the documented lock order, and dropping it
    // would let a renewal reach the session row and the token rows without it -- inverting the
    // order `Installations::revoke()` takes the same three in.
    //
    // **SQLite serializes writers, so no deadlock test in this suite could show that.** What is
    // observable on every engine is the query itself, which is what this counts. Driven through
    // the store rather than the endpoint, because the guard reads the same table on the way in and
    // would make the count say nothing.
    [$session] = $this->startAgentSession($this->installation);

    $touched = [];

    DB::listen(function (QueryExecuted $query) use (&$touched): void {
        if (str_contains($query->sql, 'robot_council_installations')) {
            $touched[] = $query->sql;
        }
    });

    $this->service(AgentSessions::class)->renew($this->installation, $session);

    expect($touched)->toHaveCount(1)
        ->and($touched[0])->toStartWith('select');
});

it('backfills a coordinator role for the sessions of a machine that already held the ability', function (): void {
    $coordinatorInstallation = $this->approveInstallation($this->developer, [
        Ability::TasksCreate->value,
        Ability::CoordinatorDirect->value,
    ], machineLabel: 'coordinator-machine');

    [$coordinatorSession] = $this->startAgentSession($coordinatorInstallation);
    [$buildSession] = $this->startAgentSession($this->installation);

    // Back to the pre-#221 shape, through the migration's own `down()` rather than a hand-written
    // `dropColumn` beside it. Those are body-equivalent today and that is exactly why the
    // hand-written form is the wrong instrument: it would keep passing against a `down()` that
    // dropped the wrong column or threw, which is the method CLAUDE.md records as the one that
    // drops a populated table when it is wrong.
    runTheRoleMigration('down');

    expect(Schema::hasColumn('robot_council_agent_sessions', 'role'))->toBeFalse();

    runTheRoleMigration('up');

    expect(Schema::hasColumn('robot_council_agent_sessions', 'role'))->toBeTrue();

    // **Both halves, because either one alone would pass against a broken derivation.** A migration
    // that wrote `coordinator` everywhere satisfies the first row; one that wrote `build`
    // everywhere satisfies the second.
    expect(AgentSession::query()->whereKey($coordinatorSession->getKey())->sole()->role)->toBe(Role::Coordinator)
        ->and(AgentSession::query()->whereKey($buildSession->getKey())->sole()->role)->toBe(Role::Build);

    // And the running coordinator keeps its ability through the next renewal, which is the whole
    // reason the backfill exists rather than the column's default being left to speak
    $renewed = $this->machine($this->installationCredential($coordinatorInstallation))
        ->postJson(route('robot-council.sessions.renew', ['session' => $coordinatorSession->getKey()]))
        ->assertOk();

    expect($renewed->json('abilities'))->toContain(Ability::CoordinatorDirect->value);
});

it('backfills on a re-run that finds the column already there', function (): void {
    // **The population this covers is a run that DIED between the two statements.** Only Postgres
    // and SQL Server wrap a migration in a transaction, so on SQLite and MySQL the ALTER and the
    // UPDATE are independent: a crash in between leaves the column added, nothing backfilled, and
    // no `migrations` row, and the operator re-runs `migrate`. A guard that returned early on
    // `hasColumn` would skip the backfill and report success -- which is how #54 shipped an
    // access-control change covering six of seven columns.
    $coordinatorInstallation = $this->approveInstallation($this->developer, [
        Ability::CoordinatorDirect->value,
    ], machineLabel: 'coordinator-machine');

    [$session] = $this->startAgentSession($coordinatorInstallation);

    // The half-finished state: the column exists and says `build` for everyone.
    DB::table('robot_council_agent_sessions')->update(['role' => Role::Build->value]);

    expect(AgentSession::query()->whereKey($session->getKey())->sole()->role)->toBe(Role::Build);

    runTheRoleMigration('up');

    expect(AgentSession::query()->whereKey($session->getKey())->sole()->role)->toBe(Role::Coordinator)
        ->and(Schema::hasColumn('robot_council_agent_sessions', 'role'))->toBeTrue();
});

it('rolls back twice and migrates twice without erroring, which is what the guards are for', function (): void {
    // The re-run guards on both sides. A second `down()` must not try to drop a column that is
    // gone, and a second `up()` must not try to add one that is there.
    [$session] = $this->startAgentSession($this->installation);

    runTheRoleMigration('down');
    runTheRoleMigration('down');

    expect(Schema::hasColumn('robot_council_agent_sessions', 'role'))->toBeFalse();

    runTheRoleMigration('up');
    runTheRoleMigration('up');

    expect(Schema::hasColumn('robot_council_agent_sessions', 'role'))->toBeTrue()
        ->and(AgentSession::query()->whereKey($session->getKey())->sole()->role)->toBe(Role::Build);
});

/**
 * Run the role migration in one direction against whatever the table currently looks like.
 *
 * Called as a narrowed callable rather than as `$migration->up()`, for the reason
 * `tests/EventIndexDropTest.php` records: a migration file returns `mixed` to the analyzer, and
 * `Migration` itself declares no `up()` -- the anonymous class the file returns does.
 *
 * @param  string  $direction  `up` or `down`.
 */
function runTheRoleMigration(string $direction): void
{
    // **The role INDEX migration is carried along, in Laravel's own order**, because a migration
    // cannot be run in isolation once a later one depends on its column.
    // `2026_09_23_000006` indexes `role`, and SQLite refuses to drop a column an index still
    // references: `error in index robot_council_agent_sessions_role_status_id_index after drop
    // column: no such column: role`. `Migrator::rollbackMigrations()` reverses the order, so a real
    // `php artisan migrate:rollback` drops the index first and never meets this -- it is reachable
    // only from a test that calls one `down()` on its own, which is what this helper was doing.
    //
    // Postgres and MySQL drop a dependent index along with the column and would not have reported
    // it, so this is one more thing only the SQLite runs can see.
    $order = [
        '2026_09_23_000003_add_role_to_robot_council_agent_sessions',
        '2026_09_23_000006_index_robot_council_agent_session_roles',
    ];

    foreach ($direction === 'down' ? array_reverse($order) : $order as $name) {
        $migration = require __DIR__.'/../database/migrations/'.$name.'.php';

        $run = [$migration, $direction];

        if (! \is_callable($run)) {
            throw new RuntimeException('The migration file did not return something with a '.$direction.'().');
        }

        $run();
    }
}
