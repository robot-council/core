<?php

declare(strict_types=1);

/**
 * The shipped stylesheet's themes: which two exist, which is served when, whether the colour
 * pairs the dashboard renders are legible, and the type scale the views draw their sizes from.
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

it('keeps every filter boundary and focus ring at 3:1 against the card, in both themes', function (string $theme, string $selector): void {
    $tokens = themeTokens($selector);

    // The pairs below name the tokens daisyUI draws with, so read that from the rule itself: a
    // daisyUI release that drew the border as a mix of `base-content` would otherwise pass here
    // while the painted border fell below 3:1
    preg_match_all('/\.btn-outline\{([^}]*)\}/', stylesheet(), $outline);

    expect($outline[1])->not->toBeEmpty()
        ->and(implode(';', $outline[1]))->toContain('--btn-border:var(--btn-color,var(--color-base-content))');

    // WCAG 1.4.11: what identifies a control needs 3:1 against what is next to it, and every
    // filter and pager sits on a `bg-base-100` card (#309). daisyUI draws `btn-outline`'s border in
    // `base-content`, a `btn-primary` fill in `primary`, and a focused button's 2px ring in
    // `--btn-color` falling back to `base-content` -- so these two pairs are the unselected
    // boundary, the selected fill, and both focus rings.
    $pairs = [
        'unselected filter border and its focus ring' => ['color-base-content', 'color-base-100'],
        'selected filter fill and its focus ring' => ['color-primary', 'color-base-100'],
    ];

    foreach ($pairs as $label => [$foreground, $background]) {
        expect($tokens)->toHaveKeys([$foreground, $background]);

        $ratio = contrastRatio($tokens[$foreground], $tokens[$background]);

        expect($ratio)->toBeGreaterThanOrEqual(
            3.0,
            sprintf('%s theme, %s: %.2f:1 against a 3:1 bar', $theme, $label, $ratio)
        );
    }
})->with([
    ['light', ':where(:root)'],
    ['dark, as an explicit data-theme', '[data-theme=dark]'],
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

/**
 * The opacity steps the views dim text to, and what each one means as an alpha.
 *
 * Read by two tests: one measures every step against both surfaces in every theme, the other
 * refuses a step in a view that is not listed here. Adding a step to a view without adding it here
 * fails the second, which is what stops an unmeasured step shipping the way #197 did.
 */
const DIMMED_STEPS = [
    'opacity-80' => 0.8,
    'opacity-90' => 0.9,
];

/**
 * The bar dimmed text has to clear: WCAG's AAA ratio for normal text.
 *
 * `.claude/rules/accessibility.md` (#397) makes AAA the target for every dashboard surface. Dimmed
 * text is small by construction -- captions, cells, badges -- so the large-text bar never applies,
 * and #310 moved the steps from 60 and 70 to 80 and 90 to reach this: 70 measured 5.94:1 on a
 * hovered row and 60 measured 4.52:1 on the page, both under it.
 */
const DIMMED_BAR = 7.0;

/**
 * One channel, linear sRGB to the gamma-encoded value a browser composites with.
 *
 * @param  float  $channel  A linear channel, 0-1.
 * @return float The encoded channel, 0-1.
 */
function gammaEncode(float $channel): float
{
    return $channel <= 0.0031308
        ? 12.92 * $channel
        : 1.055 * $channel ** (1 / 2.4) - 0.055;
}

/**
 * The inverse, for measuring luminance once the compositing is done.
 *
 * @param  float  $channel  A gamma-encoded channel, 0-1.
 * @return float The linear channel, 0-1.
 */
function gammaDecode(float $channel): float
{
    return $channel <= 0.04045
        ? $channel / 12.92
        : (($channel + 0.055) / 1.055) ** 2.4;
}

/**
 * What an `opacity-*` utility actually paints, in linear sRGB.
 *
 * **The compositing happens in gamma-encoded sRGB, and that is the whole point of this function.**
 * CSS composites where the pixels are, not in linear light, and the difference is not small: black
 * at 70% over white paints `#4D4D4D` and measures 8.52:1, where compositing the same pair in
 * linear light reports 3.00:1 -- a factor of 2.8, and on the wrong side of every bar. #197's first
 * revision failed two usages on that error and had to be corrected after it was published.
 *
 * @param  string  $foreground  The text colour, as an `oklch()` value.
 * @param  string  $background  What it sits on, as an `oklch()` value.
 * @param  float  $alpha  The opacity, 0-1.
 * @return array{float, float, float} Linear sRGB of the painted result, each channel 0-1.
 */
