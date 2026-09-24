<?php

declare(strict_types=1);

/**
 * Every store refuses what its columns cannot hold, before an engine decides what that means.
 *
 * **These write through the stores, never through an endpoint.** Each of these values is already
 * refused by a validation rule somewhere, so an endpoint test would exercise the validator rather
 * than the guarantee -- and the guarantee is the point: every store here is a public method on a
 * `final` class a host can resolve from the container and call with whatever it likes.
 *
 * The column cannot be the bound, for two reasons recorded in `CLAUDE.md`. It means something
 * different on each engine: SQLite stores an over-long `varchar` whole, Postgres refuses it, and
 * MySQL refuses or truncates depending on strict mode. And its width is not even ours, because a
 * `string()` with no length takes `Schema::$defaultStringLength`, which the host owns.
 *
 * @command  vendor/bin/pest --compact tests/StoreBoundsTest.php
 */

use RobotCouncil\Access\Ability;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\Installations;
use RobotCouncil\Support\MachineIdentity;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242]);
});

it('refuses a harness or machine label the device-code table cannot hold', function (): void {
    // `CLAUDE.md` records the measurement this exists for: a 34-character write into this table's
    // `varchar(32)` harness passed every local SQLite run and failed only CI's `postgres` job.
    $codes = $this->service(DeviceCodes::class);

    $issue = fn (string $harness, string $label) => $codes->issue(
        [Ability::TasksCreate->value],
        $harness,
        $label,
        hash('sha256', 'a-verifier-only-the-helper-holds'),
        null,
    );

    $refused = [
        'a harness one character past its column' => [str_repeat('a', MachineIdentity::MAX_HARNESS + 1), 'workbench-01', 'A harness is 1 to 32'],
        'a label one character past its column' => ['claude-code', str_repeat('b', MachineIdentity::MAX_LABEL + 1), 'A machine label is 1 to 64'],

        // Charset too, because both reach other developers' agents: `AgentSessions::start()` writes
        // them into the change feed, which every session in the fleet reads
        'a harness carrying markup' => ['<script>', 'workbench-01', 'A harness is 1 to 32'],
        'a label carrying a newline' => ['claude-code', "workbench\n", 'A machine label is 1 to 64'],
        'an upper-case harness, which would read as a second harness' => ['Claude-Code', 'workbench-01', 'A harness is 1 to 32'],
        'an empty harness' => ['', 'workbench-01', 'A harness is 1 to 32'],
        'an empty label' => ['claude-code', '', 'A machine label is 1 to 64'],
    ];

    // Each case names which field and which kind of bound it expects, so a guard that applied the
    // harness pattern to the label -- or measured the wrong one -- would fail rather than still
    // throwing for all five
    foreach ($refused as $what => [$harness, $label, $expected]) {
        expect(fn (): object => $issue($harness, $label))
            ->toThrow(InvalidArgumentException::class, $expected, $what.' should be refused for that reason');
    }

    // Nothing was written. `issue()` guards before its insert, so this pins the ordering rather than
    // the refusal, which the expectations above already carry.
    expect(DeviceCode::query()->count())->toBe(0);

    // The control, and it has to be a label the HARNESS charset would REFUSE. Every machine label
    // elsewhere in this suite happens to be valid under both patterns, so a guard that checked the
    // label against the harness's stricter set would ship green and start refusing ordinary
    // hostnames at the unauthenticated device-code endpoint.
    $issue('claude-code', 'Workbench_01.local');

    expect(DeviceCode::query()->sole()->machine_label)->toBe('Workbench_01.local');

    // And at exactly both limits, so the refusals above are the bounds firing rather than the store
    // refusing everything
    $issue(str_repeat('a', MachineIdentity::MAX_HARNESS), str_repeat('b', MachineIdentity::MAX_LABEL));

    $stored = DeviceCode::query()->orderByDesc('id')->firstOrFail();

    expect($stored->harness)->toHaveLength(MachineIdentity::MAX_HARNESS)
        ->and($stored->machine_label)->toHaveLength(MachineIdentity::MAX_LABEL);
});

it('refuses a requested IP longer than its column', function (): void {
    $codes = $this->service(DeviceCodes::class);

    expect(fn (): object => $codes->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench-01',
        hash('sha256', 'a-verifier'),
        str_repeat('9', DeviceCodes::MAX_REQUESTED_IP + 1),
    ))->toThrow(InvalidArgumentException::class);

    // The control: the longest real address this column exists for -- IPv6 with an embedded IPv4 --
    // is accepted, so the bound is not narrower than the thing it has to store
    $longest = '0000:0000:0000:0000:0000:ffff:255.255.255.255';

    expect($longest)->toHaveLength(DeviceCodes::MAX_REQUESTED_IP);

    $codes->issue([Ability::TasksCreate->value], 'claude-code', 'workbench-01', hash('sha256', 'v'), $longest);

    expect(DeviceCode::query()->sole()->requested_ip)->toBe($longest);
});

