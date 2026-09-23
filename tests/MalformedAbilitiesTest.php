<?php

declare(strict_types=1);

/**
 * What one malformed `granted_abilities` row costs.
 *
 * **The column is `json` and the row decides what comes back.** Every row here is planted
 * directly, because the endpoints narrow before they write and a test that went through one would
 * be testing the validator rather than the guarantee.
 *
 * **The stores themselves do not narrow, though, and an earlier draft of this file claimed they
 * did.** `Support\DeviceCodes::issue()` writes `requested_abilities` exactly as handed over --
 * bounding `harness`, `machine_label`, and `requested_ip` in the same method and not these -- and
 * `approve()` `json_encode`s its `$granted` argument verbatim. Both are public methods on `final`
 * classes a host can resolve and call, so the package can reach a malformed row through its own
 * API without anybody editing a row by hand. #170 is that gap; this file covers the reads, which
 * is what stops any of it from raising.
 *
 * **The blast radius is the fleet, not the row's owner.** Before #159, `abilities()` was called on
 * the requesting principal's own installation. `Support\FleetAbilities::anyInstallationHolds()`
 * now calls it on every usable installation to answer `fleet_can_direct`, so a value the accessor
 * could not take answered `GET {prefix}/api/agent/session` with a 500 for every agent in the
 * fleet -- the route a bridge calls after every start and every renewal (#167).
 *
 * @command  vendor/bin/pest --compact tests/MalformedAbilitiesTest.php
 */

use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\FleetAbilities;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);
});

/**
 * Write a raw `granted_abilities` payload onto a row, past every narrowing in the package.
 *
 * The query builder rather than the model, because a save would put the `array` cast back in the
 * way and re-encode whatever it was handed -- which is the narrowing being stepped around.
 */
function plantAbilities(Installation $installation, string $json): void
{
    DB::table('robot_council_installations')
        ->where('id', $installation->getKey())
        ->update(['granted_abilities' => $json]);
}

it('drops a stored element that is not a string instead of raising', function (): void {
    // A declared `string` parameter on the filter raised a `TypeError` for each of these rather
    // than dropping it. `1` is the third shape and the one that did not raise: PHP coerced it to
    // `"1"`, which `in_array`'s strict comparison then refused -- so it is asserted here to pin
    // that the guard did not change its fate.
    $installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    plantAbilities($installation, (string) json_encode([
        Ability::TasksCreate->value,
        null,
        ['coordinator:direct'],
        1,
        ['nested' => Ability::CoordinatorDirect->value],
    ]));

    expect($installation->fresh()?->abilities())->toBe([Ability::TasksCreate->value]);
});

it('drops a stored value that is not a list at all', function (): void {
    // The column is NOT NULL, which stops SQL `NULL` and not the JSON literal `null`: both
    // Postgres and MySQL accept `null` and a bare scalar as valid `json`, and SQLite stores the
    // text either way. Each of these decodes to something `array_filter()` cannot take, so a guard
    // over the elements alone would still have raised.
    $installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $wellFormed = (string) json_encode([Ability::TasksCreate->value]);

    foreach (['null', '5', '"coordinator:direct"', 'true'] as $payload) {
        // **Re-planted before each shape, and that is the whole point of the loop's structure.**
        // After the first iteration the row already reads back `[]`, so an iteration whose write
        // silently did not land would assert against the previous one's residue and pass. Putting
        // a readable value back first means each payload has to displace it to produce `[]`, and
        // a single end-of-loop control could not have shown that -- it proves the mechanism works,
        // not that every shape reached the column.
        plantAbilities($installation, $wellFormed);
        expect($installation->fresh()?->abilities())->toBe([Ability::TasksCreate->value]);

        plantAbilities($installation, $payload);
        expect($installation->fresh()?->abilities())->toBe([]);
    }
});

