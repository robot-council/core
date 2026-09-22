<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as GitHubAccount;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * Whether this run is against MySQL or MariaDB, which is the only place these questions exist.
 */
function notMySql(): bool
{
    return DB::connection()->getDriverName() !== 'mysql';
}

/**
 * One field of an `information_schema` row, as a string, whatever case the server names it in.
 *
 * Taking `mixed` and narrowing here rather than typing the parameter, for the reason CLAUDE.md
 * gives: `DB::select()` returns `mixed` rows, and a narrower signature turns an unexpected shape
 * into an uncaught `TypeError` from inside vendor code. MySQL lower-cases these column names and
 * some configurations upper-case them, so both spellings are read.
 *
 * @param  mixed  $row  One row as the driver returned it.
 * @param  string  $field  The lower-case field name.
 * @return string The value, or an empty string when the row does not carry it.
 */
function schemaField(mixed $row, string $field): string
{
    if (! is_object($row)) {
        return '';
    }

    foreach ([$field, strtoupper($field)] as $name) {
        if (property_exists($row, $name)) {
            $value = $row->{$name};

            return is_scalar($value) ? (string) $value : '';
        }
    }

    return '';
}

/**
 * Whether this run is on something other than Postgres.
 *
 * **Three of the `cross-connection` files are Postgres-specific in their SQL, not merely in where
 * they are run.** They set `lock_timeout`, which is Postgres's spelling -- MySQL bounds a row wait
 * with `innodb_lock_wait_timeout`, in whole seconds -- and `FeedOrderingTest` additionally reads a
 * sequence through `pg_get_serial_sequence()` and `last_value`.
 *
 * **Pointed at MySQL they do not fail, they stall.** The bound never applies, so each blocked
 * writer waits out `innodb_lock_wait_timeout`, 50 seconds by default, and the idle transaction the
 * other connection is holding blocks the teardown's `DROP TABLE` on a metadata lock. Measured
 * 2026-09-22 while adding the `mysql` job (#39): one run sat for 815 seconds before it was killed,
 * with `Waiting for table metadata lock` as the only symptom.
 *
 * Skipping on the engine rather than trusting the workflow, because a comment saying which job may
 * run a test is not a thing the test can enforce -- and the failure mode for getting it wrong is a
 * job that hangs until its timeout rather than one that says what is wrong.
 */
function notPostgres(): bool
{
    return DB::connection()->getDriverName() !== 'pgsql';
}

/**
 * Whether a query is a write of one kind against one of the package's tables.
 *
 * **Identifier quoting is per-driver, and a test that spells it out tests one driver.** SQLite and
 * Postgres quote with `"`, MySQL with a backtick, so a predicate written as
 * `update "robot_council_locks"` silently matches nothing on MySQL -- and a `DB::listen` predicate
 * that never fires does not fail, it just never injects what the test was about. Every one of
 * those tests passed on two engines and failed on the third for a reason that had nothing to do
 * with what it was testing (#39).
 *
 * Quoting is stripped rather than matched, so this holds on any driver Laravel supports.
 *
 * @param  string  $sql  The statement, as `QueryExecuted` reports it.
 * @param  string  $verb  The leading keyword, lower-case: `update`, `insert into`, `delete from`.
 * @param  string  $table  The unquoted table name.
 * @return bool True when the statement opens with that verb against that table.
 */
function isWriteTo(string $sql, string $verb, string $table): bool
{
    $normalized = strtolower(str_replace(['"', '`', '[', ']'], '', ltrim($sql)));

    return str_starts_with($normalized, $verb.' '.$table);
}

/**
 * One `meta` payload with its keys in a fixed order, at every depth.
 *
 * **MySQL's `JSON` column does not preserve object key order**, and neither the package nor any
 * reader depends on it: a JSON object is an unordered map, and `Models\FleetEvent` casts the
 * column to an array. Asserting the round trip with `toBe()` compares arrays identically, which
 * for an associative array means key order too -- so sixteen assertions were pinning a property
 * the storage never promised, and MySQL is simply the engine that does not happen to return them
 * as written (#39).
 *
 * Sorting both sides keeps the comparison strict about values while dropping the order, which is
 * what `toEqual()` would have loosened instead.
 *
 * @param  array<array-key, mixed>|null  $meta  The payload, or null for an event carrying none.
 * @return array<array-key, mixed> The payload with keys sorted at every level.
 */
