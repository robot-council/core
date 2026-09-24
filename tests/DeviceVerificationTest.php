<?php

declare(strict_types=1);

/**
 * The page a developer approves or denies an enrollment on: what it shows, what it refuses, and
 * what a decision writes.
 *
 * @command  vendor/bin/pest --compact tests/DeviceVerificationTest.php
 */

use Carbon\CarbonImmutable;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\Installation;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

it('shows what the request claims, how old it is, and both addresses', function (): void {
    // Pinned to a whole second before the row is written. `created_at` is stored to the second,
    // so a request that lands at x.8 seconds and a clock moved on by exactly 42 leave an age of
    // either 41 or 42 depending on how the driver rounded -- which is a test that fails on one CI
    // cell in eight and looks like flake.
    $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));

    // The two addresses must differ, or one assertion satisfies both rows and deleting either from
    // the page leaves this green -- and comparing them is the whole reason they are shown
    $enrollment = requestDeviceCode($this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']));

    $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:42'));

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]));

    $response->assertOk()
        ->assertSee($enrollment['record']->user_code)
        ->assertSee('claude-code')
        ->assertSee('workbench-01')
        ->assertSee('tasks:create')->assertSee('events:post')->assertSeeHtml('claims, not facts')

        // The age as a number, not as the words around it
        ->assertSee('42 seconds ago')

        // Both rows, each with its label, and each address only one of them can supply
        ->assertSee('Requested from')->assertSee('203.0.113.10')
        ->assertSee('You are at')->assertSee('198.51.100.7')
        ->assertSeeHtml('This code is displayed on a machine I control');

    // The page can show the user code; it must never show what the helper polls with
    expect($response->getContent())->not->toContain($enrollment['device_code'])
        ->and($response->getContent())->not->toContain($enrollment['verifier']);
});

it('says so when no live request carries the code', function (): void {
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => 'BCDFGHJK']))
        ->assertOk()
        ->assertSee('No enrollment is waiting on that code');
});

it('sends a visitor who is not signed in to GitHub', function (): void {
    $this->get(route('robot-council.enroll.show'))
        ->assertRedirect(route('robot-council.auth.redirect'));
});

it('refuses a developer who is not on the access list', function (): void {
    $this->setAccessLists(developers: []);

    $this->actingAs($this->developer, 'web')->get(route('robot-council.enroll.show'))->assertForbidden();
});

it('changes nothing when approve or deny is reached with GET', function (string $route): void {
    $enrollment = requestDeviceCode($this);

    $this->actingAs($this->developer, 'web')
        ->get(route($route, ['user_code' => $enrollment['record']->user_code, 'confirmed' => '1']))
        ->assertMethodNotAllowed();

    expect($enrollment['record']->refresh()->isDecided())->toBeFalse();
})->with([
    'approve' => ['robot-council.enroll.approve'],
    'deny' => ['robot-council.enroll.deny'],
]);

it("refuses an approval that does not confirm the machine is the developer's", function (): void {
    $enrollment = requestDeviceCode($this);

    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.approve'), ['user_code' => $enrollment['record']->user_code])
        ->assertSessionHasErrors('confirmed');

    expect($enrollment['record']->refresh()->isDecided())->toBeFalse();
});

it('grants what the request asked for, and records who decided', function (): void {
    $enrollment = requestDeviceCode($this, [Ability::TasksClaim->value]);

    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.approve'), [
            'user_code' => $enrollment['record']->user_code,
            'confirmed' => '1',
        ])
        ->assertRedirect(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]));

    $decided = $enrollment['record']->refresh();

    expect($decided->approved_at)->not->toBeNull()
        ->and($decided->denied_at)->toBeNull()
        ->and($decided->decided_by)->toBe(keyValue($this->developer->getKey()));
});

it('grants nothing that was added to the approval itself', function (): void {
    $enrollment = requestDeviceCode($this, [Ability::TasksCreate->value]);

    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.approve'), [
            'user_code' => $enrollment['record']->user_code,
            'confirmed' => '1',

            // An approver's browser, or anything that reached it, asking for more than was requested
            'granted_abilities' => [Ability::CoordinatorDirect->value],
            'requested_abilities' => ['*'],
            'abilities' => [Ability::CoordinatorDirect->value],
        ])
        ->assertRedirect();

    // **The property survives the column that used to carry it** (#239). It used to be that a
    // `granted_abilities` posted by the approver's browser did not widen what was stored. There is
    // no stored list any more, so the assertion moves to the row that remains: approving records
    // the decision and changes nothing about what was asked for. A form field named after a column
    // this package no longer has must reach nothing.
    $decided = $enrollment['record']->refresh();

    expect($decided->approved_at)->not->toBeNull()
        ->and($decided->requestedAbilities())->toBe([Ability::TasksCreate->value])
        ->and($decided->getAttributes())->not->toHaveKey('granted_abilities')
        ->and($decided->getAttributes())->not->toHaveKey('abilities');
});

it('records a denial, and grants nothing', function (): void {
    $enrollment = requestDeviceCode($this);

    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.deny'), ['user_code' => $enrollment['record']->user_code])
        ->assertRedirect();

    $decided = $enrollment['record']->refresh();

    expect($decided->denied_at)->not->toBeNull()
        ->and($decided->approved_at)->toBeNull()
        ->and(Installation::query()->count())->toBe(0);
});