function compositeOver(string $foreground, string $background, float $alpha): array
{
    $front = linearRgb($foreground);
    $back = linearRgb($background);

    $painted = [];

    foreach ([0, 1, 2] as $channel) {
        $mixed = gammaEncode($front[$channel]) * $alpha + gammaEncode($back[$channel]) * (1 - $alpha);

        $painted[] = gammaDecode($mixed);
    }

    return [$painted[0], $painted[1], $painted[2]];
}

/**
 * Composite a colour over a background already expressed in linear sRGB.
 *
 * `compositeOver()` takes two `oklch()` strings, which is every case but one: a surface that is
 * itself composited -- daisyUI's hovered menu row -- has no token of its own to name.
 *
 * @param  array{float, float, float}  $front  Linear channels of the top colour.
 * @param  array{float, float, float}  $back  Linear channels of what it sits on.
 * @param  float  $alpha  The opacity, 0-1.
 * @return array{float, float, float} Linear channels of the result.
 */
function overLinear(array $front, array $back, float $alpha): array
{
    $painted = [];

    foreach ([0, 1, 2] as $channel) {
        $painted[] = gammaDecode(gammaEncode($front[$channel]) * $alpha + gammaEncode($back[$channel]) * (1 - $alpha));
    }

    return [$painted[0], $painted[1], $painted[2]];
}

/**
 * The contrast ratio of dimmed text against what it sits on.
 *
 * @param  string  $foreground  The text colour, as an `oklch()` value.
 * @param  string  $background  What it sits on, as an `oklch()` value.
 * @param  float  $alpha  The opacity the text is dimmed to, 0-1.
 * @return float The ratio, from 1.0 to 21.0.
 */
function dimmedContrastRatio(string $foreground, string $background, float $alpha): float
{
    $text = relativeLuminance(compositeOver($foreground, $background, $alpha)) + 0.05;
    $behind = relativeLuminance(linearRgb($background)) + 0.05;

    return $text > $behind ? $text / $behind : $behind / $text;
}

/**
 * Every ratio dimmed text at one alpha reaches, on every surface the dashboard paints it on.
 *
 * Both themes; the page (`base-200`) and a card (`base-100`); and a hovered or focused menu row over
 * each, which daisyUI paints as `base-content` at alpha 0.1 over the surface, lifting the background
 * toward the text. Keyed by a label, so a failure says which surface it was.
 *
 * `currentcolor` in a daisyUI rule is `base-content` wherever the dashboard dims text, because
 * nothing dimmed sits inside a coloured component.
 *
 * @param  float  $alpha  The opacity, 0-1.
 * @return array<string, float> Each surface's ratio.
 */
function dimmedRatiosEverywhere(float $alpha): array
{
    $ratios = [];

    foreach (['light' => ':where(:root)', 'dark' => '[data-theme=dark]'] as $theme => $selector) {
        $tokens = themeTokens($selector);
        $content = linearRgb($tokens['color-base-content']);

        foreach (['color-base-100', 'color-base-200'] as $surface) {
            $ratios[sprintf('%s theme on %s', $theme, $surface)] = dimmedContrastRatio($tokens['color-base-content'], $tokens[$surface], $alpha);

            $hover = overLinear($content, linearRgb($tokens[$surface]), 0.1);
            $text = relativeLuminance(overLinear($content, $hover, $alpha)) + 0.05;
            $behind = relativeLuminance($hover) + 0.05;

            $ratios[sprintf('%s theme on a hovered row over %s', $theme, $surface)] = $text > $behind ? $text / $behind : $behind / $text;
        }
    }

    return $ratios;
}