it('accepts a top-level object, which is the shape it does not drop', function (): void {
    // **Pinned because it is the one malformed shape that grants rather than loses**, and a reader
    // who assumes "malformed means dropped" would otherwise read the guard as covering it.
    // `json_decode($v, true)` turns a JSON object into a PHP array, so `is_array()` is true and
    // `array_values()` throws the keys away.
    //
    // Left as it is rather than guarded with `array_is_list()`: every value still has to be in the
    // fixed list, so the row claims nothing it could not claim well-formed -- and the two are not
    // separable anyway, because `{"0": "tasks:create"}` decodes to a list.
    $installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    plantAbilities($installation, (string) json_encode([
        'first' => Ability::TasksCreate->value,
        'second' => Ability::LocksAcquire->value,
        'third' => null,
        'fourth' => 'coordinator:direkt',
    ]));

    expect($installation->fresh()?->abilities())
        ->toBe([Ability::TasksCreate->value, Ability::LocksAcquire->value]);
});

it('still drops a string the fixed list does not hold', function (): void {
    // The guard admits values the filter used to refuse by raising, so the other direction needs
    // pinning: it must not have widened what a stored row can claim. `sessions:start` is the
    // sharp case -- a real member of the enum that `Ability::grantable()` excludes, which is the
    // shape a retired ability would take.
    $installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    plantAbilities($installation, (string) json_encode([
        Ability::SessionsStart->value,
        'coordinator:direkt',
        'COORDINATOR:DIRECT',
        Ability::TasksCreate->value,
    ]));

    expect($installation->fresh()?->abilities())->toBe([Ability::TasksCreate->value]);
});

it('keeps the returned abilities a list after dropping from the middle', function (): void {
    // `array_values()` is what makes this a list. Without it the surviving keys are `0` and `2`,
    // and `json_encode` emits an object -- so `abilities` would reach a bridge as
    // `{"0":"...","2":"..."}` where it expects an array, on the response this same fix is for.
    $installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    plantAbilities($installation, (string) json_encode([
        Ability::TasksCreate->value,
        null,
        Ability::LocksAcquire->value,
    ]));

    $abilities = $installation->fresh()?->abilities() ?? [];

    expect($abilities)->toBe([Ability::TasksCreate->value, Ability::LocksAcquire->value])
        ->and(json_encode($abilities))->toBe(json_encode([Ability::TasksCreate->value, Ability::LocksAcquire->value]));
});

it('answers the session endpoint for a fleet that contains a malformed row', function (): void {
    // **The blast radius, asserted against the endpoint rather than the accessor.** The row
    // planted here belongs to a different developer and a different installation from the one
    // asking, and the request still has to be answered -- which is the whole difference #159 made.
    $receiver = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);

    $coordinator = $this->approveInstallation(
        $this->enrollDeveloper(77, 'coordinator'),
        [Ability::CoordinatorDirect->value],
        'coordinator-machine'
    );

    plantAbilities($coordinator, (string) json_encode([null, ['coordinator:direct']]));

    [, $token] = $this->startAgentSession($receiver);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson([
            'abilities' => [Ability::TasksCreate->value],
            // The malformed row held the only `coordinator:direct` on the fleet, and dropping its
            // contents is what the answer reflects. A row nobody can read grants nothing.
            'fleet_can_direct' => false,
        ]);

    // The control: the same installation, the same request, one well-formed row. Without it a
    // broken fixture -- an installation the walk never reached -- would answer `false` too, and
    // the assertion above would pass for the wrong reason.
    plantAbilities($coordinator, (string) json_encode([Ability::CoordinatorDirect->value]));

    [, $second] = $this->startAgentSession($receiver);

    $this->machine($second)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['fleet_can_direct' => true]);
});

