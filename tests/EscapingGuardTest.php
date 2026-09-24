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
use RobotCouncil\Support\WorkIdentity;
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

it('reports every shape that put the guard token somewhere it did not govern', function (string $expression): void {
    // **The admit rule was anchored at neither end**, so `str_contains($interpolation, '::of(')`
    // passed every row here. Measured against the shipped detector before the change, each one
    // admitted (#240). A `wire:click` value is evaluated, so this is expression injection rather
    // than the navigation the sibling URL guard risks.
    expect(wireExpressionInterpolations('<button wire:click="act({{ '.$expression.' }})">go</button>'))
        ->not->toBeEmpty();
})->with([
    'concatenated after the call' => 'Wire::of($id).$evil',
    'concatenated before it' => '$evil.Wire::of($id)',
    'one element of a list' => '[Wire::of($a), $evil]',
    'the token inside a string literal' => "'::of('.\$evil",
    'the token inside a comment' => '$evil /* ::of( */',
]);

it('still admits the two call shapes the package actually writes', function (): void {
    // **The negative control for the test above, and it is the one that matters.** An anchored rule
    // that reported everything would satisfy every row there and fail the whole suite over the real
    // views. The second row is not hypothetical: `task-board.blade.php` and `administration.blade.php`
    // both pass a fully-qualified enum case, whose `::` and `\` the pattern has to carry.
    expect(wireExpressionInterpolations('<button wire:click="act({{ Wire::of($id) }})">go</button>'))
        ->toBeEmpty();

    // **Nowdocs, because Rector rewrites the qualified name inside a quoted literal.** Measured: it
    // turned `\RobotCouncil\Support\Scope::All` into `'.Scope::class.'`, which resolves WITHOUT the
    // leading separator -- so the test kept passing while no longer pinning the shape the views
    // carry. The bytes are the subject here, so they are written where nothing rewrites them.
    $qualified = <<<'BLADE'
        <button wire:click="go({{ Wire::of(\RobotCouncil\Support\Scope::All) }})">go</button>
        BLADE;

    $unqualified = <<<'BLADE'
        <button wire:click="go({{ Wire::of(RobotCouncil\Support\Scope::All) }})">go</button>
        BLADE;

    expect(wireExpressionInterpolations($qualified))->toBeEmpty()
        ->and(wireExpressionInterpolations($unqualified))->toBeEmpty();
});

it('examines the raw echo form in a Livewire expression, which it read past entirely', function (): void {
    // `interpolationsIn()` matched only `{{ … }}`, so this shape was invisible to the expression
    // guard -- the one echo form it never examined (#240).
    expect(wireExpressionInterpolations('<button wire:click="act({!! $evil !!})">go</button>'))
        ->toBe(['wire:click="$evil"']);

    // And a guarded raw echo is still admitted, so the fix reports the shape rather than the syntax.
    expect(wireExpressionInterpolations('<button wire:click="act({!! Wire::of($id) !!})">go</button>'))
        ->toBeEmpty();
});

it('examines the evaluated Alpine attributes it did not name', function (string $attribute): void {
    // `x-init` and `x-effect` are evaluated expressions and were in no prefix list. `x-text` is a
    // value rather than a sink, and is examined because that is cheaper than arguing about it.
    expect(wireExpressionInterpolations('<div '.$attribute.'="{{ $evil }}"></div>'))
        ->toBe([$attribute.'="$evil"']);
})->with(['x-init', 'x-effect', 'x-text']);

it('examines the Alpine shorthand, which neither detector reached', function (string $attribute): void {
    // **#241, and which detector owns it is a decision rather than an accident.** `:href` is the
    // same attribute as `x-bind:href` spelled two ways, and this detector already owned the long
    // form -- so splitting one attribute across two detectors by spelling would be the arbitrary
    // choice. `urlAttributeInterpolations()` is left alone, and `:class` comes along free.
    //
    // `v-bind:` is Vue's and this package ships none. It is three characters in an alternation and
    // the failure it guards is somebody reaching for a familiar spelling, so it is included rather
    // than argued about.
    expect(wireExpressionInterpolations('<a '.$attribute.'="{{ $evil }}">x</a>'))
        ->toBe([$attribute.'="$evil"']);
})->with([':href', ':class', 'v-bind:href', 'x-bind:href']);

