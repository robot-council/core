<?php

declare(strict_types=1);

/**
 * Who an event is ABOUT, and who DID it, in two columns rather than one.
 *
 * `robot_council_events.user_id` used to mean one of two things depending on how the row was
 * written: for a session's event the developer it concerns, and for an administrative event the
 * developer who acted. Those are different people by construction, because an admin administers
 * *other* developers' installations.
 *
 * **Nothing read the difference, which is what made it easy to leave.** #29's visibility rule
 * consults `user_id` only for a restricted type, and `Models\FleetEventType::isRestricted()`
 * answers true for narration alone -- so every administrative type reached every reader through the
 * other branch. The enum presents adding a restricted type as a one-line change, and the moment an
 * `installation.*` type became one, the rule would have served the event to the **admin's**
 * sessions and hidden it from the **owner's**. Backwards, silently, in the file that decides a
 * security boundary (#115).
 *
 * @command  vendor/bin/pest --compact tests/AdministrativeActorTest.php
 */

use RobotCouncil\Access\Ability;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\FleetFeed;
use RobotCouncil\Support\Installations;
use RobotCouncil\Support\SessionPresence;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77], admins: [77]);

    // Two accounts, deliberately: every assertion below distinguishes them, so a copy of the wrong
    // key cannot pass for the right one.
    $this->developer = $this->enrollDeveloper(4242, 'octodev');
    $this->admin = $this->enrollDeveloper(77, 'octoadmin');

    $this->installation = $this->approveInstallation($this->developer);
});

it('records which admin revoked a session, against a second developer entirely', function (): void {
    // **The action the feed could not attribute.** `SessionPresence::revoke()` took no actor, so
    // the `session.gone` event named the developer whose agent was killed and nothing else. Killing
    // another developer's running agent is the case where "by whom" matters most.
    [$session] = $this->startAgentSession($this->installation);

    $this->service(SessionPresence::class)->revoke($session, keyValue($this->admin->getKey()));

    $event = FleetEvent::query()->where('type', FleetEventType::SessionGone)->sole();

    expect($event->user_id)->toBe(keyValue($this->developer->getKey()))
        ->and($event->actor_user_id)->toBe(keyValue($this->admin->getKey()))
        ->and($event->user_id)->not->toBe($event->actor_user_id);
});

it('leaves the actor null when nobody signed in ended the session', function (): void {
    // The control for the test above, and the honest answer for a session that ended on its own.
    // Repeating the owner there would say an admin acted when none did.
    [$session] = $this->startAgentSession($this->installation);

    $this->service(SessionPresence::class)->end($session);

    $event = FleetEvent::query()->where('type', FleetEventType::SessionGone)->sole();

    expect($event->user_id)->toBe(keyValue($this->developer->getKey()))
        ->and($event->actor_user_id)->toBeNull();
});

it('gives user_id one meaning across an agent-written and an admin-written event', function (): void {
    // The criterion this file exists for: one column, one meaning, pinned from both directions in
    // a single test so the two cannot drift apart unnoticed.
    [$session] = $this->startAgentSession($this->installation);

    // **`revoke()` rather than the ability grant this used to call.** `robot-council/core#231`
    // retired that control; what this needs is an administrative act whose actor differs from its
    // subject, and revoking another developer's installation is one.
    $this->service(Installations::class)->revoke($this->installation, keyValue($this->admin->getKey()));

    $enrolled = FleetEvent::query()->where('type', FleetEventType::SessionJoined)->sole();
    $granted = FleetEvent::query()->where('type', FleetEventType::InstallationRevoked)->sole();

    expect($enrolled->user_id)->toBe(keyValue($this->developer->getKey()))
        ->and($enrolled->actor_user_id)->toBeNull()
        // The admin-written event is about the same developer, not about the admin.
        ->and($granted->user_id)->toBe(keyValue($this->developer->getKey()))
        ->and($granted->actor_user_id)->toBe(keyValue($this->admin->getKey()))
        ->and($session->user_id)->toBe($granted->user_id);
});

