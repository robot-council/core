<?php

declare(strict_types=1);

/**
 * The behavior the device-code classes had but nothing held in place.
 *
 * **Every test here exists because a mutant survived**, which is to say the suite stayed green with
 * the behavior changed (#172). They are grouped by what they pin rather than by class, because the
 * reason each one matters is not the method it lives in.
 *
 * Three survivors are NOT killed here and are annotated where they live instead, with the reason on
 * the line above: two are guards against a shape this package cannot produce, and one is required
 * by `composer analyse` rather than by any input.
 *
 * @command  vendor/bin/pest --compact tests/DeviceCodeMutantsTest.php
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Support\Contracts\DrawsUserCodes;
use RobotCouncil\Support\DeviceCodes;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // No test here makes a request or reads an access list; the developer exists only to supply a
    // host user key for `approve()` and `deny()`.
    $this->developer = $this->enrollDeveloper(4242);
});

it('hydrates every decision timestamp as a date, on the application clock', function (): void {
    // **Three `RemoveArrayItem` mutants survived on `casts()`, and they are equivalent INSIDE this
    // package.** An earlier version of this comment said the casts decide something here. They do
    // not, and the model's own docblock says so: every read of these three columns is a null check
    // -- `isDecided()`, the `denied_at` and `consumed_at` guards in `DeviceCodes::consume()`, and
    // the enrollment page -- so a column hydrating as a string answers `!== null` identically.
    //
    // **What the cast decides is what a HOST gets**, and that is why it is pinned rather than
    // annotated away. `@property Carbon|null $approved_at` is a promise to anyone who resolves the
    // model and reads the column; without the cast they get a string and `->format()` fatals.
    //
    // **The timezone assertions are the half that holds the #149 decision**, which the first
    // version of this test did not. These three stay on the application clock rather than
    // `PresenceTimestamp`, and both produce a `Carbon` -- so `toBeInstanceOf` alone passes with
    // either. Only a non-UTC application timezone separates them: the `datetime` cast hydrates in
    // the application's zone, `PresenceTimestamp` in the presence clock's fixed one.
    config()->set('app.timezone', 'America/Chicago');
    date_default_timezone_set('America/Chicago');
    $issued = app(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'a-verifier'),
        null
    );

    DB::table('robot_council_device_codes')
        ->where('id', $issued->record->id)
        ->update([
            'approved_at' => Carbon::now()->subMinutes(3),
            'denied_at' => Carbon::now()->subMinutes(2),
            'consumed_at' => Carbon::now()->subMinute(),
        ]);

    $code = DeviceCode::query()->findOrFail($issued->record->id);

    expect($code->approved_at)->toBeInstanceOf(Carbon::class)
        ->and($code->denied_at)->toBeInstanceOf(Carbon::class)
        ->and($code->consumed_at)->toBeInstanceOf(Carbon::class)
        // The application's zone, not the presence clock's. This is what a `PresenceTimestamp`
        // substitution fails, and what `toBeInstanceOf` on its own cannot see.
        ->and($code->approved_at?->timezone->getName())->toBe('America/Chicago')
        ->and($code->denied_at?->timezone->getName())->toBe('America/Chicago')
        ->and($code->consumed_at?->timezone->getName())->toBe('America/Chicago');
});

it('reports no age at all for a code stamped in the future', function (): void {
    // **`max(0, ...)` had two surviving integer mutants**, and only a future `created_at` separates
    // them: `max(-1, ...)` and `max(1, ...)` agree with `max(0, ...)` on every code whose stamp is
    // in the past. The verification page renders this, and a negative age there is the shape a
    // clock skew between the machine that asked and the server takes.
    $issued = app(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'a-second-verifier'),
        null
    );

    DB::table('robot_council_device_codes')
        ->where('id', $issued->record->id)
        ->update(['created_at' => Carbon::now()->addMinutes(5)]);

    expect(DeviceCode::query()->findOrFail($issued->record->id)->ageInSeconds())->toBe(0);

    // The control, so the zero above is the clamp rather than the method always answering zero.
    DB::table('robot_council_device_codes')
        ->where('id', $issued->record->id)
        ->update(['created_at' => Carbon::now()->subMinutes(5)]);

    expect(DeviceCode::query()->findOrFail($issued->record->id)->ageInSeconds())->toBeGreaterThan(250);
});

it('records who denied an enrollment, not only that it was denied', function (): void {
    // **`RemoveArrayItem` survived on `deny()`'s `decided_by`.** A denial that records no decider
    // is an audit gap: the row says the request was refused and nothing says by whom. `approve()`
    // writes the same column and is covered; this one was not.
    $issued = app(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'a-third-verifier'),
        null
    );

    $key = keyValue($this->developer->getKey());

    expect(app(DeviceCodes::class)->deny($issued->record, $key))->toBeTrue();

    $row = DB::table('robot_council_device_codes')->where('id', $issued->record->id)->first();

    expect($row?->decided_by)->toBe($key)
        ->and($row?->denied_at)->not->toBeNull();
});

it('refuses a user code of the wrong length without asking the database', function (): void {
    // **`RemoveEarlyReturn` survived on the length guard**, and it survived for a reason worth
    // stating: without the guard the lookup still returns null, because no stored code matches a
    // string of the wrong length. The guard is not about the answer. It is about not issuing a
    // query for input that cannot match, on a lookup that takes whatever a developer typed.
    //
    // So the assertion is on the QUERIES, which is the only thing the two versions differ in.
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    // `'SHORT'` normalizes to five characters. A first draft used `'TOO-SHORT'`, which normalizes
    // to `TOOSHORT` -- exactly the eight `UserCodes::LENGTH` wants -- so it passed the guard and
    // queried, and the test failed on its own fixture rather than on the code.
    expect(app(DeviceCodes::class)->findByUserCode('SHORT'))->toBeNull()
        ->and($queries)->toBe(0);

    // The control: a well-formed code does reach the database, so the zero above is the guard and
    // not a listener that never fired.
    expect(app(DeviceCodes::class)->findByUserCode('BCDFGHJK'))->toBeNull()
        ->and($queries)->toBeGreaterThan(0);
});

it('gives up drawing a user code after exactly the attempts it promises', function (): void {
    // **Three mutants survived on the draw loop** -- the bound `<`, and the counter's start. Each
    // changes how many times the drawer is asked before the throw, and nothing counted.
    //
    // The number matters in both directions. Fewer attempts and a fleet that draws a collision
    // early fails an enrollment it could have served; more, and an unauthenticated endpoint does
    // unbounded work.
    //
    // `tests/DeviceCodeRequestTest.php` already drives this loop to its bound with the shared
    // `AlwaysDrawsUserCode` fixture and asserts the throw. What it does not do is COUNT, which is
    // why all three mutants survived it: 11, 12 or 13 draws all end in the same exception.
    $issued = app(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'a-fourth-verifier'),
        null
    );

    // A counting drawer, which the shared `AlwaysDrawsUserCode` fixture is not. Bound through
    // `container()` like its neighbor in `DeviceCodeRequestTest`, over a captured instance so the
    // count survives resolution.
    $drawer = new class($issued->record->user_code) implements DrawsUserCodes
    {
        public int $draws = 0;

        public function __construct(private readonly string $taken) {}

        public function draw(): string
        {
            $this->draws++;

            return $this->taken;
        }
    };

    $this->container()->bind(DrawsUserCodes::class, fn (): DrawsUserCodes => $drawer);

    expect(fn (): mixed => app(DeviceCodes::class)->issue(
        [Ability::TasksCreate->value],
        'claude-code',
        'workbench',
        hash('sha256', 'a-fifth-verifier'),
        null
    ))->toThrow(RuntimeException::class, 'in 12 attempts');

    // 12 mirrors the private `USER_CODE_ATTEMPTS`, which no test can read. The message is built
    // from the same constant, so it cannot disagree with the loop -- what the count adds is a
    // check on the loop itself, which the message cannot make.
    expect($drawer->draws)->toBe(12);
});
