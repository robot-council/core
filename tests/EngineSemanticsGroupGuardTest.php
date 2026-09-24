<?php

declare(strict_types=1);

/**
 * That every test whose subject is what an ENGINE does is selected by the job that has one.
 *
 * The `mysql` job runs `--group=engine-semantics` rather than the whole suite, because #137 dropped
 * the full MySQL job for a measured reason that still holds: it cost 585s against 168s for
 * `postgres` and rose with every test file added. A narrow selection keeps the signal and not the
 * cost.
 *
 * **A selector nobody is forced to remember is the defect this file exists to stop.** A new test
 * gated on MySQL that forgets the group is not run by the only job that could answer it, and
 * nothing says so: the suite is green on SQLite because the test skipped, and the `mysql` job is
 * green because it never selected it. Two green readings, neither of them about the question.
 *
 * So membership is derived from something the author cannot forget -- the gate itself. A test that
 * skips unless the driver is MySQL, or unless the workflow promised MySQL, has declared that its
 * subject is the engine. This scan requires those files to carry the group, and fails in the pull
 * request that adds one rather than in whichever job happens to have a database.
 *
 * `MigrationTimestampGuardTest` is the precedent, including the control that runs before the real
 * scan: a detector whose expression never matches reports a clean tree in exactly the same words as
 * a clean tree.
 *
 * @command  vendor/bin/pest --compact tests/EngineSemanticsGroupGuardTest.php
 */

/**
 * The group the `mysql` job selects on.
 */
const ENGINE_SEMANTICS_GROUP = 'engine-semantics';

/**
 * The two files that carry a marker without being gated by it.
 *
 * Both are unavoidable rather than convenient, which is why the list is asserted by a test of its
 * own: an exemption nobody re-reads is how a scan quietly stops covering things.
 *
 * @return list<string> Basenames.
 */
function notGatedDespiteTheMarker(): array
{
    return [
        // Declares `notMySqlFamily()` for everything else to gate on. Not a test file, and the group
        // would have no meaning on it.
        'Pest.php',

        // This scanner. Its probes quote both markers as fixture source, so a scan that read them
        // as gates would demand the group on the file whose job is to run on every engine.
        'EngineSemanticsGroupGuardTest.php',
    ];
}

/**
 * Whether a file's code declares that its subject is MySQL.
 *
 * Comments are stripped first, because this repository documents the constructs it constrains at
 * length and beside the code that constrains them -- this file's own docblock names both markers.
 *
 * @param  string  $path  The file to read.
 * @return bool Whether it gates itself on MySQL.
 */
function gatesOnMySql(string $path): bool
{
    if (in_array(basename($path), notGatedDespiteTheMarker(), true)) {
        return false;
    }

    $code = sourceWithoutComments($path);

    // The two ways a test in this suite says "my subject is MySQL". `notMySqlFamily()` skips on
    // the driver; `ROBOT_COUNCIL_EXPECT_MYSQL` is the workflow's own promise, which
    // `MySqlSchemaTest` gates on precisely so that a job whose `DB_CONNECTION` never took effect
    // cannot skip every MySQL test and report green.
    //
    // The needle stays the shorter `notMySql`, which is a prefix of the current name. That is
    // deliberate: a future rename in either direction keeps matching rather than silently
    // narrowing what the `mysql` job selects.
    return str_contains($code, 'notMySql')
        || str_contains($code, 'ROBOT_COUNCIL_EXPECT_MYSQL');
}

/**
 * Test files that gate themselves on MySQL without declaring the group the `mysql` job selects.
 *
 * @param  list<string>  $paths  Absolute paths to PHP files.
 * @return list<string> One description per omission, sorted, each naming the file and the remedy.
 */
function mysqlGatedTestsMissingTheGroup(array $paths): array
{
    $missing = [];

    foreach ($paths as $path) {
        if (! gatesOnMySql($path)) {
            continue;
        }

        $declaration = '/pest\(\)\s*->\s*group\(\s*[\'"]'.preg_quote(ENGINE_SEMANTICS_GROUP, '/').'[\'"]\s*\)/';

        if (preg_match($declaration, sourceWithoutComments($path)) === 1) {
            continue;
        }

        $missing[] = sprintf(
            "%s gates on MySQL without declaring the group; add `pest()->group('%s');` so the `mysql` job selects it.",
            basename($path),
            ENGINE_SEMANTICS_GROUP
        );
    }

    sort($missing);

    return $missing;
}