it('composites an opacity the way a browser does, not the way linear light would', function (): void {
    // The instrument's control, before anything reads it. Black at 70% over white paints `#4D4D4D`
    // -- checkable against any colour picker -- which is 8.52:1. A linear composite reports 3.00:1
    // for the same pair, so a regression to that error cannot pass this.
    $ratio = dimmedContrastRatio('oklch(0% 0 0)', 'oklch(100% 0 0)', 0.7);

    expect($ratio)->toEqualWithDelta(8.52, 0.05);

    // The painted channel itself, so a reader can check the number rather than trust it. Black at
    // 70% over white leaves 30% of the encoded range, which is 76.5 of 255 -- and a browser
    // rounding half up paints `0x4D`, the 77 in `#4D4D4D`.
    //
    // **Asserted before the rounding, not after.** The value is exactly 76.5, so `round()` sits on
    // the half-way boundary and one unit in the last place of a platform's `pow()` decides between
    // 76 and 77. Measured on PHP 8.4.23: the raw value differs from 76.5 by 0.0e+0, and
    // `round(76.5 - 1e-14)` is 76.0. The matrix runs two operating systems and two PHP versions,
    // none of them this build, and `gammaEncode(gammaDecode(x))` is not exact in general -- at 0.5
    // it lands on 127.49999999999999, which rounds the other way. The delta states the same fact
    // and cannot flip.
    $painted = compositeOver('oklch(0% 0 0)', 'oklch(100% 0 0)', 0.7);

    expect(gammaEncode($painted[0]) * 255)->toEqualWithDelta(76.5, 0.001);

    // And the ends, which no compositing error can satisfy by accident
    expect(dimmedContrastRatio('oklch(0% 0 0)', 'oklch(100% 0 0)', 1.0))->toEqualWithDelta(21.0, 0.05)
        ->and(dimmedContrastRatio('oklch(0% 0 0)', 'oklch(100% 0 0)', 0.0))->toEqualWithDelta(1.0, 0.01);
});

it('keeps every dimmed step the views use above the bar, in both themes', function (string $theme, string $selector): void {
    $tokens = themeTokens($selector);

    expect($tokens)->toHaveKeys(['color-base-content', 'color-base-100', 'color-base-200']);

    // Both surfaces text sits on. `base-200` is the page behind the cards, `base-100` the cards.
    foreach (DIMMED_STEPS as $class => $alpha) {
        foreach (['color-base-100', 'color-base-200'] as $surface) {
            $ratio = dimmedContrastRatio($tokens['color-base-content'], $tokens[$surface], $alpha);

            // AAA for normal text, per the accessibility rule. None of these usages is large text:
            // they are table cells, badges and captions, so neither large-text bar applies -- which
            // is what made #197 a defect rather than a preference.
            expect($ratio)->toBeGreaterThanOrEqual(
                DIMMED_BAR,
                sprintf('%s theme, %s on %s: %.2f:1 against a %.1f:1 bar', $theme, $class, $surface, $ratio, DIMMED_BAR)
            );
        }
    }

    // **And on a hovered row, for every step rather than only the ones a menu holds today.** The
    // hovered-menu test below reads the steps out of the rendered menu, which holds only
    // `opacity-90`, so without this the lower step's closest case, 7.69:1 on a hovered row in the
    // dark theme, would be a figure nothing computes.
    foreach (DIMMED_STEPS as $class => $alpha) {
        foreach (dimmedRatiosEverywhere($alpha) as $where => $ratio) {
            expect($ratio)->toBeGreaterThanOrEqual(
                DIMMED_BAR,
                sprintf('%s, %s: %.2f:1 against a %.1f:1 bar', $class, $where, $ratio, DIMMED_BAR)
            );
        }
    }
})->with([
    ['light', ':where(:root)'],
    ['dark, as an explicit data-theme', '[data-theme=dark]'],
    ['dark, as a system preference', ':root:not([data-theme])'],
]);