it('refuses an event body longer than the package stores', function (): void {
    // `body` is a `text` column: 65,535 bytes on MySQL, unbounded on Postgres and SQLite. The bound
    // is policy rather than capacity, and every in-package caller is bounded by construction -- so
    // this is the direct-call path the guard exists for.
    $events = $this->service(FleetEvents::class);

    expect(fn (): FleetEvent => $events->record(
        FleetEventType::Directive,
        null,
        str_repeat('x', FleetEvent::MAX_BODY + 1),
    ))->toThrow(InvalidArgumentException::class)
        ->and(FleetEvent::query()->count())->toBe(0);

    // The control: exactly at the limit is recorded
    $events->record(FleetEventType::Directive, null, str_repeat('x', FleetEvent::MAX_BODY));

    expect(FleetEvent::query()->sole()->body)->toHaveLength(FleetEvent::MAX_BODY);
});

it('refuses a host user key longer than the columns that hold it', function (): void {
    // Truncating would be worse than refusing: two developers whose keys share a 64-character
    // prefix would collapse into one, which is an access-control failure rather than a storage one.
    expect(fn (): string => HostKey::from(str_repeat('k', HostKey::MAX + 1)))
        ->toThrow(RuntimeException::class);

    expect(HostKey::tryFrom(str_repeat('k', HostKey::MAX + 1)))->toBeNull();

    // The control: at the limit it is stored, and a UUID -- the shape this string column exists for
    // -- is comfortably inside it
    expect(HostKey::from(str_repeat('k', HostKey::MAX)))->toHaveLength(HostKey::MAX)
        ->and(HostKey::from('01916d3e-9f7a-7c3d-8f2b-5a1c9e4d7b60'))->toHaveLength(36);
});

it('refuses to copy any unstorable value forward into an installation', function (): void {
    // `createFrom()` writes FIVE string columns, not the two that are obviously text: the decider's
    // key lands in both `user_id` and `approved_by`, and the IP in a `varchar(45)`. A guard covering
    // the visible two would leave the others exactly as they were.
    $installations = $this->service(Installations::class);

    $tooLongKey = new DeviceCode(['harness' => 'claude-code', 'machine_label' => 'workbench-01']);
    $tooLongKey->decided_by = str_repeat('k', 200);

    expect(fn (): object => $installations->createFrom($tooLongKey))
        ->toThrow(RuntimeException::class, 'up to 64 characters');

    $tooLongIp = new DeviceCode(['harness' => 'claude-code', 'machine_label' => 'workbench-01']);
    $tooLongIp->decided_by = '4242';
    $tooLongIp->requested_ip = str_repeat('9', DeviceCodes::MAX_REQUESTED_IP + 1);

    expect(fn (): object => $installations->createFrom($tooLongIp))
        ->toThrow(InvalidArgumentException::class, 'A requested IP is limited to 45')
        ->and(Installation::query()->count())->toBe(0);

    // The control: with every value inside its column the same call creates an installation, so the
    // refusals above are the bounds firing rather than `createFrom` refusing everything
    $fine = new DeviceCode(['harness' => 'claude-code', 'machine_label' => 'workbench-01']);
    $fine->decided_by = '4242';
    $fine->requested_ip = '203.0.113.7';

    $installations->createFrom($fine);

    $stored = Installation::query()->sole();

    expect($stored->user_id)->toBe('4242')
        ->and($stored->approved_by)->toBe('4242')
        ->and($stored->requested_ip)->toBe('203.0.113.7');
});

it('refuses to copy an unstorable harness forward into an installation', function (): void {
    // `createFrom()` takes a model, so a caller can hand it one it built itself rather than one
    // this package wrote -- which makes the copy forward its own entry point into those columns.
    $code = new DeviceCode([
        'harness' => str_repeat('a', MachineIdentity::MAX_HARNESS + 1),
        'machine_label' => 'workbench-01',
    ]);

    $code->decided_by = '4242';

    expect(fn (): object => $this->service(Installations::class)->createFrom($code))
        ->toThrow(InvalidArgumentException::class)
        ->and(Installation::query()->count())->toBe(0);
});
