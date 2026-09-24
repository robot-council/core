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
 * throws is not the question; whether `migrate` completes is. It also reproduces the state that
 * causes the problem -- the old name in the `migrations` table -- instead of describing it.
 *
 * It runs `migrate --path <this file>`, which is narrower than the bare `migrate` a host runs:
 * there is no batch, so what it establishes is that this file alone does not stop, and the claim
 * about the rest of the batch follows from that rather than being exercised.
 *
 * @command  vendor/bin/pest --compact tests/IdentityMigrationRenameTest.php
 */

// Selected by the `mysql` job, which runs `--group=engine-semantics` rather than the whole
// suite. `EngineSemanticsGroupGuardTest` fails when a file that gates itself on MySQL omits
// this line, so the group cannot silently stop covering a test.
pest()->group('engine-semantics');

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
    $batch = DB::table('migrations')
        ->where('migration', '2026_09_18_000000_create_robot_council_github_identities_table')
        ->value('batch');

    DB::table('migrations')
        ->where('migration', '2026_09_18_000000_create_robot_council_github_identities_table')
        ->update(['migration' => 'create_robot_council_github_identities_table']);

    // **The workaround's row too, because the deployed host has one and this fixture cannot
    // produce it.** `fix_robot_council_github_identity_collation` ran on exactly the hosts this
    // reproduces, and #132 deleted the file -- so a run of the current migration set never writes
    // it. Inserting it is what makes the row inventory below a statement about that host rather
    // than about this fixture.
    DB::table('migrations')->insert([
        'migration' => 'fix_robot_council_github_identity_collation',
        'batch' => \is_numeric($batch) ? (int) $batch : 1,
    ]);
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

    // **The assertion the guard exists for.** Without it an unguarded `Schema::create` THROWS out
    // of `Artisan::call` -- `Illuminate\Console\Application` sets `setCatchExceptions(false)` and
    // `MigrateCommand` rethrows without `--graceful` -- so this line never returns and the test
    // fails on the exception rather than on the code. The `toBe(0)` guards the other refusal, a
    // `confirmToProceed()` in production. Either way a deployed host's migrate stops here and
    // every migration dated after this one never runs.
    expect(Artisan::call('migrate', ['--path' => identityMigrationPath(), '--realpath' => true]))->toBe(0);

    $after = Schema::getColumnListing('robot_council_github_identities');

    sort($after);

    expect($after)->toBe($before)
        // And it did not drop and recreate the table either, which would pass a column comparison
        // while losing every row.
        ->and(DB::table('robot_council_github_identities')->where('user_id', '4242')->exists())->toBeTrue()
        // **Three rows, not two.** The old create, this one, and the collation workaround #132
        // also deleted -- which spells the table `github_identity`, singular, so a `like`
        // calibrated on `%github_identities%` could not see it. That is exactly the shape of
        // miscount this assertion exists to prevent, so it matches on the shared prefix instead.
        ->and(DB::table('migrations')->where('migration', 'like', '%github_identit%')->count())->toBe(3);
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
    //
    // **On SQLite and Postgres this proves nothing about the fold-back, and that has to be said
    // rather than left to be discovered.** `collate()` returns before `change()` is ever compiled
    // off MySQL, so here it asserts only that `Schema::create`'s two `unique()` indexes work --
    // deleting the new entry from the collation migration's list leaves this green on both CI
    // engines. The fold-back is covered by the MySQL-only test below, which the `mysql` job runs
    // because this file is in the `engine-semantics` group (`robot-council/core#253`). That is the
    // honest bound on THIS assertion, and it is the same shape `CLAUDE.md` records for SQLite
    // foreign keys: it is not evidence about the fold-back, whatever engine it passes on.
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

