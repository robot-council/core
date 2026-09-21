<?php

declare(strict_types=1);

/**
 * The standing guarantee that agent-supplied text cannot become markup.
 *
 * Three parts, because the ways a value reaches the document unescaped are not one thing.
 *
 * The first refuses the template constructs that skip `e()` -- `{!! !!}`, a `@php` block, and a raw
 * PHP tag -- anywhere under `resources/views/`. The second refuses the package from ever handing
 * Blade a value that escapes itself: `e()` returns `toHtml()` **unescaped** for anything `Htmlable`,
 * so `{{ $x }}` emits live markup when `$x` is an `HtmlString`, which is what rendering a task
 * description as Markdown would produce. No pattern over a template can see that, because the
 * template is identical either way, so it is checked where such a value would be built. The third
 * drives a hostile corpus through the pages themselves.
 *
 * The structural parts are what make this durable rather than a snapshot. A page written today is
 * reviewed today; the risk is the one added next year. `resources/views/` has one file now, so the
 * check grows with #30 rather than needing to be remembered.
 *
 * What none of this covers is recorded on the issue rather than implied here: a third-party
 * component library's own views under `vendor/`, and views a host has published out of the package.
 *
 * @command  vendor/bin/pest --compact tests/EscapingGuardTest.php
 */

use RobotCouncil\Access\Ability;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\WireArgument;
use RobotCouncil\Tests\Fixtures\HostileContent;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

it('finds every construct that reaches the document unescaped, and nothing that does not', function (): void {
    // The detector's own control, run on every invocation rather than by hand once. Each row was
    // measured against the real `BladeCompiler::compileString()`: where this expects a finding,
    // Blade compiles the construct into a raw echo, and where it expects none, it compiles no echo.
    // (The compiled shape is spelled out in `rawOutputIn()`'s docblock rather than here, because a
    // closing PHP tag inside a `//` comment ends PHP mode and takes the rest of the file with it.)
    expect(rawOutputIn('<h3>{!! $title !!}</h3><p>{{ $safe }}</p>'))
        ->toBe(['unescaped echo: {!! $title !!}']);

    // The same compiled output as the line above, with nothing about either that looks dangerous
    expect(rawOutputIn('@php echo $evil; @endphp'))->toBe(['@php block: echo $evil;']);
    expect(rawOutputIn('<p><?php echo $evil; ?></p>'))->toBe(['a raw PHP tag']);

    // `@verbatim` is the standard escape for Alpine and Vue mustaches. Blade stores those blocks
    // before it strips comments, so a `{{--` inside one cannot open a comment -- and a detector
    // that stripped comments first, over the whole file, would swallow the echo below along with it.
    expect(rawOutputIn("@verbatim\n{{-- literal\n@endverbatim\n<p>{!! \$evil !!}</p>\n{{-- real --}}"))
        ->toBe(['unescaped echo: {!! $evil !!}']);

    // A comment, and prose referring to the construct, are not findings. This package's own
    // verification page contains that sentence, so a detector without this reports the very file
    // it was written to protect.
    expect(rawOutputIn(<<<'BLADE'
        {{-- A comment mentioning {!! $ignored !!} is not a directive --}}
        <p>There is no `{!! !!}` here, and there never should be.</p>
        <p>{{ $safe }}</p>
        BLADE))->toBeEmpty();
});

it('finds a template at any depth, not only at the top of the tree', function (): void {
    // The scanner's control, and the reason it is not `glob`. A walk of the top level alone would
    // keep passing once #30 adds `resources/views/livewire/` -- green, and covering nothing in it.
    $root = $this->temporaryDirectory('views');

    mkdir($root.'/livewire/partials', 0o777, recursive: true);

    file_put_contents($root.'/top.blade.php', '<p>{{ $safe }}</p>');
    file_put_contents($root.'/livewire/board.blade.php', '<p>{{ $safe }}</p>');
    file_put_contents($root.'/livewire/partials/row.blade.php', '<td>{!! $title !!}</td>');

    $found = array_map(basename(...), bladeTemplatesIn($root));

    // Sorted here rather than relied on from the walk: `getPathname()` joins with
    // `DIRECTORY_SEPARATOR`, and `/` and `\` fall on opposite sides of the letters, so a directory
    // and a sibling file can order differently on the ubuntu and windows cells.
    sort($found);

    expect($found)->toBe(['board.blade.php', 'row.blade.php', 'top.blade.php']);

    $offenders = [];

    foreach (bladeTemplatesIn($root) as $template) {
        $offenders = [...$offenders, ...rawOutputIn((string) file_get_contents($template))];
    }

    expect($offenders)->toBe(['unescaped echo: {!! $title !!}']);
});

