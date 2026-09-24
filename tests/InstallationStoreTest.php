<?php

declare(strict_types=1);

/**
 * The parts of `Support\Installations` that `--mutate` could change without a test noticing.
 *
 * Surfaced by #146 and filed as #148: seventeen of sixty-one mutants survived, all
 * in `revoke()` and `setAbility()`. A surviving mutant is a test that stays green with the behavior
 * changed, and the criterion in this repository is **no unexplained survivors** rather than a
 * score -- a percentage merges an unexamined survivor with a provably equivalent one.
 *
 * **`setAbility()` and the four tests that covered it went with `robot-council/core#231`.** What
 * they pinned -- that an `array_values` hoist kept the stored list a JSON array rather than an
 * object, and that a no-op write recorded no event -- was about a column no authorization path
 * reads and a control no surface offers. Deleted rather than repointed: there is no surviving
 * method with that shape to repoint them at.
 *
 * **Where a store's guarantee is about what was written, the assertion reads the ROW or the
 * recorded event** rather than the instance a method returned, for the reason `CLAUDE.md` records:
 * an instance reports whatever PHP put in it. Two tests here deliberately read the instance
 * instead, because what they pin is what the caller is left holding -- `revoke()` refreshing the
 * object the dashboard re-renders from, and the token count it returns.
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

    // **The message, not just the class.** `createFrom()` reaches `MachineIdentity::ensure()`
    // before this bound, and both of its branches throw the same class -- so a change to
    // `approveInstallation()`'s default label would make a bare class assertion pass for the
    // wrong reason.
    expect(fn () => $this->service(Installations::class)->createFrom($reloaded))
        ->toThrow(InvalidArgumentException::class, sprintf(
            'A requested IP is limited to %d characters, and this one is %d.',
            DeviceCodes::MAX_REQUESTED_IP,
            DeviceCodes::MAX_REQUESTED_IP + 1
        ));

    // And it refused before writing anything: no installation landed. Asserting the device code's
    // column is still null would prove nothing -- it was issued null and only filled in memory.
    expect(Installation::query()->where('machine_label', 'other-machine')->exists())->toBeFalse();
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
