<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * The fleet's name as the console prints it (#313).
 *
 * Read from `robot-council.dashboard.name`, which a host sets. It is printed through `{{ }}` like
 * anything else, and never into a URL attribute. A value that is not a non-empty string falls back
 * to the default rather than failing a page, because every page prints it.
 */
final class DashboardName
{
    /**
     * The name when a host sets none.
     */
    public const string DEFAULT = 'Robot Council';

    /**
     * The configured name, or the default.
     *
     * @return string The name.
     */
    public static function value(): string
    {
        $name = config('robot-council.dashboard.name');

        return \is_string($name) && trim($name) !== '' ? $name : self::DEFAULT;
    }

    /**
     * A page's title: the page, then the name, or the name alone for a page that has none.
     *
     * @param  mixed  $page  The page's own title, or null for the index.
     * @return string The title.
     */
    public static function title(mixed $page): string
    {
        return \is_string($page) && $page !== '' ? $page.' · '.self::value() : self::value();
    }
}
