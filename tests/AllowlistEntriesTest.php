<?php

declare(strict_types=1);

/**
 * Allowlist entries an administrator adds beside the environment lists (#406): the union, the
 * refusal to remove an environment entry, and the bounds the store holds.
 *
 * Every write goes through the store and every assertion that something was stored reads the
 * table, per `CLAUDE.md`: a store's returned value reports what PHP handed it, not what a row says.
 *
 * @command  vendor/bin/pest --compact tests/AllowlistEntriesTest.php
 */
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Access\AccessList;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\AllowlistRemoval;
use RobotCouncil\Http\Middleware\EnsureAllowlistedDeveloper;
use RobotCouncil\RobotCouncilServiceProvider;
use RobotCouncil\Support\AllowlistEntries;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    Route::middleware(['web', EnsureAllowlistedDeveloper::class])
        ->get('/robot-council-test/developer-area', fn (): string => 'developer area');

    // The environment names one developer and one administrator; everyone else comes from the table
    $this->setAccessLists(developers: [4242], admins: [99]);
});

/**
 * The table's rows for a list, as `github_id => login`.
 *
 * @param  AccessList  $list  The list.
 * @return array<int, string> The rows.
 */
function allowlistRows(AccessList $list): array
{
    $rows = [];

    foreach (DB::table('robot_council_allowlist_entries')->where('list', $list->value)->orderBy('github_id')->get(['github_id', 'login']) as $row) {
        $rows[intValue($row->github_id)] = stringValue($row->login);
    }

    return $rows;
}

it('admits a developer added to the table on the next request, and refuses them once removed', function (): void {
    $user = $this->enrollDeveloper(5150, login: 'new-dev');
    $entries = $this->service(AllowlistEntries::class);

    $this->actingAs($user)->get('/robot-council-test/developer-area')->assertForbidden();

    expect($entries->add(AccessList::Developer, 5150, 'new-dev', addedBy: 99))->toBeTrue()
        ->and(allowlistRows(AccessList::Developer))->toBe([5150 => 'new-dev']);

    $this->actingAs($user)->get('/robot-council-test/developer-area')->assertOk();

    expect($entries->remove(AccessList::Developer, 5150))->toBe(AllowlistRemoval::Removed)
        ->and(allowlistRows(AccessList::Developer))->toBeEmpty();

    $this->actingAs($user)->get('/robot-council-test/developer-area')->assertForbidden();
});

it('grants the admin ability to an administrator added to the table, exactly as to an environment one', function (): void {
    $added = $this->enrollDeveloper(6060, login: 'table-admin');
    $environment = $this->enrollDeveloper(99, login: 'env-admin');

    expect(Gate::forUser($added)->allows(RobotCouncilServiceProvider::ADMIN_ABILITY))->toBeFalse();

    $this->service(AllowlistEntries::class)->add(AccessList::Admin, 6060, 'table-admin');

    expect(Gate::forUser($added)->allows(RobotCouncilServiceProvider::ADMIN_ABILITY))->toBeTrue()
        ->and(Gate::forUser($environment)->allows(RobotCouncilServiceProvider::ADMIN_ABILITY))->toBeTrue()
        // An administrator is admitted, as an environment administrator is
        ->and(app(Allowlist::class)->admits(6060))->toBeTrue();

    // The union, environment first, with no duplicate for an ID in both -- a row written past the
    // store, which now refuses an ID the configuration already names
    DB::table('robot_council_allowlist_entries')->insert(['github_id' => 99, 'list' => 'admin', 'login' => 'env-admin', 'added_by' => null, 'created_at' => now()]);
    app()->forgetInstance(Allowlist::class);

    expect(app(Allowlist::class)->admins())->toBe([99, 6060]);
});

it('refuses to remove an entry that comes from configuration, says so, and leaves the account on the list', function (): void {
    $entries = $this->service(AllowlistEntries::class);

    // Also in the table, from before the store refused such a row: the refusal is the same,
    // because the environment still holds it
    DB::table('robot_council_allowlist_entries')->insert(['github_id' => 4242, 'list' => 'developer', 'login' => 'octodev', 'added_by' => null, 'created_at' => now()]);

    expect($entries->remove(AccessList::Developer, 4242))->toBe(AllowlistRemoval::FromConfiguration)
        ->and($entries->remove(AccessList::Admin, 99))->toBe(AllowlistRemoval::FromConfiguration)
        ->and(allowlistRows(AccessList::Developer))->toBe([4242 => 'octodev'])
        ->and(app(Allowlist::class)->admits(4242))->toBeTrue()
        ->and(AllowlistEntries::fromConfiguration(AccessList::Developer, 4242))
        ->toBe('GitHub user 4242 is on the developer list through the host configuration (ROBOT_COUNCIL_DEVELOPERS), so it can only be removed there.');
});

it('answers not listed for an account on neither source', function (): void {
    expect($this->service(AllowlistEntries::class)->remove(AccessList::Developer, 777))->toBe(AllowlistRemoval::NotListed);
});