it('serves a restricted event to the developer it is about, not to whoever recorded it', function (): void {
    // **The property that was backwards, and what this test does and does not establish.** No
    // `installation.*` type is restricted today and `isRestricted()` is a `match` a test cannot
    // override, so rather than contriving one this plants a row in the shape an administrative
    // event now takes and gives it the one type that *is* restricted.
    //
    // What it shows is that the visibility rule follows `user_id` -- which is unchanged code, and
    // would pass on `main` too. The half that moved is that an admin's action now writes the
    // OWNER there, which the store test above pins. **The property holds by the two together**,
    // and neither alone is the whole of it. `actor_user_id` on the planted row is set for
    // realism rather than because the rule reads it; it does not.
    [$ownerSession] = $this->startAgentSession($this->installation);

    $adminInstallation = $this->approveInstallation($this->admin, 'admin-machine');

    [$adminSession] = $this->startAgentSession($adminInstallation);

    FleetEvent::query()->create([
        'agent_session_id' => null,
        'user_id' => keyValue($this->developer->getKey()),
        'actor_user_id' => keyValue($this->admin->getKey()),
        'type' => FleetEventType::Narration,
        'body' => 'restricted, and about the owner',
        'posted_with_coordinator' => false,
    ]);

    $seenByOwner = collect(arrayValue($this->service(FleetFeed::class)->after($ownerSession, 0, 50)['events']))
        ->pluck('body')->all();

    $seenByAdmin = collect(arrayValue($this->service(FleetFeed::class)->after($adminSession, 0, 50)['events']))
        ->pluck('body')->all();

    expect($seenByOwner)->toContain('restricted, and about the owner')
        ->and($seenByAdmin)->not->toContain('restricted, and about the owner');
});

it('resolves both developers for the dashboard, and neither for an ordinary event', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    // **`revoke()` rather than the ability grant this used to call.** `robot-council/core#231`
    // retired that control; what this needs is an administrative act whose actor differs from its
    // subject, and revoking another developer's installation is one.
    $this->service(Installations::class)->revoke($this->installation, keyValue($this->admin->getKey()));

    $feed = collect($this->service(FleetFeed::class)->latest(50));

    $granted = $feed->firstWhere('type', FleetEventType::InstallationRevoked->value);
    $enrolled = $feed->firstWhere('type', FleetEventType::SessionJoined->value);

    expect(arrayValue($granted['actor'] ?? [])['github_login'] ?? null)->toBe('octodev')
        ->and(arrayValue($granted['performed_by'] ?? [])['github_login'] ?? null)->toBe('octoadmin')
        // An agent's own event has nobody administering it, and says so rather than naming the
        // poster twice. **Asserted with `array_key_exists` rather than `?? 'missing'`**, which
        // reads a present null as absent and made this assertion answer for itself.
        ->and(arrayValue($enrolled['actor'] ?? [])['github_login'] ?? null)->toBe('octodev')
        ->and(arrayValue($enrolled ?? []))->toHaveKey('performed_by')
        ->and(arrayValue($enrolled ?? [])['performed_by'])->toBeNull();
});

it('refuses a key it could not store, for the actor as well as the subject', function (): void {
    // `user_id` and `actor_user_id` are both `varchar(64)`, which Postgres refuses past its length
    // and SQLite stores whole -- one call, two outcomes, which is why the store bounds it rather
    // than the column.
    $tooLong = str_repeat('k', 65);

    expect(fn () => $this->service(FleetEvents::class)
        ->record(FleetEventType::InstallationRevoked, null, 'over-long actor', [], false, $tooLong))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $this->service(FleetEvents::class)
            ->record(FleetEventType::InstallationRevoked, null, 'over-long subject', [], false, null, $tooLong))->toThrow(RuntimeException::class)
        ->and(FleetEvent::query()->count())->toBe(0);
});