function orderedMeta(?array $meta): array
{
    $meta ??= [];

    ksort($meta);

    foreach ($meta as $key => $value) {
        if (is_array($value)) {
            $meta[$key] = orderedMeta($value);
        }
    }

    return $meta;
}

/**
 * Every file under a directory with one suffix, at any depth.
 *
 * Recursive deliberately. `glob('<dir>/*.blade.php')` matches only the top level, and a guard built
 * on it goes on passing while a whole subdirectory is unexamined -- the failure looks like a clean
 * result, because one top-level file keeps the list non-empty forever.
 *
 * `FOLLOW_SYMLINKS` because without it `RecursiveDirectoryIterator::hasChildren()` defaults to
 * refusing links, so a symlinked directory is yielded as a leaf and then dropped by the suffix
 * test -- the same silent-skip shape the recursion was written to fix. The suffix is compared
 * case-insensitively, because macOS and Windows resolve `X.BLADE.PHP` while a byte-exact test
 * does not.
 *
 * An unreadable directory throws `UnexpectedValueException` rather than being skipped, and that is
 * deliberate: a guard that quietly walked past a directory it could not open would report clean.
 *
 * @param  string  $directory  The directory to walk.
 * @param  string  $suffix  The file suffix to keep.
 * @return list<string> Absolute paths.
 */