it('does not drop a table it did not create when that batch is rolled back', function (): void {
    // **The blocker the rename creates, and the reason a guarded `up()` is not enough.** The
    // re-run logs a row at the next batch number -- alone in that batch, if #132 ships by itself --
    // so `php artisan migrate:rollback`, the most ordinary thing an operator does after a bad
    // deploy, reaches this file's `down()`. `Migrator::runDown()` resolves by file and takes no
    // `shouldRun()` hook, so nothing the `up()` guard does reaches the rollback.
    //
    // What an unguarded drop costs is not one table: every developer's sign-in resolves through
    // `HostUsers::findIdentity()`, so `GitHubCallbackController` would send each of them down the
    // enrollment path and refuse on a held email. **The suite exercises `migrate:rollback`
    // nowhere else** -- grepped -- which is why this is written against the command rather than
    // against `down()`.
    $this->migrateUsersTableWithPackageColumns();

    DB::table('robot_council_github_identities')->insert([
        'user_id' => '4242',
        'github_id' => 4242,
        'github_login' => 'octodev',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    recordTheOldMigrationName();

    expect(Artisan::call('migrate', ['--path' => identityMigrationPath(), '--realpath' => true]))->toBe(0);

    // The re-run's row is the newest batch, so a bare rollback takes exactly it.
    expect(Artisan::call('migrate:rollback'))->toBe(0);

    expect(Schema::hasTable('robot_council_github_identities'))->toBeTrue()
        ->and(DB::table('robot_council_github_identities')->where('user_id', '4242')->exists())->toBeTrue();
});

it('does drop the table it did create, so the guard is not a blanket refusal', function (): void {
    // The control. A `down()` that never dropped anything would satisfy the test above completely,
    // and a create migration whose rollback is a no-op is one that cannot be rolled back at all.
    //
    // Driven through `migrate:rollback` like its pair, on the fresh-install population: no old
    // row, so nothing says the table predates this file, so the drop must happen.
    $this->migrateUsersTableWithPackageColumns();

    Schema::drop('robot_council_github_identities');

    DB::table('migrations')
        ->where('migration', '2026_09_18_000000_create_robot_council_github_identities_table')
        ->delete();

    expect(DB::table('migrations')->where('migration', 'create_robot_council_github_identities_table')->exists())
        ->toBeFalse()
        ->and(Artisan::call('migrate', ['--path' => identityMigrationPath(), '--realpath' => true]))->toBe(0)
        ->and(Schema::hasTable('robot_council_github_identities'))->toBeTrue()
        ->and(Artisan::call('migrate:rollback'))->toBe(0)
        ->and(Schema::hasTable('robot_council_github_identities'))->toBeFalse();
});

it('keeps the unique index through the collation change that actually rebuilds the column', function (): void {
    // **The MySQL-only half, which is where `change()` does anything at all.** The collation
    // migration compiles to `alter table ... modify`, and `robot_council_github_identities.user_id`
    // is the first `unique()` column that generic code has ever rebuilt -- the other six in its
    // list are `index()` or bare. `MODIFY COLUMN` does not drop secondary indexes, which is what
    // the deleted workaround's comment asserted and nothing checked.
    //
    // Read from `information_schema` rather than from `SHOW INDEX`, whose `Non_unique` arrives as
    // an integer and was compared against a string on the first attempt -- reporting the PRIMARY
    // KEY as non-unique, which is a well-formed wrong answer.
    $this->migrateUsersTableWithPackageColumns();

    // `selectOne` is declared `mixed`, so each read is narrowed before it is believed -- a null
    // here would mean the index or the column is not there at all, which is worth failing on
    // rather than coercing into a passing zero.
    $unique = DB::table('information_schema.statistics')
        ->where('table_schema', DB::raw('database()'))
        ->where('table_name', 'robot_council_github_identities')
        ->where('column_name', 'user_id')
        ->where('index_name', 'robot_council_github_identities_user_id_unique')
        ->value('non_unique');

    expect($unique)->not->toBeNull()
        ->and((int) (\is_numeric($unique) ? $unique : 1))->toBe(0);

    // And the collation it was folded in for, on the same row.
    $collation = DB::table('information_schema.columns')
        ->where('table_schema', DB::raw('database()'))
        ->where('table_name', 'robot_council_github_identities')
        ->where('column_name', 'user_id')
        ->value('collation_name');

    expect($collation)->toBe('utf8mb4_bin');
})->skip(notMySqlFamily(...), 'MySQL only: `collate()` returns before `change()` is compiled on every other engine, so there is nothing here to rebuild.');
