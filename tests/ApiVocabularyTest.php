<?php

declare(strict_types=1);

/**
 * One vocabulary across the machine endpoints that answer with a body, asserted in one place.
 *
 * A rename is easy to do in all but one file, and nothing else in the suite would notice: every
 * other test reads one endpoint and would keep passing against a response that disagreed with its
 * neighbours. This drives them all and compares them to each other.
 *
 * The shape is decided in #40, and it is deliberately not RFC 8628's: the token endpoint's request
 * already diverges from the RFC, so no standard client can complete this flow whatever the response
 * is named, and `access_token` would promise OAuth affordances this service does not have.
 *
 * @command  vendor/bin/pest --compact tests/ApiVocabularyTest.php
 */

use Illuminate\Foundation\Auth\User;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

/**
 * Drive all four machine endpoints once, and hand back what each answered.
 *
 * @param  TestCase  $case  The test case driving them.
 * @param  User  $developer  The developer who approves the enrollment.
 * @return array<string, array<string, mixed>> The four decoded bodies, keyed by endpoint.
 */
function everyMachineResponse(TestCase $case, User $developer): array
{
    $enrollment = requestDeviceCode($case, [Ability::TasksCreate->value]);

    $case->actingAs($developer, 'web')->post(route('robot-council.enroll.approve'), [
        'user_code' => $enrollment['record']->user_code,
        'confirmed' => '1',
    ])->assertRedirect();

    $case->flushSession();

    $exchange = $case->postJson(route('robot-council.device.token'), [
        'device_code' => $enrollment['device_code'],
        'code_verifier' => $enrollment['verifier'],
    ])->assertCreated();

    $credential = stringValue($exchange->json('token'));

    $start = $case->machine($credential)->postJson(route('robot-council.sessions.start'))->assertCreated();

    $renew = $case->machine($credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $start->json('session_id')]))
        ->assertOk();

    /** @var array<string, mixed> $token */
    $token = (array) $exchange->json();

    /** @var array<string, mixed> $started */
    $started = (array) $start->json();

    /** @var array<string, mixed> $renewed */
    $renewed = (array) $renew->json();

    return [
        'device/code' => $enrollment['response'],
        'device/token' => $token,
        'sessions' => $started,
        'sessions/renew' => $renewed,
    ];
}

it('names a bearer token `token` on every response that carries one', function (): void {
    $responses = everyMachineResponse($this, $this->developer);

    foreach (['device/token', 'sessions', 'sessions/renew'] as $endpoint) {
        expect($responses[$endpoint])->toHaveKey('token')
            ->and($responses[$endpoint])->not->toHaveKey('credential')
            ->and($responses[$endpoint])->not->toHaveKey('access_token');
    }

    // The code endpoint carries no bearer token at all. `device_code` is RFC 8628's name for
    // something a developer approves rather than something that authenticates a request.
    expect($responses['device/code'])->not->toHaveKey('token')
        ->and($responses['device/code'])->toHaveKey('device_code');
});

it('states every expiry as `expires_in` seconds, on every response that has one', function (): void {
    $responses = everyMachineResponse($this, $this->developer);

    foreach ($responses as $body) {
        expect($body)->toHaveKey('expires_in')
            ->and($body['expires_in'])->toBeInt()
            ->and($body['expires_in'])->toBeGreaterThan(0)
            ->and($body)->not->toHaveKey('expires_at');
    }

    // And the durations are the configured ones, not a number that merely looks plausible
    expect($responses['device/code']['expires_in'])->toBe(600)
        ->and($responses['device/token']['expires_in'])->toBe(30 * 24 * 60 * 60)
        ->and($responses['sessions']['expires_in'])->toBe(60 * 60)
        ->and($responses['sessions/renew']['expires_in'])->toBe(60 * 60);
});

it('makes `abilities` describe the token beside it, everywhere', function (): void {
    $responses = everyMachineResponse($this, $this->developer);

    // An installation credential can do one thing, and says so about itself
    expect($responses['device/token']['abilities'])->toBe([Ability::SessionsStart->value])

        // **And `abilities` is now the only ability key any of these answers carry** (#239).
        // `granted_abilities` sat beside it describing the INSTALLATION, which was a different
        // subject under a name one letter apart -- and after #221 it no longer described the
        // session tokens that installation would start either. The vocabulary is simpler for its
        // removal, which is what this assertion now pins.
        ->and($responses['device/token'])->not->toHaveKey('granted_abilities');

    // Both session responses describe their own token, which is the role's preset.
    expect($responses['sessions']['abilities'])->toBe(Role::Build->tokenAbilities())
        ->and($responses['sessions/renew']['abilities'])->toBe(Role::Build->tokenAbilities());
});