it('renders every value in the package views escaped', function (): void {
    $views = bladeTemplatesIn(__DIR__.'/../resources/views');

    // A scan that matched nothing would satisfy the assertion below and prove the opposite of what
    // it claims. `not->toBeEmpty()` is too weak alone, because one top-level view keeps it true
    // however much of the tree goes unexamined -- which is why the walk's recursion is controlled
    // in the test above rather than trusted.
    expect($views)->not->toBeEmpty();

    $offenders = [];

    foreach ($views as $view) {
        foreach (rawOutputIn((string) file_get_contents($view)) as $finding) {
            $offenders[] = basename($view).' -- '.$finding;
        }
    }

    // The message carries the offenders, because `toBeEmpty()` alone reports only "Failed asserting
    // that an array is empty" and leaves the reader to re-derive which file and which expression
    expect($offenders)->toBeEmpty('Unescaped output in package views:'.PHP_EOL.implode(PHP_EOL, $offenders));
});

it('ignores a construct named in a comment, and still finds one that is used', function (): void {
    // Stripping comments makes the check narrower, so it needs its own control: a detector that
    // stripped too much would pass this file and every future one. Both halves are asserted.
    $root = $this->temporaryDirectory('sources');

    file_put_contents($root.'/Mentioned.php', <<<'PHP'
        <?php
        // This package never builds an HtmlString, and Str::markdown is refused.
        /** Nor an Htmlable, nor ->toHtml(). */
        final class Mentioned
        {
            public function safe(): string
            {
                return 'HtmlString appears here in a string literal, which is not a comment';
            }
        }
        PHP);

    file_put_contents($root.'/Uses.php', <<<'PHP'
        <?php
        use Illuminate\Support\HtmlString;
        final class Uses
        {
            public function build(string $x): HtmlString
            {
                return new HtmlString($x);
            }
        }
        PHP);

    file_put_contents($root.'/mentions.blade.php', '{{-- Htmlable and Str::markdown are refused --}}<p>{{ $safe }}</p>');

    $find = static fn (string $file): bool => str_contains(sourceWithoutComments($file), 'HtmlString')
        || str_contains(sourceWithoutComments($file), 'Htmlable');

    expect($find($root.'/Mentioned.php'))->toBeTrue()   // the string literal survives, correctly
        ->and($find($root.'/Uses.php'))->toBeTrue()
        ->and($find($root.'/mentions.blade.php'))->toBeFalse();

    // And the comments really were removed, rather than the file being read whole
    expect(sourceWithoutComments($root.'/Mentioned.php'))
        ->not->toContain('never builds')
        ->not->toContain('Nor an Htmlable')
        ->toContain('string literal, which is not a comment');
});

it('never hands Blade a value that escapes itself', function (): void {
    // `e()` returns `$value->toHtml()` unescaped for anything `Htmlable` or
    // `DeferringDisplayableValue`, so `{{ $x }}` emits live markup for an `HtmlString` -- measured:
    // `{{ $x }}` given `new HtmlString('<img src=x onerror=alert(1)>')` renders the live tag. The
    // structural check above cannot see it, because the template is identical either way.
    //
    // The realistic route is Markdown. `Str::markdown()` returns raw HTML from whatever it was
    // given, so rendering a task description that way carries an agent's `<img onerror=...>`
    // straight through. If #30 wants Markdown, that has to be answered here with a sanitizer rather
    // than by deleting this test.
    $sources = [
        ...bladeTemplatesIn(__DIR__.'/../resources/views'),
        ...phpSourcesIn(__DIR__.'/../src'),
    ];

    expect($sources)->not->toBeEmpty();

    $selfEscaping = ['HtmlString', 'Htmlable', '->toHtml(', 'Str::markdown', '->markdown(', '@markdown'];

    $offenders = [];

    foreach ($sources as $source) {
        // Comments removed first, or this check reports the paragraph in the layout explaining that
        // the package never builds one of these -- which is what it did when it was first written
        $contents = sourceWithoutComments($source);

        foreach ($selfEscaping as $construct) {
            if (str_contains($contents, $construct)) {
                $offenders[] = basename($source).' -- '.$construct;
            }
        }
    }

    expect($offenders)->toBeEmpty(
        'These build markup that `{{ }}` will not escape:'.PHP_EOL.implode(PHP_EOL, $offenders)
    );
});