it('refuses a second decision on the same request', function (string $first, string $second): void {
    $enrollment = requestDeviceCode($this);

    $body = ['user_code' => $enrollment['record']->user_code, 'confirmed' => '1'];

    $this->actingAs($this->developer, 'web')->post(route($first), $body)->assertRedirect();

    $this->actingAs($this->developer, 'web')->post(route($second), $body)->assertStatus(409);
})->with([
    'approve then approve' => ['robot-council.enroll.approve', 'robot-council.enroll.approve'],
    'approve then deny' => ['robot-council.enroll.approve', 'robot-council.enroll.deny'],
    'deny then approve' => ['robot-council.enroll.deny', 'robot-council.enroll.approve'],
    'deny then deny' => ['robot-council.enroll.deny', 'robot-council.enroll.deny'],
]);

it('refuses an approval once the code has expired', function (): void {
    $enrollment = requestDeviceCode($this);

    $this->travelTo(now()->addSeconds(601));

    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.approve'), [
            'user_code' => $enrollment['record']->user_code,
            'confirmed' => '1',
        ])
        ->assertNotFound();

    expect($enrollment['record']->refresh()->isDecided())->toBeFalse();
});

it('accepts the code as it is printed, dash and all', function (): void {
    $enrollment = requestDeviceCode($this);

    $printed = substr($enrollment['record']->user_code, 0, 4).'-'.substr($enrollment['record']->user_code, 4);

    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.approve'), [
            'user_code' => strtolower($printed),
            'confirmed' => '1',
        ])
        ->assertRedirect();

    expect($enrollment['record']->refresh()->approved_at)->not->toBeNull();
});

it('rate limits a developer hammering decisions', function (): void {
    config()->set('robot-council.rate_limits.verification_per_user', 3);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->actingAs($this->developer, 'web')
            ->post(route('robot-council.enroll.deny'), ['user_code' => 'BCDFGHJK'])
            ->assertNotFound();
    }

    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.deny'), ['user_code' => 'BCDFGHJK'])
        ->assertStatus(429);
});

it('leaves an already-decided request alone on the page', function (): void {
    $enrollment = requestDeviceCode($this);

    $this->actingAs($this->developer, 'web')->post(route('robot-council.enroll.deny'), [
        'user_code' => $enrollment['record']->user_code,
    ])->assertRedirect();

    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]))
        ->assertOk()
        ->assertSee('was already')
        ->assertDontSee('Approve this machine');
});

it('keeps requests apart when two are in flight', function (): void {
    $first = requestDeviceCode($this, [Ability::TasksCreate->value]);
    $second = requestDeviceCode($this, [Ability::LocksAcquire->value]);

    $this->actingAs($this->developer, 'web')->post(route('robot-council.enroll.approve'), [
        'user_code' => $second['record']->user_code,
        'confirmed' => '1',
    ])->assertRedirect();

    expect($second['record']->refresh()->requestedAbilities())->toBe([Ability::LocksAcquire->value])
        ->and($first['record']->refresh()->isDecided())->toBeFalse()
        ->and(DeviceCode::query()->count())->toBe(2);
});

it('escapes what the requester supplied, whatever reached the row', function (): void {
    $enrollment = requestDeviceCode($this);

    // Written past the endpoint's validation on purpose: the page's escaping is its own guarantee,
    // and a later caller, a seeder, or a relaxed rule must not be able to turn it into markup
    $enrollment['record']->forceFill([
        'machine_label' => '<script>alert(1)</script>',
        'harness' => '"><img src=x onerror=alert(1)>',
    ])->save();

    $response = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]));

    $response->assertOk()->assertSee('<script>alert(1)</script>');

    // What matters is that no tag opens: Blade escapes the angle brackets and the quote, which is
    // what makes the payload inert, while leaving harmless characters like `=` alone
    expect($response->getContent())
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

it('does not look up a code that is not the right length', function (string $typed): void {
    $enrollment = requestDeviceCode($this);

    // A prefix of a live code must not resolve to it, and neither must an overlong reading
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $typed]))
        ->assertOk()
        ->assertDontSee('Approve this machine')
        ->assertSee('No enrollment is waiting on that code');

    expect($enrollment['record']->refresh()->isDecided())->toBeFalse();
})->with([
    'too short' => ['BCDFGH'],
    'too long' => ['BCDFGHJKL'],
    'punctuation only' => ['----'],
]);

it('limits one developer without limiting another', function (): void {
    config()->set('robot-council.rate_limits.verification_per_user', 2);

    $second = $this->enrollDeveloper(77, login: 'otherdev');
    $this->setAccessLists(developers: [4242, 77]);

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->actingAs($this->developer, 'web')
            ->post(route('robot-council.enroll.deny'), ['user_code' => 'BCDFGHJK'])
            ->assertNotFound();
    }

    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.deny'), ['user_code' => 'BCDFGHJK'])
        ->assertStatus(429);

    // Keyed on the developer, so the second one still has their whole allowance
    $this->actingAs($second, 'web')
        ->post(route('robot-council.enroll.deny'), ['user_code' => 'BCDFGHJK'])
        ->assertNotFound();
});
