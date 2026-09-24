<?php

declare(strict_types=1);

/**
 * Exchanging an approved device code for an installation credential: what each unfinished state
 * answers, and what happens when two exchanges of one code overlap.
 *
 * @command  vendor/bin/pest --compact tests/DeviceTokenExchangeTest.php
 */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\Installation;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

/**
 * Approve an enrollment as the developer would, through the page.
 *
 * @param  TestCase  $case  The test case making the request.
 * @param  User  $developer  The developer approving it.
 * @param  array{record: DeviceCode, device_code: string, verifier: string, response: array<string, mixed>}  $enrollment  The request.
 */
function approveEnrollment(TestCase $case, User $developer, array $enrollment): void
{
    $case->actingAs($developer, 'web')
        ->post(route('robot-council.enroll.approve'), [
            'user_code' => $enrollment['record']->user_code,
            'confirmed' => '1',
        ])
        ->assertRedirect();
}

/**
 * Poll the token endpoint as the helper would.
 *
 * @param  TestCase  $case  The test case making the request.
 * @param  array{record: DeviceCode, device_code: string, verifier: string, response: array<string, mixed>}  $enrollment  The request.
 * @param  string|null  $verifier  A verifier other than the one the enrollment holds.
 * @return TestResponse<JsonResponse> The response.
 */
function exchange(TestCase $case, array $enrollment, ?string $verifier = null): TestResponse
{
    return $case->postJson(route('robot-council.device.token'), [
        'device_code' => $enrollment['device_code'],
        'code_verifier' => $verifier ?? $enrollment['verifier'],
    ]);
}

it('issues a credential that can do nothing but start sessions', function (): void {
    // Pinned because the expiry below is asserted to the second. Unpinned, the expected value is a
    // SECOND read of the clock, taken when the assertion runs rather than when the request was
    // handled, and either side of a second boundary the two differ by one (#98).
    $this->freezeTime();

    $requestedAt = Carbon::now();

    $enrollment = requestDeviceCode($this, [Ability::TasksCreate->value, Ability::LocksAcquire->value]);
    approveEnrollment($this, $this->developer, $enrollment);

    $response = exchange($this, $enrollment);

    $response->assertCreated()
        ->assertJsonStructure(['installation_id', 'token', 'abilities', 'expires_in']);

    // **The key is gone, and its absence is asserted rather than left to `assertJsonStructure`**,
    // which checks that the named keys are present and says nothing about any others (#239). A
    // client older than `robot-council/cli` v0.3.0 read this; that is the breaking half.
    expect($response->json())->not->toHaveKey('granted_abilities');

    $installation = Installation::query()->sole();

    expect($installation->user_id)->toBe(keyValue($this->developer->getKey()))
        ->and($installation->harness)->toBe('claude-code')
        ->and($installation->machine_label)->toBe('workbench-01')

        // The default maximum age, to the second, measured from the instant the request was
        // handled rather than from a fresh read of the clock
        ->and($installation->expires_at->timestamp)->toBe($requestedAt->copy()->addDays(30)->timestamp);

    // The credential itself carries only the one ability; the rest ride on session tokens
    $credential = PersonalAccessToken::query()->sole();

    expect(Tokens::abilities($credential))->toBe([Ability::SessionsStart->value])
        ->and(dateValue($credential->getAttribute('expires_at'))->toDateTimeString())
        ->toBe($installation->expires_at->toDateTimeString());
});

it('measures the credential expiry from the request, not from whenever the assertion runs', function (): void {
    // #98's regression test, and the reason it pins a specific microsecond. The flake needed the
    // request and the assertion to fall either side of a second boundary, which happens at random
    // because the suite runs in random order and the work between them varies per run. Here the
    // boundary is crossed deliberately, so the condition that used to fail occurs on every run.
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00.999999'));

    $requestedAt = Carbon::now();

    $enrollment = requestDeviceCode($this);
    approveEnrollment($this, $this->developer, $enrollment);

    exchange($this, $enrollment)->assertCreated();

    // One microsecond later, and one second later by the clock
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:01.000001'));

    expect(Installation::query()->sole()->expires_at->timestamp)
        ->toBe($requestedAt->copy()->addDays(30)->timestamp);

    // The control, in the same test. Without it this passes identically on a clock that never
    // moved, and would then be a test of nothing: it asserts that a fresh read of the clock now
    // gives an answer one second different, which is exactly the discrepancy that used to reach
    // the assertion above.
    expect(now()->addDays(30)->timestamp)->toBe($requestedAt->copy()->addDays(30)->addSecond()->timestamp);
});

it('answers authorization_pending until a developer decides', function (): void {
    $enrollment = requestDeviceCode($this);

    exchange($this, $enrollment)->assertStatus(400)->assertExactJson(['error' => 'authorization_pending']);

    expect(Installation::query()->count())->toBe(0);
});

it('answers access_denied once a developer has denied it', function (): void {
    $enrollment = requestDeviceCode($this);

    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.deny'), ['user_code' => $enrollment['record']->user_code]);

    exchange($this, $enrollment)->assertStatus(400)->assertExactJson(['error' => 'access_denied']);
});

it('answers expired_token once the code has expired', function (): void {
    $enrollment = requestDeviceCode($this);
    approveEnrollment($this, $this->developer, $enrollment);

    $this->travelTo(now()->addSeconds(601));

    exchange($this, $enrollment)->assertStatus(400)->assertExactJson(['error' => 'expired_token']);

    expect(Installation::query()->count())->toBe(0);
});

