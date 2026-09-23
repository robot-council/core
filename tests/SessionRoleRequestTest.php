<?php

declare(strict_types=1);

/**
 * Asking to be a different role, and an administrator deciding.
 *
 * The rule `robot-council/core#222` states, and which every test here exists to hold: **a session
 * may request a role change, an administrator may impose one, and neither a session nor any
 * automatic rule may effect one alone.** No direction is exempt, including a narrowing.
 *
 * The load-bearing one is `it('never changes a role however the request is repeated or shaped')`.
 * `POST api/sessions` is an unattended call from a bridge, so a role granted on the strength of
 * asking would be a role asserted, and any checkout could take `coordinator:direct` by asking.
 *
 * @command  vendor/bin/pest --compact tests/SessionRoleRequestTest.php
 */

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\RoleRequests;
use RobotCouncil\Support\SessionPresence;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 4243], admins: [4242]);

    $this->admin = $this->enrollDeveloper(4242, login: 'octoadmin');
    $this->developer = $this->enrollDeveloper(4243, login: 'octodev');

    $this->installation = $this->approveInstallation($this->developer);
    $this->credential = $this->installationCredential($this->installation);

    [$this->session, $this->token] = $this->startAgentSession($this->installation);
});

it('starts every session as build, whatever its installation holds', function (): void {
    // The decision recorded on #222: the derivation `robot-council/core#221` shipped gave
    // `coordinator` to every checkout of a coordinator machine, which is the defect the epic was
    // filed about. Measured on the deployed fleet at the time: one machine, 41 sessions in 5 days.
    $coordinatorMachine = $this->approveInstallation($this->developer, [
        Ability::CoordinatorDirect->value,
    ], machineLabel: 'coordinator-machine');

    [$session] = $this->startAgentSession($coordinatorMachine);

    expect($session->role)->toBe(Role::Build)
        ->and(Tokens::abilities($session->tokens()->sole()))->toBe(Role::Build->tokenAbilities());
});

it('records a request and changes nothing the session may do', function (): void {
    $before = Tokens::abilities($this->session->tokens()->sole());

    $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
        ->assertStatus(202)
        ->assertJsonPath('pending', true)
        ->assertJsonPath('requested_role', Role::Coordinator->value)

        // What it still holds, returned beside the request so a 2xx cannot read as the change
        ->assertJsonPath('role', Role::Build->value);

    $session = $this->session->refresh();

    expect($session->role)->toBe(Role::Build)
        ->and($session->requested_role)->toBe(Role::Coordinator)
        ->and($session->requested_at)->not->toBeNull()
        ->and(Tokens::abilities($session->tokens()->sole()))->toBe($before);

    // And it is refused at the one route the role would have opened
    $this->machine($this->token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'everyone stop'])
        ->assertForbidden();
});

it('never changes a role however the request is repeated or shaped', function (): void {
    // The whole gate, asserted as a property rather than as one case: no sequence of requests from
    // the session itself moves the role, because the approval is a human and the client has none.
    foreach ([Role::Coordinator, Role::Ci, Role::Coordinator, Role::Build, Role::Coordinator] as $wanted) {
        $this->machine($this->token)
            ->postJson(route('robot-council.agent.role'), ['role' => $wanted->value]);
    }

    expect($this->session->refresh()->role)->toBe(Role::Build);

    $this->machine($this->token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'everyone stop'])
        ->assertForbidden();

    // At most one request is pending at a time: asking again replaces rather than queues
    expect(AgentSession::query()->whereKey($this->session->getKey())->sole()->requested_role)
        ->toBe(Role::Coordinator);
});

it('refuses a role outside the three, and names the valid ones', function (): void {
    $response = $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => 'superuser'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    expect(stringValue($response->json('message')))->toContain('build')
        ->toContain('ci')
        ->toContain('coordinator')
        ->and($this->session->refresh()->requested_role)->toBeNull();
});

it('treats asking to be what it already is as nothing to decide', function (): void {
    $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Build->value])
        ->assertOk()
        ->assertJsonPath('pending', false)
        ->assertJsonPath('requested_role', null);

    // Nothing queued, because approving it would change nothing and an administrator's queue is
    // the scarce resource this protects
    expect($this->session->refresh()->requested_role)->toBeNull()
        ->and(FleetEvent::query()->where('type', FleetEventType::SessionRoleRequested->value)->count())->toBe(0);
});

