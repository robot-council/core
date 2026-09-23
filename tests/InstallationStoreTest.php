<?php

declare(strict_types=1);

/**
 * The parts of `Support\Installations` that `--mutate` could change without a test noticing.
 *
 * Surfaced by #146 and filed as #132's sibling, #148: seventeen of sixty-one mutants survived, all
 * in `revoke()` and `setAbility()`. A surviving mutant is a test that stays green with the behavior
 * changed, and the criterion in this repository is **no unexplained survivors** rather than a
 * score -- a percentage merges an unexamined survivor with a provably equivalent one.
 *
 * Every assertion here reads the ROW or the recorded event rather than the instance a store method
 * returned, for the reason `CLAUDE.md` records: an instance reports whatever PHP put in it.
 *
 * @command  vendor/bin/pest --compact tests/InstallationStoreTest.php
 * @command  vendor/bin/pest --mutate --path=src --class="RobotCouncil\Support\Installations"
 */

use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\Installations;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242, 'octodev');
    $this->admin = $this->enrollDeveloper(77, 'octoadmin');
});

it('accepts a requested IP at the column width and refuses one past it', function (): void {
    // The boundary, which is where `>` and `>=` differ and which no test reached. `varchar(45)` is
    // what an IPv6 address with an embedded IPv4 needs, so a 45-character value is a real address
    // rather than a contrived one -- refusing it would refuse the widest address there is.
    $code = $this->service(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'v'),
        str_repeat('a', DeviceCodes::MAX_REQUESTED_IP)
    );

    $this->service(DeviceCodes::class)
        ->approve($code->record, keyValue($this->developer->getKey()), [Ability::TasksCreate->value]);

    // Asserted on the row rather than on the returned instance's class, which the return type
    // already guarantees -- `composer analyse` calls that expectation redundant, and it would be.
    $created = $this->service(Installations::class)->createFrom($code->record->refresh())->owner;

    expect(Installation::query()->whereKey($created->getKey())->value('requested_ip'))
        ->toBe(str_repeat('a', DeviceCodes::MAX_REQUESTED_IP));

    // One character further and the store refuses rather than letting Postgres do it -- and SQLite
    // would not have.
    $long = $this->service(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'other-machine',
        hash('sha256', 'v2'),
        null
    );

    $this->service(DeviceCodes::class)
        ->approve($long->record, keyValue($this->developer->getKey()), [Ability::TasksCreate->value]);

    // **Filled on the instance and never written**, which is both the direct-call path the guard
    // exists for -- a host constructing a model itself and handing it to a public store method --
    // and the only form that runs on every engine. `requested_ip` is `varchar(45)`: writing 46
    // characters into the row passes on SQLite, which does not enforce a length, and fails on
    // Postgres with `22001 value too long`. Measured here, on exactly that.
    //
    // `createFrom()` bounds the value before it opens its transaction, so an unsaved model reaches
    // the check without anything touching the database.
    $reloaded = DeviceCode::query()->whereKey($long->record->getKey())->sole();

    $reloaded->forceFill(['requested_ip' => str_repeat('a', DeviceCodes::MAX_REQUESTED_IP + 1)]);

    expect(fn () => $this->service(Installations::class)->createFrom($reloaded))
        ->toThrow(InvalidArgumentException::class)
        // And nothing was written on the way: the refusal is the store's, not the column's.
        ->and(DB::table('robot_council_device_codes')->where('id', $long->record->id)->value('requested_ip'))
        ->toBeNull();
});

it('leaves the caller holding a revoked installation, not the one it passed in', function (): void {
    // `revoke()` updates the row and then refreshes the instance. Without the refresh the caller
    // keeps an object that still answers `isUsable()` true -- and the dashboard re-renders from
    // exactly that object after calling this.
    $installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    expect($installation->isUsable())->toBeTrue();

    $this->service(Installations::class)->revoke($installation, keyValue($this->admin->getKey()));

    expect($installation->revoked_at)->not->toBeNull()
        ->and($installation->isUsable())->toBeFalse();
});

it('counts the tokens it deleted across the installation and its sessions', function (): void {
    // The count is a sum over two sources, so a sign flip reports the session's tokens as a debit
    // against the installation's and the caller is told fewer credentials died than did.
    $installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $this->installationCredential($installation);

    [$first] = $this->startAgentSession($installation);
    [$second] = $this->startAgentSession($installation);

    $before = DB::table('personal_access_tokens')->count();

    expect($before)->toBeGreaterThanOrEqual(3);

    $deleted = $this->service(Installations::class)->revoke($installation, keyValue($this->admin->getKey()));

    expect($deleted)->toBe($before)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and($first->getKey())->not->toBe($second->getKey());
});

