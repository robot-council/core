<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as GitHubAccount;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Support\Engines;
use RobotCouncil\Support\WireArgument;
use RobotCouncil\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * One `information_schema` column default, with MariaDB's quoting removed.
 *
 * **MariaDB reports a string default QUOTED and an absent one as the four characters `NULL`**,
 * where MySQL reports the bare value and a real SQL NULL. Measured on MariaDB 11.8.9 against MySQL
 * 9.4.0 for `robot_council_agent_sessions.role`: MariaDB says `'build'` and `NULL`, MySQL says
 * `build` and nothing. Both are correct for their own engine and neither is what the other's test
 * expectation was written against, so the difference is normalized here rather than in each
 * assertion (#257).
 *
 * The `ifnull(..., '(none)')` in the queries handles MySQL's real NULL; this handles MariaDB's
 * string.
 *
 * @param  mixed  $row  One `information_schema` row as the driver returned it.
 * @param  string  $absent  What the caller uses to mean "no default".
 * @return string The default, comparable across both engines.
 */
function schemaDefault(mixed $row, string $absent = '(none)'): string
{
    $value = schemaField($row, 'default');

    // MariaDB's literal four characters, not a value somebody defaulted to the word NULL -- which
    // would arrive quoted, as `'NULL'`, and is left alone by the check below.
    if ($value === 'NULL') {
        return $absent;
    }

    if (\strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
        return substr($value, 1, -1);
    }

    return $value;
}

/**
 * Whether this run is against MySQL or MariaDB, which is the only place these questions exist.
 */
function notMySqlFamily(): bool
{
    return ! Engines::needsBinaryCollation(DB::connection()->getDriverName());
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
 * One source file's statements, split on the semicolons that end them.
 *
 * Tokenized rather than split on the text, because a `;` inside a string literal ends no
 * statement and `explode()` cannot tell the two apart. A column declaration is one statement
 * however many lines its chain runs to, which is the unit a nullability check has to read: the
 * `->nullable()` that exempts a column can sit on a line of its own.
 *
 * @param  string  $source  PHP source, opening tag included.
 * @return list<string> One statement per entry, semicolons removed.
 */
function phpStatementsIn(string $source): array
{
    $statements = [];

    $current = '';

    foreach (token_get_all($source) as $token) {
        $text = \is_array($token) ? $token[1] : $token;

        if ($text === ';') {
            $statements[] = $current;

            $current = '';

            continue;
        }

        $current .= $text;
    }

    if (trim($current) !== '') {
        $statements[] = $current;
    }

    return $statements;
}

/**
 * Every date column the migrations under a directory declare with `timestamp()`, and whether each
 * one is nullable.
 *
 * The plural helpers are not declarations of a `timestamp()` column and are not reported:
 * `timestamps()`, `timestampsTz()`, `nullableTimestamps()` and `softDeletes()` all create nullable
 * columns, which is not the shape that carries MySQL's implicit `ON UPDATE`. The `\(` in the
 * pattern is what keeps `timestamps(` from matching `timestamp(`.
 *
 * **`->nullable(false)` is an explicit NOT NULL and is reported**, which is why the exemption
 * matches an empty argument list or `true` rather than the method name alone.
 *
 * Comments are stripped first. This package documents the rule beside the columns that follow it,
 * so a scan over the raw text would report the prose explaining the rule.
 *
 * @param  string  $directory  The migration directory to walk.
 * @return list<array{file: string, column: string, nullable: bool}> One entry per declaration.
 */
function timestampColumnsIn(string $directory): array
{
    $found = [];

    foreach (phpSourcesIn($directory) as $path) {
        $file = str_starts_with($path, $directory.'/')
            ? substr($path, \strlen($directory) + 1)
            : basename($path);

        foreach (phpStatementsIn(sourceWithoutComments($path)) as $statement) {
            $matched = preg_match_all(
                '/->(?:timestampTz|timestamp)\(\s*(?:([\'"])([^\'"]*)\1)?/',
                $statement,
                $matches,
                PREG_SET_ORDER
            );

            if ($matched === false || $matched === 0) {
                continue;
            }

            $nullable = preg_match('/->nullable\(\s*(?:true\s*)?\)/i', $statement) === 1;

            foreach ($matches as $match) {
                $found[] = [
                    'file' => $file,

                    // A name built from a variable leaves nothing to quote, and reporting it as
                    // unnamed is better than dropping a declaration the check cannot read.
                    'column' => ($match[2] ?? '') === '' ? '(unnamed)' : $match[2],
                    'nullable' => $nullable,
                ];
            }
        }
    }

    return $found;
}

/**
 * Every non-nullable `timestamp()` column the migrations under a directory declare.
 *
 * The description carries the remedy, because the reader will not know the rule: with
 * `explicit_defaults_for_timestamp` off, MySQL and MariaDB give the first NOT NULL `TIMESTAMP`
 * column in a table an implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, so any
 * update to the row rewrites it (#22, #136).
 *
 * @param  string  $directory  The migration directory to walk.
 * @return list<string> One description per offending column.
 */
function nonNullableTimestampsIn(string $directory): array
{
    $offenders = array_filter(
        timestampColumnsIn($directory),
        static fn (array $column): bool => $column['nullable'] === false
    );

    return array_values(array_map(
        static fn (array $column): string => sprintf(
            '%s declares `%s` as a non-nullable timestamp; declare it dateTime instead.',
            $column['file'],
            $column['column']
        ),
        $offenders
    ));
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

    // **A guard that dies reports clean, which is the one failure mode this repository's rules name
    // by itself.** On a PCRE error `preg_match_all` returns `false` and sets `$matches` to an empty
    // array, so the `foreach` below runs zero times and the whole template passes with no
    // interpolation ever examined. The value alternation is ambiguous -- a `{{ … }}` is also
    // matchable character by character by `[^"]` -- so an unclosed quote followed by about ten
    // interpolations exhausts the backtrack limit in roughly a hundred bytes. Measured: at ten it
    // returns `false` with `PREG_BACKTRACK_LIMIT_ERROR` and the function returns an empty list.
    //
    // Throwing rather than returning, because the caller's contract is "these are the offenders"
    // and an empty list is indistinguishable from a clean template.
    if (preg_match_all($pattern, $withoutComments, $matches, PREG_SET_ORDER) === false) {
        throw new RuntimeException('URL attribute scan failed: '.preg_last_error_msg());
    }

    // **The two helper families are admitted by different rules, because they are safe for
    // different reasons.**
    //
    // `url()`, `asset()` and `secure_url()` return their argument **verbatim** whenever
    // `UrlGenerator::isValidUrl()` accepts it -- measured, `url('//evil.example/steal')` and
    // `url('https://evil.example/x')` come back unchanged. So their argument has to be a single
    // quoted literal and nothing else. A rule that only checked the FIRST character after the
    // parenthesis was defeated by three characters: `url('' . $agentValue)` opens with a quote and
    // passes it. The worked case is not hypothetical -- `Support\ProjectId::PATTERN` admits `/`,
    // and `EscapingGuardTest` already records that `//evil.example/steal` matches it, so
    // `url('/' . $session->project_id)` renders an off-site link from an agent-supplied string.
    $literalArgumentOnly = '/^\s*(?:url|asset|secure_url)\s*\(\s*([\'"])[^\'"]*\1\s*\)\s*$/';

    // `route()` and `action()` are safe with **any** argument, because route parameters are
    // `rawurlencode`d and the scheme is the application's. They still have to be the whole
    // expression: anchored only at the start, the old rule admitted anything that merely began with
    // a helper call, so `{{ route('x').$section }}` passed whole and half of it is not built by the
    // server -- which is what the failure message below claims to be about (#210).
    //
    // The recursive group is what makes this usable rather than merely strict: `(?1)` walks
    // balanced parentheses, so a real argument list keeps its own calls and arrays and
    // `route('x', ['a' => max(1, $b)])` stays admitted, while anything after the closing
    // parenthesis leaves text the `$` anchor refuses. Neither a rule requiring the expression to
    // END in `)` nor one forbidding inner parentheses does both: the first admits
    // `route('x').foo($y)`, the second rejects the `max(1, $b)` case.
    //
    // **What this counts is balanced parentheses, blind to string context**, so a parenthesis
    // inside a string literal shifts the count: `route('x'.'(') . foo(')')` rebalances and is
    // admitted, and `route('x', ['q' => ')'])` is refused although it is one call. Both are
    // deliberate. The guard reads this package's own first-party templates, where nobody
    // adversarial writes the text, and a refusal is the safe direction for the second.
    //
    // A static suffix outside the interpolation is unaffected and still admitted:
    // `href="{{ route('x') }}#section"` puts the fragment in the attribute rather than the
    // expression, so the detector never sees it.
    $routeOrAction = '/^\s*(?:route|action)\s*\(\s*[\'"]/';
    $wholeExpression = '/^\s*(?:route|action)\s*(\((?:[^()]++|(?1))*\))\s*$/';

    // **`TicketLink::url()` is server-built for a third reason: it builds from a fixed scheme and
    // host.** It returns `https://github.com/` followed by a reference already matched against
    // `IssueReference::PATTERN`, whose characters need no escaping in a URL, or null. Admitted only as
    // the whole expression, like `route()`, so `TicketLink::url($x).$y` is still refused (#317).
    $ticketLink = '/^\s*\\\\?RobotCouncil\\\\Support\\\\TicketLink::url\s*(\((?:[^()]++|(?1))*\))\s*$/';

    $offenders = [];

    foreach ($matches as $match) {
        $value = ($match['double'] ?? '') !== '' ? $match['double']
            : ((($match['single'] ?? '') !== '') ? $match['single'] : ($match['bare'] ?? ''));

        $count = preg_match_all('/\{\{(.+?)\}\}|\{!!(.+?)!!\}/s', $value, $found, PREG_SET_ORDER);

        // `false` here would fall through to a `foreach` over an empty array and skip the
        // attribute silently, for the same reason the outer scan throws.
        if ($count === false) {
            throw new RuntimeException('Interpolation scan failed: '.preg_last_error_msg());
        }

        if ($count === 0) {
            continue;
        }

        foreach ($found as $interpolation) {
            $expression = trim($interpolation[2] ?? '') !== ''
                ? trim($interpolation[2])
                : trim($interpolation[1] ?? '');

            $serverBuilt = preg_match($literalArgumentOnly, $expression) === 1
                || (preg_match($routeOrAction, $expression) === 1
                    && preg_match($wholeExpression, $expression) === 1)
                || preg_match($ticketLink, $expression) === 1;

            // `=== 1` rather than a truthy test, deliberately: `preg_match` returns `false` on a
            // PCRE error -- a backtrack or recursion limit -- and `false === 1` is false, so an
            // expression the engine could not decide is REPORTED rather than admitted. Measured
            // with 50,000 nested parentheses: `PREG_RECURSION_LIMIT_ERROR`, and the expression is
            // reported. The error direction has to be this way round for a guard.
            if ($serverBuilt) {
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
 * **It reads TOKENS, not a quote-matching regex, and that is what makes it see a heredoc.** The
 * first version paired quote characters, so a nowdoc body -- which carries no quotes of its own --
 * was invisible: measured, `<<<'TEXT'` holding `btn-primary` returned nothing while the same class
 * in `'btn btn-primary'` returned it. #175 moved every message in `Support\Doctor` into nowdocs,
 * which would have taken that whole file out of this guard's reach silently. Worse, the regex read
 * prose BETWEEN two apostrophes as a literal, so `job's state is text-sm for the application's
 * page` did report -- the guard was answering from the wrong text in both directions at once.
 *
 * PHP's own lexer settles it: `T_CONSTANT_ENCAPSED_STRING` is a quoted literal, and
 * `T_ENCAPSED_AND_WHITESPACE` is every literal run inside a heredoc, a nowdoc, or an interpolated
 * double-quoted string. Nothing else is read, so an apostrophe in prose is an apostrophe.
 *
 * @param  string  $source  The PHP file's contents.
 * @return list<string> One finding per class-shaped token.
 */
function stylesheetClassesIn(string $source): array
{
    $prefixes = 'btn|badge|card|text|bg|flex|grid|gap|border|shadow|rounded|opacity|divide|space'
        .'|items|justify|overflow|whitespace|font|table|py|px|pt|pb|mt|mb|ml|mr|[pmwh]';

    $findings = [];

    foreach (token_get_all($source) as $token) {
        if (! \is_array($token)) {
            continue;
        }

        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            // The lexeme carries its own delimiters; the class shape below cannot contain one, so
            // trimming them is enough and no unescaping is needed.
            $value = trim($token[1], '\'"');
        } elseif ($token[0] === T_ENCAPSED_AND_WHITESPACE) {
            $value = $token[1];
        } else {
            continue;
        }

        foreach (preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (preg_match('/^(?:'.$prefixes.')-[a-z0-9]+(?:-[a-z0-9]+)*$/D', $word) === 1) {
                $findings[] = $word;
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

    // **Comments and `@verbatim` come out first, exactly as `urlAttributeInterpolations()` does
    // it.** Without this, `{{-- {!! $evil !!} --}}` is reported: the raw-echo branch added below
    // carries no comment guard of its own, and a commented-out example is not a sink.
    $withoutBlocks = preg_replace('/(?<!@)@verbatim(.*?)@endverbatim/s', '', $template) ?? $template;
    $scanned = preg_replace('/\{\{--.*?--\}\}/s', '', $withoutBlocks) ?? $withoutBlocks;

    // **The four prefixes, not a list of names.** Three reviews of this guard each found another
    // evaluated attribute missing from an enumeration -- `x-init`, `x-effect`, then `x-html`, then
    // every Alpine event -- because the set is not enumerable. Alpine maps **any** attribute
    // starting with `@` to `x-on:` (`mapAttributes(startingWith("@", into(prefix("on:"))))` in its
    // bundled dist), and any `x-` is one of its directives. So the prefixes are matched generically
    // and the inert ones are exempted below, which is the only direction that closes rather than
    // chases.
    //
    // **`\s*=\s*` is what keeps Blade's own directives out of the `@` branch.** `@use(...)`,
    // `@class([...])` and `@php ... @endphp` are followed by `(` or whitespace, never by `=`.
    //
    // **The shorthand branches require a leading letter**, because `:` and `@` followed by digits
    // occur in prose: `Ratio :1 = {{ $n }}` was reported as an attribute named `:1`.
    //
    // #241 is the `:` branch: `:href` is the same attribute as `x-bind:href` spelled two ways, and
    // this detector already owned the long form. `v-bind:` is Vue's and this package ships none;
    // included because it is cheaper than the paragraph explaining its absence.
    $prefixes = 'wire:[\w.:-]+|x-[\w.:-]+|v-bind:[\w.:-]+|@[A-Za-z][\w.:-]*|:[A-Za-z][\w.:-]*';

    // **The lookbehind excludes `@`, and that is a correction rather than an oversight.** Blade
    // renders `@@click` as a literal `@click`, so `@@click="{{ $evil }}"` is a live Alpine handler
    // -- read in `BladeCompiler::compileStatement()`, which takes the `str_contains($match[1], '@')`
    // branch and emits the directive verbatim. A class that included `@` silenced exactly that.
    // What it still excludes is what the shorthand branch would otherwise double-report:
    // `x-bind:href` and `xlink:href` both end in `:href`.
    //
    // **The value is captured with an interpolation as an atom, in all three quoting forms**, which
    // is what `urlAttributeInterpolations()` does and what this function did not. A `"` inside an
    // expression -- `wire:click="act({{ __("k") }}, {{ $evil }})"` is ordinary Blade -- otherwise
    // ends the capture early, leaves no complete interpolation in it, and consumes past the real
    // closing quote so nothing after it is ever rescanned. Single-quoted and unquoted values were
    // missed entirely. `i`, because an HTML parser lowercases attribute names and `WIRE:CLICK` is
    // live.
    $span = '\{\{.*?\}\}|\{!!.*?!!\}';

    $pattern = '/(?<![\w:.-])(?<attribute>'.$prefixes.')\s*=\s*(?:'
        .'"(?<double>(?:'.$span.'|[^"])*)"'
        ."|'(?<single>(?:".$span."|[^'])*)'"
        .'|(?<bare>(?:'.$span.'|[^\s>])+)'
        .')/is';

    // **A guard that dies reports clean**, which is the failure mode this repository names by
    // itself -- `preg_match_all` returns `false` on a PCRE error and leaves `$matches` empty, so
    // the template passes with nothing examined. The sibling detector throws for this reason and
    // this one did not, while gaining two more patterns.
    if (preg_match_all($pattern, $scanned, $attributes, PREG_SET_ORDER) === false) {
        throw new RuntimeException('Livewire expression scan failed: '.preg_last_error_msg());
    }

    foreach ($attributes as $attribute) {
        // **`wire:key` exactly, not anything starting with it.** Livewire's own reserved list is an
        // equality test on the segment before the first `.` -- read in its bundled
        // `wire-wildcard.js`, where `["snapshot","effects","model",…,"key",…].includes(directive.value)`
        // decides -- so `wire:keydown.enter`, which is idiomatic Livewire, falls through and becomes
        // an evaluated `x-on:keydown`. A `str_starts_with` exempted it. Lowercased because the
        // pattern is case-insensitive and an HTML parser lowercases attribute names.
        if (preg_match('/^wire:key(?:$|[.\s])/i', $attribute['attribute']) === 1) {
            continue;
        }

        $value = ($attribute['double'] ?? '') !== '' ? $attribute['double']
            : ((($attribute['single'] ?? '') !== '') ? $attribute['single'] : ($attribute['bare'] ?? ''));

        foreach (interpolationsIn($value) as $interpolation) {
            if (! isWireArgumentCall($interpolation)) {
                $findings[] = sprintf('%s="%s"', $attribute['attribute'], trim($interpolation));
            }
        }
    }

    // The name position: `wire:poll.{{ … }}s`, which breaks out of the attribute rather than out of
    // a string. **Both echo forms here too**, because fixing only the value position left the
    // sharper half of the same hole open.
    // **Anchored to an attribute-name position, which it was not.** `x-` matched anywhere in the
    // document, so `class="px-{{ $n }}"` -- ordinary Tailwind Blade -- was reported as an attribute
    // name and would have failed the build. `max-w-`, `space-x-`, `translate-x-` and `border-x-`
    // are all the same shape, and this file's own views already carry `class="btn btn-xs {{ … }}"`.
    if (preg_match_all('/(?<=[\s<])(?:wire:|x-)[\w.:-]*(?:\{\{(?!--)(.*?)\}\}|\{!!(.*?)!!\})/is', $scanned, $names, PREG_SET_ORDER) === false) {
        throw new RuntimeException('Livewire attribute-name scan failed: '.preg_last_error_msg());
    }

    foreach ($names as $name) {
        $expression = ($name[1] ?? '') !== '' ? $name[1] : ($name[2] ?? '');

        if (! isWireArgumentCall($expression)) {
            $findings[] = 'attribute name: {{'.trim($expression).'}}';
        }
    }

    return $findings;
}

/**
 * Whether one interpolation is exactly one call to `Support\WireArgument::of()` and nothing else.
 *
 * **Anchored at both ends, which the predicate this replaces was at neither.** It asked
 * `str_contains($interpolation, '::of(')`, so every one of these was admitted, measured against the
 * shipped detector before the change: `Wire::of($id).$evil`, `$evil.Wire::of($id)`,
 * `[Wire::of($a), $evil]`, `'::of('.$evil` -- where the token appears only inside a string literal
 * -- and `$evil /* ::of( *\/`, where it appears only inside a comment. A `wire:click` value is
 * evaluated, so that is expression injection rather than the navigation the sibling URL guard
 * risks (#240).
 *
 * The argument list is walked with a recursive group so a call inside it keeps its own parentheses:
 * `Wire::of(\RobotCouncil\Support\Scope::All)` is a real expression in this package's views.
 *
 * **Only the FULLY-QUALIFIED name is admitted, and that is what makes this check sound.** Four
 * earlier versions admitted a bare `Wire::of()` and established what `Wire` meant by reading the
 * template's `@use` directives. Five review rounds killed that, and the last killed the premise
 * rather than the implementation: **binding a name does not require a `use` statement.** Each of
 * these was executed, bound `Wire` to a foreign class, and left the guard silent:
 *
 * - `@use('Evil\Foo', 'RobotCouncil')` -- PHP substitutes an import for the FIRST segment of a
 *   relative qualified name, so `RobotCouncil\Support\WireArgument::of()` was reachable too.
 * - `@use('X as X; class Wire extends \Evil\Wire {}')` -- `compileUse()` splices its argument in
 *   verbatim, so a `;` ends the `use` and the rest is ordinary top-level PHP.
 * - `@php(class_alias(\Evil\Wire::class, 'Wire'))` -- the one-line form, which `rawOutputIn()` does
 *   not match either, and which two shipped views already use for other reasons.
 * - `@php namespace Evil; @endphp` -- a namespace declaration rebinds every bare name, no import.
 *
 * A leading `\` is immune to all of it: it resolves against the global namespace, and neither an
 * import nor `class_alias()` nor a namespace declaration changes what it names. **So there is no
 * list to keep complete**, which is what five rounds of finding one more entry argued for.
 *
 * The cost is that the views spell it out. They already did for `\RobotCouncil\Access\Role` and
 * `\RobotCouncil\Support\Scope`, so it is the house style rather than a new one.
 *
 * **The walk is blind to string context**, which #210 recorded for the URL detector and which
 * applies here unchanged: a parenthesis inside a string literal shifts the count, so
 * `Wire::of(')')` is reported. That is a false positive in the safe direction on first-party
 * templates, and it is recorded rather than fixed with a tokenizer.
 *
 * @param  string  $interpolation  The expression between the braces.
 * @return bool True when the whole expression is one such call.
 */
function isWireArgumentCall(string $interpolation): bool
{
    // `(?&balanced)` recurses into the group, so nested parentheses are consumed as a unit. The
    // possessive `[^()]++` is what keeps a long argument from backtracking exponentially.
    //
    // A nowdoc, so the pattern is the bytes written here: the backslashes are the regex's own and
    // a quoted string would have eaten one layer of them silently, which turns `\\?` -- an optional
    // leading namespace separator -- into a literal `?` that matches nothing.
    $pattern = <<<'REGEX'
        /^\s*\\RobotCouncil\\Support\\WireArgument::of\s*(?<balanced>\((?:[^()]++|(?&balanced))*\))\s*$/
        REGEX;

    return preg_match(trim($pattern), $interpolation) === 1;
}

/**
 * The `{{ … }}` and `{!! … !!}` interpolations in one attribute value.
 *
 * **Comments are NOT excluded here, and the caller is why.** The `{{` branch carries `(?!--)` and
 * the raw branch cannot, so this alone would report a commented-out example.
 * `wireExpressionInterpolations()` strips `{{-- --}}` and `@verbatim` from the template before it
 * reaches this, exactly as `urlAttributeInterpolations()` does.
 *
 * @param  string  $value  The attribute's value.
 * @return list<string> The expression inside each pair of braces.
 */
function interpolationsIn(string $value): array
{
    // **Both echo forms, because only one of them was read.** `{!! !!}` in a `wire:` attribute --
    // `wire:click="act({!! $evil !!})"` -- was invisible to this function entirely, so the raw form
    // was the one shape the expression guard never examined (#240). Blade compiles it to the same
    // `echo` either way; in an attribute value it is the *expression* that matters, and both
    // shapes put one there.
    preg_match_all('/\{\{(?!--)(.*?)\}\}|\{!!(.*?)!!\}/s', $value, $matches, PREG_SET_ORDER);

    $found = [];

    foreach ($matches as $match) {
        // The alternation leaves the unmatched branch empty, so the raw form's capture is the
        // second group whenever the first did not participate.
        $found[] = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
    }

    return $found;
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

/**
 * How many queries a callable issues on the default connection.
 *
 * Every query the dashboard's panels make goes through that connection, so one log is the whole
 * count. Lives here rather than in a test file because two files pin query counts, and a second
 * global function of this name would be a fatal redeclaration rather than a warning.
 *
 * @param  callable(): mixed  $work  What to measure.
 * @return int The number of queries logged.
 */
function queriesIssuedBy(callable $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $work();

        return \count(DB::getQueryLog());
    } finally {
        // In a `finally`, so a throwing subject does not leave the log on for whatever runs next
        DB::disableQueryLog();
    }
}
