<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * What the package knows about a developer signing in for the first time.
 *
 * **A value object rather than a parameter list, because this crosses a published boundary.**
 * `Contracts\SuppliesUserAttributes` is implemented by host applications, and adding a parameter to
 * an interface method breaks every implementation of it. Adding a readonly property here breaks
 * none, so whatever the package learns about an account later can reach a host without a major
 * version.
 *
 * Everything on it comes from the GitHub account the developer authenticated with, and the
 * allowlist has already admitted that account by the time one of these exists.
 */
final class NewDeveloper
{
    /**
     * @param  int  $githubId  The account's numeric GitHub user ID, which is what the allowlist names.
     * @param  string  $login  The account's login. The package uses it as the display name.
     * @param  string|null  $email  The account's verified primary email, when it exposes one.
     * @param  string|null  $avatarUrl  The account's avatar, when it has one.
     */
    public function __construct(
        public readonly int $githubId,
        public readonly string $login,
        public readonly ?string $email = null,
        public readonly ?string $avatarUrl = null
    ) {}
}
