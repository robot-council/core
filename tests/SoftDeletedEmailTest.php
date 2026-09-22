<?php

declare(strict_types=1);

/**
 * A developer whose GitHub email belongs to a user the host model cannot see.
 *
 * **The model and the index disagree, and the gap is a permanent lockout.** `HostUsers::findByEmail()`
 * reads through `$model::query()`, which applies the host model's global scopes -- so a host using
 * `SoftDeletes` cannot see a trashed user, while the `users.email` unique index still can. The
 * developer reads as "no such user", is sent down the create path, and collides with an index that
 * disagrees. That arrived as a bare `409` with nothing in the response or the log naming the cause,
 * on every attempt, forever, and the handler's comment blamed a race that had not happened (#38).
 *
 * Any global scope does this. A tenant scope hides a row exactly as a soft delete does, and the
 * index does not care which one it was.
 *
 * @command  vendor/bin/pest --compact tests/SoftDeletedEmailTest.php
 */

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Support\HostUsers;
use RobotCouncil\Tests\Fixtures\SoftDeletingHostUser;

beforeEach(function (): void {
    $directory = $this->temporaryDirectory('migrations');

    File::copy(
        __DIR__.'/../database/stubs/add_robot_council_columns_to_users_table.php.stub',
        $directory.'/2026_01_01_000000_add_robot_council_columns_to_users_table.php'
    );

    // The host's table with the package's columns AND the host's own soft deletes, which is the
    // combination a real application has.
    $this->migrateFresh($directory, __DIR__.'/Fixtures/soft-deletes');

    config()->set('auth.providers.users.model', SoftDeletingHostUser::class);

    $this->setAccessLists(developers: [4242]);
});

/**
 * The address the fixture account signs in with.
 */
const TRASHED_EMAIL = 'octodev@example.com';

it('refuses with an explanation when a trashed user holds the email', function (): void {
    $user = SoftDeletingHostUser::query()->create(['name' => 'Someone Else', 'email' => TRASHED_EMAIL]);
    $user->delete();

    // The control for the whole premise: the row is invisible to the model and visible to the
    // table. If these ever agreed, this test would be describing nothing.
    expect(SoftDeletingHostUser::query()->count())->toBe(0)
        ->and(SoftDeletingHostUser::query()->withTrashed()->count())->toBe(1);

    Socialite::fake('github', githubAccount(4242, email: TRASHED_EMAIL));

    $response = $this->get(route('robot-council.auth.callback'));

    $response->assertStatus(409);

    // **Asserted on the exception, not on the rendered page, and the difference is a bound worth
    // knowing.** Symfony's default error renderer prints "An Error Occurred: Conflict" and drops
    // the message, deliberately, so what this package can supply is the message -- and a host that
    // publishes `errors/409.blade.php` is what puts it in front of a person. Measured: the body of
    // this response contains no part of the sentence below.
    expect($response->exception?->getMessage())->toContain('deleted or is otherwise hidden')
        ->and($response->exception?->getMessage())->toContain('administrator')

        // The bound itself, pinned rather than left implied: if a future Laravel started rendering
        // the message, this line is what would notice.
        ->and($response->getContent())->not->toContain('deleted or is otherwise hidden');

    $this->assertGuest();
});

it('writes no user and no identity when it refuses', function (): void {
    $user = SoftDeletingHostUser::query()->create(['name' => 'Someone Else', 'email' => TRASHED_EMAIL]);
    $user->delete();

    Socialite::fake('github', githubAccount(4242, email: TRASHED_EMAIL));

    $this->get(route('robot-council.auth.callback'))->assertStatus(409);

    expect(SoftDeletingHostUser::query()->withTrashed()->count())->toBe(1)
        ->and(GithubIdentity::query()->count())->toBe(0);
});

it('says which of the two collisions it was, in the log', function (): void {
    $user = SoftDeletingHostUser::query()->create(['name' => 'Someone Else', 'email' => TRASHED_EMAIL]);
    $user->delete();

    $messages = [];

    // Typed rather than cast. `MessageLogged::$message` is `mixed` until the parameter names the
    // event, after which narrowing it is dead code -- which is the trap CLAUDE.md records about
    // writing a check only one analyzer side asks for.
    Log::listen(function (MessageLogged $entry) use (&$messages): void {
        $messages[] = $entry->message;
    });

    Socialite::fake('github', githubAccount(4242, email: TRASHED_EMAIL));

    $this->get(route('robot-council.auth.callback'))->assertStatus(409);

    // Distinguishable from the visible-collision line, which says "another user holds", and from
    // the race line. An operator reading the log has to be able to tell which state this was.
    expect(implode("\n", $messages))->toContain('the host model cannot see');
});

it('still signs in a developer whose email nobody holds', function (): void {
    // The other side of the bound. Adding the check must not refuse the ordinary path, and a test
    // that only asserted the refusal would pass on an implementation that refused everybody.
    Socialite::fake('github', githubAccount(4242, email: 'nobody-holds-this@example.com'));

    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    $this->assertAuthenticated();

    expect(SoftDeletingHostUser::query()->count())->toBe(1);
});

it('still refuses a visible user holding the email, with the older message', function (): void {
    // A live user holding the address is a different refusal and keeps its own wording, so the two
    // states stay distinguishable to whoever reads the response.
    SoftDeletingHostUser::query()->create(['name' => 'Someone Else', 'email' => TRASHED_EMAIL]);

    Socialite::fake('github', githubAccount(4242, email: TRASHED_EMAIL));

    $response = $this->get(route('robot-council.auth.callback'));

    $response->assertStatus(409);

    expect($response->exception?->getMessage())->not->toContain('deleted or is otherwise hidden');

    $this->assertGuest();
});

it('asks the table rather than the model', function (): void {
    $user = SoftDeletingHostUser::query()->create(['name' => 'Someone Else', 'email' => TRASHED_EMAIL]);
    $user->delete();

    $hostUsers = app(HostUsers::class);

    // The two questions, side by side. `findByEmail()` is the model's answer and stays the model's
    // answer; `emailIsHeld()` is the table's, and the table is what the unique index enforces.
    expect($hostUsers->findByEmail(TRASHED_EMAIL))->toBeNull()
        ->and($hostUsers->emailIsHeld(TRASHED_EMAIL))->toBeTrue()
        ->and($hostUsers->emailIsHeld('nobody-holds-this@example.com'))->toBeFalse();
});

it('matches the address case-insensitively, as the visible check does', function (): void {
    $user = SoftDeletingHostUser::query()->create(['name' => 'Someone Else', 'email' => TRASHED_EMAIL]);
    $user->delete();

    // The unique index is case-sensitive on some collations and not others, so the check has to be
    // the looser of the two or it would miss exactly the rows it exists to catch.
    expect(app(HostUsers::class)->emailIsHeld(strtoupper(TRASHED_EMAIL)))->toBeTrue();
});