function filesUnder(string $directory, string $suffix): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $found = [];

    $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS;

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, $flags)) as $file) {
        if (str_ends_with(strtolower($file->getFilename()), strtolower($suffix))) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

/**
 * Every Blade template under a directory, at any depth.
 *
 * @param  string  $directory  The directory to walk.
 * @return list<string> Absolute paths.
 */
function bladeTemplatesIn(string $directory): array
{
    return filesUnder($directory, '.blade.php');
}

/**
 * Every PHP source file under a directory, at any depth.
 *
 * @param  string  $directory  The directory to walk.
 * @return list<string> Absolute paths.
 */
function phpSourcesIn(string $directory): array
{
    return filesUnder($directory, '.php');
}

/**
 * One source file's code, with its comments removed.
 *
 * A check that searches source for a construct will otherwise report the prose written to explain
 * that construct. This repository documents the constructs it forbids, at length and next to the
 * code that forbids them, so that is not a hypothetical: the dashboard layout's own comment saying
 * the package never builds an `Htmlable` was reported as a package that builds one.
 *
 * PHP is tokenized rather than pattern-stripped, because `token_get_all()` knows a `//` inside a
 * string literal from one that opens a comment and no regex over the text does. Blade templates are
 * stripped of `{{-- --}}`, which is what Blade itself removes before compiling.
 *
 * @param  string  $path  The file to read.
 * @return string Its contents with comments removed.
 */
function sourceWithoutComments(string $path): string
{
    $contents = (string) file_get_contents($path);

    if (str_ends_with(strtolower($path), '.blade.php')) {
        return (string) (preg_replace('/\{\{--.*?--\}\}/s', '', $contents) ?? $contents);
    }

    $kept = '';

    foreach (token_get_all($contents) as $token) {
        if (\is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $kept .= $token[1];

            continue;
        }

        $kept .= $token;
    }

    return $kept;
}

/**
 * Every interpolation in a URL-bearing attribute that is not a server-derived URL.
 *
 * Escaping is no defense here, which is what makes this its own check. `htmlspecialchars` alters
 * nothing in `javascript:alert(1)` -- there is no character in it to escape -- so
 * `<a href="{{ $task->link }}">` passes every escaping guard the package has and still executes on
 * click. The same holds for `src`, `action`, `formaction`, `poster` and `xlink:href`.
 *
 * The defense is that no value a requester or an agent supplied ever reaches one of these, so the
 * check allows only URLs the server built: `route()`, `url()`, `asset()`, `secure_url()` and
 * `action()`. Anything else in one of these attributes is reported, including a bare variable that
 * happens to hold a safe URL today -- the point is that the attribute is not a place to decide it.
 *
 * Comments and `@verbatim` blocks are stripped first, for the reason `rawOutputIn()` records.
 *
 * @param  string  $template  The template's contents.
 * @return list<string> One description per offending attribute.
 */
function urlAttributeInterpolations(string $template): array
{
    $withoutBlocks = preg_replace('/(?<!@)@verbatim(.*?)@endverbatim/s', '', $template) ?? $template;
    $withoutComments = preg_replace('/\{\{--.*?--\}\}/s', '', $withoutBlocks) ?? $withoutBlocks;

    $attributes = 'href|src|srcset|srcdoc|action|formaction|poster|cite|background|manifest|ping|longdesc|data|xlink:href|data-url';

    // Quoted and unquoted forms both, because an unquoted value breaks out on a space rather than
    // on a quote and is the easier of the two to get wrong
    // An interpolation is matched as a unit in every branch. In a quoted value that is because a
    // `"` inside the expression -- `href="{{ $task->urlFor("view") }}"` is ordinary Blade -- would
    // otherwise end the capture early, leave no complete interpolation in it, and consume past the
    // real closing quote so nothing was rescanned. In an unquoted value it is because `src={{ $x }}`
    // holds spaces and a bare `[^\s>]+` stops at the first one. `s` so a expression may span lines.
    $span = '\{\{.*?\}\}|\{!!.*?!!\}';

    $pattern = '/(?<![\w:-])(?<attribute>'.$attributes.')\s*=\s*(?:'
        .'"(?<double>(?:'.$span.'|[^"])*)"'
        ."|'(?<single>(?:".$span."|[^'])*)'"
        .'|(?<bare>(?:'.$span.'|[^\s>])+)'
        .')/is';

    preg_match_all($pattern, $withoutComments, $matches, PREG_SET_ORDER);

    // A literal first argument, not merely the helper's name. `url($x)` and `asset($x)` return
    // their argument **verbatim** whenever `UrlGenerator::isValidUrl()` accepts it -- measured,
    // `url('//evil.example/steal')` and `url('https://evil.example/x')` come back unchanged -- so
    // an allowlist keyed on the name alone admits an off-site link or a remote script load.
    // `route()` and `action()` are safe with any argument, because route parameters are
    // `rawurlencode`d and the scheme is the application's, but requiring the literal costs nothing.
    $serverBuilt = '/^\s*(?:route|url|asset|secure_url|action)\s*\(\s*[\'"]/';

    $offenders = [];

    foreach ($matches as $match) {
        $value = ($match['double'] ?? '') !== '' ? $match['double']
            : ((($match['single'] ?? '') !== '') ? $match['single'] : ($match['bare'] ?? ''));

        if (preg_match_all('/\{\{(.+?)\}\}|\{!!(.+?)!!\}/s', $value, $found, PREG_SET_ORDER) === 0) {
            continue;
        }

        foreach ($found as $interpolation) {
            $expression = trim($interpolation[2] ?? '') !== ''
                ? trim($interpolation[2])
                : trim($interpolation[1] ?? '');

            if (preg_match($serverBuilt, $expression) === 1) {
                continue;
            }

            // Whitespace collapsed, as `rawOutputIn()` does, so an expression spanning lines reads
            // as one line in the failure message rather than breaking it across several
            $offenders[] = $match['attribute'].'="'.trim((string) preg_replace('/\s+/', ' ', $expression)).'"';
        }
    }

    return $offenders;
}

/**
 * Write a source to a scratch file, so a control can exercise a path-taking helper on it.
 *
 * @param  string  $contents  The source to write.
 * @return string The path written.
 */
function writeProbe(string $contents): string
{
    $path = sys_get_temp_dir().'/rc-probe-'.bin2hex(random_bytes(6)).'.php';

    file_put_contents($path, $contents);

    return $path;
}

/**
 * Class-name-shaped tokens written into a string literal under `src/`.
 *
 * **`src/` is not scanned for stylesheet classes** -- #111 measured that doing so put 30 KB of
 * prose-derived CSS into the shipped artifact, because the extractor cannot tell `ordinal` in a
 * docblock from a utility class. The cost of not scanning it is the opposite failure: a class name
 * genuinely built in PHP reaches no stylesheet and the element renders unstyled, with nothing
 * reporting it.
 *
 * **Comparing two builds cannot catch that.** Prose always yields candidates, so a build with
 * `src/` added back always differs from one without -- the check would be red on every commit and
 * say nothing. The distinction that does work is where the text lives: **prose lives in comments,
 * a deliberate class name lives in a literal.** Comments are stripped before anything is read.
 *
 * The prefixes are the ones this package's views actually use, so the check is narrow by
 * construction rather than by a list of exceptions. Verified against `src/` as it stands: no
 * string literal matches, including `robot-council installation` and `tasks:create`, which a
 * looser "hyphenated word" rule would have caught.
 *
 * @param  string  $source  The PHP file's contents.
 * @return list<string> One finding per class-shaped token.
 */
function stylesheetClassesIn(string $source): array
{
    $prefixes = 'btn|badge|card|text|bg|flex|grid|gap|border|shadow|rounded|opacity|divide|space'
        .'|items|justify|overflow|whitespace|font|table|py|px|pt|pb|mt|mb|ml|mr|[pmwh]';

    $findings = [];

    preg_match_all('/\'([^\'\\\\\n]*)\'|"([^"\\\\\n]*)"/', $source, $literals, PREG_SET_ORDER);

    foreach ($literals as $literal) {
        $value = $literal[2] ?? '';

        if ($value === '') {
            $value = $literal[1] ?? '';
        }

        foreach (preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/^(?:'.$prefixes.')-[a-z0-9]+(?:-[a-z0-9]+)*$/D', $token) === 1) {
                $findings[] = $token;
            }
        }
    }

    return $findings;
}