it('does not let the shorthand branch capture an unrelated attribute', function (): void {
    // **What the lookbehind actually buys, which is narrower than an earlier version of this test
    // claimed.** It asserted that `x-bind:href` is reported once -- and that assertion cannot fail:
    // `preg_match_all` consumes the whole attribute in one match starting at the `x`, so `:href` is
    // never tried inside text already consumed. It returns 1 with the lookbehind deleted entirely.
    //
    // The real claim is over-matching: `xlink:href` is not ours, ends in `:href`, and must not be
    // captured by the `:[\w.:-]+` branch.
    expect(wireExpressionInterpolations('<a xlink:href="{{ $evil }}">x</a>'))->toBeEmpty()
        ->and(wireExpressionInterpolations('<svg xmlns:xlink="{{ $ns }}"></svg>'))->toBeEmpty()
        // The control in the same run, so the two silences are absences rather than a dead branch.
        ->and(wireExpressionInterpolations('<a :href="{{ $evil }}">x</a>'))->toBe([':href="$evil"']);
});

it('pins the CLASS and not only the method, which an earlier version did not', function (string $expression, bool $reported): void {
    // **The admit rule matched any namespace in front of `Wire::of`**, so `Evil\Wire::of($evil)`
    // passed -- and the alias check could not close it, because a fully-qualified name needs no
    // alias. Measured against the first version of this change, not against the original guard.
    //
    // A leading separator is allowed only on the qualified form: `\Wire::of()` names the ROOT
    // `Wire`, which no `@use` can point at, so it is a different class wearing the guard's spelling.
    $findings = wireExpressionInterpolations('<button wire:click="act({{ '.$expression.' }})">x</button>');

    expect($findings === [])->toBe(! $reported);
})->with([
    // **Nowdocs for every qualified name, because Rector rewrites them in a quoted literal.**
    // Measured on this very dataset: it collapsed the two rows below into an identical
    // `WireArgument::class . '::of($id)'`, which drops the leading separator -- so the row that
    // exists to pin `\Wire::of` against the qualified form would have stopped testing anything
    // while both still passed. The bytes are the subject here.
    'the bare name every view writes' => [<<<'PHP'
        Wire::of($id)
        PHP, false],
    'the class it aliases' => [<<<'PHP'
        WireArgument::of($id)
        PHP, false],
    'this package, qualified' => [<<<'PHP'
        RobotCouncil\Support\WireArgument::of($id)
        PHP, false],
    'this package, qualified with a leading separator' => [<<<'PHP'
        \RobotCouncil\Support\WireArgument::of($id)
        PHP, false],
    'another namespace entirely' => [<<<'PHP'
        Evil\Wire::of($evil)
        PHP, true],
    'a deeper namespace' => [<<<'PHP'
        A\B\C\WireArgument::of($evil)
        PHP, true],
    'the root namespace, which no alias can reach' => [<<<'PHP'
        \Wire::of($evil)
        PHP, true],
    'a name that merely ends in the right one' => [<<<'PHP'
        EvilWire::of($evil)
        PHP, true],
    'the wrong case, because PHP is case-insensitive and this guard is not' => [<<<'PHP'
        wire::OF($evil)
        PHP, true],
]);

it('admits a nested call, which is what the recursive group is for', function (): void {
    // **The mutant that survived the first version.** Replacing the recursion with `\([^()]*\)`
    // passed every test and every view, because no fixture carried a nested parenthesis. This is
    // the input that tells the two apart.
    expect(wireExpressionInterpolations('<button wire:click="act({{ Wire::of(max(1, $n)) }})">x</button>'))
        ->toBeEmpty()
        // And the balance still has to close: an unclosed call is not a call.
        ->and(wireExpressionInterpolations('<button wire:click="act({{ Wire::of(max(1, $n) }})">x</button>'))
        ->not->toBeEmpty();
});

it('examines a value in every quoting form, and whatever case it is written in', function (string $template): void {
    // **A `"` inside an expression truncated the capture**, and everything after it in the attribute
    // went unexamined -- `wire:click="act({{ __("k") }}, {{ $evil }})"` is ordinary Blade and
    // reported nothing. The sibling `urlAttributeInterpolations()` had solved this; this function
    // had not. Single-quoted and unquoted values were missed outright, and an HTML parser
    // lowercases attribute names, so `WIRE:CLICK` is live.
    expect(wireExpressionInterpolations($template))->not->toBeEmpty();
})->with([
    'a quote inside the expression' => '<button wire:click="act({{ __("k") }}, {{ $evil }})">x</button>',
    'a single-quoted value' => "<button wire:click='act({{ \$evil }})'>x</button>",
    'an unquoted value' => '<button wire:click=act({{ $evil }})>x</button>',
    'an uppercase attribute' => '<button WIRE:CLICK="{{ $evil }}">x</button>',
    'a mixed-case attribute' => '<button Wire:Click="{{ $evil }}">x</button>',
]);