it('lets an approved session direct where it was refused a moment before', function (): void {
    $this->machine($this->token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'too early'])
        ->assertForbidden();

    $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
        ->assertStatus(202);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('approveRole', $this->session->getKey());

    $session = $this->session->refresh();

    expect($session->role)->toBe(Role::Coordinator)
        ->and($session->requested_role)->toBeNull()
        ->and($session->requested_at)->toBeNull();

    // **The same token, not a renewed one.** An approval that only reached the next renewal would
    // be up to an hour late, which is indistinguishable from one nobody made.
    $this->machine($this->token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'everyone stop'])
        ->assertCreated();
});

it('leaves a denied session where it was, and says so in the feed', function (): void {
    $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
        ->assertStatus(202);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('denyRole', $this->session->getKey());

    $session = $this->session->refresh();

    expect($session->role)->toBe(Role::Build)
        ->and($session->requested_role)->toBeNull();

    $this->machine($this->token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'everyone stop'])
        ->assertForbidden();

    // A refusal that wrote nothing would leave the session's operator unable to tell it from a
    // request nobody has looked at yet
    $events = FleetEvent::query()->where('type', FleetEventType::SessionRoleRequested->value)->get();

    expect($events)->toHaveCount(2)
        ->and(stringValue($events->last()?->body))->toContain('refused');
});

it('imposes a role with no request outstanding, in either direction', function (string $from, string $to): void {
    DB::table('robot_council_agent_sessions')
        ->where('id', $this->session->getKey())
        ->update(['role' => $from]);

    expect($this->session->refresh()->requested_role)->toBeNull();

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('imposeRole', $this->session->getKey(), $to);

    expect($this->session->refresh()->role)->toBe(Role::from($to))
        ->and(Tokens::abilities($this->session->tokens()->sole()))->toBe(Role::from($to)->tokenAbilities());
})->with([
    'promotion' => ['build', 'coordinator'],
    'demotion' => ['coordinator', 'build'],
    'sideways' => ['build', 'ci'],
]);

it('refuses a demoted session at the route its role had opened', function (): void {
    $requests = $this->service(RoleRequests::class);

    expect($requests->impose($this->session, Role::Coordinator, keyValue($this->admin->getKey())))->toBeTrue();

    $this->machine($this->token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'while coordinating'])
        ->assertCreated();

    expect($requests->impose($this->session, Role::Build, keyValue($this->admin->getKey())))->toBeTrue();

    // On its NEXT call, with no renewal in between, because the token was re-minted rather than
    // left for the next one
    $this->machine($this->token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'after being demoted'])
        ->assertForbidden();
});

it('will not approve a session that has gone', function (): void {
    $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
        ->assertStatus(202);

    $this->service(SessionPresence::class)->revoke($this->session);

    $requests = $this->service(RoleRequests::class);

    expect($requests->approve($this->session, keyValue($this->admin->getKey())))->toBeNull()
        ->and($this->session->refresh()->role)->toBe(Role::Build);

    // The control beside it: a live session with the same pending request IS approved, so the
    // refusal above is the `gone` check rather than `approve()` being broken
    [$alive] = $this->startAgentSession($this->installation);

    expect($requests->request($alive, Role::Coordinator))->toBeTrue()
        ->and($requests->approve($alive, keyValue($this->admin->getKey())))->toBe(Role::Coordinator);
});

it('will not record a request from a session that has gone', function (): void {
    $this->service(SessionPresence::class)->revoke($this->session);

    expect($this->service(RoleRequests::class)->request($this->session, Role::Coordinator))->toBeFalse()
        ->and($this->session->refresh()->requested_role)->toBeNull();
});

it('answers nothing to approve or deny when nothing is pending', function (): void {
    $requests = $this->service(RoleRequests::class);

    expect($requests->approve($this->session, keyValue($this->admin->getKey())))->toBeNull()
        ->and($requests->deny($this->session, keyValue($this->admin->getKey())))->toBeFalse()
        ->and($this->session->refresh()->role)->toBe(Role::Build);
});