it('renders a hostile value inert whatever sink it was aimed at', function (string $payload, array $forbidden, ?string $escaped): void {
    // `machine_label` alone, because `harness` is `varchar(32)` and the script-breakout payload is
    // 34 characters. Postgres refuses an overlong value with `22001` where SQLite stores it, so
    // writing both passed every local run and failed only in the `postgres` job. Asserted rather
    // than left as a comment, so a payload added later that does not fit fails on every driver.
    expect(mb_strlen($payload))->toBeLessThanOrEqual(64);

    // Written past the endpoint's validation deliberately: `machine_label` is charset-limited, so
    // this string cannot arrive through the API and the page's escaping has to be its own guarantee
    // rather than the validator's. The fields #30 adds are prose and have no such limit.
    $enrollment = requestDeviceCode($this);

    $enrollment['record']->forceFill(['machine_label' => $payload])->save();

    $response = $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]));

    $response->assertOk();

    $html = (string) $response->getContent();

    // The payload reached the page at all: without this the assertions below pass on any page that
    // simply failed to render the value, which is the same output a broken fixture produces
    expect($html)->toContain($escaped ?? $payload);

    foreach ($forbidden as $live) {
        expect($html)->not->toContain($live);
    }
})->with(HostileContent::dataset());

it('reports an interpolation in a URL attribute, and leaves a server-built URL alone', function (): void {
    // The detector's own control. A `javascript:` URL survives `htmlspecialchars` untouched, so a
    // guard that reported nothing here would look identical to one with nothing to report.
    expect(urlAttributeInterpolations('<a href="{{ $task->link }}">go</a>'))
        ->toBe(['href="$task->link"']);

    // Unquoted, which breaks out on a space rather than a quote
    expect(urlAttributeInterpolations('<img src={{ $avatar }}>'))->toBe(['src="$avatar"']);

    // Every URL the server built is fine, and the package's own pages are made of these
    expect(urlAttributeInterpolations('<form action="{{ route(\'robot-council.enroll.deny\') }}">'))->toBeEmpty();
    expect(urlAttributeInterpolations('<img src="{{ asset(\'x.png\') }}">'))->toBeEmpty();

    // Not a URL attribute, so not this check's business -- `rawOutputIn()` covers the escaping
    expect(urlAttributeInterpolations('<p title="{{ $task->title }}">x</p>'))->toBeEmpty();
});

it('puts no requester-supplied or agent-supplied value in a URL attribute', function (): void {
    $views = bladeTemplatesIn(__DIR__.'/../resources/views');

    expect($views)->not->toBeEmpty();

    $offenders = [];

    foreach ($views as $view) {
        foreach (urlAttributeInterpolations((string) file_get_contents($view)) as $finding) {
            $offenders[] = basename($view).' -- '.$finding;
        }
    }

    // Escaping cannot help here, so the rule is that such a value never reaches a URL at all. A
    // page that genuinely needs one has to allowlist the scheme at the point of use and record why.
    expect($offenders)->toBeEmpty(
        'Interpolations in URL attributes that the server did not build:'.PHP_EOL.implode(PHP_EOL, $offenders)
    );
});

