<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use BackedEnum;
use InvalidArgumentException;

/**
 * The one way a value may be written into a `wire:` action call.
 *
 * **A `wire:click` attribute is a scripting context, and Blade's escaping does not protect it.**
 * `{{ }}` turns a quote into `&#039;`, and an HTML parser decodes entities inside an attribute
 * value *before* Livewire evaluates the expression. So `wire:click="act('{{ $x }}')"` with `$x` of
 * `a'b` renders, after decoding, as `act('a'b')` -- the string literal closes early and whatever
 * follows is expression text. Measured, not reasoned about.
 *
 * Every interpolation the package writes into such an attribute passes through here, which is what
 * `EscapingGuardTest` enforces. That turns a property the dashboard held **by accident of its
 * inputs** -- a `TaskStatus` is `[a-z_]+`, an `Access\Ability` is `[a-z:]+`, an id is a bigint --
 * into one it holds by construction, and it keeps holding when somebody interpolates a machine
 * label next year.
 *
 * **This is a whitelist, and deliberately a narrow one.** It admits what an action argument is
 * actually made of and nothing else. There is no escaping here on purpose: a value that would need
 * escaping is refused, because escaping for a context that is simultaneously HTML and a JavaScript
 * expression is exactly the layered guess this exists to avoid.
 *
 * `wire:key` is **not** in scope and needs nothing from this class. It is an identifier Livewire
 * reads as a literal string for DOM diffing, never evaluated, so Blade's own escaping is the whole
 * of what it needs.
 */
final class WireArgument
{
    /**
     * What an argument may be made of: letters, digits, and the three separators the package's own
     * vocabularies use -- `:` in an ability, `_` in a status, `-` in a harness.
     *
     * No quote, no parenthesis, no comma, no backslash, no whitespace, no angle bracket. Anchored
     * with `D` so a trailing newline cannot slip past `$`.
     */
    public const string PATTERN = '/^[A-Za-z0-9:_-]+$/D';

    /**
     * The longest argument this will write.
     *
     * An action argument names something the server already holds -- an id, an ability, a status --
     * so anything longer is a value that has no business being one.
     */
    public const int MAX = 64;

    /**
     * One value, ready to be written into a `wire:` expression.
     *
     * @param  int|string|BackedEnum  $value  The argument. An enum is reduced to its backing value,
     *                                        so a view can pass `$ability` rather than
     *                                        `$ability->value` and cannot pass the wrong one.
     * @return string The value, exactly as it will appear in the attribute.
     *
     * @throws InvalidArgumentException When the value is not one this may write, which is a defect
     *                                  in the caller rather than something to escape around.
     */
    public static function of(int|string|BackedEnum $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        $text = \is_int($value) ? (string) $value : $value;

        if (mb_strlen($text) > self::MAX) {
            throw new InvalidArgumentException(sprintf(
                'A `wire:` argument is limited to %d characters, and this one is %d.',
                self::MAX,
                mb_strlen($text)
            ));
        }

        if (preg_match(self::PATTERN, $text) !== 1) {
            // One string rather than three concatenated. The concatenation carried six mutants no
            // input can tell apart -- the message reads the same however its pieces are joined --
            // and removing them is better than annotating them.
            throw new InvalidArgumentException('A `wire:` argument may hold only letters, digits, `:`, `_` and `-`. A value outside that set cannot be written into a Livewire expression safely, and escaping it would be a guess about two parsers at once.');
        }

        return $text;
    }
}