it('tells a gated file that declares the group from one that does not', function (): void {
    // **The detector's control, run on every invocation rather than by hand once.** Every probe is
    // a way this scan could be wrong in the reassuring direction: a marker that only appears in
    // prose, a group declared under a different name, a file that is not gated at all.
    $directory = $this->temporaryDirectory('engine-semantics-probe');

    $probes = [
        'GatedNoGroupTest.php' => "<?php\n\nit('x', fn () => null)->skip(notMySqlFamily(...), 'reason');\n",
        'GatedWithGroupTest.php' => "<?php\n\npest()->group('engine-semantics');\n\nit('x', fn () => null)->skip(notMySqlFamily(...), 'reason');\n",
        'PromiseGatedNoGroupTest.php' => "<?php\n\nit('x', fn () => null)->skip(fn () => getenv('ROBOT_COUNCIL_EXPECT_MYSQL') === false);\n",
        'PromiseGatedWithGroupTest.php' => "<?php\n\npest()->group(\"engine-semantics\");\n\nit('x', fn () => null)->skip(fn () => getenv('ROBOT_COUNCIL_EXPECT_MYSQL') === false);\n",

        // The marker in prose only. A scan that read comments would report this file, and the
        // author would satisfy it by adding the group to a test that has nothing to do with MySQL.
        'ProseOnlyTest.php' => "<?php\n\n// This is not gated on notMySqlFamily or ROBOT_COUNCIL_EXPECT_MYSQL.\nit('x', fn () => null);\n",

        // A group, but the wrong one. The remedy is a specific string, so a check that merely
        // looked for `->group(` would pass this file while the job still did not select it.
        'GatedWrongGroupTest.php' => "<?php\n\npest()->group('cross-connection');\n\nit('x', fn () => null)->skip(notMySqlFamily(...), 'reason');\n",

        'UngatedTest.php' => "<?php\n\nit('x', fn () => null);\n",
    ];

    foreach ($probes as $name => $source) {
        file_put_contents($directory.'/'.$name, $source);
    }

    expect(mysqlGatedTestsMissingTheGroup(phpSourcesIn($directory)))->toBe([
        "GatedNoGroupTest.php gates on MySQL without declaring the group; add `pest()->group('engine-semantics');` so the `mysql` job selects it.",
        "GatedWrongGroupTest.php gates on MySQL without declaring the group; add `pest()->group('engine-semantics');` so the `mysql` job selects it.",
        "PromiseGatedNoGroupTest.php gates on MySQL without declaring the group; add `pest()->group('engine-semantics');` so the `mysql` job selects it.",
    ]);
});

it('exempts exactly two files, and both of them exist', function (): void {
    // An exemption list is the one part of a scan that fails silently in the reassuring direction:
    // every name on it is a file the scan has stopped looking at. Pinning the list by name means a
    // third entry is a failure rather than a decision nobody saw.
    expect(notGatedDespiteTheMarker())->toBe(['Pest.php', 'EngineSemanticsGroupGuardTest.php']);

    foreach (notGatedDespiteTheMarker() as $name) {
        expect(__DIR__.'/'.$name)->toBeFile();
    }
});

it('selects every file that gates itself on MySQL, and nothing else', function (): void {
    // The other half of the control. The test above shows what the scan reports; this shows what
    // it considers at all, because a scan that classified the real tree as ungated would report no
    // omissions for the same reason a correct tree does.
    $gated = [];

    foreach (phpSourcesIn(__DIR__) as $path) {
        if (gatesOnMySql($path)) {
            $gated[] = basename($path);
        }
    }

    sort($gated);

    // Named rather than counted, so a file that stops gating itself on MySQL is a failure here
    // rather than a silent narrowing of what the `mysql` job runs.
    expect($gated)->toBe([
        'HostKeyComparisonTest.php',
        'IdentityMigrationRenameTest.php',
        'MySqlSchemaTest.php',
        'RoleComparisonTest.php',
    ]);
});

it('leaves no MySQL-gated test outside the group the job selects', function (): void {
    $paths = phpSourcesIn(__DIR__);

    // The control that the walk found anything at all. Without it, a directory that moved leaves
    // the assertion below passing on an empty list.
    expect($paths)->not->toBeEmpty();

    expect(mysqlGatedTestsMissingTheGroup($paths))->toBeEmpty();
});
