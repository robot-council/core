<?php

declare(strict_types=1);

/**
 * The change feed page names a role by its label, as the Agents and Administration pages do (#501).
 *
 * Rendered on read: the stored body and its data keep the stored value `ci`, which is what an agent
 * reads and what the tools take, and the page shows "gate". Each case writes through the store and
 * then reads both sides, so a change that rewrote the stored body would fail the stored half and one
 * that stopped relabeling would fail the page half.
 *
 * @command  vendor/bin/pest --compact tests/RoleLabelInFeedTest.php
 */
use Livewire\Livewire;
use RobotCouncil\Access\Role;
use RobotCouncil\Livewire\ChangeFeed;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\RoleRequests;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 4243], admins: [4242]);

    $this->admin = $this->enrollDeveloper(4242, login: 'octoadmin');
    $this->developer = $this->enrollDeveloper(4243, login: 'octodev');

    [$this->session] = $this->startAgentSession($this->approveInstallation($this->developer));
});

/**
 * The latest event of a type, as stored.
 *
 * @param  string  $type  The event type.
 * @return FleetEvent The event.
 */
function latestOfType(string $type): FleetEvent
{
    return FleetEvent::query()->where('type', $type)->orderByDesc('id')->firstOrFail();
}

it('shows each role change by its label on the change feed page, and stores the value an agent reads', function (Closure $act, string $type, string $stored, string $shown, array $data): void {
    $act($this->service(RoleRequests::class), $this->session);

    $event = latestOfType($type);
    $id = $this->session->id;

    // Stored as written: the body and the data keep `ci`, which `events_read` returns
    expect($event->body)->toBe(sprintf($stored, $id))
        ->and(array_intersect_key($event->meta ?? [], $data))->toBe($data);

    $html = Livewire::actingAs($this->admin)->test(ChangeFeed::class)->html();

    expect($html)->toContain('<p class="break-words">'.sprintf($shown, $id).'</p>')
        ->and($html)->not->toContain(sprintf($stored, $id));
})->with([
    'a request' => [
        static fn (RoleRequests $roles, AgentSession $session): bool => $roles->request($session, Role::Ci),
        'session.role_requested',
        'session %d asked to change from build to ci.',
        'session %d asked to change from build to gate.',
        ['from' => 'build', 'to' => 'ci'],
    ],
    'a refusal' => [
        static function (RoleRequests $roles, AgentSession $session): void {
            $roles->request($session, Role::Ci);
            $roles->deny($session->refresh(), 'test-administrator');
        },
        'session.role_requested',
        'session %d was refused ci and stays build.',
        'session %d was refused gate and stays build.',
        ['refused' => 'ci', 'stays' => 'build'],
    ],
    'a withdrawal' => [
        static function (RoleRequests $roles, AgentSession $session): void {
            $roles->request($session, Role::Ci);
            $roles->request($session->refresh(), Role::Build);
        },
        'session.role_withdrawn',
        'session %d withdrew its request for ci and stays build.',
        'session %d withdrew its request for gate and stays build.',
        ['withdrawn' => 'ci', 'stays' => 'build'],
    ],
    'an approval' => [
        static function (RoleRequests $roles, AgentSession $session): void {
            $roles->request($session, Role::Ci);
            $roles->approve($session->refresh(), Role::Ci, 'test-administrator');
        },
        'session.role_changed',
        'session %d approved from build to ci.',
        'session %d approved from build to gate.',
        ['from' => 'build', 'to' => 'ci', 'how' => 'approved'],
    ],
    'an imposition' => [
        static fn (RoleRequests $roles, AgentSession $session): bool => $roles->impose($session, Role::Ci, 'test-administrator'),
        'session.role_changed',
        'session %d imposed from build to ci.',
        'session %d imposed from build to gate.',
        ['from' => 'build', 'to' => 'ci', 'how' => 'imposed'],
    ],
]);

it('shows a role event exactly as written when its body and its data disagree', function (array $meta): void {
    /** @var array<string, mixed> $meta */
    // An event whose body the data does not rebuild -- edited, or written in some other shape --
    // is not relabeled, since the page would then be saying something the event did not
    $this->service(FleetEvents::class)->record(
        FleetEventType::SessionRoleRequested,
        $this->session,
        sprintf('session %d asked to change from build to ci.', $this->session->id),
        $meta
    );

    $html = Livewire::actingAs($this->admin)->test(ChangeFeed::class)->html();

    expect($html)->toContain(sprintf('session %d asked to change from build to ci.', $this->session->id))
        ->and($html)->not->toContain('to gate');
})->with([
    'data naming another role' => [['from' => 'build', 'to' => 'coordinator']],
    'data naming no role' => [['from' => 'build', 'to' => 'nothing']],
    'no data at all' => [[]],
]);

it('rewrites no other event, whatever it says', function (): void {
    $this->service(FleetEvents::class)->record(
        FleetEventType::Narration,
        $this->session,
        sprintf('session %d asked to change from build to ci.', $this->session->id),
        ['from' => 'build', 'to' => 'ci']
    );

    $html = Livewire::actingAs($this->admin)->test(ChangeFeed::class)->html();

    expect($html)->toContain(sprintf('session %d asked to change from build to ci.', $this->session->id));
});