it('reports an interpolation in a Livewire expression, and leaves a guarded one alone', function (): void {
    // The detector's own control, and it has to discriminate three things rather than two: an
    // unguarded interpolation in an expression, a guarded one, and `wire:key`, which is not an
    // expression at all.
    expect(wireExpressionInterpolations('<button wire:click="act(\'{{ $x }}\')">go</button>'))
        ->toBe(['wire:click="$x"']);

    // Unquoted is no safer: the value is expression text either way.
    expect(wireExpressionInterpolations('<button wire:click="act({{ $id }})">go</button>'))
        ->toBe(['wire:click="$id"']);

    // Routed through the whitelist, so nothing to report.
    expect(wireExpressionInterpolations('<button wire:click="act({{ Wire::of($id) }})">go</button>'))
        ->toBeEmpty();

    // The attribute NAME position, which breaks out of the attribute rather than out of a string.
    expect(wireExpressionInterpolations('<div wire:poll.{{ $seconds }}s></div>'))
        ->toBe(['attribute name: {{$seconds}}']);

    expect(wireExpressionInterpolations('<div wire:poll.{{ Wire::of($seconds) }}s></div>'))
        ->toBeEmpty();

    // `wire:key` is a literal identifier Livewire never evaluates, so it is deliberately exempt.
    // Without this row the guard would demand a guarantee that buys nothing and the exemption
    // would be invisible.
    expect(wireExpressionInterpolations('<li wire:key="task-{{ $task[\'id\'] }}">x</li>'))
        ->toBeEmpty();

    // An ordinary attribute is not this check's business; `rawOutputIn()` covers the escaping.
    expect(wireExpressionInterpolations('<p title="{{ $title }}">x</p>'))->toBeEmpty();
});

it('writes nothing into a Livewire expression that did not come from the whitelist', function (): void {
    $views = bladeTemplatesIn(__DIR__.'/../resources/views');

    expect($views)->not->toBeEmpty();

    $offenders = [];

    foreach ($views as $view) {
        foreach (wireExpressionInterpolations((string) file_get_contents($view)) as $finding) {
            $offenders[] = basename($view).' -- '.$finding;
        }
    }

    // Escaping cannot help in an expression context, which is why the rule is a whitelist rather
    // than an escaper: `Support\WireArgument::of()` refuses a value it cannot write instead of
    // guessing how two parsers will read it.
    expect($offenders)->toBeEmpty(
        'Unguarded interpolations in Livewire expressions:'.PHP_EOL.implode(PHP_EOL, $offenders)
    );
});

it('refuses a value that would break out of a Livewire expression', function (string $value): void {
    expect(fn (): string => WireArgument::of($value))->toThrow(InvalidArgumentException::class);
})->with([
    'a quote, which closes the string literal' => ["a'b"],
    'a double quote, which closes the attribute' => ['a"b'],
    'a comma, which adds an argument' => ['a,b'],
    'a parenthesis, which ends the call' => ['a)b'],
    'a space' => ['a b'],
    'an angle bracket' => ['a<b'],
    'a backslash' => ['a\\b'],
    'empty' => [''],
]);

it('accepts a value exactly at the length limit, and refuses one past it', function (): void {
    // The boundary, from both sides. Without the accepting half, `>` and `>=` are the same check
    // for every input any other test supplies, and the limit could move by one unnoticed.
    $atTheLimit = str_repeat('a', WireArgument::MAX);

    expect(WireArgument::of($atTheLimit))->toBe($atTheLimit)
        ->and(fn (): string => WireArgument::of($atTheLimit.'a'))->toThrow(InvalidArgumentException::class, (string) WireArgument::MAX);
});

it('writes through the values an action argument is actually made of', function (): void {
    // The control for the refusals above: four refusals prove nothing if it refuses everything.
    // These are the three vocabularies the package interpolates today.
    expect(WireArgument::of(42))->toBe('42')
        ->and(WireArgument::of(Ability::CoordinatorDirect))->toBe('coordinator:direct')
        ->and(WireArgument::of(TaskStatus::Pending))->toBe(TaskStatus::Pending->value)
        ->and(WireArgument::of('claude-code'))->toBe('claude-code');
});

