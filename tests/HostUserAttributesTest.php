<?php

declare(strict_types=1);

/**
 * Letting a host decide what a developer's first user row carries.
 *
 * `HostUsers::create()` wrote `name` and `email`, and `robot-council:install` relaxes nullability on
 * `users.password` and `users.email` because those are the two the framework's own skeleton makes
 * `NOT NULL`. Any other `NOT NULL` column without a default -- `tenant_id`, `organization_id`,
 * `role_id`, a `first_name`/`last_name` pair -- is ordinary in a real application, and it failed the
 * **very first** GitHub sign-in with a raw integrity error, after the OAuth round trip. A host could
 * not fix it from outside: `HostUsers` is `final`, the `strict()` preset forbids `protected`, and
 * nothing dispatched an event or took a callback (#36).
 *
 * The extension point is a contract bound in the container, matching `Contracts\DrawsUserCodes`,
 * which is the one this package already had. One binding means one implementation, so two
 * registrations cannot silently fight, and a host that binds nothing gets the package's default.
 *
 * @command  vendor/bin/pest --compact tests/HostUserAttributesTest.php
 */

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use RobotCouncil\Support\Contracts\SuppliesUserAttributes;
use RobotCouncil\Support\NewDeveloper;
use RobotCouncil\Support\UserAttributes;
use RobotCouncil\Tests\Fixtures\TenantUserAttributes;

beforeEach(function (): void {
    $this->setAccessLists(developers: [4242]);

    // A closure rather than a plain function, because `temporaryDirectory()` and `migrateFresh()`
    // are protected: Pest binds a test's closures to the case, and a global function is not bound.
    $this->migrateHostUsers = function (bool $withTenantColumns): void {
        $directory = $this->temporaryDirectory('migrations');

        File::copy(
            __DIR__.'/../database/stubs/add_robot_council_columns_to_users_table.php.stub',
            $directory.'/2026_01_01_000000_add_robot_council_columns_to_users_table.php'
        );

        $withTenantColumns
            ? $this->migrateFresh($directory, __DIR__.'/Fixtures/tenant-users')
            : $this->migrateFresh($directory);
    };
});

it('signs in a developer whose users table needs a column the package cannot guess', function (): void {
    ($this->migrateHostUsers)(true);

    $this->container()->bind(SuppliesUserAttributes::class, TenantUserAttributes::class);

    Socialite::fake('github', githubAccount(4242));

    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    $this->assertAuthenticated();

    $user = User::query()->sole();

    // Both columns, because one would pass on an implementation that happened to fill the other.
    // `external_ref` is derived from the account, so it also shows the contract was handed who is
    // signing in rather than being asked for a constant.
    expect($user->getAttribute('tenant_id'))->toEqual(TenantUserAttributes::TENANT_ID)
        ->and($user->getAttribute('external_ref'))->toBe('github:4242')
        ->and($user->getAttribute('email'))->toBe('octo@example.com');
});

it('fails that same sign-in without the binding, which is the defect', function (): void {
    // **The control for the whole ticket.** Same schema, same account, package default. If this
    // ever passed, the test above would be proving nothing about the extension point.
    ($this->migrateHostUsers)(true);

    Socialite::fake('github', githubAccount(4242));

    expect(fn () => $this->withoutExceptionHandling()->get(route('robot-council.auth.callback')))
        ->toThrow(QueryException::class)
        ->and(User::query()->count())->toBe(0);
});

it('changes nothing for a host that binds nothing', function (): void {
    ($this->migrateHostUsers)(false);

    // The default is what `HostUsers::create()` wrote before the contract existed, so an ordinary
    // users table keeps working without anybody reading about any of this.
    // The class name rather than `toBeInstanceOf`, which the analyzer can resolve statically and
    // then reports as a redundant assertion. The binding is still what is being checked.
    expect($this->container()->make(SuppliesUserAttributes::class)::class)->toBe(UserAttributes::class);

    Socialite::fake('github', githubAccount(4242));

    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    $user = User::query()->sole();

    expect($user->getAttribute('name'))->toBe('octodev')
        ->and($user->getAttribute('email'))->toBe('octo@example.com');
});

it('hands the contract the account that is signing in', function (): void {
    ($this->migrateHostUsers)(false);

    $attributes = new UserAttributes;

    $developer = new NewDeveloper(4242, 'octodev', 'octo@example.com', 'https://example.com/a.png');

    expect($attributes->for($developer))->toBe(['name' => 'octodev', 'email' => 'octo@example.com'])
        ->and($developer->githubId)->toBe(4242)
        ->and($developer->avatarUrl)->toBe('https://example.com/a.png');
});

it('tolerates an account that exposes no email', function (): void {
    ($this->migrateHostUsers)(false);

    // `users.email` is nullable after `robot-council:install`, and a GitHub account need not expose
    // an address, so the default has to carry the null through rather than refuse it.
    expect((new UserAttributes)->for(new NewDeveloper(4242, 'octodev')))
        ->toBe(['name' => 'octodev', 'email' => null]);
});

it('names the columns a first sign-in would fail on, at install time', function (): void {
    ($this->migrateHostUsers)(true);

    Artisan::call('robot-council:install');

    $output = Artisan::output();

    // The point of the warning: the names, so a host knows what its binding has to cover.
    expect($output)->toContain('tenant_id')
        ->and($output)->toContain('external_ref')
        ->and($output)->toContain('SuppliesUserAttributes');
});

it('says nothing at install time when every column is fillable', function (): void {
    ($this->migrateHostUsers)(false);

    Artisan::call('robot-council:install');

    // Asserted as well as the positive case, because a command that always warned would pass the
    // test above and tell a host nothing.
    expect(Artisan::output())->not->toContain('NOT NULL with no default');
});

it('does not report the columns the package itself fills', function (): void {
    ($this->migrateHostUsers)(false);

    // `name` is NOT NULL with no default on the framework's own users table, and the package writes
    // it. Reporting it would be a warning nobody can act on, on every install.
    expect(Schema::hasColumn('users', 'name'))->toBeTrue();

    Artisan::call('robot-council:install');

    expect(Artisan::output())->not->toContain('name');
});
