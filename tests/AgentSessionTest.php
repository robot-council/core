<?php

declare(strict_types=1);

/**
 * Starting and renewing the session an agent process runs under, and what each of the two Sanctum
 * guards refuses.
 *
 * The refusals are the point. Sanctum's guard tries the `web` guard before it looks at a bearer
 * token, and a guard with no provider accepts a token belonging to any model at all, so "a guard
 * returned somebody" is never the same question as "the right kind of principal is here".
 *
 * @command  vendor/bin/pest --compact tests/AgentSessionTest.php
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Middleware\EnsureAllowlistedDeveloper;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RobotCouncil\Tests\Fixtures\HostUserWithTokens;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
    $this->credential = $this->installationCredential($this->installation);

    // A human-facing route, to show a bearer token reaches nothing there
    Route::middleware(['web', EnsureAllowlistedDeveloper::class])
        ->get('/robot-council-test/developer-area', fn (): string => 'developer area');
});

it("starts a session carrying its role's preset, not the installation's abilities", function (): void {
    // Pinned for the reason #98 records: the token expiry below is asserted to the second, and
    // unpinned the expected value is a second read of the clock taken when the assertion runs.
    $this->freezeTime();

    $startedAt = Carbon::now();

    $response = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), ['project_id' => 'uams-statamic']);

    $response->assertCreated()->assertJsonStructure(['session_id', 'token', 'abilities', 'expires_in']);

    // **The full `build` preset, although this installation was granted two of the four.** That
    // difference is the whole of #221: the preset is the source, so the installation's stored
    // abilities neither widen nor narrow what the session holds. Asserted as the list rather than
    // as a count, so a preset that gained or lost a member fails here by name.
    expect($response->json('abilities'))->toBe(Role::Build->tokenAbilities())
        ->and($response->json('expires_in'))->toBe(3600);

    $session = AgentSession::query()->sole();

    expect($session->installation_id)->toBe($this->installation->getKey())
        ->and($session->user_id)->toBe(keyValue($this->developer->getKey()))
        ->and($session->project_id)->toBe('uams-statamic')
        ->and($session->role)->toBe(Role::Build)
        ->and($session->hasGone())->toBeFalse();

    // The session's token expires on the session's schedule, not the installation's
    $token = PersonalAccessToken::query()->where('tokenable_type', (new AgentSession)->getMorphClass())->sole();

    expect(Tokens::abilities($token))->toBe(Role::Build->tokenAbilities())
        ->and(dateValue($token->getAttribute('expires_at'))->timestamp)
        ->toBe($startedAt->copy()->addMinutes(60)->timestamp);
});

it('measures the session token lifetime from the request, not from whenever it is read', function (): void {
    // The session path's half of #98. It is a different computation from the installation
    // credential's -- a session token's lifetime comes from `sessions.ttl_minutes` rather than
    // `installation_lifetime_days` -- and the property is one the fleet depends on: a token's
    // expiry is fixed when it is issued, so reading it later cannot move it.
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00.999999'));

    $startedAt = Carbon::now();

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), ['project_id' => 'uams-statamic'])
        ->assertCreated();

    // Across the second boundary, which is what used to make the assertion below fail at random
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:01.000001'));

    $token = PersonalAccessToken::query()->where('tokenable_type', (new AgentSession)->getMorphClass())->sole();

    expect(dateValue($token->getAttribute('expires_at'))->timestamp)
        ->toBe($startedAt->copy()->addMinutes(60)->timestamp);

    // The control: a fresh read of the clock is now one second out, so the assertion above is
    // passing because the expiry is pinned rather than because the clock never moved
    expect(now()->addMinutes(60)->timestamp)->toBe($startedAt->copy()->addMinutes(60)->addSecond()->timestamp);
});

it('authenticates an agent route as the session, not as the developer', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson([
            'session_id' => $session->getKey(),
            'installation_id' => $this->installation->getKey(),
            'status' => 'active',
            'role' => Role::Build->value,
            'abilities' => Role::Build->tokenAbilities(),
        ]);
});

it("replaces a session's token on renewal, and refuses the one it replaced", function (): void {
    [$session, $first] = $this->startAgentSession($this->installation);

    $response = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]));

    $response->assertOk();

    $second = stringValue($response->json('token'));

    expect($second)->not->toBe($first);

    $this->machine($second)->getJson(route('robot-council.agent.session'))->assertOk();

    $this->machine($first)->getJson(route('robot-council.agent.session'))->assertUnauthorized();

    expect(PersonalAccessToken::query()->where('tokenable_type', (new AgentSession)->getMorphClass())->count())->toBe(1);
});

it('refuses to renew a session that has gone', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $this->markSessionGone($session);

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertStatus(409);
});

it("refuses to renew another installation's session", function (): void {
    $other = $this->approveInstallation($this->developer, machineLabel: 'laptop');

    [$session] = $this->startAgentSession($other);

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertForbidden();

    // The other installation's own credential still renews it
    $this->machine($this->installationCredential($other))
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertOk();
});

it('answers 404 for a session that does not exist', function (): void {
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => 987654]))
        ->assertNotFound();
});

it('refuses a session token on the session endpoints', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->machine($token)
        ->postJson(route('robot-council.sessions.start'))
        ->assertUnauthorized();

    $this->machine($token)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertUnauthorized();

    expect(AgentSession::query()->count())->toBe(1);
});

it('refuses an installation credential on the agent routes', function (): void {
    $this->machine($this->credential)
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it('refuses a signed-in human on the machine routes', function (string $route): void {
    // Sanctum's guard finds this human on the `web` guard before it reads any bearer token, and
    // hands back a transient token whose `can()` answers true to every ability
    $this->actingAs($this->developer, 'web')
        ->postJson(route($route))
        ->assertUnauthorized();

    expect(AgentSession::query()->count())->toBe(0);
})->with([
    'starting a session' => ['robot-council.sessions.start'],
]);

it('refuses a signed-in human on an agent route', function (): void {
    $this->actingAs($this->developer, 'web')
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it('refuses a bearer token on a human route', function (): void {
    [, $token] = $this->startAgentSession($this->installation);

    // Nothing signs in: the human route reads the session guard, which a bearer token never sets
    $this->machine($token)
        ->get('/robot-council-test/developer-area')
        ->assertRedirect(route('robot-council.auth.redirect'));

    $this->machine($this->credential)
        ->get('/robot-council-test/developer-area')
        ->assertRedirect(route('robot-council.auth.redirect'));
});

it('refuses both credentials once the developer comes off the access list', function (): void {
    [, $token] = $this->startAgentSession($this->installation);

    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk();

    $this->setAccessLists(developers: []);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertForbidden();

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'))
        ->assertForbidden();
});

it('refuses a token belonging to a session that has gone', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->markSessionGone($session);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it('refuses an installation credential past its maximum age', function (): void {
    [, $token] = $this->startAgentSession($this->installation);

    $this->travelTo(now()->addDays(31));

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'))
        ->assertUnauthorized();

    // The session token expired on its own, much earlier
    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it('refuses an installation credential whose expiry configuration shrank underneath it', function (): void {
    // The token was minted with a month on it; the installation row is what is re-read
    $this->installation->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'))
        ->assertUnauthorized();
});

it('honors a shortened session lifetime', function (): void {
    config()->set('robot-council.credentials.session_ttl_minutes', 5);

    $response = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'));

    expect($response->json('expires_in'))->toBe(300);

    [, $token] = $this->startAgentSession($this->installation);

    $this->travelTo(now()->addMinutes(6));

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it("refuses the package's tokens on a host route guarded by the host's own users provider", function (): void {
    // What a host application is told to do: give its `sanctum` guard a provider, so the guard's
    // default of accepting any token owner does not admit agents
    config()->set('auth.guards.sanctum.provider', 'users');

    Route::middleware(['api', 'auth:sanctum'])
        ->get('/host-api/me', fn (): string => "the host's own route");

    [, $token] = $this->startAgentSession($this->installation);

    $this->machine($token)->getJson('/host-api/me')->assertUnauthorized();
    $this->machine($this->credential)->getJson('/host-api/me')->assertUnauthorized();

    expect(config('auth.providers.users.model'))->toBe(User::class);
});

it('refuses a bearer token that was never issued', function (): void {
    $this->machine('1|not-a-token-anybody-minted')
        ->postJson(route('robot-council.sessions.start'))
        ->assertUnauthorized();

    $this->machine('1|not-a-token-anybody-minted')
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it('refuses a request with no credential at all', function (): void {
    $this->postJson(route('robot-council.sessions.start'))->assertUnauthorized();
    $this->getJson(route('robot-council.agent.session'))->assertUnauthorized();
});

it('rate limits one installation starting sessions', function (): void {
    config()->set('robot-council.rate_limits.sessions_per_installation', 3);

    for ($start = 0; $start < 3; $start++) {
        $this->machine($this->credential)
            ->postJson(route('robot-council.sessions.start'))
            ->assertCreated();
    }

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'))
        ->assertStatus(429);

    // A second installation is unaffected, because the limit is keyed on the installation
    $other = $this->approveInstallation($this->developer, machineLabel: 'laptop');

    $this->machine($this->installationCredential($other))
        ->postJson(route('robot-council.sessions.start'))
        ->assertCreated();
});

it("keeps sessions apart: one installation cannot read another's", function (): void {
    $otherDeveloper = $this->enrollDeveloper(77, login: 'otherdev');
    $this->setAccessLists(developers: [4242, 77]);

    $otherInstallation = $this->approveInstallation($otherDeveloper, machineLabel: 'their-machine');

    [$mine] = $this->startAgentSession($this->installation);
    [$theirs] = $this->startAgentSession($otherInstallation);

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $theirs->getKey()]))
        ->assertForbidden();

    expect($mine->installation_id)->not->toBe($theirs->installation_id)
        ->and(Installation::query()->count())->toBe(2);
});

it('refuses an installation credential that does not carry sessions:start', function (string $route): void {
    // A credential narrowed by anything -- an admin, a future revocation path, a hand-written row.
    // Without the ability check the session endpoints would admit any stored installation token.
    $narrowed = $this->installation->createToken('narrowed', [Ability::TasksCreate->value])->plainTextToken;

    $this->machine($narrowed)->postJson(route($route, ['session' => 1]))->assertUnauthorized();

    expect(AgentSession::query()->count())->toBe(0);
})->with([
    'starting a session' => ['robot-council.sessions.start'],
    'renewing one' => ['robot-council.sessions.renew'],
]);

it('refuses a revoked installation whose tokens are somehow still there', function (): void {
    [, $sessionToken] = $this->startAgentSession($this->installation);

    // Revoked without deleting anything, so the refusal can only come from the row being re-read
    // on the request rather than from the token having gone
    $this->installation->forceFill(['revoked_at' => now()])->save();

    expect(PersonalAccessToken::query()->count())->toBe(2);

    $this->machine($this->credential)->postJson(route('robot-council.sessions.start'))->assertUnauthorized();
    $this->machine($sessionToken)->getJson(route('robot-council.agent.session'))->assertUnauthorized();

    // And the installation is nowhere near its expiry, so that is not what fired
    expect($this->installation->expires_at->isFuture())->toBeTrue();
});

it('refuses a signed-in human whose own user model issues API tokens', function (string $route, string $method): void {
    // The configuration a host running Sanctum for its own API has, and the only one in which
    // Sanctum hands the human a transient token whose `can()` answers true to everything
    config()->set('auth.providers.users.model', HostUserWithTokens::class);

    $human = HostUserWithTokens::query()->whereKey($this->developer->getKey())->firstOrFail();

    $this->actingAs($human, 'web')->json($method, route($route, ['session' => 1]))->assertUnauthorized();

    expect(AgentSession::query()->count())->toBe(0);
})->with([
    'starting a session' => ['robot-council.sessions.start', 'POST'],
    'renewing one' => ['robot-council.sessions.renew', 'POST'],
    'an agent route' => ['robot-council.agent.session', 'GET'],
]);

it('rate limits renewals as well as starts, per installation', function (): void {
    config()->set('robot-council.rate_limits.sessions_per_installation', 3);

    [$session] = $this->startAgentSession($this->installation);

    for ($renewal = 0; $renewal < 3; $renewal++) {
        $this->machine($this->credential)
            ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
            ->assertOk();
    }

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertStatus(429);
});

it('answers 404 rather than a database error for a session id that is not a number', function (): void {
    // SQLite quietly matches no rows here; Postgres raises `22P02 invalid input syntax for bigint`,
    // so without the route constraint this is a 500 on the database CI actually runs
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => 'not-a-number']))
        ->assertNotFound();
});

it('rate limits an unauthenticated flood, which needs the limiter declared ahead of the guard', function (string $route): void {
    // `sortMiddleware()` reorders only middleware that are themselves in the framework's priority
    // list, relative to each other. `ThrottleRequests` is in it and neither `EnsureAgentSession`
    // nor `EnsureInstallation` is, so with one member present nothing moves and declaration order
    // decides. Declared after the guard, the limiter never runs for a request the guard refuses --
    // so a caller with no token at all costs a token lookup per request, unlimited, and both
    // limiters' `ip:` fallbacks are unreachable.
    config()->set('robot-council.rate_limits.sessions_per_installation', 2);
    config()->set('robot-council.rate_limits.agent_per_session', 2);

    for ($refused = 0; $refused < 2; $refused++) {
        $this->machine('not-a-token')->postJson(route($route))->assertStatus(401);
    }

    $this->machine('not-a-token')->postJson(route($route))->assertStatus(429);
})->with([
    'a machine route behind the installation guard' => 'robot-council.sessions.start',
    'a machine route behind the agent guard' => 'robot-council.agent.heartbeat',
]);
