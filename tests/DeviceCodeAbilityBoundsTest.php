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
use RobotCouncil\Support\Installations;

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

/**
 * The `granted_abilities` column as the database holds it, decoded but unfiltered.
 *
 * Read off the row for the same reason as `storedRequested()`: the model's `array` cast and the
 * accessors over it are what this is asking about, so going through either would hide the answer.
 *
 * @param  DeviceCode  $code  The row to read.
 * @return mixed Whatever the column decoded to, including null for a value that is not JSON.
 */
function storedGranted(DeviceCode $code): mixed
{
    $raw = DB::table('robot_council_device_codes')->where('id', $code->getKey())->value('granted_abilities');

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

it('records only requestable strings when approve is called directly', function (): void {
    // **`coordinator:direct` is the one that matters.** The device-code flow can never request it,
    // and `Models\Installation::abilities()` filters against the *grantable* list, which includes
    // it -- so a value recorded here survives every later check and the installation carries an
    // ability no developer approved through the page.
    $issued = app(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'a-third-verifier'),
        null
    );

    $decided = app(DeviceCodes::class)->approve(
        $issued->record,
        keyValue($this->developer->getKey()),
        [Ability::CoordinatorDirect->value, null, Ability::TasksCreate->value, Ability::TasksCreate->value]
    );

    expect($decided)->toBeTrue()
        ->and(storedGranted($issued->record))->toBe([Ability::TasksCreate->value]);
});

it('does not let a directly approved coordinator ability reach the installation', function (): void {
    // One hop further, because the device-code row is not where the value does damage. This is the
    // property the narrowing exists for, asserted end to end rather than inferred from the column.
    $issued = app(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'a-fourth-verifier'),
        null
    );

    app(DeviceCodes::class)->approve(
        $issued->record,
        keyValue($this->developer->getKey()),
        [Ability::CoordinatorDirect->value, Ability::TasksCreate->value]
    );

    $credential = app(Installations::class)->createFrom($issued->record->refresh());

    // One assertion, not two: `toBe()` already excludes everything absent from the list, so a
    // `not->toContain()` beside it cannot fail when this passes.
    expect($credential->owner->abilities())->toBe([Ability::TasksCreate->value]);
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

it('narrows the abilities it copies forward, not just the ones it was approved with', function (): void {
    // **`createFrom()` reads the model's in-memory attribute, not a fresh row.** So narrowing in
    // `approve()` is not enough on its own: a caller can approve normally, set the attribute on the
    // model it is holding, and hand that to `createFrom()`, which copies it straight into the
    // installation. The guard at the top of that method enumerated the columns it re-checks and the
    // abilities were not among them, although the create writes them.
    //
    // `coordinator:direct` is the value that matters, because `Models\Installation::abilities()`
    // filters against the GRANTABLE list, which holds it -- so it survives every later check.
    $issued = app(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'an-eighth-verifier'),
        null
    );

    app(DeviceCodes::class)->approve($issued->record, keyValue($this->developer->getKey()), [Ability::TasksCreate->value]);

    $code = $issued->record->refresh();

    // Set on the model after the approval wrote the narrowed value to the row, which is what makes
    // this reach a path `approve()`'s own bound cannot.
    $code->granted_abilities = [Ability::CoordinatorDirect->value, Ability::TasksCreate->value];

    $credential = app(Installations::class)->createFrom($code);

    expect($credential->owner->abilities())->toBe([Ability::TasksCreate->value]);

    // And the ROW, because the assertion above reads an accessor that filters. If `createFrom()`
    // had written the wide value, the accessor would still have returned it -- `coordinator:direct`
    // is grantable -- so this is the one that would have caught a stored value.
    $stored = DB::table('robot_council_installations')
        ->where('id', $credential->owner->getKey())
        ->value('granted_abilities');

    expect(json_decode(\is_string($stored) ? $stored : '', true))->toBe([Ability::TasksCreate->value]);
});

it('copies forward a device code whose abilities column is not a list at all', function (): void {
    // **The container guard on `Ability::requestableFrom()`, which no mutation run can reach.**
    // The plugin builds no mutants for an enum -- measured, `0 Mutations for 0 Files created` --
    // so the only thing that can hold this guard in place is a test that supplies the shape.
    //
    // `granted_abilities` is `json` and NOT NULL, which stops SQL `NULL` and not the JSON literal
    // `null` (#167). `createFrom()` reads the attribute, so an `array` parameter on the narrowing
    // would raise a `TypeError` here and take enrollment down for that developer.
    $issued = app(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'a-ninth-verifier'),
        null
    );

    app(DeviceCodes::class)->approve($issued->record, keyValue($this->developer->getKey()), [Ability::TasksCreate->value]);

    DB::table('robot_council_device_codes')
        ->where('id', $issued->record->getKey())
        ->update(['granted_abilities' => 'null']);

    $credential = app(Installations::class)->createFrom($issued->record->refresh());

    expect($credential->owner->abilities())->toBeEmpty();
});
