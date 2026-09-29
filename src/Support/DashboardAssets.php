<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * The two files the dashboard loads from the package's own routes, and the version each URL carries.
 *
 * **The version is what makes a year's `max-age` safe.** Both files are served `public` with a
 * `max-age` of a year, which a browser honors without asking again, so an ETag alone never reaches a
 * returning developer: a fixed script would go on running as the old one until the cache expired, or
 * against a newer Livewire and newer markup. So each page names the file with `?v=` and a hash of
 * its bytes (#472), and a release that changes the file changes the URL. The route ignores the
 * parameter; it only has to differ.
 *
 * The hash is taken once per process. The files change only when the package does, which is a
 * deploy and a new process.
 */
final class DashboardAssets
{
    /**
     * The compiled stylesheet, relative to the package root.
     */
    public const string STYLESHEET = 'resources/dist/dashboard.css';

    /**
     * The hand-written script, relative to the package root.
     */
    public const string SCRIPT = 'resources/js/dashboard.js';

    /**
     * Versions already taken, by file.
     *
     * @var array<string, string>
     */
    private static array $versions = [];

    /**
     * Where a file is on disk.
     *
     * @param  string  $file  One of this class's constants.
     * @return string The absolute path.
     */
    public static function path(string $file): string
    {
        return \dirname(__DIR__, 2).'/'.$file;
    }

    /**
     * A short hash of a file's bytes, for its URL.
     *
     * @param  string  $file  One of this class's constants.
     * @return string Sixteen hex characters, or `missing` when the file is absent, which its route
     *                then reports loudly.
     */
    public static function version(string $file): string
    {
        if (! isset(self::$versions[$file])) {
            $hash = is_file(self::path($file)) ? hash_file('sha256', self::path($file)) : false;

            self::$versions[$file] = \is_string($hash) ? substr($hash, 0, 16) : 'missing';
        }

        return self::$versions[$file];
    }
}
