<?php

declare(strict_types=1);

/**
 * That every test which cannot run inside the rolled-back test transaction says so by name (#473).
 *
 * Database tests share a schema migrated once per process and roll back what they wrote, the
 * decision on #467. Four kinds of test observe something different inside that transaction, and
 * each opts out with `migrateFreshSchema()` (in place of the transactional helper, usually in its
 * file's `beforeEach`) or `leaveTestTransaction()` (inside one test):
 *
 * - **a schema change** -- `Schema::create`, `table`, `drop`, `rename`, a dropped column -- which
 *   MySQL commits implicitly, ending the transaction under the test;
 * - **a migration run** forward or back, which is a schema change made by a migration;
 * - **a second connection**, which cannot read what the test's uncommitted transaction wrote --
 *   the `cross-connection` group is exactly this;
 * - **the transaction boundary itself** -- `DB::afterCommit()` callbacks and
 *   `DB::transactionLevel()` -- which the wrapping transaction moves by one.
 *
 * **Missing the opt-out fails quietly, which is why this is a scan.** On SQLite, DDL is
 * transactional, and a test asserting `afterCommit` timing may still pass by accident, so the suite
 * is green where CI's `mysql` job, or the next author, would not be. The scan fails in the pull
 * request that adds such a test.
 *
 * Each test is read with the file-local helpers it calls, so a schema change moved into a helper is
 * still seen; comments are stripped first, because this repository documents these constructs
 * beside the code that uses them. The scanner is exercised on synthetic sources before it reads the
 * tree, so a pattern that stopped matching reports as a failed control rather than a clean suite.
 *
 * @command  vendor/bin/pest --compact tests/MigrateOnceGuardTest.php
 */

/**
 * What makes a test need the opt-out, by kind.
 *
 * @return array<string, string> Patterns over comment-stripped source, keyed by what they find.
 */
function transactionSensitivePatterns(): array
{
    return [
        'a schema change' => '/Schema::(create|table|drop|dropIfExists|dropColumns|rename)\s*\(|->dropColumn\s*\(/',
        'a migration run' => '/->(up|down)\s*\(\s*\)|(Artisan::call|->artisan)\s*\(\s*[\'"]migrate(:[a-z]+)?[\'"]|\bMigrator\b/',
        'a second connection' => '/DB::connection\s*\(\s*[\'"](?!testing[\'"])[A-Za-z0-9_]+[\'"]|group\s*\(\s*[\'"]cross-connection[\'"]/',
        'the transaction boundary' => '/afterCommit\s*\(|transactionLevel\s*\(/',
    ];
}

/**
 * What opts a test out.
 */
const TRANSACTION_OPT_OUTS = '/->(migrateFreshSchema|leaveTestTransaction|migrateFresh)\s*\(/';

/**
 * What puts a test inside the rolled-back transaction. A test that never asks for it runs outside
 * one already, and has nothing to opt out of.
 */
const TRANSACTIONAL_SCHEMA = '/->migrateUsersTableWithPackageColumns\s*\(/';

/**
 * Files that carry a pattern without running a database test through it, each for a stated reason.
 *
 * @return array<string, string> Basenames, with why each is exempt.
 */
function transactionGuardExemptions(): array
{
    return [
        'TestCase.php' => 'defines the opt-outs and the transaction the patterns are about',
        'Pest.php' => 'declares the cross-connection group for the files that carry it',
        'MigrateOnceGuardTest.php' => 'this scanner, whose probes quote every pattern as fixture source',
        'EngineSemanticsGroupGuardTest.php' => 'a source scan quoting the cross-connection group as fixture text',
        'MigrationTimestampGuardTest.php' => 'a source scan over migration files, run against no database',
        'MigrationManifestGuardTest.php' => 'reads the migrator\'s file list, and migrates nothing',
    ];
}

/**
 * Source with its comments removed.
 */
function withoutComments(string $source): string
{
    $kept = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            // A comment's newlines are kept, so the blocks below still start on their own lines
            $kept .= str_repeat("\n", substr_count($token[1], "\n"));

            continue;
        }

        $kept .= is_array($token) ? $token[1] : $token;
    }

    return $kept;
}

/**
 * The tests in a file that need the opt-out and do not have it.
 *
 * @param  string  $source  A test file's source.
 * @return list<string> One line per offending test: its description and what it does.
 */
