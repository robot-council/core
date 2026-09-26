<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Composer\InstalledVersions;
use OutOfBoundsException;

/**
 * The `robot-council/core` release the deployment is running, as every dashboard page shows it (#405).
 *
 * **Read from the installed package, never from GitHub.** Whoever judges a new release on the
 * dashboard needs to know whether the deployment has picked it up, which is a question about what is
 * installed rather than what is published -- and core cuts tags without GitHub Releases, so a
 * Releases lookup would find nothing anyway. Composer's `InstalledVersions` answers from the host's
 * own install record, with no request made.
 *
 * **A version that is not a tag says so.** Installed from a branch (`dev-main`, `1.x-dev`) or with no
 * version at all, the page shows that name with the short commit, rather than showing nothing or
 * passing a branch off as a release.
 *
 * **The commit is the one Composer recorded when it last installed the package**, not one read from
 * the running code. For a tag, a VCS branch or a deployment that installs on every release, the two
 * are the same. For a path repository they can differ until the next `composer install`, and where
 * the path has no `.git` directory Composer records a hash of the package's `composer.json` in its
 * place, which this cannot tell from a commit.
 */
final class CoreVersion
{
    /**
     * The package this reports on.
     */
    public const string PACKAGE = 'robot-council/core';

    /**
     * How much of a commit reference is shown, which is what git abbreviates to.
     */
    public const int SHORT_REFERENCE = 7;

    /**
     * The running version, as the page prints it.
     *
     * @param  (callable(string): ?string)|null  $version  How to read the package's version; Composer's install record when null.
     * @param  (callable(string): ?string)|null  $reference  How to read its commit reference; Composer's install record when null.
     * @return string For example `robot-council/core v0.7.1`, or `robot-council/core dev-main (0924157)`.
     */
    public static function current(?callable $version = null, ?callable $reference = null): string
    {
        $version ??= InstalledVersions::getPrettyVersion(...);
        $reference ??= InstalledVersions::getReference(...);

        try {
            return self::describe($version(self::PACKAGE), $reference(self::PACKAGE));
        } catch (OutOfBoundsException) {
            // Not in the install record at all, which a host could only arrange by loading the
            // package some other way. The page says so rather than failing to render.
            return self::describe(null, null);
        }
    }

    /**
     * A version and commit reference, as the page prints them.
     *
     * @param  string|null  $version  Composer's pretty version: a tag such as `v0.7.1`, or a branch such as `dev-main`.
     * @param  string|null  $reference  The commit the install came from, when Composer recorded one.
     * @return string The line.
     */
    public static function describe(?string $version, ?string $reference): string
    {
        if ($version === null || $version === '') {
            return self::PACKAGE.', version unknown';
        }

        if (self::isTag($version)) {
            return self::PACKAGE.' '.$version;
        }

        $short = $reference !== null && preg_match('/^[0-9a-f]{7,64}$/i', $reference) === 1
            ? substr($reference, 0, self::SHORT_REFERENCE)
            : null;

        return self::PACKAGE.' '.$version.' ('.($short ?? 'commit unknown').')';
    }

    /**
     * Whether a pretty version names a tagged release rather than a branch or nothing.
     *
     * Composer names a branch `dev-<name>`, or `<n>.x-dev` for a numbered one, and gives a root
     * package with no version at all a `+no-version-set` suffix.
     */
    private static function isTag(string $version): bool
    {
        return ! str_starts_with($version, 'dev-')
            && ! str_ends_with($version, '-dev')
            && ! str_contains($version, 'no-version-set');
    }
}
