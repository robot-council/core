<?php

declare(strict_types=1);

/**
 * Every link that leaves the dashboard opens in a new tab and says so, and no other link does (#387).
 *
 * **One component makes the links, and this guard stops a link being written any other way.** A
 * view scan reads every `<a>` under `resources/views/` and refuses one that could leave the
 * dashboard without exactly one `target="_blank"` and `rel="noopener noreferrer"`. It is
 * fail-closed on purpose: a link counts as internal only when its `href` is, whole, a `route()`
 * call, a same-page fragment, or a root-relative path, so any other `href` -- a variable, a helper,
 * a literal URL, one quoted some other way, or one the scan cannot read at all because the tag
 * spreads attributes into itself -- has to carry both. An internal link must carry no `target`,
 * so the dashboard's own pages never open a tab of their own. `components/external-link.blade.php`
 * builds GitHub URLs with `TicketLink`, and passes because it writes both.
 *
 * The scan's own control runs first, on fixtures it must refuse and fixtures it must admit, so a
 * scan that stopped matching reports that instead of a clean tree.
 *
 * @command  vendor/bin/pest --compact tests/ExternalLinkGuardTest.php
 */
use Illuminate\Support\Facades\Blade;
use RobotCouncil\Support\TicketLink;
use Symfony\Component\Finder\Finder;

/**
 * The `<a>` tags in some Blade source that open a tab they should not, or fail to open one they should.
 *
 * @param  string  $source  The Blade source.
 * @return list<string> The offending opening tags.
 */
function linksWithTheWrongTab(string $source): array
{
    // A Blade comment is prose, and may quote a tag without being one
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    // An echo is read whole, since `$attributes->filter()` carries a `>` of its own
    preg_match_all('/<a\b(?:\{\{.*?\}\}|[^>"\']|"[^"]*"|\'[^\']*\')*>/is', $source, $tags);

    $offending = [];

    foreach ($tags[0] as $tag) {
        // Echoes blanked first, so an attribute name inside one is not read as the tag's own
        $bare = (string) preg_replace('/\{\{.*?\}\}/s', '{{}}', $tag);

        $hrefs = preg_match_all('/\shref\s*=/i', $bare);
        $spread = preg_match('/<a\b[^>]*?\s\{\{\}\}/i', $bare) === 1;

        // Neither an href nor a spread that could bring one: not a link anywhere
        if ($hrefs === 0 && ! $spread) {
            continue;
        }

        $href = preg_match('/\shref="([^"]*)"/i', $tag, $match) === 1 ? trim($match[1]) : null;

        $internal = $hrefs === 1 && ! $spread && $href !== null && (
            preg_match('/^\{\{\s*route\s*(\((?:[^()]++|(?1))*\))\s*\}\}$/', $href) === 1
            || str_starts_with($href, '#')
            || preg_match('#^/(?![/\\\\])#', $href) === 1
        );

        $targets = preg_match_all('/\starget\s*=/i', $bare);

        if ($internal) {
            if ($targets !== 0) {
                $offending[] = $tag;
            }

            continue;
        }

        $newTab = $targets === 1 && preg_match('/\starget="_blank"/i', $tag) === 1;
        $safe = preg_match_all('/\srel\s*=/i', $bare) === 1 && preg_match('/\srel="noopener noreferrer"/i', $tag) === 1;

        if (! $newTab || ! $safe) {
            $offending[] = $tag;
        }
    }

    return $offending;
}

