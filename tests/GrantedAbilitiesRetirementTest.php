<?php

declare(strict_types=1);

/**
 * That `granted_abilities` is gone, from the schema, from the package, and from the wire.
 *
 * **The column decided nothing for two releases before it could be removed**, and the gap was a
 * compatibility one rather than a design one: `robot-council/core#221` gave a session token its
 * `Access\Role` preset, `#222` removed the column's one authorization reader, `#231` retired the
 * controls that wrote it -- and a released `robot-council/cli` went on reading the key out of the
 * enrollment response. `robot-council/cli#152` stopped, and `robot-council/cli` v0.3.0 shipped it.
 *
 * **The asymmetry that decided the order is worth keeping in view.** A column kept one release too
 * long costs a dead accessor. A column dropped one release too early cannot be reconstructed --
 * nothing derives what an administrator once granted -- so the repair would have been asking every
 * developer to re-enroll.
 *
 * @command  vendor/bin/pest --compact tests/GrantedAbilitiesRetirementTest.php
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Models\Installation;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

/**
 * Run the drop migration in one direction.
 *
 * Narrowed rather than called as `$migration->up()`, for the reason `tests/EventIndexDropTest.php`
 * records: a migration file returns `mixed` to the analyzer, and `Migration` declares no `up()` --
 * the anonymous class the file returns does.
 *
 * @param  string  $direction  `up` or `down`.
 */
function runTheGrantedAbilitiesMigration(string $direction): void
{
    $migration = require __DIR__.'/../database/migrations/2026_09_24_000001_drop_granted_abilities_columns.php';

    $run = [$migration, $direction];

    if (! \is_callable($run)) {
        throw new RuntimeException('The migration file did not return something with a '.$direction.'().');
    }

    $run();
}

/**
 * Whether a table still carries the column, on either of the two tables that had one.
 *
 * @return array{installations: bool, device_codes: bool} What the schema reports.
 */
function grantedAbilitiesPresence(): array
{
    return [
        'installations' => Schema::hasColumn('robot_council_installations', 'granted_abilities'),
        'device_codes' => Schema::hasColumn('robot_council_device_codes', 'granted_abilities'),
    ];
}

/**
 * Whether a file's code reaches the attribute, rather than merely naming the migration that drops it.
 *
 * **A bare substring cannot do this**, because `Support\PackageMigrations`'s manifest lists
 * `2026_09_24_000001_drop_granted_abilities_columns` -- the file whose job is the removal. A scan
 * that reported it would be asking the package to forget the migration it ships. The two access
 * forms are the quoted column name and the property fetch, and neither appears inside that name.
 *
 * @param  string  $code  One file's source, with comments already stripped.
 * @return bool Whether it reads or writes the attribute.
 */
function readsGrantedAbilities(string $code): bool
{
    return str_contains($code, "'granted_abilities'")
        || str_contains($code, '"granted_abilities"')
        || str_contains($code, '->granted_abilities');
}

it('leaves all three populations in the same state', function (): void {
    // **The three a host can be in, driven through the migration's own `up()` and `down()`.** A
    // host that installed before the epic had both columns; one that installed after `#231` still
    // had them, because that ticket retired the controls and not the schema; and one that rolled
    // this back has them restored. All three run the same file, and `CLAUDE.md` records what
    // happens when a migration assumes one of them: `#54` shipped an access-control change
    // covering six of seven columns and reported success.
    $after = grantedAbilitiesPresence();

    expect($after)->toBe(['installations' => false, 'device_codes' => false]);

    // Rolled back: a host that took this and then reversed it.
    runTheGrantedAbilitiesMigration('down');

    expect(grantedAbilitiesPresence())->toBe(['installations' => true, 'device_codes' => true]);

    // Migrated again: the population that re-runs it. A guard reading `hasColumn` the wrong way
    // round would drop nothing here and report success.
    runTheGrantedAbilitiesMigration('up');

    expect(grantedAbilitiesPresence())->toBe($after);

    // And running `up()` against a database that has already taken it is a no-op rather than an
    // error, which is the population an unguarded `dropColumn` stops the whole batch on.
    runTheGrantedAbilitiesMigration('up');

    expect(grantedAbilitiesPresence())->toBe($after);
});

