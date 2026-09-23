<?php

declare(strict_types=1);

/**
 * What the rename in #132 does to a host that already migrated.
 *
 * `create_robot_council_github_identities_table.php` carried no date prefix, so it ran after every
 * dated migration and nothing dated could alter the table it creates. #132 renamed it to
 * `2026_09_18_000000_...`, which fixes the ordering and creates one new problem: a host that
 * migrated before the rename has a `migrations` row for the OLD name, so the new name is **pending**
 * to it and Laravel runs the file a second time.
 *
 * An unguarded `Schema::create` on an existing table is an error that stops the whole `migrate`,
 * taking every later migration with it. The guard makes the second run a no-op.
 *
 * **Driven through `migrate` rather than by calling `up()` directly.** Whether the file's method
 * throws is not the question; whether a deployed host's `php artisan migrate` completes is. Running
 * it the way the host does also reproduces the state that causes the problem -- the old name in the
 * `migrations` table -- instead of describing it.
 *
 * @command  vendor/bin/pest --compact tests/IdentityMigrationRenameTest.php
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The path of the renamed create migration, as `migrate --path` takes it.
 */
function identityMigrationPath(): string
{
    return realpath(__DIR__.'/../database/migrations/2026_09_18_000000_create_robot_council_github_identities_table.php')
        ?: '';
}

/**
 * Put the `migrations` table into the state a host that migrated before #132 is in.
 *
 * The row names the file under the name it had then, so the file's current name is pending and
 * Laravel will run it again.
 */
function recordTheOldMigrationName(): void
{
    DB::table('migrations')
        ->where('migration', '2026_09_18_000000_create_robot_council_github_identities_table')
        ->update(['migration' => 'create_robot_council_github_identities_table']);
}

it('completes a migrate on a host that ran the old name, and keeps the table', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    expect(identityMigrationPath())->not->toBeEmpty()
        ->and(Schema::hasTable('robot_council_github_identities'))->toBeTrue();

    $before = Schema::getColumnListing('robot_council_github_identities');

    sort($before);

    DB::table('robot_council_github_identities')->insert([
        'user_id' => '4242',
        'github_id' => 4242,
        'github_login' => 'octodev',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    recordTheOldMigrationName();

    // The file is pending again, exactly as it is to a deployed host.
    expect(DB::table('migrations')->where('migration', 'create_robot_council_github_identities_table')->exists())
        ->toBeTrue();

    // **The assertion the guard exists for.** Without it this exits non-zero and a deployed host's
    // migrate stops here, so every migration dated after this one never runs.
    expect(Artisan::call('migrate', ['--path' => identityMigrationPath(), '--realpath' => true]))->toBe(0);

    $after = Schema::getColumnListing('robot_council_github_identities');

    sort($after);

    expect($after)->toBe($before)
        // And it did not drop and recreate the table either, which would pass a column comparison
        // while losing every row.
        ->and(DB::table('robot_council_github_identities')->where('user_id', '4242')->exists())->toBeTrue()
        // The host now carries two rows naming one table, which is the cost the migration's
        // docblock names. Nothing reads it, and asserting it here keeps it from surprising anybody.
        ->and(DB::table('migrations')->where('migration', 'like', '%github_identities%')->count())->toBe(2);
});

it('creates the table when it is genuinely absent, so the guard is not a no-op', function (): void {
    // The control. A guard that returned early always would satisfy the test above completely, and
    // a create migration that never creates is a worse defect than the one being fixed.
    $this->migrateUsersTableWithPackageColumns();

    Schema::drop('robot_council_github_identities');

    recordTheOldMigrationName();

    expect(Schema::hasTable('robot_council_github_identities'))->toBeFalse()
        ->and(Artisan::call('migrate', ['--path' => identityMigrationPath(), '--realpath' => true]))->toBe(0)
        ->and(Schema::hasTable('robot_council_github_identities'))->toBeTrue()
        ->and(Schema::hasColumn('robot_council_github_identities', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('robot_council_github_identities', 'github_id'))->toBeTrue();
});

it('keeps one identity per host user after the collation migration redefines the column', function (): void {
    // **The risk the fold-back creates, and the reason it is asserted behaviorally.**
    // `2026_09_22_000002`'s `collate()` is generic code that rebuilds a column with
    // `$table->string(...)->change()`, restating only the length, the collation and the
    // nullability. `robot_council_github_identities.user_id` is the first `unique()` column it has
    // ever touched -- the other six are not -- and `change()` redefines rather than amends. If the
    // unique index did not survive, two host users could claim one GitHub identity, which is an
    // access-control failure rather than a storage one.
    //
    // Asked of the database rather than of the schema: an index NAME ending `_unique` is not
    // evidence that it is unique, and reading `Non_unique` compared the wrong type on the first
    // attempt and reported the primary key as non-unique. A refused insert cannot be misread.
    $this->migrateUsersTableWithPackageColumns();

    DB::table('robot_council_github_identities')->insert([
        'user_id' => '4242',
        'github_id' => 4242,
        'github_login' => 'octodev',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('robot_council_github_identities')->insert([
        'user_id' => '4242',
        'github_id' => 9999,
        'github_login' => 'impostor',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // And the other direction, because the table carries two unique columns and the migration
    // redefines only one of them.
    expect(fn () => DB::table('robot_council_github_identities')->insert([
        'user_id' => '77',
        'github_id' => 4242,
        'github_login' => 'impostor',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // The control: a genuinely distinct identity is accepted, so the two refusals above are the
    // unique indexes rather than the insert being broken.
    DB::table('robot_council_github_identities')->insert([
        'user_id' => '77',
        'github_id' => 77,
        'github_login' => 'octoadmin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('robot_council_github_identities')->count())->toBe(2);
});