it('refuses an external link without a new tab or an internal one with a tab, and admits the rest', function (string $source, bool $refused): void {
    expect(linksWithTheWrongTab($source) !== [])->toBe($refused);
})->with([
    // Refused: each way a link can leave without saying so
    'a ticket link with rel and no target' => ['<a href="{{ '.TicketLink::class.'::url($t) }}" class="link" rel="noopener noreferrer"><code>x</code></a>', true],
    'a literal URL' => ['<a href="https://github.com/robot-council/core">core</a>', true],
    'a protocol-relative URL' => ['<a href="//github.com/robot-council/core">core</a>', true],
    'a backslash the browser reads as a second slash' => ['<a href="/\evil.example">x</a>', true],
    'a single-quoted URL' => ["<a href='https://evil.example'>x</a>", true],
    'an unquoted URL' => ['<a href=https://evil.example>x</a>', true],
    'an uppercase tag' => ['<A HREF="https://evil.example">x</A>', true],
    'a variable' => ['<a href="{{ $href }}" class="link">x</a>', true],
    'a route with more after it' => ['<a href="{{ route(\'x\') }}{{ $v }}">x</a>', true],
    'a route with a value joined on' => ['<a href="{{ route(\'x\').$v }}">x</a>', true],
    'an href spread in' => ['<a {{ $attributes }}>x</a>', true],
    'a target without rel' => ['<a href="https://github.com" target="_blank">x</a>', true],
    'a target on another frame' => ['<a href="https://github.com" target="_self" rel="noopener noreferrer">x</a>', true],
    'a second target the browser would ignore' => ['<a href="https://e" target="_self" target="_blank" rel="noopener noreferrer">x</a>', true],
    'across lines' => ["<a\n    href=\"{{ ".TicketLink::class."::profile(\$l) }}\"\n    class=\"link\">x</a>", true],
    'an internal link opening a tab' => ['<a href="{{ route(\'robot-council.lanes\') }}" target="_blank">Lanes</a>', true],
    // Admitted: a link that leaves and says so, and every internal shape the dashboard has
    'a new tab with rel' => ['<a href="https://github.com" target="_blank" rel="noopener noreferrer">x</a>', false],
    'an echo carrying an arrow' => ['<a href="{{ $href }}" target="_blank" rel="noopener noreferrer" {{ $attributes->except([\'target\']) }}>x</a>', false],
    'a route' => ['<a href="{{ route(\'robot-council.lanes\') }}" class="link">Lanes page</a>', false],
    'a route with parameters' => ['<a href="{{ route(\'robot-council.queue\', [\'status\' => max(1, $s)]) }}">x</a>', false],
    'a route across lines' => ["<a href=\"{{ route('robot-council.agents') }}\"\n    class=\"flex\">x</a>", false],
    'a fragment' => ['<a href="#robot-council-main" class="sr-only">Skip</a>', false],
    'a root-relative path' => ['<a href="/dashboard">x</a>', false],
    'a quoted tag in a Blade comment' => ['{{-- never write <a href="https://github.com">x</a> --}}', false],
    'an anchor with no href' => ['<a id="top"></a>', false],
]);

it('finds no link in any view that opens the wrong kind of tab', function (): void {
    $scanned = 0;
    $offending = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../resources/views')->name('*.blade.php') as $file) {
        $scanned++;

        foreach (linksWithTheWrongTab($file->getContents()) as $tag) {
            $offending[] = $file->getRelativePathname().': '.$tag;
        }
    }

    // A scan that read nothing would pass on nothing
    expect($scanned)->toBeGreaterThan(10)
        ->and($offending)->toBeEmpty('Links that open the wrong kind of tab:'.PHP_EOL.implode(PHP_EOL, $offending));
});

it('renders a new tab, the safe rel, and words saying so, and cannot be talked out of them', function (string $passed): void {
    $html = Blade::render('<x-robot-council::external-link reference="robot-council/core#387" class="link" '.$passed.'><code>robot-council/core#387</code></x-robot-council::external-link>');

    expect($html)->toContain('<a href="https://github.com/robot-council/core/issues/387" target="_blank" rel="noopener noreferrer" class="link"><code>robot-council/core#387</code> (new tab)</a>')
        ->and($html)->not->toContain('_self')
        ->and($html)->not->toContain('opener"')
        ->and($html)->not->toContain('evil.example')
        ->and(preg_match_all('/\starget=/i', $html))->toBe(1)
        ->and(preg_match_all('/\srel=/i', $html))->toBe(1)
        ->and(preg_match_all('/\shref=/i', $html))->toBe(1);
})->with([
    'lower case' => ['href="https://evil.example" target="_self" rel="opener"'],
    'any other case' => ['HREF="https://evil.example" Target="_self" REL="opener"'],
]);

it('links a login to its GitHub profile the same way', function (): void {
    $html = Blade::render('<x-robot-council::external-link login="todd-uams" class="link">todd-uams</x-robot-council::external-link>');

    expect($html)->toContain('<a href="https://github.com/todd-uams" target="_blank" rel="noopener noreferrer" class="link">todd-uams (new tab)</a>');
});