it('keeps the two lists apart: a developer entry grants no admin right', function (): void {
    $this->service(AllowlistEntries::class)->add(AccessList::Developer, 5150, 'new-dev');

    expect(app(Allowlist::class)->admits(5150))->toBeTrue()
        ->and(app(Allowlist::class)->isAdmin(5150))->toBeFalse();
});

it('adds an entry once, reporting the second as already there', function (): void {
    $entries = $this->service(AllowlistEntries::class);

    expect($entries->add(AccessList::Developer, 5150, 'new-dev'))->toBeTrue()
        ->and($entries->add(AccessList::Developer, 5150, 'renamed'))->toBeFalse()
        ->and(allowlistRows(AccessList::Developer))->toBe([5150 => 'new-dev']);
});

it('refuses an ID that is not a positive whole number, and stores nothing', function (mixed $id): void {
    expect(fn () => $this->service(AllowlistEntries::class)->add(AccessList::Developer, $id, 'someone'))
        ->toThrow(InvalidArgumentException::class, 'A GitHub user ID is a positive whole number.')
        ->and(DB::table('robot_council_allowlist_entries')->count())->toBe(0);
})->with([
    'zero' => [0],
    'negative' => [-5],
    'a float' => [12.5],
    'a decimal string' => ['12.5'],
    'a signed string' => ['+12'],
    'past a bigint' => ['99999999999999999999'],
    'empty' => [''],
    'a boolean' => [true],
    'null' => [null],
]);

it('accepts an ID given as a numeric string, as the environment list does', function (): void {
    $this->service(AllowlistEntries::class)->add(AccessList::Developer, ' 5150 ', 'new-dev');

    expect(allowlistRows(AccessList::Developer))->toBe([5150 => 'new-dev']);
});

it('refuses a login that is not a GitHub login, and stores nothing', function (mixed $login): void {
    expect(fn () => $this->service(AllowlistEntries::class)->add(AccessList::Developer, 5150, $login))
        ->toThrow(InvalidArgumentException::class)
        ->and(DB::table('robot_council_allowlist_entries')->count())->toBe(0);
})->with([
    'forty characters' => [str_repeat('a', 40)],
    'a leading hyphen' => ['-dev'],
    'a double hyphen' => ['new--dev'],
    'a space' => ['new dev'],
    'markup' => ['<b>dev</b>'],
    'a trailing newline' => ["dev\n"],
    'empty' => [''],
    'not a string' => [5150],
]);

it('accepts a login of exactly 39 characters', function (): void {
    $login = str_repeat('a', 39);
    $this->service(AllowlistEntries::class)->add(AccessList::Developer, 5150, $login);

    expect(allowlistRows(AccessList::Developer))->toBe([5150 => $login]);
});

it('reads the environment lists alone when the table has not been migrated yet', function (): void {
    Schema::drop('robot_council_allowlist_entries');

    expect(app(Allowlist::class)->developers())->toBe([4242])
        ->and(app(Allowlist::class)->admits(99))->toBeTrue();
});

it('reads only a missing table as empty, on each engine, and nothing else', function (string $state, string $message, bool $missing): void {
    // Tested at the classifier rather than through a broken table, because SQLite answers a
    // misshapen table with no error at all: it reads a double-quoted name that is not a column as a
    // string literal, so `select "list"` on a table without one succeeds
    $pdo = new PDOException($message);
    $pdo->errorInfo = [$state, 0, $message];

    expect(Allowlist::isMissingTable(new QueryException('testing', 'select 1', [], $pdo)))->toBe($missing);
})->with([
    'Postgres, undefined table' => ['42P01', 'relation "robot_council_allowlist_entries" does not exist', true],
    'MySQL, no such table' => ['42S02', "Table 'x.robot_council_allowlist_entries' doesn't exist", true],
    'SQLite, no such table' => ['HY000', 'no such table: robot_council_allowlist_entries', true],
    'SQLite, no such column' => ['HY000', 'no such column: list', false],
    'a lost connection' => ['08006', 'server closed the connection unexpectedly', false],
    'permission denied' => ['42501', 'permission denied for table robot_council_allowlist_entries', false],
]);

it('reads the environment lists by the same rule as the table, refusing zero and a number past a bigint', function (): void {
    config()->set('robot-council.access.developers', '0, 4242, 007, 99999999999999999999');

    expect(app(Allowlist::class)->developers())->toBe([4242, 7]);
});

it('refuses to add an account the configuration already puts on that list, and stores nothing', function (): void {
    // A row here would change nothing today, and would keep the account admitted after the host
    // took it out of its configuration
    expect(fn () => $this->service(AllowlistEntries::class)->add(AccessList::Developer, 4242, 'octodev'))
        ->toThrow(InvalidArgumentException::class, 'GitHub user 4242 is already on the developer list through the host configuration (ROBOT_COUNCIL_DEVELOPERS). Nothing changed.')
        ->and(DB::table('robot_council_allowlist_entries')->count())->toBe(0);

    // The other list is a different question: an environment developer can be made an administrator
    expect($this->service(AllowlistEntries::class)->add(AccessList::Admin, 4242, 'octodev'))->toBeTrue();
});
