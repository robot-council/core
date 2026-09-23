<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

/**
 * Reads a console argument as text.
 *
 * It takes `mixed` rather than reading the argument itself, and that is the point. What
 * `Illuminate\Console\Command::argument()` is inferred to return depends on whether the analyzer
 * could boot the application and read the command's signature, which differs between a developer's
 * machine and CI. A narrowing written against either answer is reported as dead code by the other.
 * Taking `mixed` is true in both.
 */
final class Argument
{
    /**
     * One console argument as a string.
     *
     * @param  mixed  $value  Whatever the argument came back as.
     * @return string The value as text, or an empty string for an argument that is not scalar.
     */
    public static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * A repeatable option as a list of non-empty strings, splitting each on commas.
     *
     * Same reason as `text()`, and it was paid for the same way: `--only=*` is typed
     * `array|bool|float|int|string|null` where the analyzer cannot read the signature, so
     * `foreach`ing it directly passes locally and fails in CI with *"Argument of an invalid type
     * ... supplied for foreach"*. Taking `mixed` is true on both sides.
     *
     * Splitting on commas here rather than at the call site means both shapes an operator reaches
     * for -- the option repeated, and one comma-separated list -- arrive the same way.
     *
     * @param  mixed  $value  Whatever the option came back as.
     * @return list<string> The values, trimmed, with empties dropped.
     */
    public static function texts(mixed $value): array
    {
        if (! \is_array($value)) {
            return [];
        }

        $texts = [];

        foreach ($value as $entry) {
            foreach (explode(',', self::text($entry)) as $text) {
                $text = trim($text);

                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        return $texts;
    }
}