it('lifts every text daisyUI or the preflight dims below the bar, for everything a view uses', function (): void {
    $css = stylesheet();

    // **Dimming the views never wrote.** daisyUI draws a table's header, a stat's title and
    // description and a form label at `color-mix(..., 60%, transparent)`, and Tailwind's preflight
    // draws a placeholder at 50%. The step tests read `opacity-*` out of the views and cannot see
    // any of it, which is how #413's first AAA revision said dimmed text met 7:1 while every table
    // header measured 4.52:1. Read out of the artifact, so a component a daisyUI upgrade starts
    // dimming is found without anyone listing it.
    preg_match_all(
        '/([^{}]*)\{(?:[^{}]*;)?color:color-mix\(in oklab, ?(?:var\(--color-base-content\)|currentcolor) (\d+)%, ?transparent\)[^{}]*\}/i',
        $css,
        $rules,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );

    // One key per selector in a rule's list: its first class, or `::placeholder`. A naive split
    // cuts `:where(thead,tfoot)` in two, which can only drop a fragment with no class, never invent one
    $keys = static function (string $selectors): array {
        $found = [];

        foreach (explode(',', $selectors) as $selector) {
            if (str_contains($selector, '::placeholder')) {
                $found[] = '::placeholder';
            } elseif (preg_match('/\.([a-z][a-z0-9-]*)/', $selector, $class) === 1) {
                $found[] = $class[1];
            }
        }

        return $found;
    };

    $failing = [];
    $overridden = [];

    foreach ($rules as [, [$selectors, $offset], [$percent]]) {
        // A disabled control's text is exempt: SC 1.4.3 and 1.4.6 both exclude text that is part
        // of an inactive component, and daisyUI dims exactly those -- a disabled button at 10%, a
        // disabled input at 40%, a disabled menu row at 20%. Lifting them would make an unusable
        // control look usable, which is the opposite of what the dimming says.
        if (preg_match('/disabled/i', $selectors) === 1) {
            continue;
        }

        $worst = INF;

        foreach (dimmedRatiosEverywhere((int) $percent / 100) as $ratio) {
            $worst = min($worst, $ratio);
        }

        $passes = $worst >= DIMMED_BAR;
        $enclosing = blocksEnclosing($css, $offset);

        // An override is the package's own rule, directly in `utilities` -- where it beats
        // daisyUI's sublayers and the preflight's `base`, as the size lifts do -- and it passes
        $ours = ($enclosing[0] ?? null) === '@layer utilities'
            && array_filter($enclosing, static fn (string $block): bool => str_starts_with($block, '@layer daisyui')) === [];

        foreach ($keys(trim($selectors)) as $key) {
            if ($ours && $passes) {
                $overridden[$key] = true;
            } elseif (! $passes) {
                $failing[$key] = true;
            }
        }
    }

    // The controls: what daisyUI and the preflight dim today. A detector missing one has stopped
    // reading the artifact rather than found it clean
    expect($failing)->toHaveKeys(['table', 'stat-title', 'stat-desc', 'label', '::placeholder']);

    $views = '';

    foreach (bladeTemplatesIn(__DIR__.'/../resources/views') as $view) {
        $views .= sourceWithoutComments($view)."\n";
    }

    // Only what a view can render: a class a view writes, and a placeholder where a view sets one
    $rendered = array_values(array_filter(
        array_keys($failing),
        static fn (string $key): bool => $key === '::placeholder'
            ? str_contains($views, 'placeholder=')
            : preg_match('/(?<![\w-])'.preg_quote($key, '/').'(?![\w-])/', $views) === 1,
    ));

    expect($rendered)->toContain('table', 'stat-title', 'label', '::placeholder');

    $unlifted = array_values(array_filter($rendered, static fn (string $key): bool => ! isset($overridden[$key])));

    expect($unlifted)->toBeEmpty(implode(', ', $unlifted));
});

it('measures every dimmed step the views actually use', function (): void {
    // **The half that keeps the test above honest.** Measuring a fixed list proves those two steps
    // are legible and says nothing about a third somebody adds later -- which is exactly how #197
    // arrived, as a step nobody had measured. This reads the steps out of the views and fails when
    // one of them is not in the measured set, so a new step has to be measured before it ships.
    // **`bladeTemplatesIn()`, not `glob`, and the difference is not style.** A `{,*/}` brace
    // pattern expands to two branches and `*` does not cross a separator, so it reads the top level
    // and one directory below and stops. Tailwind's `@source` is recursive, so a view at
    // `resources/views/<dir>/<dir>/` would ship its classes while this reported clean.
    //
    // `EscapingGuardTest` already paid for this: its scanner carries a test named "finds a template
    // at any depth, not only at the top of the tree", whose comment records that a top-level walk
    // "would keep passing once #30 adds `resources/views/livewire/` -- green, and covering nothing
    // in it". No view sits that deep today, which is exactly why a depth-limited walk would have
    // gone unnoticed until one did.
    $found = [];

    foreach (bladeTemplatesIn(__DIR__.'/../resources/views') as $view) {
        preg_match_all('/opacity-(\d+)/', (string) file_get_contents($view), $matches);

        foreach ($matches[1] as $step) {
            $found['opacity-'.$step] = true;
        }
    }

    // The instrument has to have found something, or this passes by reading nothing. It is too weak
    // on its own -- one view keeps it true however much of the tree goes unread -- which is what
    // the recursive walk above is for rather than this line.
    expect($found)->not->toBeEmpty();

    // **The containment is one-directional on purpose.** A step in a view must be measured; a
    // measured step no view uses is allowed, because removing its last usage should not fail a
    // suite. The cost is that a dead entry is measured forever with nothing reporting it.
    expect(array_keys($found))->each->toBeIn(array_keys(DIMMED_STEPS));
});

