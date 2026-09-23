<?php

declare(strict_types=1);

/**
 * The shipped stylesheet's themes: which two exist, which is served when, and whether the colour
 * pairs the dashboard renders are legible.
 *
 * Asserted against the built artifact rather than against `resources/css/dashboard.css`, because
 * the source is a statement of intent and the artifact is what a browser receives. A daisyUI
 * upgrade that moved a stock token would leave the source untouched and the artifact wrong.
 *
 * @command  vendor/bin/pest --compact tests/DashboardThemeTest.php
 */

/**
 * The shipped stylesheet.
 */
function stylesheet(): string
{
    return (string) file_get_contents(__DIR__.'/../resources/dist/dashboard.css');
}

/**
 * The custom properties one theme's rule declares.
 *
 * **Anchored on rule boundaries rather than found with `strpos`.** The artifact is minified, so a
 * fragment search followed by "the next `{`" and "the next `}`" can read a rule that merely sits
 * nearby -- and the dark tokens are emitted TWICE, once inside the `prefers-color-scheme` query and
 * once under `[data-theme=dark]`, 1,130 bytes apart. A loose parser that landed on the wrong one
 * would still find every key it asserts on and pass. The selector capture is brace-free, so it
 * cannot span a preceding rule, and the body capture is brace-free, so a nested at-rule truncates
 * loudly instead of silently promoting a conditional declaration.
 *
 * @param  string  $selector  A literal fragment of the rule's selector list.
 * @return array<string, string> Property name without the leading dashes, mapped to its value.
 *
 * @throws RuntimeException When the fragment matches no rule, or more than one.
 */
function themeTokens(string $selector): array
{
    $pattern = '/(?:^|[{}])([^{}]*'.preg_quote($selector, '/').'[^{}]*)\{([^{}]*)\}/';

    if (preg_match_all($pattern, stylesheet(), $rules, PREG_SET_ORDER) !== 1) {
        throw new RuntimeException(sprintf(
            'Expected `%s` to identify exactly one rule; matched %d.',
            $selector,
            \count($rules)
        ));
    }

    preg_match_all('/--([a-z0-9-]+):([^;}]+)/i', $rules[0][2], $found, PREG_SET_ORDER);

    $tokens = [];

    foreach ($found as $match) {
        $tokens[$match[1]] = trim($match[2]);
    }

    return $tokens;
}

/**
 * One colour's linear-sRGB channels.
 *
 * Only `oklch()` is handled, which is every colour daisyUI writes. Out-of-gamut channels are
 * clamped the way a browser clamps them before painting, so the luminance below is the one a
 * viewer actually sees rather than a theoretical value.
 *
 * @param  string  $color  An `oklch()` value, with lightness as a percentage or a 0-1 number.
 * @return array{float, float, float} Linear sRGB, each channel in 0-1.
 */
function linearRgb(string $color): array
{
    if (preg_match('/oklch\(\s*([\d.]+)(%?)\s+([\d.]+)\s+([\d.]+)/i', $color, $parts) !== 1) {
        throw new InvalidArgumentException('Not an oklch() colour: '.$color);
    }

    $lightness = (float) $parts[1];
    $lightness = $parts[2] === '%' || $lightness > 1 ? $lightness / 100 : $lightness;

    $chroma = (float) $parts[3];
    $hue = (float) $parts[4] * M_PI / 180;

    $a = $chroma * cos($hue);
    $b = $chroma * sin($hue);

    $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
    $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
    $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

    $clamp = static fn (float $channel): float => max(0.0, min(1.0, $channel));

    return [
        $clamp(4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s),
        $clamp(-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s),
        $clamp(-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s),
    ];
}

/**
 * The WCAG 2.x contrast ratio between two colours.
 *
 * @param  string  $foreground  The text colour.
 * @param  string  $background  What it sits on.
 * @return float The ratio, from 1.0 to 21.0.
 */
function contrastRatio(string $foreground, string $background): float
{
    $first = relativeLuminance(linearRgb($foreground)) + 0.05;
    $second = relativeLuminance(linearRgb($background)) + 0.05;

    return $first > $second ? $first / $second : $second / $first;
}

/**
 * One colour's relative luminance, as WCAG defines it.
 *
 * A named function rather than a closure inside `contrastRatio()`, because a closure's array
 * parameter cannot carry the shape `linearRgb()` returns and the analyzer reads each channel as
 * `mixed`.
 *
 * @param  array{float, float, float}  $rgb  Linear sRGB channels, each in 0-1.
 * @return float The luminance, from 0.0 to 1.0.
 */
function relativeLuminance(array $rgb): float
{
    return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
}

it('computes a contrast ratio it can be trusted on', function (): void {
    // The instrument's own control, run before anything reads it. Black on white is 21:1 and white
    // on white is 1:1 by definition, so a broken conversion cannot report a passing theme.
    expect(contrastRatio('oklch(0% 0 0)', 'oklch(100% 0 0)'))->toEqualWithDelta(21.0, 0.05)
        ->and(contrastRatio('oklch(100% 0 0)', 'oklch(100% 0 0)'))->toEqualWithDelta(1.0, 0.01);

    // And it discriminates: a pair that is known to fail must not report as passing.
    expect(contrastRatio('oklch(71% 0.194 13.428)', 'oklch(100% 0 0)'))->toBeLessThan(4.5);
});