it('restores the installation column NOT NULL, which is what the create migration declares', function (): void {
    // **A rollback that changed a column's nullability leaves a host in a state neither migration
    // describes.** The installation's column is NOT NULL and the device code's is nullable, and
    // `down()` restates both rather than inferring either.
    $installation = $this->approveInstallation($this->developer);

    runTheGrantedAbilitiesMigration('down');

    // Every existing row got an empty list, because nothing derives what was there. Read from the
    // row rather than from the model, which has no cast for a column that does not normally exist.
    $stored = DB::table('robot_council_installations')->where('id', $installation->getKey())->value('granted_abilities');

    expect($stored)->toBe('[]');

    // NOT NULL, asserted by asking the database to break it rather than by reading a schema field
    // whose spelling differs per engine.
    //
    // **`QueryException` rather than `Throwable`, and the difference is not cosmetic.** Pest's
    // `toThrow()` treats a string as a class name only when `class_exists()` says so, and
    // `Throwable` is an INTERFACE -- so it fell back to matching the string as a substring of the
    // exception MESSAGE, and reported `Expected: SQLSTATE[23502] ... To contain: Throwable` while
    // the database had refused the write exactly as intended. The constraint was right and the
    // assertion was wrong, which is the direction that wastes an afternoon.
    expect(fn (): mixed => DB::table('robot_council_installations')
        ->where('id', $installation->getKey())
        ->update(['granted_abilities' => null]))->toThrow(QueryException::class);

    runTheGrantedAbilitiesMigration('up');
})->skip(
    fn (): bool => DB::connection()->getDriverName() === 'sqlite',
    'SQLite does not enforce NOT NULL on a column added by a rebuild of the table.'
);

it('reads no installation ability anywhere in the package, asserted rather than reviewed', function (): void {
    // **The acceptance criterion of `robot-council/core#239`, as a scan rather than a claim.**
    // Its predecessor in `SessionRoleTest` planted a value in the column and showed nothing read
    // it; there is no column to plant in now, so the property becomes structural: no source file
    // mentions the attribute at all.
    //
    // Comments are stripped first, because this package documents what it removed beside the code
    // that removed it -- the migration's own docblock names the column a dozen times, and a scan
    // that read prose would report the file whose job is the removal.
    $offenders = [];

    foreach (phpSourcesIn(__DIR__.'/../src') as $path) {
        if (readsGrantedAbilities(sourceWithoutComments($path))) {
            $offenders[] = basename($path);
        }
    }

    sort($offenders);

    expect($offenders)->toBeEmpty();

    // The control: the scan can see the string, so an empty result above is an absence rather than
    // a broken walk. Without this the assertion passes identically against a mistyped directory.
    expect(phpSourcesIn(__DIR__.'/../src'))->not->toBeEmpty();

    $planted = $this->temporaryDirectory('granted-abilities-scan');

    file_put_contents($planted.'/Reader.php', "<?php\n\nreturn \$row->granted_abilities;\n");
    file_put_contents($planted.'/Prose.php', "<?php\n\n// granted_abilities is gone.\nreturn true;\n");

    $found = [];

    foreach (phpSourcesIn($planted) as $path) {
        if (readsGrantedAbilities(sourceWithoutComments($path))) {
            $found[] = basename($path);
        }
    }

    expect($found)->toBe(['Reader.php']);
});

it('has no accessor left on the model, and no cast for the column', function (): void {
    // **`method_exists()` is deliberately NOT asserted here**, and the reason is worth keeping:
    // PHPStan proves it statically and reports the call as `function.impossibleType`, so writing it
    // would trade a stronger guarantee for a weaker one and fail the analysis gate to do it. The
    // accessor's removal is enforced by `composer analyse` refusing any caller of it.
    //
    // What a test can still say is what the model carries, which the analyzer does not check
    // against a live row.
    $installation = $this->approveInstallation($this->developer);

    expect($installation->getCasts())->not->toHaveKey('granted_abilities')
        ->and($installation->getAttributes())->not->toHaveKey('granted_abilities');
});