it('examines the escaped directive Blade renders as a live one', function (): void {
    // **`@@click` renders as `@click`.** Read in `BladeCompiler::compileStatement()`, which takes
    // the `str_contains($match[1], '@')` branch and emits the directive verbatim -- so this is a
    // working Alpine handler with a Blade interpolation in it. A lookbehind class that excluded `@`
    // silenced exactly this, which is why the class does not.
    expect(wireExpressionInterpolations('<button @@click="{{ $evil }}">x</button>'))
        ->toBe(['@click="$evil"'])
        ->and(wireExpressionInterpolations('<button @click="{{ $evil }}">x</button>'))
        ->toBe(['@click="$evil"']);
});

it('reads the raw echo form in the attribute-NAME position too', function (): void {
    // Fixing only the value position left the sharper half open: the name position breaks out of
    // the attribute rather than out of a string.
    expect(wireExpressionInterpolations('<div wire:poll.{!! $evil !!}s></div>'))
        ->toBe(['attribute name: {{$evil}}'])
        ->and(wireExpressionInterpolations('<div wire:poll.{!! Wire::of($s) !!}s></div>'))
        ->toBeEmpty();
});

it('examines every Alpine directive that evaluates or writes', function (string $attribute): void {
    // `x-html` is the sharpest and was absent: it evaluates its expression AND writes the result as
    // HTML, so leaving it out while including `x-text` -- a strictly weaker sink -- was backwards.
    expect(wireExpressionInterpolations('<div '.$attribute.'="{{ $evil }}"></div>'))
        ->toBe([$attribute.'="$evil"']);
})->with(['x-html', 'x-if', 'x-for', 'x-init', 'x-effect', 'x-text', 'x-ref', 'x-teleport', 'x-mask']);

it('leaves a commented-out example alone, in both echo forms', function (): void {
    // Reading `{!! !!}` without stripping comments first made this a finding. The sibling detector
    // strips `{{-- --}}` and `@verbatim`; this one now does too.
    expect(wireExpressionInterpolations('<button wire:click="{{-- {!! $evil !!} --}}">x</button>'))
        ->toBeEmpty()
        ->and(wireExpressionInterpolations('@verbatim<button wire:click="{{ $evil }}">x</button>@endverbatim'))
        ->toBeEmpty()
        // The control: the same expression outside a comment is still reported.
        ->and(wireExpressionInterpolations('<button wire:click="{!! $evil !!}">x</button>'))
        ->not->toBeEmpty();
});

it('reports a single-argument alias, which is the shorter way past the two-argument check', function (): void {
    // `@use('Evil\Wire')` compiles to `use Evil\Wire;`, after which `Wire::of()` in that view is
    // somebody else's method and every expression check still passes.
    expect(wireExpressionInterpolations("@use('Evil\\Wire')"))
        ->toBe(['alias: Evil\\Wire as Wire'])
        ->and(wireExpressionInterpolations("@use('Evil\\WireArgument')"))
        ->not->toBeEmpty()
        // The controls: this package's own import in either form, and an unrelated one.
        ->and(wireExpressionInterpolations("@use('RobotCouncil\\Support\\WireArgument')"))->toBeEmpty()
        ->and(wireExpressionInterpolations("@use('RobotCouncil\\Support\\WireArgument', 'Wire')"))->toBeEmpty()
        ->and(wireExpressionInterpolations("@use('RobotCouncil\\Support\\Scope')"))->toBeEmpty();
});

it('reports an alias that does not point at the class the whole rule rests on', function (): void {
    // **Every view spells the helper `Wire`, through a per-view Blade alias.** A template aliasing
    // that name to something else satisfies every expression check in this file while calling into
    // anything at all, so the alias is checked rather than assumed.
    expect(wireExpressionInterpolations("@use('Evil\\Thing', 'Wire')"))
        ->toBe(['alias: Evil\\Thing as Wire']);

    // The control, and the spelling the views actually carry.
    expect(wireExpressionInterpolations("@use('RobotCouncil\\Support\\WireArgument', 'Wire')"))
        ->toBeEmpty();
});