it('keeps dimmed text legible on the surface a hovered menu row paints', function (): void {
    // **A third surface, and the views reach it.** daisyUI paints a hovered or keyboard-focused
    // menu row with `color-mix(in oklab, var(--color-base-content) 10%, transparent)`, which is
    // `base-content` at alpha 0.1 over whatever is behind the row. That lifts the background toward
    // the text, so dimmed text inside a menu is measured against a different surface from the same
    // text on a card -- and the test above, which measures the two page surfaces, cannot see it.
    //
    // Found by review of #197: the overview's four section descriptions were one level dimmer and
    // measured 4.33:1 here while passing at rest. A row that fails only while it is being pointed
    // at is still a row that fails, and WCAG does not exempt a hover state.
    //
    // **Read out of the rendered page rather than from a list**, so this covers whatever a view
    // puts inside a menu rather than the one case that prompted it.
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242], admins: [4242]);
    $this->actingAs($this->enrollDeveloper(4242), 'web');

    $html = (string) $this->get(route('robot-council.dashboard'))->assertOk()->getContent();

    // An empty page parses to an empty document, which finds no dimmed text and would read as a
    // page with none. Refused here so that case is a broken test rather than a quiet pass.
    if ($html === '') {
        throw new RuntimeException('The overview rendered nothing, so there is no menu to measure.');
    }

    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($document);

    $found = $xpath->query('//ul[contains(@class, "menu")]//*[contains(@class, "opacity-")]');

    if (! $found instanceof DOMNodeList) {
        throw new RuntimeException('The menu query failed, which is not the same as finding nothing.');
    }

    $steps = [];

    foreach ($found as $node) {
        if ($node instanceof DOMElement && preg_match('/opacity-(\d+)/', $node->getAttribute('class'), $matched) === 1) {
            $steps[(int) $matched[1]] = true;
        }
    }

    // The control: this page is known to dim text inside a menu, so an empty result is the query
    // having failed rather than the page being clean.
    expect($steps)->not->toBeEmpty();

    // Both themes. The dark theme's hovered row is the closer call at AAA: `opacity-80` there
    // measures 7.69:1, where the light theme's worst is 7.93:1.
    foreach (['light' => ':where(:root)', 'dark' => '[data-theme=dark]'] as $theme => $selector) {
        $tokens = themeTokens($selector);
        $content = linearRgb($tokens['color-base-content']);

        foreach (array_keys($steps) as $step) {
            foreach (['color-base-100', 'color-base-200'] as $under) {
                $hover = overLinear($content, linearRgb($tokens[$under]), 0.1);

                $text = relativeLuminance(overLinear($content, $hover, $step / 100)) + 0.05;
                $behind = relativeLuminance($hover) + 0.05;

                $ratio = $text > $behind ? $text / $behind : $behind / $text;

                expect($ratio)->toBeGreaterThanOrEqual(
                    DIMMED_BAR,
                    sprintf('%s theme, opacity-%d on a hovered menu row over %s: %.2f:1 against a %.1f:1 bar', $theme, $step, $under, $ratio, DIMMED_BAR)
                );
            }
        }
    }
});

/**
 * The type scale #310 chose: each step's name, mapped to its size as the minified artifact writes it.
 */
const TYPE_SCALE = [
    'text-body' => '1rem',
    'text-meta' => '.875rem',
    'text-table' => '.875rem',
];

