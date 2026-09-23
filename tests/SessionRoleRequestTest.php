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
        ->call('approveRole', $this->session->getKey(), Role::Coordinator->value);

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
    // **Through the store rather than a raw column write**, so the token genuinely carries what the
    // starting role implies. Setting only the column left the demotion row asserting that a token
    // which never held `coordinator:direct` still does not hold it.
    $session = $from === Role::Coordinator->value
        ? $this->startCoordinatorSession($this->installation)[0]
        : $this->session;

    expect($session->refresh()->role)->toBe(Role::from($from))
        ->and($session->requested_role)->toBeNull()
        ->and(Tokens::abilities($session->tokens()->sole()))->toBe(Role::from($from)->tokenAbilities());

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('imposeRole', $session->getKey(), $to);

    expect($session->refresh()->role)->toBe(Role::from($to))
        ->and(Tokens::abilities($session->tokens()->sole()))->toBe(Role::from($to)->tokenAbilities());
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

    expect($requests->approve($this->session, Role::Coordinator, keyValue($this->admin->getKey())))->toBeNull()
        ->and($this->session->refresh()->role)->toBe(Role::Build);

    // The control beside it: a live session with the same pending request IS approved, so the
    // refusal above is the `gone` check rather than `approve()` being broken
    [$alive] = $this->startAgentSession($this->installation);

    expect($requests->request($alive, Role::Coordinator))->toBeTrue()
        ->and($requests->approve($alive, Role::Coordinator, keyValue($this->admin->getKey())))->toBe(Role::Coordinator);
});

it('will not record a request from a session that has gone', function (): void {
    $this->service(SessionPresence::class)->revoke($this->session);

    expect($this->service(RoleRequests::class)->request($this->session, Role::Coordinator))->toBeFalse()
        ->and($this->session->refresh()->requested_role)->toBeNull();
});

it('answers nothing to approve or deny when nothing is pending', function (): void {
    $requests = $this->service(RoleRequests::class);

    expect($requests->approve($this->session, Role::Coordinator, keyValue($this->admin->getKey())))->toBeNull()
        ->and($requests->deny($this->session, keyValue($this->admin->getKey())))->toBeFalse()
        ->and($this->session->refresh()->role)->toBe(Role::Build);
});

it('writes one event per role change, naming the old role, the new one and who decided', function (): void {
    $requests = $this->service(RoleRequests::class);

    $requests->request($this->session, Role::Coordinator);
    $requests->approve($this->session, Role::Coordinator, keyValue($this->admin->getKey()));

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

    // `approveRole` and `imposeRole` both carry a role; `denyRole` does not.

    // A status rather than a thrown exception: Livewire's harness renders `AuthorizationException`
    // into a response instead of propagating it.
    $component->call($action, ...$arguments)->assertForbidden();

    expect($this->session->refresh()->role)->toBe(Role::Build);
})->with([
    'approveRole' => ['approveRole', true],
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

    // **Pinned at five, which is what says WHICH limiter fired.** `toContain(429)` alone would
    // read the same if the route's own throttle were deleted and `agent_per_session` lowered --
    // the bound is the discriminator.
    expect($seen[4])->not->toBe(429)
        ->and($seen[5])->toBe(429)
        ->and($seen[0])->toBe(202);

    // **And it is keyed per session, not globally.** A single bucket would let one noisy session
    // lock every other session in the fleet out of asking, which is the opposite of what the
    // limiter exists for.
    $other = $this->approveInstallation($this->developer, machineLabel: 'unrelated');

    [, $otherToken] = $this->startAgentSession($other);

    $this->machine($otherToken)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
        ->assertStatus(202);
});

