<?php

declare(strict_types=1);

/**
 * Every link that leaves the dashboard opens in a new tab and says so (#387).
 *
 * **One component makes the links, and this guard stops a link being written any other way.** A
 * view scan reads every `<a>` under `resources/views/` and refuses one that could leave the
 * dashboard without `target="_blank"` and `rel="noopener noreferrer"`. It is fail-closed on
 * purpose: a link counts as internal only when its `href` is a `route()`, a same-page fragment,
 * or a root-relative path, so an `href` the scan cannot read -- a variable, a helper, a literal
 * URL -- has to carry both attributes. `components/external-link.blade.php` is one of those, and
 * passes because it does.
 *
 * The scan's own control runs first, on fixtures it must refuse and fixtures it must admit, so a
 * scan that stopped matching reports that instead of a clean tree.
 *
 * @command  vendor/bin/pest --compact tests/ExternalLinkGuardTest.php
 */
use RobotCouncil\Support\TicketLink;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;

/**
 * The `<a>` tags in some Blade source that could leave the dashboard but would not open a new tab.
 *
 * @param  string  $source  The Blade source.
 * @return list<string> The offending opening tags.
 */
function externalLinksWithoutNewTab(string $source): array
{
    // A Blade comment is prose, and may quote a tag without being one
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    // An echo is read whole, since `$attributes->except()` carries a `>` of its own
    preg_match_all('/<a\b(?:\{\{.*?\}\}|[^>"\']|"[^"]*"|\'[^\']*\')*>/is', $source, $tags);

    $offending = [];

    foreach ($tags[0] as $tag) {
        $href = preg_match('/\shref\s*=\s*"([^"]*)"/i', $tag, $match) === 1 ? trim($match[1]) : null;

        // No href at all is not a link anywhere
        if ($href === null) {
            continue;
        }

        $internal = preg_match('/^\{\{\s*route\(/', $href) === 1
            || str_starts_with($href, '#')
            || preg_match('#^/(?!/)#', $href) === 1;

        if ($internal) {
            continue;
        }

        if (preg_match('/\starget\s*=\s*"_blank"/i', $tag) !== 1 || preg_match('/\srel\s*=\s*"noopener noreferrer"/i', $tag) !== 1) {
            $offending[] = $tag;
        }
    }

    return $offending;
}

it('refuses an external link without a new tab, and admits an internal one', function (string $source, bool $refused): void {
    expect(externalLinksWithoutNewTab($source) !== [])->toBe($refused);
})->with([
    // Refused: each way a link can leave without saying so
    'a ticket link with rel and no target' => ['<a href="{{ ' . TicketLink::class . '::url($t) }}" class="link" rel="noopener noreferrer"><code>x</code></a>', true],
    'a literal URL' => ['<a href="https://github.com/robot-council/core">core</a>', true],
    'a protocol-relative URL' => ['<a href="//github.com/robot-council/core">core</a>', true],
    'a variable' => ['<a href="{{ $href }}" class="link">x</a>', true],
    'a target without rel' => ['<a href="https://github.com" target="_blank">x</a>', true],
    'a target on another frame' => ['<a href="https://github.com" target="_self" rel="noopener noreferrer">x</a>', true],
    'across lines' => ['<a
    href="{{ ' . TicketLink::class . '::profile($l) }}"
    class="link">x</a>', true],
    // Admitted: a link that leaves and says so, and every internal shape the dashboard has
    'a new tab with rel' => ['<a href="https://github.com" target="_blank" rel="noopener noreferrer">x</a>', false],
    'a route' => ['<a href="{{ route(\'robot-council.lanes\') }}" class="link">Lanes page</a>', false],
    'a route across lines' => ["<a href=\"{{ route('robot-council.agents') }}\"\n    class=\"flex\">x</a>", false],
    'a fragment' => ['<a href="#robot-council-main" class="sr-only">Skip</a>', false],
    'a root-relative path' => ['<a href="/dashboard">x</a>', false],
    'an echo carrying an arrow' => ['<a href="{{ $href }}" {{ $attributes->except([\'target\']) }} target="_blank" rel="noopener noreferrer">x</a>', false],
    'a quoted tag in a Blade comment' => ['{{-- never write <a href="https://github.com">x</a> --}}', false],
]);

it('finds no link in any view that leaves the dashboard without opening a new tab', function (): void {
    $scanned = 0;
    $offending = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../resources/views')->name('*.blade.php') as $file) {
        $scanned++;

        foreach (externalLinksWithoutNewTab($file->getContents()) as $tag) {
            $offending[] = $file->getRelativePathname().': '.$tag;
        }
    }

    // A scan that read nothing would pass on nothing
    expect($scanned)->toBeGreaterThan(10)
        ->and($offending)->toBeEmpty();
});

it('renders a new tab, the safe rel, and words saying so, and cannot be talked out of them', function (): void {
    $html = Blade::render('<x-robot-council::external-link href="https://github.com/robot-council/core/issues/387" class="link" target="_self" rel="opener"><code>robot-council/core#387</code></x-robot-council::external-link>');

    expect($html)->toContain('<a href="https://github.com/robot-council/core/issues/387" class="link" target="_blank" rel="noopener noreferrer"><code>robot-council/core#387</code>')
        ->and($html)->toContain('<span class="sr-only"> (opens in a new tab)</span></a>')
        ->and($html)->toContain('aria-hidden="true"')
        ->and($html)->not->toContain('_self')
        ->and($html)->not->toContain('"opener"')
        ->and(substr_count($html, 'target='))->toBe(1)
        ->and(substr_count($html, 'rel='))->toBe(1);
});
