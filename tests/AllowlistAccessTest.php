<?php

declare(strict_types=1);

/**
 * The access lists as a live gate: the human-route middleware and the `robot-council-admin`
 * ability both read configuration on every request, so removing an ID takes effect immediately.
 *
 * @command  vendor/bin/pest --compact tests/AllowlistAccessTest.php
 */
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Http\Middleware\EnsureAllowlistedDeveloper;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\RobotCouncilServiceProvider;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // A route standing in for the human-facing pages later slices add
    Route::middleware(['web', EnsureAllowlistedDeveloper::class])
        ->get('/robot-council-test/developer-area', fn (): string => 'developer area');
});

it('admits a developer on the access list', function (): void {
    $this->setAccessLists(developers: [4242]);

    $this->actingAs($this->enrollDeveloper(4242))
        ->get('/robot-council-test/developer-area')
        ->assertOk()
        ->assertSee('developer area');
});

it('refuses a developer removed from the access list', function (): void {
    $this->setAccessLists(developers: [4242]);
    $user = $this->enrollDeveloper(4242);

    $this->actingAs($user)->get('/robot-council-test/developer-area')->assertOk();

    // The developer leaves, and their ID comes off the list
    $this->setAccessLists(developers: []);

    $this->actingAs($user)->get('/robot-council-test/developer-area')->assertForbidden();
});

it('stops a removed developer session from authenticating the next request', function (): void {
    // Sign in for real, so the test holds a session cookie rather than an in-process user
    $this->setAccessLists(developers: [4242]);
    Socialite::fake('github', githubAccount(4242));
    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    $this->get('/robot-council-test/developer-area')->assertOk();

    $this->setAccessLists(developers: []);

    $this->get('/robot-council-test/developer-area')->assertForbidden();

    // The refusal ended the session, so the next request arrives as a visitor
    $this->get('/robot-council-test/developer-area')
        ->assertRedirect(route('robot-council.auth.redirect'));
});

it('refuses a signed-in user that has no GitHub identity', function (): void {
    $this->setAccessLists(developers: [4242]);

    $user = new User;
    $user->forceFill(['name' => 'Local Admin', 'email' => 'local@example.com'])->save();

    $this->actingAs($user)->get('/robot-council-test/developer-area')->assertForbidden();
});

it('sends a visitor who is not signed in to GitHub, keeping only the path', function (): void {
    // A request carrying somebody else's host: only the path may survive into the session
    $this->get('http://evil.example/robot-council-test/developer-area')
        ->assertRedirect(route('robot-council.auth.redirect'));

    expect(session()->get('url.intended'))->toBe('/robot-council-test/developer-area');
});

it('reads the package guard, not the host default, when checking a developer', function (): void {
    $this->setAccessLists(developers: [4242]);
    Socialite::fake('github', githubAccount(4242));
    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    // The host's default guard is a token guard this developer never used
    config()->set('auth.guards.api', ['driver' => 'token', 'provider' => 'users']);
    config()->set('auth.defaults.guard', 'api');

    $this->get('/robot-council-test/developer-area')->assertOk();
});

it('grants the admin ability only while the ID is listed as an admin', function (): void {
    $this->setAccessLists(developers: [4242], admins: [4242]);
    $user = $this->enrollDeveloper(4242);

    expect(Gate::forUser($user)->allows(RobotCouncilServiceProvider::ADMIN_ABILITY))->toBeTrue();

    // Demote the developer, who keeps developer access
    $this->setAccessLists(developers: [4242], admins: []);

    expect(Gate::forUser($user)->allows(RobotCouncilServiceProvider::ADMIN_ABILITY))->toBeFalse();

    $this->actingAs($user)->get('/robot-council-test/developer-area')->assertOk();
});

it('denies the admin ability to a developer who was never an admin', function (): void {
    $this->setAccessLists(developers: [4242], admins: [99]);

    expect(Gate::forUser($this->enrollDeveloper(4242))->allows(RobotCouncilServiceProvider::ADMIN_ABILITY))
        ->toBeFalse();
});

it('ignores an access list that is neither a string nor an array', function (): void {
    // `ROBOT_COUNCIL_ADMINS=true` reaches config as boolean true, whose string form is `1`
    config()->set('robot-council.access.admins', true);
    config()->set('robot-council.access.developers', false);

    $allowlist = app(Allowlist::class);

    expect($allowlist->admins())->toBeEmpty()
        ->and($allowlist->developers())->toBeEmpty()
        ->and($allowlist->admits(1))->toBeFalse();
});

it('records at most one identity per GitHub account', function (): void {
    // Provokes a failing query, which aborts a whole test transaction on Postgres (#473)
    $this->leaveTestTransaction();
    $this->enrollDeveloper(4242);

    $second = new User;
    $second->forceFill(['name' => 'Second', 'email' => 'second@example.com'])->save();

    expect(fn () => GithubIdentity::query()->create([
        'user_id' => $second->getKey(),
        'github_id' => 4242,
        'github_login' => 'octodev',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('accepts an access list given as an array of integers', function (): void {
    config()->set('robot-council.access.developers', [4242, 77]);

    expect(app(Allowlist::class)->developers())->toBe([4242, 77]);
});