it('writes one event per role change, naming the old role, the new one and who decided', function (): void {
    $requests = $this->service(RoleRequests::class);

    $requests->request($this->session, Role::Coordinator);
    $requests->approve($this->session, keyValue($this->admin->getKey()));

    $event = FleetEvent::query()->where('type', FleetEventType::SessionRoleChanged->value)->sole();

    expect(arrayValue($event->meta)['from'] ?? null)->toBe(Role::Build->value)
        ->and(arrayValue($event->meta)['to'] ?? null)->toBe(Role::Coordinator->value)
        ->and(arrayValue($event->meta)['how'] ?? null)->toBe('approved')
        ->and($event->actor_user_id)->toBe(keyValue($this->admin->getKey()))

        // The event is ABOUT the session's own developer and was DONE by the administrator, which
        // is the split #115 added and the reason a feed reader can attribute either
        ->and($event->user_id)->toBe(keyValue($this->developer->getKey()));

    // An imposition is the same type with a different `how`, so a reader hunting "what may this
    // session do and who decided" reads one stream rather than two
    $requests->impose($this->session, Role::Build, keyValue($this->admin->getKey()));

    expect(FleetEvent::query()->where('type', FleetEventType::SessionRoleChanged->value)->count())->toBe(2)
        ->and(arrayValue(FleetEvent::query()->where('type', FleetEventType::SessionRoleChanged->value)
            ->orderByDesc('id')->firstOrFail()->meta)['how'] ?? null)->toBe('imposed');
});

it('writes no event and changes nothing when an imposition asks for the role already held', function (): void {
    $before = FleetEvent::query()->count();

    expect($this->service(RoleRequests::class)->impose($this->session, Role::Build, keyValue($this->admin->getKey())))
        ->toBeFalse()
        ->and(FleetEvent::query()->count())->toBe($before)
        ->and($this->session->refresh()->role)->toBe(Role::Build);
});

it('refuses every new entry point to a developer who is not an admin', function (string $action, bool $withRole): void {
    // **Mounted as the admin first, then the rights are taken away**, which is the shape the
    // existing refusal test in `AdministrationTest` records: `mount()` authorizes, so a component
    // tested as a non-admin never exists to call an action on. This is also the real case -- a
    // developer whose admin status is removed is still holding a page that renders every control,
    // and nothing re-runs the dashboard's route middleware on `/livewire/update`.
    $component = Livewire::actingAs($this->admin)->test(Administration::class);

    $this->setAccessLists(developers: [4242, 4243], admins: []);

    $arguments = $withRole
        ? [$this->session->getKey(), Role::Coordinator->value]
        : [$this->session->getKey()];

    // A status rather than a thrown exception: Livewire's harness renders `AuthorizationException`
    // into a response instead of propagating it.
    $component->call($action, ...$arguments)->assertForbidden();

    expect($this->session->refresh()->role)->toBe(Role::Build);
})->with([
    'approveRole' => ['approveRole', false],
    'denyRole' => ['denyRole', false],
    'imposeRole' => ['imposeRole', true],
]);

it('refuses an imposed role outside the three', function (): void {
    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('imposeRole', $this->session->getKey(), 'superuser')
        ->assertStatus(422);

    expect($this->session->refresh()->role)->toBe(Role::Build);
});

it('limits how fast a session can ask, so a denial cannot fill the queue', function (): void {
    // The limiter is the session's own and stacks on the agent group's, because a denied session
    // re-asking in a loop is a flood aimed at a human rather than at the service.
    $seen = [];

    foreach (range(1, 8) as $ignored) {
        $seen[] = $this->machine($this->token)
            ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
            ->getStatusCode();
    }

    expect($seen)->toContain(429);

    // The control: the first call was not itself refused, so the 429 above is a limit rather than
    // the route being broken
    expect($seen[0])->toBe(202);
});

it('shows a pending request on the panel without pre-filling the answer', function (): void {
    $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
        ->assertStatus(202);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->assertSeeHtml('asked for coordinator')
        ->assertSeeHtml('approveRole('.keyValue($this->session->getKey()).')')
        ->assertSeeHtml('denyRole('.keyValue($this->session->getKey()).')');
});
