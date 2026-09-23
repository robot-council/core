<?php

declare(strict_types=1);

/**
 * The package inside a host application that is not the default skeleton: an enforced morph map, a
 * guard backed by another provider, and configuration with the wrong types in it.
 *
 * Every case here is a real convention in a large Laravel application, and none of them is what
 * Testbench gives by default, so each would otherwise be discovered by whoever installed the
 * package first.
 *
 * @command  vendor/bin/pest --compact tests/HostApplicationTest.php
 */

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RobotCouncil\RobotCouncilServiceProvider;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\HostUsers;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

afterEach(function (): void {
    // Both are process-global statics; the package re-registers its aliases on the next boot
    Relation::morphMap([], merge: false);
    Relation::requireMorphMap(false);
});

it('issues credentials in a host that enforces a morph map', function (): void {
    // The convention this breaks without an alias: `getMorphClass()` throws for any model outside
    // the map, so every `createToken()` would die with `No morph map defined`
    Relation::enforceMorphMap(['host-user' => User::class]);

    expect(Relation::requiresMorphMap())->toBeTrue();

    $installation = $this->approveInstallation($this->developer);
    $credential = $this->installationCredential($installation);

    $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertCreated();

    [, $token] = $this->startAgentSession($installation);

    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk();
});

it('stores its own morph aliases, not the class names', function (): void {
    $installation = $this->approveInstallation($this->developer);
    $this->installationCredential($installation);

    [$session] = $this->startAgentSession($installation);

    $types = DB::table('personal_access_tokens')->pluck('tokenable_type')->unique()->sort()->values()->all();

    // Stable names the package owns: a host adding these classes to its own map later would
    // otherwise rewrite `tokenable_type` and orphan every live token
    expect($types)->toBe(['robot-council-agent-session', 'robot-council-installation'])
        ->and($installation->getMorphClass())->toBe('robot-council-installation')
        ->and($session->getMorphClass())->toBe('robot-council-agent-session');
});

it("resolves the host user model through the configured guard's provider", function (): void {
    // A host that keeps its people under a provider of another name
    config()->set('auth.providers.accounts', config('auth.providers.users'));
    config()->set('auth.guards.web.provider', 'accounts');

    // No `users` provider at all, which is the case the old hard-coded lookup died on
    config()->set('auth.providers.users');

    expect(app(HostUsers::class)->providerName())->toBe('accounts')
        ->and(app(HostUsers::class)->modelClass())
        ->toBe(User::class);
});

it('refuses to be framed on the pages that approve a machine', function (): void {
    $response = $this->actingAs($this->developer, 'web')->get(route('robot-council.enroll.show'));

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
});

it('keeps working when a published config file has the wrong types in it', function (): void {
    // The natural typo: a string where an array belongs. Throwing here would take down every
    // request and every artisan command, including the one that would fix it.
    config()->set('robot-council.routes.web_middleware', 'web');
    config()->set('robot-council.routes.api_prefix', ['robot-council/api']);

    $this->container()->make(RobotCouncilServiceProvider::class, ['app' => $this->container()]);

    expect(Route::has('robot-council.enroll.show'))->toBeTrue();
});

it('falls back to a documented lifetime when configuration holds nothing usable', function (mixed $value): void {
    config()->set('robot-council.credentials.session_ttl_minutes', $value);
    config()->set('robot-council.credentials.installation_max_age_days', $value);
    config()->set('robot-council.rate_limits.device_code_per_ip', $value);

    $credentials = app(Credentials::class);

    expect($credentials->sessionTtlMinutes())->toBe(60)
        ->and($credentials->installationMaxAgeDays())->toBe(30)
        ->and($credentials->rateLimit('device_code_per_ip', 10))->toBe(10);
})->with([
    'zero' => [0],
    'negative' => [-5],
    'empty' => [''],
    'prose' => ['an hour'],
    'null' => [null],
    'a boolean' => [true],
]);

it('narrows a stored ability the fixed list no longer holds', function (): void {
    $installation = $this->approveInstallation($this->developer);

    // A row written before an ability was retired -- or by anything that reached the column.
    // `*` is the one that matters: Sanctum reads it as every ability there is.
    $installation->forceFill([
        'granted_abilities' => ['*', Ability::TasksCreate->value, 'tasks:delete'],
    ])->save();

    $credential = $this->installationCredential($installation);

    $started = $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertCreated();

    // **The token is the `build` preset, and `*` is not in it.** Since #221 the session's
    // abilities no longer come from this column at all, which makes the property stronger rather
    // than weaker: the malformed row cannot reach the token by any route. Asserted as the exact
    // list and then again for `*` by name, because a subset assertion would pass while `*` sat
    // anywhere past the first element.
    expect($started->json('abilities'))->toBe(Role::Build->tokenAbilities())
        ->and($started->json('abilities'))->not->toContain('*');

    $token = stringValue($started->json('token'));

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['abilities' => Role::Build->tokenAbilities()]);

    // The narrowing the accessor does is still the installation-level property, and this is where
    // it is now observable: the stored row keeps `*` and `tasks:delete`, and neither is read back.
    expect(AgentSession::query()->sole()->installation_id)->toBe($installation->id)
        ->and(Installation::query()->sole()->abilities())->toBe([Ability::TasksCreate->value]);
});