/**
 * Every interpolation the package writes into a Livewire or Alpine EXPRESSION, which Blade's
 * escaping does not protect.
 *
 * **A `wire:click` value is evaluated, not displayed.** `{{ }}` turns a quote into `&#039;`, and an
 * HTML parser decodes entities inside an attribute value before Livewire ever sees the expression
 * -- so `act('{{ $x }}')` with `$x` of `a'b` becomes `act('a'b')` and the literal closes early.
 * Measured rather than assumed. `#70` makes the same argument about URL attributes; this is its
 * third case, and `#81` is where it was written down.
 *
 * Two positions are checked, because they fail differently:
 *
 * - **Inside the quoted value** of an attribute whose contents are an expression.
 * - **Inside the attribute NAME**, which `wire:poll.{{ $seconds }}s` does. Breaking out there
 *   leaves the attribute entirely and can open another one.
 *
 * **`wire:key` is deliberately not checked.** Livewire reads it as a literal string for DOM
 * diffing and never evaluates it, so Blade's own escaping is the whole of what it needs. Treating
 * it as an expression would demand a guarantee that buys nothing.
 *
 * An interpolation is accepted only when it is a `WireArgument::of()` call, which is a whitelist
 * rather than an escaper: a value that would need escaping is refused at render time.
 *
 * @param  string  $template  The template source.
 * @return list<string> One finding per unguarded interpolation, naming the attribute.
 */
function wireExpressionInterpolations(string $template): array
{
    $findings = [];

    // The value position. `wire:key` is excluded by name; everything else under these prefixes is
    // treated as an expression, which errs toward reporting.
    preg_match_all('/((?:wire:|x-on:|x-bind:|x-data|x-show|x-model|@click)[\w.:-]*)\s*=\s*"([^"]*)"/', $template, $attributes, PREG_SET_ORDER);

    foreach ($attributes as $attribute) {
        if (str_starts_with($attribute[1], 'wire:key')) {
            continue;
        }

        foreach (interpolationsIn($attribute[2]) as $interpolation) {
            if (! str_contains($interpolation, '::of(')) {
                $findings[] = sprintf('%s="%s"', $attribute[1], trim($interpolation));
            }
        }
    }

    // The name position: `wire:poll.{{ … }}s`.
    preg_match_all('/(?:wire:|x-)[\w.:-]*\{\{(.*?)\}\}/s', $template, $names, PREG_SET_ORDER);

    foreach ($names as $name) {
        if (! str_contains($name[1], '::of(')) {
            $findings[] = 'attribute name: {{'.trim($name[1]).'}}';
        }
    }

    return $findings;
}

/**
 * The `{{ … }}` interpolations in one attribute value, comments excluded.
 *
 * @param  string  $value  The attribute's value.
 * @return list<string> The expression inside each pair of braces.
 */
function interpolationsIn(string $value): array
{
    preg_match_all('/\{\{(?!--)(.*?)\}\}/s', $value, $matches);

    return $matches[1];
}

