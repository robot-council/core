<?php

declare(strict_types=1);

/**
 * The standing guarantee that every package migration carries a date prefix.
 *
 * **An unprefixed migration always runs last, so nothing dated can ever alter what it creates.**
 * Laravel runs migrations in filename order and digits sort before letters, so
 * `create_robot_council_github_identities_table.php` ran after every `2026_*` file. That is not a
 * style question: #54 gave the seven columns holding a host user key a binary collation on MySQL,
 * its migration is dated, it therefore ran before that table existed, and its `Schema::hasTable()`
 * guard **skipped the column silently**. An access-control change shipped covering six of seven and
 * reported success, and it was caught only because that change asserts the collation of every key
 * column by name.
 *
 * The workaround was a second unprefixed file named `f...` so it would sort after `c...`. #132
 * dated the create instead and removed the workaround, and this is what stops the next one.
 *
 * **A scan of the source rather than a behavior**, for the reason `MigrationTimestampGuardTest`
 * records: the rule is about what the directory contains, so it fails in the pull request that adds
 * the file rather than in whichever job happens to have the right database, runs on every engine,
 * and costs no CI time.
 *
 * @command  vendor/bin/pest --compact tests/MigrationPrefixGuardTest.php
 */

/**
 * The migrations in a directory whose names carry no date prefix.
 *
 * Laravel's own convention is `Y_m_d_His_name`, and what actually decides the ordering is that the
 * name begins with digits -- so that is what this asks, rather than matching the full shape. A file
 * named `2026_09_18_000000_x.php` and one named `20260918000000_x.php` both sort before every
 * letter, and neither is the defect this exists to catch.
 *
 * @param  string  $directory  The directory to read.
 * @return list<string> The offending basenames, sorted.
 */
function unprefixedMigrationsIn(string $directory): array
{
    $offenders = [];

    foreach (phpSourcesIn($directory) as $path) {
        $name = basename($path);

        if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_/', $name) !== 1) {
            $offenders[] = $name;
        }
    }

    sort($offenders);

    return $offenders;
}

it('tells a prefixed migration from an unprefixed one', function (string $name, bool $offends): void {
    // **The detector is run against names whose right answer is known before it is trusted on the
    // real tree**, which is `EscapingGuardTest`'s shape and the reason it exists: a scanner whose
    // expression never matches reports a clean directory in exactly the same words as a clean one.
    $directory = sys_get_temp_dir().'/rc-prefix-'.bin2hex(random_bytes(6));

    mkdir($directory);

    try {
        file_put_contents($directory.'/'.$name, "<?php\n");

        expect(unprefixedMigrationsIn($directory))->toBe($offends ? [$name] : []);
    } finally {
        @unlink($directory.'/'.$name);
        @rmdir($directory);
    }
})->with([
    'the shape Laravel generates' => ['2026_09_18_000000_create_a_table.php', false],
    'a later date' => ['2027_01_31_235959_alter_a_table.php', false],
    'no prefix at all' => ['create_a_table.php', true],
    'the workaround shape #132 removed' => ['fix_a_collation.php', true],
    'digits but not the convention' => ['20260918_create_a_table.php', true],
    'a date with no time' => ['2026_09_18_create_a_table.php', true],
]);

it('carries a date prefix on every migration this package ships', function (): void {
    $directory = __DIR__.'/../database/migrations';

    // **Two controls before the assertion, for two different ways of reading clean.** The first
    // fails when the directory moves or the walk stops finding files; the second when the
    // expression stops matching anything at all. Either one leaves the offender list empty, which
    // is byte-identical to a directory with nothing wrong in it.
    //
    // The second control reads `src/Support`, where every file is unprefixed by construction and
    // always will be -- the same call, on a directory whose answer cannot drift to empty. The
    // `database/stubs` directory looks like the natural choice and is not: its one file ends
    // `.stub`, so `phpSourcesIn()` returns nothing for it and the control would have passed
    // vacuously in the direction that matters.
    expect(phpSourcesIn($directory))->not->toBeEmpty()
        ->and(unprefixedMigrationsIn(__DIR__.'/../src/Support'))->not->toBeEmpty()
        ->and(unprefixedMigrationsIn($directory))->toBeEmpty();
});

it('creates the identities table before the migrations that alter it', function (): void {
    // The ordering the prefix buys, stated as the property rather than as a number: #54's collation
    // migration has to find this table, and it only does when the create sorts first.
    $names = array_map(basename(...), phpSourcesIn(__DIR__.'/../database/migrations'));

    sort($names);

    $create = array_search('2026_09_18_000000_create_robot_council_github_identities_table.php', $names, true);
    $collate = array_search('2026_09_22_000002_compare_host_user_keys_byte_exactly.php', $names, true);

    // Narrowed before comparing: `array_search` returns `int|false`, and a `false` here would mean
    // one of the two files has been renamed -- which is worth failing on rather than comparing as
    // a zero, since `false < int` is exactly the direction that would read as ordered.
    expect($create)->toBeInt()
        ->and($collate)->toBeInt();

    expect(\is_int($create) ? $create : PHP_INT_MAX)
        ->toBeLessThan(\is_int($collate) ? $collate : 0);
});