it('renders the enrollment page for a malformed requested_abilities row', function (): void {
    // **The sibling column, and the one the package's own store can leave malformed.**
    // `Support\DeviceCodes::issue()` writes `requested_abilities` exactly as handed over, so
    // reaching this needs no hand-edited row -- but the row is planted here anyway, because
    // `Http\Controllers\DeviceCodeController` validates and a test going through it would be
    // testing the validator.
    //
    // The view iterates the value. Before `Models\DeviceCode::requestedAbilities()` existed, the
    // JSON literal `null` made `@foreach` raise rather than iterate nothing, and the developer's
    // verification page answered 500 with no way to approve or deny the waiting code.
    $enrollment = requestDeviceCode($this);

    DB::table('robot_council_device_codes')
        ->where('id', $enrollment['record']->getKey())
        // **A good value after the bad ones, which is what makes this a list test as well.** With
        // the drops in the middle the surviving keys are 0 and 3, so without `array_values()` the
        // accessor returns `[0 => ..., 3 => ...]` -- still iterable by the view, and `json_encode`
        // would write it as an object. Measured as a surviving `UnwrapArrayValues` mutant until
        // this payload replaced one whose only survivor sat at key 0.
        ->update(['requested_abilities' => (string) json_encode([
            Ability::TasksCreate->value,
            null,
            ['nested'],
            Ability::EventsPost->value,
            7,
        ])]);

    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]))
        ->assertOk()
        // What survived is shown; nothing else is, and the page does not print `Array` or a
        // literal `7` where an ability belongs.
        ->assertSee(Ability::TasksCreate->value)
        ->assertSee(Ability::EventsPost->value)
        ->assertDontSee('nested');

    expect($enrollment['record']->refresh()->requestedAbilities())
        ->toBe([Ability::TasksCreate->value, Ability::EventsPost->value]);

    // **And the container, which fails differently.** A malformed element made `e()` raise on the
    // value; the JSON literal `null` makes `@foreach` itself raise, because iterating a non-array
    // is an error rather than a zero-iteration loop. One guard does not imply the other, so both
    // are planted.
    DB::table('robot_council_device_codes')
        ->where('id', $enrollment['record']->getKey())
        ->update(['requested_abilities' => 'null']);

    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]))
        ->assertOk()
        // The page still renders, and the section it renders is empty rather than stale.
        ->assertDontSee(Ability::TasksCreate->value);

    expect($enrollment['record']->refresh()->requestedAbilities())->toBeEmpty();
});

it('approves an enrollment whose requested_abilities row is malformed', function (): void {
    // The other reader of the same column. `Access\Ability::granted()` declared `string $ability`
    // on its filter, so the approval POST raised the same `TypeError` and the enrollment could
    // never be decided -- it would sit until it expired, with a 500 on every attempt.
    $enrollment = requestDeviceCode($this, [Ability::TasksCreate->value]);

    DB::table('robot_council_device_codes')
        ->where('id', $enrollment['record']->getKey())
        ->update(['requested_abilities' => (string) json_encode([null, Ability::TasksCreate->value, ['x']])]);

    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.approve'), [
            'user_code' => $enrollment['record']->user_code,
            'confirmed' => '1',
        ])
        ->assertRedirect(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]));

    $decided = $enrollment['record']->refresh();

    expect($decided->isDecided())->toBeTrue()
        ->and($decided->granted_abilities)->toBe([Ability::TasksCreate->value]);
});

it('narrows what a host hands Ability::granted directly', function (): void {
    // A public static on a `final` class. The in-package caller now passes an already-narrowed
    // list, so nothing in this repository can reach it with another shape -- which is exactly why
    // a test that went through the controller would prove nothing about the method.
    expect(Ability::granted([Ability::TasksCreate->value, null, ['x'], 5, Ability::TasksCreate->value]))
        ->toBe([Ability::TasksCreate->value])
        ->and(Ability::granted([]))->toBeEmpty()
        // Still dropped, so the guard did not widen what an enrollment can ask for:
        // `coordinator:direct` is grantable but never requestable.
        ->and(Ability::granted([Ability::CoordinatorDirect->value]))->toBeEmpty();
});

it('answers the fleet-level question directly for a malformed row', function (): void {
    // `Support\FleetAbilities` is a public method on a `final` class a host can resolve and call,
    // so a guarantee held only by the controller would protect the route and nothing else.
    $installation = $this->approveInstallation($this->developer, [Ability::CoordinatorDirect->value]);

    expect(app(FleetAbilities::class)->anyInstallationHolds(Ability::CoordinatorDirect))->toBeTrue();

    plantAbilities($installation, 'null');

    expect(app(FleetAbilities::class)->anyInstallationHolds(Ability::CoordinatorDirect))->toBeFalse();
});