/**
 * Every construct in one Blade template that can put bytes into the document unescaped.
 *
 * Not only `{!! !!}`. Blade compiles three shapes that skip `e()`, and a guard that knew about one
 * of them reported clean on the other two -- measured against the real
 * `Illuminate\View\Compilers\BladeCompiler::compileString()` rather than reasoned about:
 *
 * - `{!! $x !!}`, which compiles to `<?php echo $x; ?>`.
 * - `@php echo $x; @endphp`, which compiles to exactly the same thing.
 * - A raw `<?php echo $x; ?>` or `<?= $x ?>`, likewise -- a one-line rewrite of the first that
 *   carries no visual warning at all.
 *
 * **The order here mirrors Blade's, and that is load-bearing.** `compileString()` calls
 * `storeUncompiledBlocks()` before `compileComments()`, so a `{{--` inside `@verbatim` or `@php` is
 * already a placeholder when the comment regex runs and cannot open a comment. Stripping comments
 * first instead, over the whole file, lets a stray `{{--` in a verbatim block swallow everything up
 * to the next real `--}}`: measured, a `{!! $evil !!}` between them went unreported while Blade
 * compiled it into a live echo. `@verbatim` is the standard escape for Alpine and Vue mustaches, so
 * a Livewire dashboard is a plausible place to meet one.
 *
 * A genuinely unclosed `{{--` needs no special handling. Blade's own `compileComments()` uses the
 * same non-greedy pattern over the whole string, so both strip the identical span and the echo
 * inside it really does not render -- verified in both directions.
 *
 * What this cannot see is `{{ $x }}` where `$x` is `Htmlable`, because `e()` returns `toHtml()`
 * unescaped for those. No pattern over a template can tell that apart; `tests/EscapingGuardTest.php`
 * carries a separate check that the package never constructs one.
 *
 * @param  string  $template  The template's contents.
 * @return list<string> One description per construct found.
 */
function rawOutputIn(string $template): array
{
    $findings = [];

    // Taken out first, exactly as Blade takes them out first. Their contents are reported rather
    // than discarded, because a `@php` block is one of the shapes being looked for.
    $withoutBlocks = preg_replace_callback(
        '/(?<!@)@verbatim(?<verbatim>.*?)@endverbatim|(?<!@)@php(?<php>.*?)@endphp/s',
        static function (array $match) use (&$findings): string {
            if (($match['php'] ?? '') !== '') {
                $findings[] = '@php block: '.trim((string) preg_replace('/\s+/', ' ', $match['php']));
            }

            return '';
        },
        $template
    ) ?? $template;

    $withoutComments = preg_replace('/\{\{--.*?--\}\}/s', '', $withoutBlocks) ?? $withoutBlocks;

    // Blade leaves a raw PHP tag alone, and it echoes whatever it is given
    if (preg_match('/<\?(?:php|=)/', $withoutComments) === 1) {
        $findings[] = 'a raw PHP tag';
    }

    preg_match_all('/\{!!\s*(?!\s*!!\})(.+?)!!\}/s', $withoutComments, $matches);

    foreach ($matches[1] as $expression) {
        $findings[] = 'unescaped echo: {!! '.trim((string) preg_replace('/\s+/', ' ', $expression)).' !!}';
    }

    return $findings;
}

/**
 * Start an enrollment through the real endpoint, and hand back everything a helper would hold.
 *
 * Going through the endpoint rather than writing a row keeps the fixtures honest: the hashes the
 * exchange looks a code up by are the ones the endpoint wrote, not ones a test computed to match.
 *
 * @param  TestCase  $case  The test case making the request.
 * @param  list<string>  $requestedAbilities  The abilities to ask for.
 * @param  string  $verifier  The secret the helper keeps, whose hash is sent as the challenge.
 * @param  array<string, mixed>  $overrides  Fields to replace in the request body.
 * @return array{record: DeviceCode, device_code: string, verifier: string, response: array<string, mixed>} What the helper holds, and what it was told.
 */