function testsMissingTheOptOut(string $source): array
{
    $code = withoutComments($source);

    // Top-level blocks: each starts at column zero with a test, a hook, or a helper function
    preg_match_all('/^(it|test|beforeEach|function\s+([A-Za-z_][A-Za-z0-9_]*))\s*\(/m', $code, $starts, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

    $blocks = [];

    foreach ($starts as $i => $start) {
        $from = $start[0][1];
        $to = isset($starts[$i + 1]) ? $starts[$i + 1][0][1] : strlen($code);

        $blocks[] = ['kind' => $start[1][0], 'function' => $start[2][0] ?? null, 'text' => substr($code, $from, $to - $from)];
    }

    $needs = static function (string $text): array {
        return array_keys(array_filter(transactionSensitivePatterns(), static fn (string $pattern): bool => preg_match($pattern, $text) === 1));
    };

    $helpers = array_column(array_filter($blocks, static fn (array $block): bool => $block['function'] !== null), 'text', 'function');
    $hook = implode("\n", array_column(array_filter($blocks, static fn (array $block): bool => $block['kind'] === 'beforeEach'), 'text'));
    $fileWide = $needs(preg_replace('/^(it|test|beforeEach|function)\b.*$/ms', '', $code) ?? '');

    $missing = [];

    foreach ($blocks as $block) {
        if (! in_array($block['kind'], ['it', 'test'], true)) {
            continue;
        }

        // What the test runs: its own body, the hook before it, and the file-local helpers it
        // calls, so a schema change or an opt-out moved into a helper is still the test's
        $runs = $block['text']."\n".$hook;

        foreach ($helpers as $name => $text) {
            if (preg_match('/\b'.preg_quote((string) $name, '/').'\s*\(/', $block['text']) === 1) {
                $runs .= "\n".$text;
            }
        }

        $reasons = [...$needs($runs), ...$fileWide];

        if ($reasons === [] || preg_match(TRANSACTIONAL_SCHEMA, $runs) !== 1 || preg_match(TRANSACTION_OPT_OUTS, $runs) === 1) {
            continue;
        }

        preg_match('/^(?:it|test)\s*\(\s*([\'"])(.*?)(?<!\\\\)\1/s', $block['text'], $name);

        $missing[] = sprintf('%s: %s', $name[2] ?? '(unnamed)', implode(', ', array_unique($reasons)));
    }

    return $missing;
}

it('finds each kind of test that needs the opt-out, and passes one that has it', function (): void {
    // The controls, run before the scan, so a pattern that stopped matching fails here rather than
    // reporting a clean tree
    $probes = [
        'a schema change' => "Schema::drop('robot_council_allowlist_entries');",
        'a migration run' => "Artisan::call('migrate:rollback');",
        'a second connection' => "DB::connection('second')->table('x')->count();",
        'the transaction boundary' => 'DB::afterCommit(fn () => null);',
    ];

    foreach ($probes as $kind => $line) {
        $bare = "<?php\nbeforeEach(function () { \$this->migrateUsersTableWithPackageColumns(); });\nit('probes', function () {\n    {$line}\n});\n";
        $optedOut = str_replace("it('probes', function () {\n", "it('probes', function () {\n    \$this->leaveTestTransaction();\n", $bare);
        $optedOutInHook = str_replace('migrateUsersTableWithPackageColumns', 'migrateFreshSchema', $bare);
        $commented = "<?php\nit('probes', function () {\n    // {$line}\n});\n";

        expect(testsMissingTheOptOut($bare))->toBe(['probes: '.$kind])
            ->and(testsMissingTheOptOut($optedOut))->toBe([])
            ->and(testsMissingTheOptOut($optedOutInHook))->toBe([])
            ->and(testsMissingTheOptOut($commented))->toBe([]);
    }

    // Moved into a file-local helper, it is still the test's doing, and so is an opt-out there
    $helper = "<?php\nfunction dropIt(): void\n{\n    Schema::drop('x');\n}\n\nit('calls a helper', function () {\n    \$this->migrateUsersTableWithPackageColumns();\n    dropIt();\n});\n";
    $helperOptsOut = "<?php\nfunction freshly(\$case): void\n{\n    \$case->migrateUsersTableWithPackageColumns();\n    \$case->leaveTestTransaction();\n}\n\nit('opts out in a helper', function () {\n    freshly(\$this);\n    Schema::drop('x');\n});\n";

    expect(testsMissingTheOptOut($helper))->toBe(['calls a helper: a schema change'])
        ->and(testsMissingTheOptOut($helperOptsOut))->toBe([])
        // A plain database test is left alone, and so is one never inside the transaction
        ->and(testsMissingTheOptOut("<?php\nit('reads', function () {\n    \$this->migrateUsersTableWithPackageColumns();\n});\n"))->toBe([])
        ->and(testsMissingTheOptOut("<?php\nit('builds its own table', function () {\n    Schema::create('x', fn () => null);\n});\n"))->toBe([]);
});

it('finds no test that needs the opt-out and lacks it', function (): void {
    $files = glob(__DIR__.'/{,*/}*.php', GLOB_BRACE) ?: [];

    // The denominator, so a glob that matched nothing cannot read as a clean suite
    expect(count($files))->toBeGreaterThan(100);

    $missing = [];

    foreach ($files as $file) {
        if (array_key_exists(basename($file), transactionGuardExemptions()) || str_contains($file, '/Fixtures/')) {
            continue;
        }

        foreach (testsMissingTheOptOut((string) file_get_contents($file)) as $test) {
            $missing[] = basename($file).' > '.$test;
        }
    }

    expect($missing)->toBe([]);
});

it('exempts only files that exist, each for a reason', function (): void {
    foreach (transactionGuardExemptions() as $file => $reason) {
        expect(is_file(__DIR__.'/'.$file))->toBeTrue()
            ->and($reason)->not->toBeEmpty();
    }
});