/**
 * The floor, in rem: the `meta` step, below which nothing a view renders is set.
 */
const TYPE_FLOOR_REM = 0.875;

/**
 * Off-scale sizes a view may still carry, each mapped to the reason it has to.
 *
 * Empty, and meant to stay that way. A view names a step of the scale, because any other size in a
 * view is a size the scale cannot move.
 *
 * @var array<string, string>
 */
const OFF_SCALE_SIZES_ALLOWED_IN_VIEWS = [];

/**
 * Every way a source sets a text size without naming a step of the scale.
 *
 * - A Tailwind size at or below `body`: `text-xs`, `text-sm`, `text-base`. A variant counts --
 *   `sm:text-xs` is reported as its `text-xs` -- and `text-xs-foo` does not.
 * - An arbitrary size: `text-[13px]`, `text-[length:...]`, `text-(length:--x)`, or the arbitrary
 *   property `[font-size:...]`. An arbitrary COLOR, `text-[#fff]`, is not a size and is not reported.
 * - An inline style setting `font-size` or the `font` shorthand.
 * - `<small>`, which the browser's own stylesheet draws at `smaller`.
 *
 * Comments are NOT stripped: Tailwind's scanner reads them, so a class named in a Blade comment
 * reaches the artifact (#230), and a view explaining which size it no longer uses should say so
 * without spelling the class.
 *
 * @param  string  $source  A template's raw source.
 * @return list<string> What was found, in order.
 */
function offScaleSizesIn(string $source): array
{
    preg_match_all(
        '/(?<![\w-])text-(?:xs|sm|base)(?![\w-])'
        .'|(?<![\w-])text-\[(?:length:[^\]]*|[\d.]+[a-z%]*)\]'
        .'|(?<![\w-])text-\(length:[^)]*\)'
        .'|\[font-size:[^\]]*\]'
        .'|\bstyle\s*=\s*["\'][^"\']*\bfont(?:-size)?\s*:'
        .'|<small\b/i',
        $source,
        $found
    );

    return $found[0];
}

/**
 * The blocks enclosing an offset in the stylesheet, outermost first.
 *
 * Each is the header text before its `{` -- `@layer utilities`, `@media (...)`, a selector -- read
 * after the last `;` so that `@layer components;@layer utilities{` reads as `@layer utilities`. The
 * minified artifact carries no brace inside a string or a comment, which the controls in the test
 * that uses this establish rather than assume: a daisyUI rule must come back nested deeper than a
 * Tailwind utility, or the reading is wrong.
 *
 * @param  string  $css  The stylesheet.
 * @param  int  $offset  A byte offset into it.
 * @return list<string> The enclosing headers, outermost first.
 */
function blocksEnclosing(string $css, int $offset): array
{
    preg_match_all('/[{}]/', substr($css, 0, $offset), $braces, PREG_OFFSET_CAPTURE);

    $stack = [];
    $previous = -1;

    foreach ($braces[0] as [$brace, $at]) {
        if ($brace === '{') {
            $header = substr($css, $previous + 1, $at - $previous - 1);
            $semicolon = strrpos($header, ';');

            $stack[] = trim($semicolon === false ? $header : substr($header, $semicolon + 1));
        } else {
            array_pop($stack);
        }

        $previous = $at;
    }

    return $stack;
}

/**
 * The rules that apply the scale to daisyUI's own sizes: each `:where()` rule setting a value from
 * a step's token, with its byte offset in the artifact.
 *
 * @return list<array{selectors: string, offset: int}>
 */
function scaleLiftRules(): array
{
    preg_match_all(
        '/:where\(([^{}]*)\)\{[^{}]*var\(--text-(?:body|meta|table)[^{}]*\}/',
        stylesheet(),
        $found,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );

    return array_map(
        static fn (array $rule): array => ['selectors' => $rule[1][0], 'offset' => $rule[0][1]],
        $found
    );
}