it('finds a class name written into a PHP literal, and ignores one written in prose', function (): void {
    // The detector's own control, and the discrimination that matters is between a literal and a
    // comment: #111's whole finding was that prose yields class candidates, so a check that read
    // comments would be red on every commit and say nothing.
    expect(stylesheetClassesIn("<?php \$x = 'btn btn-primary';"))
        ->toBe(['btn-primary']);

    expect(stylesheetClassesIn('<?php $x = "flex items-center gap-2";'))
        ->toBe(['items-center', 'gap-2']);

    // Prose naming the same classes, which `sourceWithoutComments()` removes before this ever runs
    expect(stylesheetClassesIn(sourceWithoutComments(writeProbe('<?php
        // A lapsed lease is marked with badge-warning rather than text-warning.
        /** The card uses card-body and bg-base-200. */
        final class Prose {}
    '))))->toBeEmpty();

    // The strings this package actually holds, which a looser rule would have caught
    expect(stylesheetClassesIn("<?php \$x = 'robot-council installation'; \$y = 'tasks:create';"))
        ->toBeEmpty();
});

it('writes no stylesheet class into a PHP literal, because `src/` is not scanned', function (): void {
    // #111 decided `src/` is not a Tailwind source, which removed 30 KB of prose-derived CSS from
    // the shipped artifact. The cost is the opposite failure: a class named in PHP reaches no
    // stylesheet and renders unstyled with nothing reporting it. This is what makes that loud.
    $sources = phpSourcesIn(__DIR__.'/../src');

    expect($sources)->not->toBeEmpty();

    $offenders = [];

    foreach ($sources as $source) {
        foreach (stylesheetClassesIn(sourceWithoutComments($source)) as $class) {
            $offenders[] = basename($source).' -- '.$class;
        }
    }

    expect($offenders)->toBeEmpty(
        'These name a stylesheet class in PHP, which `src/` is no longer scanned for:'
        .PHP_EOL.implode(PHP_EOL, $offenders)
        .PHP_EOL.'Move the class into a view, or add the directory back as an `@source`.'
    );
});

it('shows the javascript payload is detectable, against a sink no package view has', function (): void {
    // The corpus row aimed at a URL cannot fail against the package's own pages, because none of
    // them puts a value in a URL attribute -- which is the guarantee the test above enforces. Left
    // there, the row would be a tripwire nobody can trip, indistinguishable from a passing one.
    // So it is exercised here against a deliberately unguarded sink.
    $payload = 'javascript:alert(1)';

    $unguarded = '<a href="'.e($payload).'">go</a>';

    $forbidden = arrayValue(HostileContent::payloads()['a javascript URL'])['forbidden'];

    $survived = array_values(array_filter(
        arrayValue($forbidden),
        static fn (mixed $live): bool => \is_string($live) && str_contains($unguarded, $live)
    ));

    // Escaping the payload changes nothing about it, which is the whole point of the row -- and it
    // is the one assertion here that is not circular, since the sink string is built by this test.
    expect(e($payload))->toBe($payload);

    // A property rather than the exact array. Pinning the contents would fail this test when a
    // genuinely stronger entry is added to the corpus, which punishes the improvement.
    expect($survived)->not->toBeEmpty();
});

it('admits no executable payload in any charset-limited field, though it does admit an off-site URL', function (string $label, string $pattern): void {
    // An earlier version of this test claimed these allowlists "admit no colon, so none can carry
    // `javascript:`". That is false, and it passed only because its single probe was
    // `javascript:alert(1)`, whose parentheses are out of charset. A lock name's class is
    // `[A-Za-z0-9._:\/-]` and the colon is in it deliberately, so `branch:feature/foo` works.
    //
    // What is true, and is what these fields actually buy, is narrower: none of the four admits a
    // parenthesis, an equals sign, a percent or whitespace, so none can express a call or an
    // assignment and none can carry executable JavaScript.
    foreach (['(', ')', '=', '%', ' ', '"', "'"] as $needed) {
        expect(preg_match($pattern, 'a'.$needed.'b'))->toBe(0);
    }

    // The control: the pattern accepts something ordinary, so one that refused everything could not
    // pass as a guarantee
    expect('harmless-value')->toMatch($pattern);
})->with([
    'harness' => ['harness', '/^[a-z0-9-]{1,32}$/D'],
    'machine_label' => ['machine_label', '/^[A-Za-z0-9._-]{1,64}$/D'],
    'project_id' => ['project_id', '/^[A-Za-z0-9._\/-]{1,128}$/D'],

    // Read from the source of truth rather than retyped. The other three have one call site each
    // and no constant yet; this one has three and does.
    'a lock name' => ['a lock name', Locks::NAME],
]);

it('is why the structural rule exists: an off-site URL IS expressible in two of those fields', function (): void {
    // The charset limits stop executable JavaScript and nothing else. `//evil.example/steal` is a
    // protocol-relative URL -- an off-site link in an `href`, a remote script or image load in a
    // `src` -- and it needs none of the characters the fields exclude.
    expect('//evil.example/steal')->toMatch('/^[A-Za-z0-9._\/-]{1,128}$/D')
        ->and('//evil.example/steal')->toMatch(Locks::NAME)
        ->and('javascript:')->toMatch(Locks::NAME);

    // Which is the point: the guarantee is that no such value reaches a URL attribute at all, not
    // that the value could not be a URL. The charset limits are a second line, not the first.
    expect(urlAttributeInterpolations('<a href="{{ $task->project_id }}">go</a>'))
        ->toBe(['href="$task->project_id"']);
});

it('is not blinded by an escaped verbatim marker', function (): void {
    // `@@verbatim` is the escape sequence telling Blade NOT to open a block, so Blade renders the
    // marker literally and compiles what follows. A stripper without Blade's own `(?<!@)` lookbehind
    // opens a block anyway and deletes to the next `@endverbatim`, taking a live sink with it.
    $template = "@@verbatim\n<a href=\"{{ \$agentValue }}\">go</a>\n@endverbatim";

    expect(urlAttributeInterpolations($template))->toBe(['href="$agentValue"'])
        ->and(rawOutputIn("@@verbatim\n<p>{!! \$evil !!}</p>\n@endverbatim"))
        ->toBe(['unescaped echo: {!! $evil !!}']);

    // And the unescaped form still suppresses, so the fix did not simply disable the stripper
    expect(urlAttributeInterpolations("@verbatim\n<a href=\"{{ \$x }}\">go</a>\n@endverbatim"))->toBeEmpty();
});

it('allows only a URL the server built from a literal', function (): void {
    // `url()` and `asset()` return their argument verbatim whenever `UrlGenerator::isValidUrl()`
    // accepts it, so the helper's name alone is not a guarantee. Measured: `url('//evil.example')`
    // and `url('https://evil.example/x')` come back unchanged.
    expect(urlAttributeInterpolations('<a href="{{ url($agentValue) }}">go</a>'))
        ->toBe(['href="url($agentValue)"'])
        ->and(urlAttributeInterpolations('<img src="{{ asset($agentValue) }}">'))
        ->toBe(['src="asset($agentValue)"']);

    // A literal argument is the case the package actually uses
    expect(urlAttributeInterpolations('<a href="{{ url(\'/docs\') }}">go</a>'))->toBeEmpty()
        ->and(urlAttributeInterpolations('<form action="{{ route(\'robot-council.enroll.deny\') }}">'))->toBeEmpty();
});

it('sees an interpolation that contains a quote, or spans lines, or sits in a widened attribute', function (): void {
    // A double quote inside the expression is ordinary Blade. Capturing the attribute value as
    // `[^"]*` ended it at that quote, found no complete interpolation, and then consumed past the
    // real closing quote so nothing was rescanned -- a silent false negative.
    expect(urlAttributeInterpolations('<a href="{{ $task->urlFor("view") }}">go</a>'))
        ->toBe(['href="$task->urlFor("view")"']);

    expect(urlAttributeInterpolations("<img src={{ \$task\n->link }}>"))
        ->toBe(['src="$task ->link"']);

    // Attributes that carry a URL and were not in the first list
    expect(urlAttributeInterpolations('<object data="{{ $x }}"></object>'))->toBe(['data="$x"']);
    expect(urlAttributeInterpolations('<img srcset="{{ $x }} 2x">'))->toBe(['srcset="$x"']);

    // And a name that merely ends in one of them is not one of them
    expect(urlAttributeInterpolations('<a data-href="{{ $x }}">go</a>'))->toBeEmpty();
});
