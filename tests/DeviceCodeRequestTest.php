<?php

declare(strict_types=1);

/**
 * The unauthenticated endpoint a helper calls to start an enrollment: what it accepts, what it
 * returns, and what it stores.
 *
 * @command  vendor/bin/pest --compact tests/DeviceCodeRequestTest.php
 */

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Support\Contracts\DrawsUserCodes;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\UserCodes;
use RobotCouncil\Tests\Fixtures\AlwaysDrawsUserCode;

/**
 * A well-formed request body, with whatever the test is varying overridden.
 *
 * @param  array<string, mixed>  $overrides  Fields to replace.
 * @return array<string, mixed> The body to post.
 */
function codeRequest(array $overrides = []): array
{
    return [
        'harness' => 'claude-code',
        'machine_label' => 'workbench-01',
        'requested_abilities' => [Ability::TasksCreate->value, Ability::EventsPost->value],
        'code_challenge' => hash('sha256', 'a-verifier-only-the-helper-holds-and-nobody-else-at-all'),
        ...$overrides,
    ];
}

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
});

it('returns what the helper needs, and stores only hashes', function (): void {
    $response = $this->postJson(route('robot-council.device.code'), codeRequest());

    $response->assertCreated()
        ->assertJsonStructure(['device_code', 'user_code', 'verification_uri', 'expires_in', 'interval']);

    $deviceCode = stringValue($response->json('device_code'));

    expect($deviceCode)
        // 64 hexadecimal characters is 256 bits, well past the 128 the flow calls for
        ->and($deviceCode)->toMatch('/^[0-9a-f]{64}$/')
        ->and($response->json('expires_in'))->toBe(600)
        ->and($response->json('interval'))->toBe(5)
        ->and($response->json('verification_uri'))->toEndWith('/robot-council/enroll');

    $stored = DeviceCode::query()->sole();

    expect($stored->device_code_hash)->toBe(hash('sha256', $deviceCode))
        ->and($stored->challenge_hash)->toBe(hash('sha256', hash('sha256', 'a-verifier-only-the-helper-holds-and-nobody-else-at-all')))
        ->and($stored->requested_abilities)->toBe(['tasks:create', 'events:post']);

    // Nothing anywhere in the row is the device code or the verifier
    $row = (array) DB::table('robot_council_device_codes')->sole();

    expect(implode('|', array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', $row)))
        ->not->toContain($deviceCode)
        ->not->toContain('a-verifier-only-the-helper-holds-and-nobody-else-at-all');
});

it('draws a user code of eight characters from the RFC 8628 alphabet', function (): void {
    // Enough requests to say something about the alphabet, which is more than the limit this test
    // is not about
    config()->set('robot-council.rate_limits.device_code_per_ip', 100);

    $codes = [];

    for ($request = 0; $request < 12; $request++) {
        $codes[] = stringValue($this->postJson(route('robot-council.device.code'), codeRequest())->json('user_code'));
    }

    foreach ($codes as $code) {
        expect($code)->toHaveLength(8)
            ->and($code)->toMatch('/^['.UserCodes::ALPHABET.']{8}$/D');
    }

    // The alphabet excludes the vowels and everything a digit can be read as
    expect(UserCodes::ALPHABET)->toBe('BCDFGHJKLMNPQRSTVWXZ')
        ->and(array_unique($codes))->toHaveCount(12);
});

it('gives up rather than spinning when every drawn user code collides', function (): void {
    $this->postJson(route('robot-council.device.code'), codeRequest())->assertCreated();

    $taken = DeviceCode::query()->sole()->user_code;

    // Every draw now returns a code that is already live
    $this->container()->bind(DrawsUserCodes::class, fn (): DrawsUserCodes => new AlwaysDrawsUserCode($taken));

    expect(fn () => $this->service(DeviceCodes::class)->issue(['tasks:create'], 'claude-code', 'workbench', hash('sha256', 'v'), null))
        ->toThrow(RuntimeException::class)
        ->and(DeviceCode::query()->count())->toBe(1);
});

it('refuses an ability outside the fixed list', function (array $requested): void {
    $this->postJson(route('robot-council.device.code'), codeRequest(['requested_abilities' => $requested]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('requested_abilities.0');

    expect(DeviceCode::query()->count())->toBe(0);
})->with([
    'the wildcard' => [['*']],
    'the coordinator' => [[Ability::CoordinatorDirect->value]],
    'the installation credential' => [[Ability::SessionsStart->value]],
    'an invention' => [['tasks:delete']],
]);

it('refuses a request with no abilities at all', function (): void {
    $this->postJson(route('robot-council.device.code'), codeRequest(['requested_abilities' => []]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('requested_abilities');
});

it('refuses a harness or machine label outside the safe character set', function (string $field, string $value): void {
    $this->postJson(route('robot-council.device.code'), codeRequest([$field => $value]))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(DeviceCode::query()->count())->toBe(0);
})->with([
    'a harness with markup' => ['harness', '<script>'],
    'a harness in capitals' => ['harness', 'Claude-Code'],
    'a harness too long' => ['harness', str_repeat('a', 33)],
    'a label with a space' => ['machine_label', 'my machine'],
    'a label with a slash' => ['machine_label', '../../etc/passwd'],
    'a label too long' => ['machine_label', str_repeat('a', 65)],
]);

it('refuses a challenge that is not a SHA-256 digest', function (string $challenge): void {
    $this->postJson(route('robot-council.device.code'), codeRequest(['code_challenge' => $challenge]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('code_challenge');
})->with([
    'too short' => ['abc123'],
    'not hexadecimal' => [str_repeat('g', 64)],
    'in capitals' => [strtoupper(hash('sha256', 'v'))],
]);

it('never offers a code lifetime past ten minutes, whatever configuration asks', function (): void {
    config()->set('robot-council.credentials.device_code_ttl_seconds', 86_400);

    $response = $this->postJson(route('robot-council.device.code'), codeRequest());

    expect($response->json('expires_in'))->toBe(Credentials::MAX_DEVICE_CODE_TTL_SECONDS)
        ->and(DeviceCode::query()->sole()->expires_at->diffInSeconds(now()))
        ->toBeLessThanOrEqual(Credentials::MAX_DEVICE_CODE_TTL_SECONDS);
});

it("builds the verification URL from the application, not from the requester's headers", function (): void {
    config()->set('app.url', 'https://council.example.com');

    $response = $this->postJson(
        route('robot-council.device.code'),
        codeRequest(),
        ['Host' => 'attacker.example.net']
    );

    expect($response->json('verification_uri'))->toBe('https://council.example.com/robot-council/enroll');
});

it('rate limits one address asking for codes', function (): void {
    config()->set('robot-council.rate_limits.device_code_per_ip', 3);

    for ($request = 0; $request < 3; $request++) {
        $this->postJson(route('robot-council.device.code'), codeRequest())->assertCreated();
    }

    $this->postJson(route('robot-council.device.code'), codeRequest())->assertStatus(429);

    // A different address is unaffected, which is what keying on the address means
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->postJson(route('robot-council.device.code'), codeRequest())
        ->assertCreated();
});

it('refuses a value that only fits because the pattern stopped at a newline', function (string $field, string $value): void {
    // PHP's `$` matches before a trailing newline unless the pattern carries `/D`, so this is one
    // byte past a column the database will not stretch.
    //
    // The host's global `TrimStrings` is taken out of the way deliberately. With it, the newline
    // never reaches the rule and this passes whether or not the pattern is anchored -- so the test
    // would be measuring a middleware the package does not ship and a host may reorder or remove,
    // rather than the package's own guarantee.
    $this->withoutMiddleware(TrimStrings::class)
        ->postJson(route('robot-council.device.code'), codeRequest([$field => $value]))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(DeviceCode::query()->count())->toBe(0);
})->with([
    'a full-length harness' => ['harness', str_repeat('a', 32)."\n"],
    'a short harness' => ['harness', "claude-code\n"],
    'a full-length label' => ['machine_label', str_repeat('m', 64)."\n"],
    'a challenge' => ['code_challenge', hash('sha256', 'v')."\n"],
]);
