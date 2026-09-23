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

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
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
        ->assertJson(['role' => Role::Ci->value, 'abilities' => Role::Ci->tokenAbilities()]);
});

it('mints a renewal from the role on the row, not from the instance the request arrived with', function (): void {
    $coordinatorInstallation = $this->approveInstallation($this->developer, [
        Ability::CoordinatorDirect->value,
    ], machineLabel: 'coordinator-machine');

    [$session, $first] = $this->startAgentSession($coordinatorInstallation);

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

it('backfills a coordinator role for the sessions of a machine that already held the ability', function (): void {
    $coordinatorInstallation = $this->approveInstallation($this->developer, [
        Ability::TasksCreate->value,
        Ability::CoordinatorDirect->value,
    ], machineLabel: 'coordinator-machine');

    [$coordinatorSession] = $this->startAgentSession($coordinatorInstallation);
    [$buildSession] = $this->startAgentSession($this->installation);

    // Back to the pre-#221 shape. The rows survive; only the column goes, which is the state every
    // host that has run the release before this one is in.
    Schema::table('robot_council_agent_sessions', function (Blueprint $table): void {
        $table->dropColumn('role');
    });

    expect(Schema::hasColumn('robot_council_agent_sessions', 'role'))->toBeFalse();

    runTheRoleBackfill();

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

it('runs the backfill again without changing anything, which is the re-migrated population', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    // The column is already there, so `up()` returns before it touches anything. A host that runs
    // `migrate` twice, and a fresh install that has no rows to rewrite, both take this path.
    runTheRoleBackfill();

    expect(AgentSession::query()->whereKey($session->getKey())->sole()->role)->toBe(Role::Build)
        ->and(Schema::hasColumn('robot_council_agent_sessions', 'role'))->toBeTrue();
});

/**
 * Run the role migration against whatever the table currently looks like.
 *
 * Called as a narrowed callable rather than as `$migration->up()`, for the reason
 * `tests/EventIndexDropTest.php` records: a migration file returns `mixed` to the analyzer, and
 * `Migration` itself declares no `up()` -- the anonymous class the file returns does.
 */
function runTheRoleBackfill(): void
{
    $migration = require __DIR__.'/../database/migrations/2026_09_23_000003_add_role_to_robot_council_agent_sessions.php';

    $up = [$migration, 'up'];

    if (! \is_callable($up)) {
        throw new RuntimeException('The migration file did not return something with an up().');
    }

    $up();
}
