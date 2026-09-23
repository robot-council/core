<?php

declare(strict_types=1);

/**
 * The manifest names every migration this package ships, and the guard is what keeps it that way.
 *
 * **It runs with no database**, like `tests/MigrationPrefixGuardTest.php` and for the same reason:
 * the rule is about what the package **declares**, so it fails in the pull request that adds a
 * migration rather than in whichever job happens to have a schema.
 *
 * **Why the manifest is named rather than derived.** A check that speaks about "this package's
 * migrations" has to know which rows in a host's `migrations` table are its own, and the table
 * holds the host's and every other package's too. The obvious narrowing -- a substring test on
 * `robot_council` -- is already wrong for one of this package's own files, which is the measurement
 * below (#169).
 *
 * @command  vendor/bin/pest --compact tests/MigrationManifestGuardTest.php
 */

use RobotCouncil\Support\PackageMigrations;

it('lists every migration it ships', function (): void {
    // The one that fails in the pull request that forgets. `unlisted()` is `shipped \ manifest`,
    // and a non-empty result means every answer computed against the manifest is unreliable --
    // which is why `robot-council:doctor` fails on it rather than reporting it.
    expect(PackageMigrations::unlisted())->toBeEmpty();
});

it('reads the manifest against the directory rather than against itself', function (): void {
    // **The control the assertion above needs.** `toBe([])` passes just as well when the directory
    // cannot be read and `shipped()` returns nothing, which is a broken instrument reporting clean.
    // So: the directory exists, it holds files, and every one of them is a name the manifest has.
    $shipped = PackageMigrations::shipped();

    expect($shipped)->not->toBeEmpty()
        ->and(PackageMigrations::directory())->toBeDirectory()
        // Named rather than counted, so a file added without a manifest entry says which.
        ->and(array_values(array_diff($shipped, PackageMigrations::EVER_SHIPPED)))->toBeEmpty();
});

it('keeps a retired name rather than dropping it when the file goes', function (): void {
    // **A rename is two entries, not one changed entry.** The old name has to stay, because a host
    // that ran it still carries the row and removing the entry makes that row unidentifiable again
    // -- which is the state this whole mechanism exists to end.
    //
    // #132's two are the case on record: it dated `create_robot_council_github_identities_table`
    // and removed the `f...` sort workaround that existed only to sort after it.
    expect(PackageMigrations::retired())->toBe([
        'create_robot_council_github_identities_table',
        'fix_robot_council_github_identity_collation',
    ]);
});

it('cannot identify this package by a substring, which is why the manifest is named', function (): void {
    // **The measurement the design rests on, asserted so it cannot rot into a comment.** If every
    // shipped migration happened to contain `robot_council`, a future reader would reasonably ask
    // why a hand-maintained list exists at all. One does not, and this names it.
    $withoutTheSubstring = array_values(array_filter(
        PackageMigrations::shipped(),
        static fn (string $name): bool => ! str_contains($name, 'robot_council')
    ));

    // **`not->toBeEmpty()`, not set equality.** Pinning the exact list would go red when a second
    // such name is added -- a failure saying "the substring test is even MORE wrong than recorded",
    // which is the design's thesis strengthening rather than breaking. This fails on exactly the
    // one thing that would undermine it: every shipped name containing `robot_council`.
    expect($withoutTheSubstring)->not->toBeEmpty();
});

it('lists each name once', function (): void {
    // **This is the only thing here that its neighbors do not already imply**, and an earlier
    // version of this test also asserted `count(EVER_SHIPPED) === count(shipped) + count(retired)`,
    // which they do: given a duplicate-free `shipped()`, that identity follows from `unlisted()`
    // being empty and this line. It was two assertions where there was one.
    //
    // The property the old title claimed -- that the manifest records nothing that was never a
    // file -- cannot be checked against the disk for a retired entry, whose file is gone by
    // definition. What catches a fabricated entry is the `retired()` assertion above, which names
    // the two that are allowed to be there.
    expect(array_values(array_unique(PackageMigrations::EVER_SHIPPED)))
        ->toBe(PackageMigrations::EVER_SHIPPED);
});

it('reads a directory it is pointed at, which is what makes the check testable', function (): void {
    // The seam `Support\Doctor` uses so its migration checks can be driven without writing a probe
    // into the tracked tree. Asserted here so the parameter cannot be quietly dropped.
    $directory = sys_get_temp_dir().'/rc-seam-'.bin2hex(random_bytes(6));

    mkdir($directory);

    try {
        // **A listed name FIRST, then an unlisted one**, which is what makes the re-indexing
        // observable: `array_diff` preserves keys, so the survivor here sits at key 1 and a result
        // that skipped `array_values()` would be `[1 => ...]`. With one file, or with the unlisted
        // one first, the keys coincide and the mutant lives.
        file_put_contents($directory.'/2026_09_18_000001_create_robot_council_installations_table.php', "<?php\n");
        file_put_contents($directory.'/2099_01_01_000000_not_ours.php', "<?php\n");

        expect(PackageMigrations::shipped($directory))->toBe([
            '2026_09_18_000001_create_robot_council_installations_table',
            '2099_01_01_000000_not_ours',
        ])
            ->and(PackageMigrations::unlisted($directory))->toBe(['2099_01_01_000000_not_ours'])
            // And the real tree still answers for itself.
            ->and(PackageMigrations::unlisted())->toBeEmpty();
    } finally {
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

it('ignores a file the migrator itself would not run', function (): void {
    // `Migrator::getMigrationFiles()` globs `*_*.php`, so a name with no underscore is never run
    // and must not be reported as shipped -- it would read as permanently pending in one check and
    // as an unlisted migration in the other, for a file that can do nothing.
    $directory = sys_get_temp_dir().'/rc-glob-'.bin2hex(random_bytes(6));

    mkdir($directory);

    try {
        file_put_contents($directory.'/probe.php', "<?php\n");
        file_put_contents($directory.'/2099_01_01_000000_real.php', "<?php\n");

        expect(PackageMigrations::shipped($directory))->toBe(['2099_01_01_000000_real']);
    } finally {
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

it('returns a list when only the later retired name was run', function (): void {
    // **`retiredAmong()` re-indexes, and only this input shows it.** `array_intersect` keeps the
    // first array's keys, so matching just the SECOND retired name yields key 1. A host that ran
    // only the collation workaround is the real shape of that -- and the diagnosis message uses
    // `implode`, which ignores keys, so nothing downstream would ever notice.
    expect(PackageMigrations::retiredAmong(['fix_robot_council_github_identity_collation']))
        ->toBe(['fix_robot_council_github_identity_collation']);

    // Both, in the host's reverse order, still come back in manifest order.
    expect(PackageMigrations::retiredAmong([
        'fix_robot_council_github_identity_collation',
        'create_robot_council_github_identities_table',
    ]))->toBe([
        'create_robot_council_github_identities_table',
        'fix_robot_council_github_identity_collation',
    ]);

    // And a row that is not this package's matches nothing.
    expect(PackageMigrations::retiredAmong(['2019_08_19_000000_create_failed_jobs_table']))->toBeEmpty();
});