it('answers expired_token on a second exchange of the same code', function (): void {
    $enrollment = requestDeviceCode($this);
    approveEnrollment($this, $this->developer, $enrollment);

    exchange($this, $enrollment)->assertCreated();
    exchange($this, $enrollment)->assertStatus(400)->assertExactJson(['error' => 'expired_token']);

    expect(Installation::query()->count())->toBe(1);
});

it('answers invalid_grant for a code nobody issued', function (): void {
    $this->postJson(route('robot-council.device.token'), [
        'device_code' => bin2hex(random_bytes(32)),
        'code_verifier' => 'a-verifier-long-enough-to-satisfy-rfc-7636-floor',
    ])->assertStatus(400)->assertExactJson(['error' => 'invalid_grant']);
});

it('answers invalid_grant in every state when the verifier is wrong', function (string $state): void {
    $enrollment = requestDeviceCode($this);

    if ($state === 'approved') {
        approveEnrollment($this, $this->developer, $enrollment);
    }

    if ($state === 'denied') {
        // Asserted, or a deny that stopped working would leave the row undecided and quietly turn
        // this case into a second copy of the pending one
        $this->actingAs($this->developer, 'web')
            ->post(route('robot-council.enroll.deny'), ['user_code' => $enrollment['record']->user_code])
            ->assertRedirect();

        expect($enrollment['record']->refresh()->denied_at)->not->toBeNull();
    }

    // Whoever holds the device code but not the verifier is told the same thing throughout, so
    // polling a stolen code cannot reveal that a developer has approved it
    exchange($this, $enrollment, verifier: 'not-the-verifier-but-long-enough-for-rfc-7636-floor')
        ->assertStatus(400)
        ->assertExactJson(['error' => 'invalid_grant']);

    expect(Installation::query()->count())->toBe(0);
})->with(['pending', 'approved', 'denied']);

it('mints one installation and one credential when two exchanges of a code overlap', function (): void {
    $enrollment = requestDeviceCode($this);
    approveEnrollment($this, $this->developer, $enrollment);

    $injected = null;
    $fired = false;

    // A second exchange lands immediately after the first exchange's first query against the
    // device codes table. Against a read then a write, that first query is the read, so the second
    // exchange finishes while the first is still holding a stale row, and both create an
    // installation.
    //
    // The filter is load-bearing wherever the cache store is a database one, because the limiter's
    // queries then run first and injecting on those would let the second exchange finish before the
    // first had touched anything. Which store is in play differs between a local checkout and CI,
    // so the filter is what makes this land in the same place either way.
    $case = $this;

    DB::listen(function (QueryExecuted $query) use (&$fired, &$injected, $enrollment, $case): void {
        if ($fired || ! str_contains($query->sql, 'robot_council_device_codes')) {
            return;
        }

        $fired = true;

        $injected = exchange($case, $enrollment);
    });

    $first = exchange($this, $enrollment);

    expect($fired)->toBeTrue()
        ->and($injected)->toBeInstanceOf(TestResponse::class)
        ->and(Installation::query()->count())->toBe(1)
        ->and(PersonalAccessToken::query()->count())->toBe(1);

    // Exactly one of the two walked away with a credential
    $statuses = [$first->getStatusCode(), $injected instanceof TestResponse ? $injected->getStatusCode() : 0];

    sort($statuses);

    expect($statuses)->toBe([201, 400]);
});

it('refuses a request missing the device code or the verifier', function (array $body, string $missing): void {
    $this->postJson(route('robot-council.device.token'), $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors($missing);
})->with([
    'no verifier' => [['device_code' => 'abc'], 'code_verifier'],
    'no device code' => [['code_verifier' => 'abc'], 'device_code'],
]);

it('rate limits a helper polling one code too hard', function (): void {
    config()->set('robot-council.rate_limits.device_token_per_code', 3);

    $enrollment = requestDeviceCode($this);

    for ($poll = 0; $poll < 3; $poll++) {
        exchange($this, $enrollment)->assertStatus(400);
    }

    exchange($this, $enrollment)->assertStatus(429);
});

it('limits one code without touching another from the same address', function (): void {
    config()->set('robot-council.rate_limits.device_token_per_code', 3);

    $mine = requestDeviceCode($this);
    $theirs = requestDeviceCode($this);

    for ($poll = 0; $poll < 3; $poll++) {
        exchange($this, $mine)->assertStatus(400);
    }

    exchange($this, $mine)->assertStatus(429);

    // The second code is a separate bucket, which is the whole point of keying on the code
    exchange($this, $theirs)->assertStatus(400);
});

it('gives a thief without the verifier a bucket of their own', function (): void {
    config()->set('robot-council.rate_limits.device_token_per_code', 2);

    $enrollment = requestDeviceCode($this);

    // Someone holding the device code but not the verifier spends their allowance
    for ($poll = 0; $poll < 2; $poll++) {
        exchange($this, $enrollment, verifier: 'a-stolen-code-is-useless-without-the-verifier-here')
            ->assertStatus(400);
    }

    exchange($this, $enrollment, verifier: 'a-stolen-code-is-useless-without-the-verifier-here')
        ->assertStatus(429);

    // The helper that owns the code is untouched, so a stolen code cannot deny it service
    exchange($this, $enrollment)->assertStatus(400)->assertExactJson(['error' => 'authorization_pending']);
});

it('rate limits one address polling many codes', function (): void {
    config()->set('robot-council.rate_limits.device_token_per_ip', 3);
    config()->set('robot-council.rate_limits.device_code_per_ip', 100);

    // Each poll uses a different code, so only the per-address limit can be what fires
    for ($poll = 0; $poll < 3; $poll++) {
        exchange($this, requestDeviceCode($this))->assertStatus(400);
    }

    exchange($this, requestDeviceCode($this))->assertStatus(429);
});
