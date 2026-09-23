<?php

declare(strict_types=1);

namespace RobotCouncil\Access;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Ends a developer's session on the package's own guard.
 *
 * **Two paths sign a developer out, and they have to agree.** `EnsureAllowlistedDeveloper` drops an
 * account that has left the access lists, and `SignOutController` handles a developer doing it
 * themselves. Signing out on the host's default guard would leave the package's own session
 * standing, so the guard is read from configuration here exactly as it is everywhere else.
 *
 * The three steps are one unit. Calling `logout()` without invalidating leaves the session's own
 * data behind, and invalidating without regenerating the token leaves the token the old session
 * issued valid against the new one.
 */
final class DeveloperSignOut
{
    /**
     * @param  AuthFactory  $auth  The host application's authentication factory.
     * @param  Guard  $guard  The configured guard's name.
     */
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Guard $guard
    ) {}

    /**
     * Sign the developer out and replace the session, so nothing of it survives.
     *
     * @param  Request  $request  The request whose session is ending.
     *
     * @throws RuntimeException When the configured guard cannot sign a developer out.
     */
    public function end(Request $request): void
    {
        $guard = $this->auth->guard($this->guard->name());

        // A guard that cannot sign anybody out would leave this returning as though it had
        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException(sprintf('The `%s` guard must be stateful for robot-council to sign a developer out.', $this->guard->name()));
        }

        $guard->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