it('answers zero and records nothing when the ability is already what was asked for', function (): void {
    // The "nothing changed" answer from a conditional write, which is the pattern every store here
    // decides by. A mutant returning 1 or -1 says an authorization change happened when none did.
    //
    // Asserted alongside the event count, because `setAbility()`'s return is tokens rewritten and
    // a fleet with no live session rewrites none -- so the number alone cannot tell "nothing
    // changed" from "changed, and nobody was holding a token". The absent event can.
    $installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $before = FleetEvent::query()->count();

    expect($this->service(Installations::class)
        ->setAbility($installation, Ability::TasksCreate, true, keyValue($this->admin->getKey())))
        ->toBe(0)
        ->and(FleetEvent::query()->count())->toBe($before);

    // And the same for removing one that was never held.
    expect($this->service(Installations::class)
        ->setAbility($installation, Ability::LocksAcquire, false, keyValue($this->admin->getKey())))
        ->toBe(0)
        ->and(FleetEvent::query()->count())->toBe($before);
});

it('stores the remaining abilities as a list after removing one from the middle', function (): void {
    // **`array_values` around the filter is load-bearing, not tidiness.** `array_filter` preserves
    // keys, so removing the first of three leaves `[1 => ..., 2 => ...]` -- which the `array` cast
    // writes to the JSON column as an OBJECT rather than an array. Every reader of
    // `granted_abilities` then gets a shape it was not written for, and
    // `Installation::abilities()`'s own `array_values` would paper over it one layer too late.
    $installation = $this->approveInstallation($this->developer, [
        Ability::TasksCreate->value,
        Ability::TasksClaim->value,
        Ability::EventsPost->value,
    ]);

    // A live session, so the return value is the count of tokens rewritten rather than zero.
    // **`setAbility()` returns tokens rewritten, not whether anything changed**, so `0` means
    // both "nothing to do" and "done, and no session held a token" -- worth knowing before a
    // caller reads it as a success flag.
    $this->startAgentSession($installation);

    expect($this->service(Installations::class)
        ->setAbility($installation, Ability::TasksCreate, false, keyValue($this->admin->getKey())))
        ->toBe(1);

    // The RAW column, because the cast on the way out would hide a keyed write.
    $stored = DB::table('robot_council_installations')->where('id', $installation->id)->value('granted_abilities');

    expect($stored)->toBeString()
        ->and(json_decode(\is_string($stored) ? $stored : '', true))
        ->toBe([Ability::TasksClaim->value, Ability::EventsPost->value]);
});

it('says in the event body which ability moved and which way', function (): void {
    // The body is what a human reads in the feed, and the ternary plus two concatenations were
    // changeable in six ways with nothing failing -- a feed saying "lost tasks:create" where an
    // ability was granted is worse than saying nothing.
    $installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $this->service(Installations::class)
        ->setAbility($installation, Ability::LocksAcquire, true, keyValue($this->admin->getKey()));

    $granted = FleetEvent::query()->where('type', FleetEventType::InstallationAbilityGranted)->sole();

    expect($granted->body)->toBe('claude-code on workbench was granted locks:acquire.')
        ->and($granted->meta['ability'] ?? null)->toBe(Ability::LocksAcquire->value)
        ->and($granted->meta['installation_id'] ?? null)->toBe($installation->id);

    $this->service(Installations::class)
        ->setAbility($installation, Ability::LocksAcquire, false, keyValue($this->admin->getKey()));

    $revoked = FleetEvent::query()->where('type', FleetEventType::InstallationAbilityRevoked)->sole();

    expect($revoked->body)->toBe('claude-code on workbench lost locks:acquire.')
        ->and($revoked->meta['ability'] ?? null)->toBe(Ability::LocksAcquire->value)
        // Both keys, because the spread merges the caller's `meta` into a literal one and either
        // side could be dropped without the other noticing.
        ->and($revoked->meta['installation_id'] ?? null)->toBe($installation->id);
});

it('carries the installation id on an event whose caller passed no meta of its own', function (): void {
    // `revoke()` passes an empty `meta`, so the literal `installation_id` is the only entry and
    // the spread contributes nothing -- the case where dropping the literal leaves `meta` null
    // rather than merely shorter.
    $installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $this->service(Installations::class)->revoke($installation, keyValue($this->admin->getKey()));

    $event = FleetEvent::query()->where('type', FleetEventType::InstallationRevoked)->sole();

    expect($event->body)->toBe('claude-code on workbench was revoked.')
        ->and($event->meta['installation_id'] ?? null)->toBe($installation->id);
});