function requestDeviceCode(
    TestCase $case,
    array $requestedAbilities = [],
    string $verifier = 'a-verifier-only-the-helper-holds-and-nobody-else-at-all',
    array $overrides = []
): array {
    $requestedAbilities = $requestedAbilities === []
        ? [Ability::TasksCreate->value, Ability::EventsPost->value]
        : $requestedAbilities;

    $response = $case->postJson(route('robot-council.device.code'), [
        'harness' => 'claude-code',
        'machine_label' => 'workbench-01',
        'requested_abilities' => $requestedAbilities,
        'code_challenge' => hash('sha256', $verifier),
        ...$overrides,
    ]);

    $response->assertCreated();

    $deviceCode = stringValue($response->json('device_code'));

    /** @var array<string, mixed> $body */
    $body = (array) $response->json();

    return [
        'record' => DeviceCode::query()->where('device_code_hash', hash('sha256', $deviceCode))->sole(),
        'device_code' => $deviceCode,
        'verifier' => $verifier,
        'response' => $body,
    ];
}

/**
 * Build the account Socialite would return for a GitHub user, for `Socialite::fake()`.
 *
 * @param  int  $id  The account's numeric GitHub user ID.
 * @param  string|null  $login  The account's login, which Socialite maps to its nickname.
 * @param  string|null  $name  The account's display name, absent on many accounts.
 * @param  string|null  $email  The verified primary email, absent when the account exposes none.
 * @return GitHubAccount The account a faked provider hands the callback.
 */
function githubAccount(
    int $id,
    ?string $login = 'octodev',
    ?string $name = 'Octo Dev',
    ?string $email = 'octo@example.com'
): GitHubAccount {
    $account = new GitHubAccount;

    $account->map([
        'id' => (string) $id,
        'nickname' => $login,
        'name' => $name,
        'email' => $email,
        'avatar' => sprintf('https://avatars.example.com/u/%d', $id),
    ]);

    return $account;
}

/**
 * Narrow a value a test read off an Eloquent model, which arrives untyped when the analyzer cannot
 * infer the column.
 *
 * @param  mixed  $value  The value to narrow.
 * @return CarbonInterface The value, as a date.
 *
 * @throws RuntimeException When the value is not a date.
 */
function dateValue(mixed $value): CarbonInterface
{
    if (! $value instanceof CarbonInterface) {
        throw new RuntimeException(sprintf('Expected a date, got %s.', get_debug_type($value)));
    }

    return $value;
}

/**
 * Narrow a whole number a test read out of JSON, which arrives untyped.
 *
 * @param  mixed  $value  The value to narrow.
 * @return int The value, as an integer.
 *
 * @throws RuntimeException When the value is not an integer.
 */
function intValue(mixed $value): int
{
    if (! is_int($value)) {
        throw new RuntimeException(sprintf('Expected an integer, got %s.', get_debug_type($value)));
    }

    return $value;
}

/**
 * Narrow a value a test read out of JSON that should be a list or a map.
 *
 * @param  mixed  $value  The value to narrow.
 * @return array<int|string, mixed> The value, as an array.
 *
 * @throws RuntimeException When the value is not an array.
 */
function arrayValue(mixed $value): array
{
    if (! is_array($value)) {
        throw new RuntimeException(sprintf('Expected an array, got %s.', get_debug_type($value)));
    }

    return $value;
}

/**
 * Narrow a host user key a test read off a model, which `getKey()` returns untyped.
 *
 * Deliberately not `RobotCouncil\Support\HostKey`, which is the production narrowing: a test that
 * computed its expected value with the code under test would agree with it however wrong both were.
 *
 * @param  mixed  $value  The key to narrow.
 * @return string The key as text.
 *
 * @throws RuntimeException When the key is neither an integer nor a string.
 */
function keyValue(mixed $value): string
{
    if (! is_int($value) && ! is_string($value)) {
        throw new RuntimeException(sprintf('Expected a host user key, got %s.', get_debug_type($value)));
    }

    return (string) $value;
}

/**
 * Narrow a value a test read out of JSON, which arrives untyped.
 *
 * A cast would turn an absent key into an empty string and carry on, so an assertion written
 * against it would pass for the wrong reason. This refuses instead.
 *
 * @param  mixed  $value  The value to narrow.
 * @return string The value, as a string.
 *
 * @throws RuntimeException When the value is not a string.
 */
function stringValue(mixed $value): string
{
    if (! is_string($value)) {
        throw new RuntimeException(sprintf('Expected a string, got %s.', get_debug_type($value)));
    }

    return $value;
}