it("leaves `data-href` alone, which is what the URL detector's lookbehind is for", function (): void {
    // Asserted because the shorthand work is adjacent to that lookbehind: a pattern widened to
    // admit `:href` must not also start matching the attribute the lookbehind exists to exclude.
    expect(urlAttributeInterpolations('<a data-href="{{ $evil }}">x</a>'))->toBeEmpty()
        // The control in the same run, so the silence above is an absence rather than a broken
        // detector.
        ->and(urlAttributeInterpolations('<a href="{{ $evil }}">x</a>'))->toBe(['href="$evil"']);
});

it('examines every Alpine event, not the one directive that was named', function (string $attribute): void {
    // **Alpine maps ANY attribute starting with `@` to `x-on:`** -- `mapAttributes(startingWith("@",
    // into(prefix("on:"))))` in its bundled dist -- so the whole event surface is evaluated and the
    // prefix list named `@click` alone. `@click.away` was caught only because a wildcard followed
    // the literal.
    expect(wireExpressionInterpolations('<input '.$attribute.'="save({{ $evil }})">'))
        ->toBe([$attribute.'="$evil"']);
})->with(['@submit.prevent', '@keydown.enter', '@input', '@blur', '@mouseover', '@click']);

it('exempts `wire:key` exactly, the way Livewire reserves it', function (): void {
    // **Livewire's reserved list is an equality test on the segment before the first `.`** -- read
    // in its bundled `wire-wildcard.js`, where `[…,"key",…].includes(directive.value)` decides.
    // So `wire:keydown.enter`, which is idiomatic, falls through and becomes an evaluated
    // `x-on:keydown`, while a `str_starts_with` exemption swallowed it.
    expect(wireExpressionInterpolations('<input wire:keydown.enter="save({{ $evil }})">'))
        ->toBe(['wire:keydown.enter="$evil"'])
        ->and(wireExpressionInterpolations('<input wire:keyup.escape="save({{ $evil }})">'))
        ->not->toBeEmpty()
        // The exemption itself still holds, in either case, because an HTML parser lowercases.
        ->and(wireExpressionInterpolations('<li wire:key="task-{{ $id }}">x</li>'))->toBeEmpty()
        ->and(wireExpressionInterpolations('<li WIRE:KEY="{{ $id }}">x</li>'))->toBeEmpty()
        ->and(wireExpressionInterpolations('<li wire:key.foo="{{ $id }}">x</li>'))->toBeEmpty();
});

it('leaves ordinary Tailwind alone, which the attribute-name scan did not', function (string $class): void {
    // **`x-` matched anywhere in the document**, so any utility carrying it immediately before an
    // interpolation was reported as an attribute name -- and a finding fails the build. This
    // package's own views already carry `class="btn btn-xs {{ … }}"`, so the tree was one class
    // away from a guard that refused it.
    expect(wireExpressionInterpolations('<div class="'.$class.'">x</div>'))->toBeEmpty();
})->with(['px-{{ $n }}', 'max-w-{{ $w }}', 'space-x-{{ $gap }}', 'translate-x-{{ $n }}', 'px-{!! $n !!}']);