it('refuses a selector fragment that identifies more than one rule', function (): void {
    // The control for the parser above. Without it, a fragment that matched several rules would
    // quietly return the first one's tokens and the contrast rows would pass on the wrong theme --
    // which is the failure the dark theme's two emissions make easy.
    expect(fn (): array => themeTokens(':root'))
        ->toThrow(RuntimeException::class);

    // And it discriminates: a fragment that identifies exactly one rule still returns its tokens.
    expect(themeTokens('[data-theme=light]'))->toHaveKey('color-primary');
});

it('ships exactly two themes, and no stock one besides', function (): void {
    preg_match_all('/\[data-theme=["\']?([a-z0-9-]+)["\']?\]/i', stylesheet(), $found);

    expect(array_values(array_unique($found[1])))->toEqualCanonicalizing(['light', 'dark']);
});

it('serves light by default and dark to a system that asks for it', function (): void {
    $css = stylesheet();

    // `:where(:root)` is what `default: true` emits, and it is what makes an unthemed page light
    expect($css)->toContain(':where(:root)')

        // `:root:not([data-theme])` is the half that matters: a system preference decides only when
        // the page has not said otherwise, so `data-theme` on `<html>` still wins
        ->and($css)->toContain('@media (prefers-color-scheme:dark){:root:not([data-theme])');
});

it('carries the brand primary in both themes', function (): void {
    // Pinned on #184, and measured rather than picked: `oklch(57% 0.237 270)` is TailAdmin's
    // `brand-500` converted, which is daisyUI's own stock hue family a step lighter.
    expect(themeTokens(':where(:root)')['color-primary'] ?? null)->toBe('oklch(57% .237 270)')
        ->and(themeTokens('[data-theme=dark]')['color-primary'] ?? null)->toBe('oklch(69% .163 270)');
});

it('keeps every pair it owns legible in both themes', function (string $theme, string $selector): void {
    $tokens = themeTokens($selector);

    expect($tokens)->not->toBeEmpty();

    // Only the pairs a THEME decides, at full opacity. Text dimmed with an `opacity-*` utility is
    // a composited colour and is measured in #197 -- note that CSS composites in gamma-encoded
    // sRGB, not linear light, which is a 2.8x difference: black at 70% over white is 8.52:1, not
    // the 3.00:1 a linear composite reports.
    $pairs = [
        'body text' => ['color-base-content', 'color-base-200'],
        'card text' => ['color-base-content', 'color-base-100'],
        'primary button' => ['color-primary-content', 'color-primary'],
        'warning alert' => ['color-warning-content', 'color-warning'],
        'error alert' => ['color-error-content', 'color-error'],
        'error text on a card' => ['color-error', 'color-base-100'],
    ];

    foreach ($pairs as $label => [$foreground, $background]) {
        expect($tokens)->toHaveKeys([$foreground, $background]);

        $ratio = contrastRatio($tokens[$foreground], $tokens[$background]);

        // AA for normal text. The large-text bar of 3.0 is not used: a button label and a
        // validation message are both normal text, and the theme cannot know which is which.
        expect($ratio)->toBeGreaterThanOrEqual(
            4.5,
            sprintf('%s theme, %s: %.2f:1 against a 4.5:1 bar', $theme, $label, $ratio)
        );
    }
})->with([
    ['light', ':where(:root)'],
    ['dark, as an explicit data-theme', '[data-theme=dark]'],

    // The copy a system-preference visitor actually resolves. It is a SEPARATE emission from the
    // one above, so the upgrade this test exists to catch -- a stock token moving underneath the
    // package -- is exactly the case where the two could diverge.
    ['dark, as a system preference', ':root:not([data-theme])'],
]);

it('emits the same dark theme to both the attribute and the system preference', function (): void {
    // Asserted as equality rather than by checking each separately, so a divergence fails even on a
    // token neither the pairs above nor anything else names.
    expect(themeTokens(':root:not([data-theme])'))->toBe(themeTokens('[data-theme=dark]'));
});

it('leaves the shell unthemed, so the dark theme can be reached at all', function (): void {
    // The defect this pins: the layout rendered `data-theme="{{ $theme ?? 'light' }}"`, nothing
    // supplied `$theme`, and the dark theme is served by `:root:not([data-theme])` -- so the
    // attribute was always present, the selector could never match, and a shipped, tested theme
    // was unreachable from every page.
    $layout = (string) file_get_contents(__DIR__.'/../resources/views/layouts/dashboard.blade.php');

    expect($layout)->not->toContain("data-theme=\"{{ \$theme ?? 'light' }}\"")
        ->and($layout)->toContain('@if (($theme ?? null) !== null) data-theme=');
});