it('states the type scale once, at the sizes it chose', function (): void {
    // The theme layer's one `:root,:host` rule, which is where `@theme static` emits. Read through
    // `themeTokens()`, which refuses a fragment matching more than one rule, so a second emission
    // holding different sizes fails here instead of one of them being read at random
    $tokens = themeTokens(':root,:host');

    foreach (TYPE_SCALE as $step => $size) {
        expect($tokens[$step] ?? null)->toBe($size, sprintf('--%s', $step))
            ->and($tokens[$step.'--line-height'] ?? null)->not->toBeNull(sprintf('--%s--line-height', $step));
    }

    // And whatever draws each step reads its tokens rather than a size of its own, so the token is
    // the one place a step changes. `table` has no utility; the rule lifting daisyUI's table is
    // what consumes it, line-height included
    expect(stylesheet())
        ->toContain('.text-body{font-size:var(--text-body);line-height:var(--tw-leading,var(--text-body--line-height))}')
        ->toContain('.text-meta{font-size:var(--text-meta);line-height:var(--tw-leading,var(--text-meta--line-height))}')
        ->toContain('{font-size:var(--text-table);line-height:var(--text-table--line-height)}');
});

it('names a step of the scale in every view, never another size', function (): void {
    // The instrument first, on sources it must and must not report, so an empty result below is a
    // clean tree rather than a pattern that stopped matching
    expect(offScaleSizesIn('<p class="mt-1 text-xs opacity-70">'))->toBe(['text-xs'])
        ->and(offScaleSizesIn('<p class="sm:text-sm">'))->toBe(['text-sm'])
        ->and(offScaleSizesIn('<h2 class="card-title text-base">'))->toBe(['text-base'])
        ->and(offScaleSizesIn('<p class="text-[13px] text-[length:var(--x)] text-(length:--y)">'))
        ->toBe(['text-[13px]', 'text-[length:var(--x)]', 'text-(length:--y)'])
        ->and(offScaleSizesIn('<p class="[font-size:11px]">'))->toBe(['[font-size:11px]'])
        ->and(offScaleSizesIn('<p style="color: red; font-size: 11px">'))->toHaveCount(1)
        ->and(offScaleSizesIn("<p style='font: 11px sans-serif'>"))->toHaveCount(1)
        ->and(offScaleSizesIn('<small>fine print</small>'))->toBe(['<small'])
        ->and(offScaleSizesIn('<p class="text-meta text-xl text-xs-wide text-[#fff]" style="color: red">'))->toBeEmpty();

    $found = [];
    $read = 0;

    foreach (bladeTemplatesIn(__DIR__.'/../resources/views') as $view) {
        $read++;

        foreach (offScaleSizesIn((string) file_get_contents($view)) as $size) {
            if (! array_key_exists($size, OFF_SCALE_SIZES_ALLOWED_IN_VIEWS)) {
                $found[] = sprintf('%s: %s', basename($view), $size);
            }
        }
    }

    // A walk that read nothing would report nothing
    expect($read)->toBeGreaterThan(10)
        ->and($found)->toBeEmpty(implode(', ', $found));
});