it('reports every `@use` form that binds a foreign class under the guarded name', function (string $directive): void {
    // **The alias check is the only thing that makes a bare `Wire::of()` safe**, so a binding it
    // misses defeats every other check in this file at once. Three versions modelled
    // `CompilesUseStatements::compileUse()` with string operations and each was wrong in a new way;
    // every row here was executed end to end against a real foreign class before it became a test --
    // the template compiled, `Wire::of()` reached the foreign class, and the guard said nothing.
    expect(wireExpressionInterpolations($directive."\n<button wire:click=\"go({{ Wire::of(\$id) }})\">x</button>"))
        ->not->toBeEmpty();
})->with([
    // Blade trims quotes and whitespace with one interleaved charlist; two sequential trims leave
    // the space behind, and an alias of `Wire ` matches nothing.
    'a trailing space inside the quotes' => "@use('Evil\\Wire ')",
    'a trailing space in the alias' => "@use('Evil\\Foo', 'Wire ')",
    'a tab in the alias' => "@use('Evil\\Foo', \"Wire\t\")",

    // `compileUse()` deletes every parenthesis before parsing; a non-greedy `\((.*?)\)` stops at the
    // first one. The third row lands on a class string that compares EQUAL to the safe one.
    'a parenthesis inside the quotes' => "@use('Evil\\Foo)', 'Wire')",
    'a parenthesis in the alias' => "@use('Evil\\Foo', 'Wi)re')",
    'a truncation ending at the safe name' => "@use('RobotCouncil\\Support\\WireArgument)Sneaky', 'Wire')",

    // A group import binds every name in the braces.
    'a group import binding a second name' => "@use('Evil\\{Foo, Wire}')",
    'a group import with an alias' => "@use('Evil\\{Foo as Wire}')",
    'a group import with a nested name' => "@use('Evil\\{Sub\\Nope, Wire}')",

    // Blade emits the string verbatim and PHP accepts any whitespace around `as`.
    'as separated by tabs' => "@use(\"Evil\\Foo\tas\tWire\")",
    'as separated by newlines' => "@use(\"Evil\\Foo\nas\nWire\")",

    'unquoted' => '@use(Evil\Wire)',
    'unquoted with two arguments' => '@use(Evil\Foo, Wire)',
    'the plain quoted form' => "@use('Evil\\Wire')",
]);

it('leaves alone every form that binds nothing, or binds the right class', function (string $directive): void {
    // **The other half, and the one that decides whether this guard is usable at all.** A check
    // that reported these would fail the build on ordinary templates.
    // `robotcouncil\support\wireargument` is here because PHP class names are case-insensitive, so
    // it binds the real class and a byte comparison against `WireArgument::class` refused it.
    expect(wireExpressionInterpolations($directive))->toBeEmpty();
})->with([
    'the spelling every view carries' => "@use('RobotCouncil\\Support\\WireArgument', 'Wire')",
    'the one-argument form' => "@use('RobotCouncil\\Support\\WireArgument')",
    'a group import of the safe class' => "@use('RobotCouncil\\Support\\{WireArgument}')",
    'padding Blade strips' => "@use(' RobotCouncil\\Support\\WireArgument ', 'Wire')",
    'a case PHP accepts' => "@use('robotcouncil\\support\\wireargument', 'Wire')",
    'an escaped directive Blade renders literally' => "@@use('Evil\\Wire')",
    'a function import, which binds no class' => "@use('function Evil\\wire')",
    'a const import' => "@use('const Evil\\WIRE')",
    'an unrelated import' => "@use('RobotCouncil\\Support\\Scope')",
    'one inside a Blade comment' => "{{-- @use('Evil\\Wire') --}}",
    'one inside @verbatim' => "@verbatim @use('Evil\\Wire') @endverbatim",
    'a closure use, which is not an import' => '<?php $f = function () use ($x) { return $x; }; ?>',
]);

it('refuses a qualified class this package does not have', function (): void {
    // `src/Support/` holds `WireArgument.php` and no `Wire.php`, so admitting
    // `RobotCouncil\Support\Wire` was untested widening that pre-blessed a class nobody has
    // written. Removing that alternative killed no assertion, which is what said it was untested.
    expect(wireExpressionInterpolations('<button wire:click="act({{ RobotCouncil\Support\Wire::of($id) }})">x</button>'))
        ->not->toBeEmpty();
});

it('captures a later interpolation in each quoting form, not just the first', function (string $template): void {
    // **The quoted branches had no test that distinguished them from the bare fallback.** Neutering
    // either left every assertion green, because the bare branch stops at the first space and still
    // catches the FIRST interpolation. A guarded value first and an unguarded one second is what
    // tells them apart.
    expect(wireExpressionInterpolations($template))->toBe(['x-html="$evil"']);
})->with([
    'double-quoted' => '<div x-html="go({{ Wire::of($a) }}, {{ $evil }})"></div>',
    'single-quoted' => "<div x-html='go({{ Wire::of(\$a) }}, {{ \$evil }})'></div>",
]);

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

