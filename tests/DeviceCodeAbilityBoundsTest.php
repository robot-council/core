<?php

declare(strict_types=1);

/**
 * What the device-code store will write into an abilities column.
 *
 * **Every test here calls the store, never the endpoint.** `Http\Controllers\DeviceCodeController`
 * validates `requested_abilities.*` against the requestable list, so a test that went through it
 * would pass with the store unbounded and would be testing the validator. `Support\DeviceCodes` is
 * a public class a host resolves and calls, and `CLAUDE.md` records the rule this is an instance
 * of: a bound a validation rule states is not a bound the package holds (#170).
 *
 * **And every assertion reads the ROW**, not the model the store returned, which reports whatever
 * PHP handed in rather than what the column took.
 *
 * @command  vendor/bin/pest --compact tests/DeviceCodeAbilityBoundsTest.php
 */

use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Support\DeviceCodes;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

/**
 * The `requested_abilities` column as the database holds it, decoded but unfiltered.
 *
 * Read straight off the row rather than through `Models\DeviceCode::requestedAbilities()`, whose
 * whole job is to hide exactly what this is asking about.
 *
 * @param  DeviceCode  $code  The row to read.
 * @return mixed Whatever the column decoded to, including null for a value that is not JSON.
 */
function storedRequested(DeviceCode $code): mixed
{
    $raw = DB::table('robot_council_device_codes')->where('id', $code->getKey())->value('requested_abilities');

    return json_decode(\is_string($raw) ? $raw : '', true);
}

it('writes only requestable strings when issue is called directly', function (): void {
    // The shapes that reach a `json` column from a host: a non-string, an ability the flow may not
    // request, and a duplicate. The store spread its argument straight into the insert.
    $issued = app(DeviceCodes::class)->issue(
        [
            Ability::TasksCreate->value,
            null,
            ['nested'],
            Ability::CoordinatorDirect->value,
            'tasks:kreate',

            // **`true` is the one that needs the STRICT flag on `in_array()`, and nothing else
            // here does.** PHP compares a bool against a string by casting the string to bool, so
            // a loose comparison finds `true` equal to any non-empty ability name and admits a
            // boolean -- which `json_encode` then writes into the column as `true`. Every other
            // value here is refused either way, so without this element the flag is unkillable.
            true,
            Ability::TasksCreate->value,
            Ability::EventsPost->value,
        ],
        'claude-code',
        'workbench',
        hash('sha256', 'a-verifier-the-test-holds'),
        '203.0.113.10'
    );

    // A list, in the order given, deduplicated: the drops are in the middle, so a result that
    // kept its keys would be `{"0":...,"6":...}` and this comparison sees it.
    expect(storedRequested($issued->record))
        ->toBe([Ability::TasksCreate->value, Ability::EventsPost->value]);
});

it('leaves a well-formed request exactly as it was given', function (): void {
    // The other side of the bound: narrowing must not reorder or drop what was always valid.
    // Without it a passing test above could mean the store writes a constant.
    $issued = app(DeviceCodes::class)->issue(
        [Ability::EventsPost->value, Ability::TasksClaim->value, Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'another-verifier-the-test-holds'),
        null
    );

    expect(storedRequested($issued->record))
        ->toBe([Ability::EventsPost->value, Ability::TasksClaim->value, Ability::TasksCreate->value]);
});

it('still refuses an over-length harness, so the new bound did not displace the old ones', function (): void {
    // `issue()` throws for three values and now drops for a fourth. A narrowing inserted before
    // them could have shadowed the throws, and nothing else in this file would notice.
    expect(fn (): mixed => app(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        str_repeat('h', 33),
        'workbench',
        hash('sha256', 'a-fifth-verifier'),
        null
    ))->toThrow(InvalidArgumentException::class);
});

it('says so on the page when nothing asked for survives narrowing', function (): void {
    // **A non-empty request can narrow to nothing**, and only through the store: the endpoint
    // carries `'requested_abilities' => ['required', 'array', 'min:1']` with each entry validated
    // against the requestable list, so it cannot produce this shape. A host or a CLI still sending
    // an ability name this package renamed can.
    //
    // The row is what it is; what must not happen is the page rendering an empty list under
    // "Asked for" and leaving the developer to guess. Approving this grants nothing.
    $issued = app(DeviceCodes::class)->issue(
        ['fleet:read', 'tasks:kreate'],
        'claude-code',
        'workbench',
        hash('sha256', 'a-sixth-verifier'),
        null
    );

    expect(storedRequested($issued->record))->toBe([]);

    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $issued->record->user_code]))
        ->assertOk()
        ->assertSee('Nothing this server recognizes');

    // The control: a code whose abilities did survive must NOT carry that sentence, or the
    // assertion above would pass on every page this suite renders.
    $ordinary = app(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'a-seventh-verifier'),
        null
    );

    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $ordinary->record->user_code]))
        ->assertOk()
        ->assertSee(Ability::TasksCreate->value)
        ->assertDontSee('Nothing this server recognizes');
});