it('renders a value GitHub cannot link as plain text, never as a link to nowhere', function (string $attribute): void {
    $html = Blade::render('<x-robot-council::external-link '.$attribute.' class="link">not a reference</x-robot-council::external-link>');

    expect(trim($html))->toBe('not a reference');
})->with([
    'an unqualified reference' => ['reference="#387"'],
    'a login GitHub would refuse' => ['login="-not-a-login"'],
    'nothing at all' => [''],
]);

/**
 * The `external-link` tags in some Blade source that make the link a flex or grid container (#536).
 *
 * Inside a flex box the slot and the words " (new tab)" become two flex items: the space between
 * them is dropped, and at a narrow width each shrinks into a column of its own. A grid splits them
 * the same way. A `class` or `:class`, quoted either way and read whole through any echo, with a
 * `flex`, `inline-flex`, `grid` or `inline-grid` token at any breakpoint is refused; `flex-wrap`,
 * `flex-1`, `grid-cols-2` and their kind are not display values and are left alone.
 *
 * @param  string  $source  The Blade source.
 * @return list<string> The offending tags.
 */
function flexExternalLinks(string $source): array
{
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    preg_match_all('/<x-robot-council::external-link\b(?:\{\{.*?\}\}|[^>"\']|"[^"]*"|\'[^\']*\')*>/is', $source, $tags);

    return array_values(array_filter($tags[0], static function (string $tag): bool {
        preg_match_all('/\s:?class=("(?:\{\{.*?\}\}|[^"])*"|\'[^\']*\')/is', $tag, $classes);
        return array_any($classes[1], fn(string $class): bool => preg_match('/(?<![\w-])(?:[\w\[\]-]+:)*!?(?:inline-)?(?:flex|grid)!?(?![\w-])/i', $class) === 1);
    }));
}

it('refuses an external link made a flex or grid container, at any breakpoint, and admits the rest (#536)', function (string $class, bool $refused): void {
    $source = sprintf('<x-robot-council::external-link :reference="$ticket" %s><code>{{ $ticket }}</code></x-robot-council::external-link>', str_contains($class, '=') ? $class : 'class="'.$class.'"');

    expect(flexExternalLinks($source))->toHaveCount($refused ? 1 : 0);
})->with([
    'inline-flex' => ['link inline-flex min-h-11 items-center', true],
    'flex' => ['link flex min-h-11', true],
    'flex at a breakpoint' => ['link inline-block sm:flex', true],
    'inline-flex at a stacked variant' => ['link md:hover:inline-flex', true],
    'important flex' => ['link !flex', true],
    'grid' => ['link grid', true],
    'inline-grid at a breakpoint' => ['link max-sm:inline-grid', true],
    'single-quoted' => ["class='link inline-flex'", true],
    'bound' => [':class="$wide ? \'link flex\' : \'link\'"', true],
    'built in an echo' => ['class="link {{ $wide ? "inline-flex" : "" }}"', true],
    'the fix' => ['link inline-block min-h-11 py-3', false],
    'flex-1 is not a display' => ['link flex-1', false],
    'grid-cols-2 is not a display' => ['link grid-cols-2', false],
    'a plain link' => ['link', false],
    'flex-wrap is not a display' => ['link inline-block flex-wrap', false],
    'flex-col is not a display' => ['link flex-col', false],
]);

it('finds no external link in any view made a flex or grid container (#536)', function (): void {
    $scanned = 0;
    $links = 0;
    $offending = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../resources/views')->name('*.blade.php') as $file) {
        $scanned++;
        $links += preg_match_all('/<x-robot-council::external-link\b/', $file->getContents());

        foreach (flexExternalLinks($file->getContents()) as $tag) {
            $offending[] = $file->getRelativePathname().': '.$tag;
        }
    }

    // A scan that found no link would pass on nothing
    expect($scanned)->toBeGreaterThan(10)
        ->and($links)->toBeGreaterThan(3)
        ->and($offending)->toBeEmpty('External links made flex or grid containers:'.PHP_EOL.implode(PHP_EOL, $offending));
});
