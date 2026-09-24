<?php

declare(strict_types=1);

/**
 * Which drivers `Support\Engines` says need a collation stated, and why the list is closed.
 *
 * **This runs on every engine and needs no database**, because the question is about a mapping from
 * a driver name to a decision rather than about what any server does. That is the same reasoning
 * `MigrationTimestampGuardTest` records: a rule about what the source declares fails in the pull
 * request that breaks it, rather than in whichever job happens to have the right server.
 *
 * @command  vendor/bin/pest --compact tests/EnginesTest.php
 */

use RobotCouncil\Support\Engines;

it('names both engines whose default collation compares loosely', function (string $driver): void {
    expect(Engines::needsBinaryCollation($driver))->toBeTrue();
})->with(['mysql', 'mariadb']);

it('leaves the engines that already compare bytes alone', function (string $driver): void {
    // Not merely "returns false for anything else": these three are the drivers Laravel actually
    // ships, so naming them pins that the answer for them is decided rather than defaulted.
    expect(Engines::needsBinaryCollation($driver))->toBeFalse();
})->with(['pgsql', 'sqlite', 'sqlsrv']);

it('matches the driver exactly, without prefixes or case folding', function (string $driver): void {
    // `str_contains` or a case-insensitive compare would admit these, and each is a plausible
    // mis-implementation of the same intent. A driver name arrives from configuration, so a loose
    // match would let a typo silently take the binary-collation path -- or, worse, let a real
    // driver miss it, which is the defect #257 was.
    expect(Engines::needsBinaryCollation($driver))->toBeFalse();
})->with(['MySQL', 'MARIADB', 'mysqli', 'mysql2', 'maria', '', 'my', 'pgsql-mysql']);

it('is the single source the migrations and the test gate both read', function (): void {
    // **The drift this replaced.** Four migrations and `tests/Pest.php` each carried their own
    // `'mysql'` comparison, and `notMySqlFamily()`'s docblock said "MySQL or MariaDB" while its
    // code asked about `mysql` alone. A scan is cheaper than trusting that nobody adds a fifth.
    $offenders = [];

    foreach ([...phpSourcesIn(__DIR__.'/../database/migrations'), __DIR__.'/Pest.php'] as $path) {
        $code = sourceWithoutComments($path);

        if (preg_match("/getDriverName\(\)\s*[!=]==\s*'mysql'/", $code) === 1) {
            $offenders[] = basename($path);
        }
    }

    sort($offenders);

    expect($offenders)->toBeEmpty();
});

it('reports a planted bare comparison, so the scan above is not silent by construction', function (): void {
    // The positive control for the scan in the test above, which would otherwise pass identically
    // whether its expression matched anything or nothing.
    $directory = $this->temporaryDirectory('engines-scan-probe');

    file_put_contents($directory.'/PlantedMigration.php', "<?php\n\nif (DB::getDriverName() !== 'mysql') {\n    return;\n}\n");
    file_put_contents($directory.'/CleanMigration.php', "<?php\n\nif (! Engines::needsBinaryCollation(DB::getDriverName())) {\n    return;\n}\n");

    // And a file whose only mention is in a comment, because this repository documents the
    // construct it forbids beside the code that forbids it.
    file_put_contents($directory.'/ProseOnly.php', "<?php\n\n// Do not write getDriverName() !== 'mysql' here.\nreturn true;\n");

    $found = [];

    foreach (phpSourcesIn($directory) as $path) {
        if (preg_match("/getDriverName\(\)\s*[!=]==\s*'mysql'/", sourceWithoutComments($path)) === 1) {
            $found[] = basename($path);
        }
    }

    expect($found)->toBe(['PlantedMigration.php']);
});