it('answers 201 where it creates something and 200 where it replaces one', function (): void {
    // Not RFC 8628's 200s. Each of the first three creates a row that did not exist; a renewal
    // replaces a token on a session that already does.
    $enrollment = requestDeviceCode($this);

    $this->actingAs($this->developer, 'web')->post(route('robot-council.enroll.approve'), [
        'user_code' => $enrollment['record']->user_code,
        'confirmed' => '1',
    ])->assertRedirect();

    $this->flushSession();

    $credential = stringValue($this->postJson(route('robot-council.device.token'), [
        'device_code' => $enrollment['device_code'],
        'code_verifier' => $enrollment['verifier'],
    ])->assertStatus(201)->json('token'));

    $start = $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertStatus(201);

    $this->machine($credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $start->json('session_id')]))
        ->assertStatus(200);
});

it('names a duration the same way wherever one is stated', function (): void {
    // The presence endpoints state durations that are not expiries -- how long the silence may
    // last, not when a credential dies -- so they do not carry `expires_in`. What they must not do
    // is state an instant: a bridge on a machine whose clock is wrong could not use one, which is
    // the same reason every expiry here is a duration.
    $enrollment = requestDeviceCode($this, [Ability::TasksCreate->value]);

    $this->actingAs($this->developer, 'web')->post(route('robot-council.enroll.approve'), [
        'user_code' => $enrollment['record']->user_code,
        'confirmed' => '1',
    ])->assertRedirect();

    $this->flushSession();

    $credential = stringValue($this->postJson(route('robot-council.device.token'), [
        'device_code' => $enrollment['device_code'],
        'code_verifier' => $enrollment['verifier'],
    ])->json('token'));

    $start = $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertCreated();

    $token = stringValue($start->json('token'));

    /** @var array<string, mixed> $heartbeat */
    $heartbeat = (array) $this->machine($token)
        ->postJson(route('robot-council.agent.heartbeat'))
        ->assertOk()
        ->json();

    foreach (['stale_in', 'gone_in'] as $duration) {
        expect($heartbeat[$duration])->toBeInt()->toBeGreaterThan(0);
    }

    expect($heartbeat)->not->toHaveKey('stale_at')
        ->and($heartbeat)->not->toHaveKey('gone_at')
        ->and($heartbeat)->not->toHaveKey('last_seen_at')

        // And the session is named the same way it is everywhere else
        ->and($heartbeat)->toHaveKey('session_id')
        ->and($heartbeat)->not->toHaveKey('id');
});

it('keeps the RFC 8628 error vocabulary, which is the half that is conformant', function (): void {
    $enrollment = requestDeviceCode($this);

    // Unchanged by the rename, and deliberately so: these name states this flow genuinely has
    $this->postJson(route('robot-council.device.token'), [
        'device_code' => $enrollment['device_code'],
        'code_verifier' => $enrollment['verifier'],
    ])->assertStatus(400)->assertExactJson(['error' => 'authorization_pending']);
});

it('names the feed position `feed_cursor`, and only where starting one means something', function (): void {
    $responses = everyMachineResponse($this, $this->developer);

    // A session being started is told where to begin reading, so it reaches current events in one
    // request instead of walking the whole feed to reach the present
    expect($responses['sessions'])->toHaveKey('feed_cursor')
        ->and($responses['sessions']['feed_cursor'])->toBeInt()
        ->and($responses['sessions']['feed_cursor'])->toBeGreaterThan(0);

    // A renewal RESTATES the position the session has acknowledged, which is not the same as
    // handing back a fresh one: a fresh cursor would skip everything the session had not yet read,
    // and its starting position would replay everything since. Restating what the row already
    // holds is what lets a process that restarted with a live token resume rather than guess,
    // which is the whole of #86 -- the value was previously stated once, at start, and stored
    // nowhere.
    expect($responses['sessions/renew'])->toHaveKey('feed_cursor')
        ->and($responses['sessions/renew']['feed_cursor'])->toBeInt()
        ->and($responses['sessions/renew']['feed_cursor'])->toBeGreaterThan(0);

    // An installation credential reads no feed, so it names no position in it
    expect($responses['device/token'])->not->toHaveKey('feed_cursor')
        ->and($responses['device/code'])->not->toHaveKey('feed_cursor');
});