it('shows a pending request on the panel, and carries the role it rendered into the control', function (): void {
    // **A second installation first, so the two id sequences diverge.** With one of each, the
    // session id and the installation id are both 1 and an assertion naming `approveRole(1)` cannot
    // tell them apart -- it would keep passing with the control wired to the installation, which
    // would send an administrator's approval at the wrong row forever.
    $this->approveInstallation($this->developer, machineLabel: 'second-machine');
    $this->approveInstallation($this->developer, machineLabel: 'third-machine');

    [$session, $token] = $this->startAgentSession($this->installation);

    expect($session->getKey())->not->toBe($this->installation->getKey());

    $this->machine($token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
        ->assertStatus(202);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->assertSeeHtml('asked for coordinator')

        // The role rides the control, which is what makes the approval a compare-and-swap rather
        // than a read of whatever the row says when the click lands.
        ->assertSeeHtml('approveRole('.keyValue($session->getKey()).", 'coordinator')")
        ->assertSeeHtml('denyRole('.keyValue($session->getKey()).')');
});

it('offers no control for the role a session already holds', function (): void {
    // The "minus the one it already holds" rule, which nothing asserted.
    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->assertSeeHtml('Make coordinator')
        ->assertSeeHtml('Make ci')
        ->assertDontSeeHtml('Make build');
});

it('refuses an approval when the request changed after the page rendered it', function (): void {
    // **The escalation this exists to refuse.** The Approve control carries only what the page
    // showed, and the coordinator warning is gated on that value -- so a session that asks for a
    // harmless role, waits for the button to render with no warning on it, then asks for
    // `coordinator`, would collect `coordinator:direct` from an administrator who consented to
    // something else. Requests replace rather than queue, `wire:poll` pauses on a hidden tab, and
    // the asker pays nothing to wait, so the window is wide and free.
    $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Ci->value])
        ->assertStatus(202);

    // The administrator's page renders here, showing `ci` and no confirmation.
    $rendered = Role::Ci;

    // The session moves the goalposts before the click lands.
    $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
        ->assertStatus(202);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('approveRole', $this->session->getKey(), $rendered->value);

    $session = $this->session->refresh();

    // Nothing was granted, and the request is still pending for somebody to look at properly.
    expect($session->role)->toBe(Role::Build)
        ->and($session->requested_role)->toBe(Role::Coordinator);

    $this->machine($this->token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'everyone stop'])
        ->assertForbidden();

    // The control: approving the role that IS pending goes through, so the refusal above is the
    // compare-and-swap rather than approval being broken.
    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('approveRole', $this->session->getKey(), Role::Coordinator->value);

    expect($this->session->refresh()->role)->toBe(Role::Coordinator);
});

it('clears both request columns on a denial, not just the role', function (): void {
    // The migration's own docblock says the two move together, so a row can never say a role was
    // asked for at no time. Only the approval path asserted it.
    $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
        ->assertStatus(202);

    expect($this->session->refresh()->requested_at)->not->toBeNull();

    $this->service(RoleRequests::class)->deny($this->session, keyValue($this->admin->getKey()));

    $session = $this->session->refresh();

    expect($session->requested_role)->toBeNull()
        ->and($session->requested_at)->toBeNull();
});

it('says which role was refused and which it stays, in that order', function (): void {
    // A feed saying "was refused build and stays coordinator" is the exact inversion, and
    // `toContain('refused')` cannot tell the two apart. `InstallationStoreTest` pins a whole event
    // body for the same reason.
    $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
        ->assertStatus(202);

    $this->service(RoleRequests::class)->deny($this->session, keyValue($this->admin->getKey()));

    $event = FleetEvent::query()
        ->where('type', FleetEventType::SessionRoleRequested->value)
        ->orderByDesc('id')
        ->firstOrFail();

    expect($event->body)->toBe(sprintf(
        'session %s was refused coordinator and stays build.',
        keyValue($this->session->getKey())
    ))
        ->and(arrayValue($event->meta)['refused'] ?? null)->toBe(Role::Coordinator->value)
        ->and(arrayValue($event->meta)['stays'] ?? null)->toBe(Role::Build->value);
});

it('says which way a request was asked, in that order', function (): void {
    $this->service(RoleRequests::class)->request($this->session, Role::Coordinator);

    $event = FleetEvent::query()
        ->where('type', FleetEventType::SessionRoleRequested->value)
        ->orderBy('id')
        ->firstOrFail();

    expect($event->body)->toBe(sprintf(
        'session %s asked to change from build to coordinator.',
        keyValue($this->session->getKey())
    ))
        ->and(arrayValue($event->meta)['from'] ?? null)->toBe(Role::Build->value)
        ->and(arrayValue($event->meta)['to'] ?? null)->toBe(Role::Coordinator->value)

        // Nobody decided anything yet, so nobody is recorded as having
        ->and($event->actor_user_id)->toBeNull();
});

it('reports a pending request to the session itself', function (): void {
    // What lets a bridge tell "nobody has decided yet" from "it was refused". Without it a client
    // either asks forever or gives up the first time.
    $this->machine($this->token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('requested_role', null);

    $this->machine($this->token)
        ->postJson(route('robot-council.agent.role'), ['role' => Role::Coordinator->value])
        ->assertStatus(202);

    $this->machine($this->token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('requested_role', Role::Coordinator->value);

    $this->service(RoleRequests::class)->deny($this->session, keyValue($this->admin->getKey()));

    $this->machine($this->token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('requested_role', null);
});

it('answers a stale click on a session that no longer exists, rather than failing', function (string $action, array $extra): void {
    // An administrator's page can outlive the rows it rendered. A 500 here would be an unhandled
    // `TypeError` on a null, which is a worse answer than doing nothing.
    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call($action, 987654, ...$extra)
        ->assertOk();
})->with([
    'approveRole' => ['approveRole', ['coordinator']],
    'denyRole' => ['denyRole', []],
    'imposeRole' => ['imposeRole', ['coordinator']],
]);