it('lifts every size daisyUI draws below the floor, for every component a view uses', function (): void {
    $css = stylesheet();

    // **Every property daisyUI feeds into a font size, read out of the artifact.** A component that
    // sizes its text through a custom property -- `--fontsize` for a button, `--card-fs` for a card
    // body, `--font-size-min` for an input -- is invisible to a check reading only `font-size`, and
    // that is how `input-sm` shipped at 0.75rem past the first version of this test. Derived rather
    // than listed, so a property a daisyUI upgrade introduces is read without anyone adding it.
    preg_match_all('/font-size:([^;}]*)/', $css, $declarations);
    preg_match_all('/var\(--([a-z0-9-]+)/', implode(';', $declarations[1]), $fed);

    $properties = array_values(array_unique(array_filter(
        $fed[1],
        static fn (string $property): bool => ! str_starts_with($property, 'text-') && ! str_starts_with($property, 'tw-'),
    )));

    // The control: the four daisyUI draws with today. Missing one means the derivation stopped
    // reading the artifact rather than that the property went away
    expect($properties)->toContain('fontsize', 'card-fs', 'font-size', 'font-size-min');

    $sized = implode('|', array_map(
        static fn (string $property): string => preg_quote('--'.$property, '/'),
        $properties,
    ));

    // The classes the scale's own rules cover, read out of the artifact rather than listed here
    $lifted = [];

    foreach (scaleLiftRules() as $rule) {
        preg_match_all('/\.([a-z][a-z0-9-]*)/', $rule['selectors'], $classes);

        $lifted = [...$lifted, ...$classes[1]];
    }

    expect($lifted)->toContain('btn-xs', 'btn-sm', 'badge-sm', 'stat-title', 'stat-desc', 'table', 'card-body', 'input-sm');

    // Every rule in the artifact that sets a rem size below the floor, keyed by the first class of
    // EACH selector in its list -- `.btn-xs{--fontsize:.6875rem}` is `btn-xs`. Each, not the list's
    // first: daisyUI shares one rule between components, `.badge-sm,.kbd-sm{...}`, and reading only
    // the first reported a view using `kbd-sm` as clean because `badge-sm` is lifted
    preg_match_all('/([^{}]*)\{([^{}]*)\}/', $css, $all, PREG_SET_ORDER);

    $small = [];

    foreach ($all as [, $selectors, $body]) {
        preg_match_all('/(?:^|;)(?:font-size|'.$sized.'):(\d*\.?\d+)rem/', $body, $sizes);

        if (array_filter($sizes[1], static fn (string $size): bool => (float) $size < TYPE_FLOOR_REM) === []) {
            continue;
        }

        // A naive split, which cuts `:not(thead,tfoot)` in two. That can only ADD candidates -- a
        // class inside a `:not()` read as a selector of its own -- so its error is a loud failure,
        // never a quiet pass
        foreach (explode(',', $selectors) as $selector) {
            if (preg_match('/\.([a-z][a-z0-9-]*)/', $selector, $class) === 1) {
                $small[$class[1]] = true;
            }
        }
    }

    // The controls, one per path: daisyUI draws `btn-xs` at 0.6875rem through `--fontsize` and
    // `input-sm` at 0.75rem through `--font-size-min`, so a detector missing either has stopped
    // reading that path rather than found it clean
    expect($small)->toHaveKeys(['btn-xs', 'input-sm']);

    $views = '';

    foreach (bladeTemplatesIn(__DIR__.'/../resources/views') as $view) {
        $views .= sourceWithoutComments($view)."\n";
    }

    // Only a component a view names can render, and the artifact carries more than the views use:
    // daisyUI emits some rules wholesale -- the card size modifiers are here with no view naming
    // one -- so the candidates are narrowed to classes a view actually writes
    $unlifted = array_values(array_filter(
        array_keys($small),
        static fn (string $class): bool => preg_match('/(?<![\w-])'.preg_quote($class, '/').'(?![\w-])/', $views) === 1
            && ! in_array($class, $lifted, true),
    ));

    expect($unlifted)->toBeEmpty(implode(', ', $unlifted));
});

it('keeps the scale where it beats daisyUI and loses to a utility', function (): void {
    $css = stylesheet();

    // The reader's controls, first. A Tailwind utility sits directly in `utilities`, and a daisyUI
    // component in a sublayer of it, so a reader that could not tell those apart would pass the
    // assertion below wherever the rules went
    $utility = strpos($css, '.text-meta{');
    $daisy = strpos($css, '.btn-xs{--fontsize:.6875rem');

    expect($utility)->toBeInt()
        ->and($daisy)->toBeInt()
        ->and(blocksEnclosing($css, (int) $utility))->toBe(['@layer utilities'])
        ->and(blocksEnclosing($css, (int) $daisy))->toHaveCount(2)
        ->and(blocksEnclosing($css, (int) $daisy)[0])->toBe('@layer utilities')
        ->and(blocksEnclosing($css, (int) $daisy)[1])->toStartWith('@layer daisyui');

    // **Directly inside `utilities`, and nowhere else, is what makes the lift work.** daisyUI nests
    // its components in sublayers of `utilities`, and a rule unlayered within a layer beats every
    // sublayer of it. In `@layer components` the lift would lose to daisyUI outright; inside a
    // daisyUI sublayer it would depend on source order. Either way the text would render at
    // daisyUI's size with every other assertion here still passing.
    $rules = scaleLiftRules();

    expect($rules)->not->toBeEmpty();

    foreach ($rules as $rule) {
        expect(blocksEnclosing($css, $rule['offset']))->toBe(['@layer utilities'], $rule['selectors']);
    }
});