it('reads a class name out of a heredoc and a nowdoc, not only a quoted literal', function (): void {
    // **The hole #175 would otherwise have opened.** It moved every message in `Support\Doctor`
    // into nowdocs, and the first detector paired quote CHARACTERS -- a nowdoc body carries none,
    // so the whole file left this guard's reach with nothing reporting it. Measured before the
    // fix: the nowdoc below returned nothing while `'btn btn-primary'` returned its class.
    expect(stylesheetClassesIn("<?php \$x = <<<'TEXT'\n    a nowdoc naming btn-lg inline\n    TEXT;\n"))
        ->toBe(['btn-lg']);

    expect(stylesheetClassesIn("<?php \$x = <<<TEXT\n    a heredoc naming card-body inline\n    TEXT;\n"))
        ->toBe(['card-body']);

    // **And the same defect ran the other way, which is why prose is asserted too.** The regex
    // read the text BETWEEN two apostrophes as a literal, so an ordinary sentence with two
    // possessives reported whatever sat between them. This is the shape `Support\Doctor`'s own
    // messages have.
    expect(stylesheetClassesIn("<?php \$x = <<<'TEXT'\n    The job's state is text-sm for the application's page.\n    TEXT;\n"))
        ->toBe(['text-sm'], 'a class name in a nowdoc is reported wherever it sits');

    expect(stylesheetClassesIn("<?php \$x = <<<'TEXT'\n    The oldest job's timestamp is unreadable, so this application's age check cannot run.\n    TEXT;\n"))
        ->toBeEmpty('prose with apostrophes is prose, not a quoted literal');
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

it('admits no executable payload in any charset-limited field, though it does admit an off-site URL', function (string $label, string $pattern, string $harmless): void {
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
    // pass as a guarantee.
    //
    // **Carried per row rather than fixed**, because the fields no longer share one shape: a
    // repository needs a separator, so a single `harmless-value` would have made that row's control
    // fail for a reason that says nothing about the property under test.
    expect($harmless)->toMatch($pattern);
})->with([
    'harness' => ['harness', '/^[a-z0-9-]{1,32}$/D', 'harmless-value'],
    'machine_label' => ['machine_label', '/^[A-Za-z0-9._-]{1,64}$/D', 'harmless-value'],
    'project_id' => ['project_id', '/^[A-Za-z0-9._\/-]{1,128}$/D', 'harmless-value'],

    // Read from the source of truth rather than retyped. The other three have one call site each
    // and no constant yet; these three have constants and use them.
    'a lock name' => ['a lock name', Locks::NAME, 'harmless-value'],
    'repository' => ['repository', WorkIdentity::REPOSITORY, 'robot-council/core'],
    'work_location' => ['work_location', WorkIdentity::LOCATION, 'primary'],
]);

it('refuses a traversal segment in the two fields whose names invite one', function (): void {
    // **The enumeration above is about executable payloads; this is about paths.** `repository` and
    // `work_location` are the first fields here whose NAMES tell a consumer what to do with them --
    // join one into a checkout path, hand the other to a command -- and both are broadcast to every
    // agent in the fleet through `session.joined`. `project_id` and a lock name are deliberately
    // wider and carry no such invitation, which is why they are not in this one.
    foreach (['../..', './.', 'a/..', '-x/-y'] as $traversal) {
        expect(preg_match(WorkIdentity::REPOSITORY, $traversal))->toBe(0);
    }

    foreach (['..', '.', '-rf'] as $traversal) {
        expect(preg_match(WorkIdentity::LOCATION, $traversal))->toBe(0);
    }

    // The control: a leading dot is still a real name, so the narrowing above took nothing with it
    expect('.github/workflows')->toMatch(WorkIdentity::REPOSITORY)
        ->and('.hidden')->toMatch(WorkIdentity::LOCATION);
});

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

it('reports a value concatenated after the URL the server built', function (): void {
    // **The gap #210 recorded.** The admit-rule was anchored only at the start, so an expression
    // that merely BEGAN with a helper call was admitted whole -- and half of this one is not built
    // by the server, which is exactly what the guard's failure message claims to be about.
    //
    // Less dangerous than a bare variable, and still worth refusing: `route()` fixes the scheme and
    // host, and `{{ }}` escapes, so no `javascript:` payload and no attribute breakout. What an
    // appended value can add is a path, a query or a fragment, on pages that render another
    // developer's agent-supplied strings.
    expect(urlAttributeInterpolations('<a href="{{ route(\'x\').$section }}">go</a>'))
        ->toBe(['href="route(\'x\').$section"'])
        ->and(urlAttributeInterpolations('<a href="{{ route(\'x\') . $section }}">go</a>'))
        ->toBe(['href="route(\'x\') . $section"']);

    // Concatenation that ends in another call, which an "ends with a parenthesis" rule would admit
    expect(urlAttributeInterpolations('<a href="{{ route(\'x\').foo($y) }}">go</a>'))
        ->toBe(['href="route(\'x\').foo($y)"']);

    // And one with a literal in the middle, so the variable is not at either end
    expect(urlAttributeInterpolations('<a href="{{ route(\'x\').\'#\'.$id }}">go</a>'))
        ->toBe(['href="route(\'x\').\'#\'.$id"']);
});

it('keeps admitting a server-built URL with a static suffix, and one whose arguments nest', function (): void {
    // **The half the sidebar depends on.** A fragment after the interpolation is attribute text
    // rather than part of the expression, so the detector never sees it -- and must not start
    // reporting it, because that is the shape a link to a section on the page it is already on
    // takes.
    expect(urlAttributeInterpolations('<a href="{{ route(\'x\') }}#section">go</a>'))->toBeEmpty();

    // A route with parameters is one call, not a concatenation. The recursive group is what tells
    // them apart: a non-recursive pattern demanding the expression end at a parenthesis would admit
    // `route('x').foo($y)` above and reject this.
    expect(urlAttributeInterpolations('<a href="{{ route(\'x\', [\'id\' => $id]) }}">go</a>'))->toBeEmpty()
        ->and(urlAttributeInterpolations('<a href="{{ route(\'x\', [\'a\' => max(1, $b)]) }}">go</a>'))->toBeEmpty();

    // The detector's own control, in the same run: a bare variable is still reported, so a rewrite
    // that quietly stopped reporting anything cannot pass this file.
    expect(urlAttributeInterpolations('<a href="{{ $section }}">go</a>'))->toBe(['href="$section"']);
});

it('refuses a value concatenated INSIDE a pass-through helper, which a literal first argument hides', function (): void {
    // **The same defect as the one above, moved three characters left.** A rule that checked only
    // the first character after the parenthesis saw a quote and admitted the whole call, so
    // `url('' . $x)` passed while `url($x)` was reported -- and the two return the same string.
    //
    // The worked case is this package's own: `Support\ProjectId::PATTERN` admits `/`, and the test
    // above asserts `//evil.example/steal` matches it. `UrlGenerator::to()` returns its argument
    // verbatim when `isValidUrl()` accepts it, and that accepts anything opening `//`. So
    // `url('/' . $session->project_id)` renders an off-site link built from an agent-supplied
    // string, through a helper whose name is on the allowlist.
    expect(urlAttributeInterpolations('<a href="{{ url(\'/\' . $project) }}">go</a>'))
        ->toBe(['href="url(\'/\' . $project)"'])
        ->and(urlAttributeInterpolations('<img src="{{ asset(\'\' . $agentValue) }}">'))
        ->toBe(['src="asset(\'\' . $agentValue)"'])
        ->and(urlAttributeInterpolations('<a href="{{ secure_url(\'\' . $agentValue) }}">go</a>'))
        ->toBe(['href="secure_url(\'\' . $agentValue)"']);

    // A single quoted literal is the only argument these three accept, and it is every use the
    // package has
    expect(urlAttributeInterpolations('<a href="{{ url(\'/docs\') }}">go</a>'))->toBeEmpty()
        ->and(urlAttributeInterpolations('<img src="{{ asset(\'x.png\') }}">'))->toBeEmpty();

    // `route()` and `action()` keep taking parameters, because they do not pass an argument
    // through: route parameters are `rawurlencode`d and the scheme is the application's.
    expect(urlAttributeInterpolations('<a href="{{ route(\'x\', [\'id\' => $id]) }}">go</a>'))->toBeEmpty();
});

it('refuses a parenthesis inside a string argument, which is a deliberate false positive', function (): void {
    // The recursion counts parentheses **blind to string context**, so a `)` inside a literal shifts
    // the count and this one call reads as unbalanced. Recorded rather than left for somebody to
    // find and read as a bug: the refusal is the safe direction, nothing in the package writes it,
    // and the alternative is a PHP tokenizer in a test helper.
    expect(urlAttributeInterpolations('<a href="{{ route(\'x\', [\'q\' => \')\']) }}">go</a>'))
        ->toBe(['href="route(\'x\', [\'q\' => \')\'])"']);

    // Balanced pairs inside a literal are counted correctly and still admitted
    expect(urlAttributeInterpolations('<a href="{{ url(\'/docs/Foo_(bar)\') }}">go</a>'))->toBeEmpty();
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
