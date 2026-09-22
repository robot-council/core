<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Two\InvalidStateException;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\Guard;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Support\HostUsers;
use RobotCouncil\Support\NewDeveloper;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Finishes GitHub's OAuth flow: refuses accounts the access lists do not name, then records the
 * account's identity and signs the developer in on the configured guard.
 */
final class GitHubCallbackController
{
    /**
     * Enroll the GitHub account that just authorized the application, and sign it in.
     *
     * @param  Request  $request  The callback request GitHub sent the visitor back with.
     * @param  Factory  $socialite  Socialite's provider factory.
     * @param  Allowlist  $allowlist  The configured access lists.
     * @param  HostUsers  $hostUsers  The developer's host user row and GitHub identity.
     * @param  AuthFactory  $auth  The host application's authentication factory.
     * @param  Guard  $guard  The configured guard's name.
     * @return RedirectResponse|Response A redirect to wherever the developer was heading, or the
     *                                   page shown when the callback's state did not match.
     *
     * @throws AccessDeniedHttpException When the GitHub account is on neither access list.
     * @throws ConflictHttpException When another user holds the account's email address, or the
     *                               user row behind a known identity is gone.
     * @throws RuntimeException When the host application's user model or guard cannot sign in.
     */
    public function __invoke(
        Request $request,
        Factory $socialite,
        Allowlist $allowlist,
        HostUsers $hostUsers,
        AuthFactory $auth,
        Guard $guard
    ): RedirectResponse|Response {
        // Read the GitHub account that authorized the application; Socialite validates the state
        try {
            $account = $socialite->driver('github')->user();
        } catch (InvalidStateException) {
            // **Refused exactly as before, presented differently.** The state check is what stops a
            // callback forged by somebody else's page from signing a developer in, and this catches
            // the exception without weakening it: nothing below runs, and nobody is signed in.
            //
            // What changes is the answer. Letting it escape produced a 500, and a stale state is
            // not a server fault -- a developer refreshing after signing in causes it, as does a
            // session lapsing while GitHub's consent screen is open, or cookies being blocked.
            // Measured on the deployed application on 2026-09-18, where a developer testing the
            // access list refreshed the callback and got a 500 for it.
            // Pinned, because whether the analyzer can resolve a package view depends on whether
            // it could boot the application, which differs between a developer's machine and CI.
            // Written unpinned first: `composer analyse` passed locally and CI answered
            // `expects view-string, string given`.
            /** @var view-string $template */
            $template = 'robot-council::sign-in-expired';

            return response()->view($template, status: 400);
        }

        $githubId = (int) $account->getId();

        // Refuse an unlisted account before reading or writing any row
        if (! $allowlist->admits($githubId)) {
            Log::warning('robot-council refused a GitHub account on neither access list.', [
                'github_id' => $githubId,
            ]);

            throw new AccessDeniedHttpException;
        }

        $login = $account->getNickname() ?? (string) $githubId;
        $identity = $hostUsers->findIdentity($githubId);

        // Sign a returning developer into the user their identity points at
        if (! $identity instanceof GithubIdentity) {
            $user = $this->enroll($hostUsers, $githubId, $login, $account->getEmail(), $account->getAvatar());
        } else {
            $user = $hostUsers->findUserFor($identity);

            if (! $user instanceof Model) {
                Log::warning('robot-council found no user row behind a known GitHub identity.', [
                    'github_id' => $githubId,
                ]);

                throw new ConflictHttpException;
            }
        }

        // Refresh what GitHub can change between sign-ins, on the package's own table
        $hostUsers->recordIdentity($user, $githubId, $login, $account->getAvatar());

        if (! $user instanceof Authenticatable) {
            throw new RuntimeException("The host application's user model must implement Authenticatable.");
        }

        // Sign the developer in without a remember-me cookie, then start a fresh session
        $sessionGuard = $auth->guard($guard->name());

        if (! $sessionGuard instanceof StatefulGuard) {
            throw new RuntimeException(sprintf('The `%s` guard must be stateful for robot-council sign-in.', $guard->name()));
        }

        $sessionGuard->login($user);

        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    /**
     * Create the host user for a developer signing in for the first time.
     *
     * @param  HostUsers  $hostUsers  The developer's host user row and GitHub identity.
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @param  string  $login  The account's login, used as the display name.
     * @param  string|null  $email  The account's verified primary email, when it exposes one.
     * @param  string|null  $avatarUrl  The account's avatar, passed through to the host's attributes.
     * @return Model The saved user.
     *
     * @throws ConflictHttpException When another user already holds that email address.
     */
    private function enroll(
        HostUsers $hostUsers,
        int $githubId,
        string $login,
        ?string $email,
        ?string $avatarUrl
    ): Model {
        // Never claim an existing account by email: only a recorded identity says who someone is
        if ($email !== null && $hostUsers->findByEmail($email) instanceof Model) {
            Log::warning('robot-council refused a GitHub account whose email another user holds.', [
                'github_id' => $githubId,
            ]);

            throw new ConflictHttpException;
        }

        // **Asked of the table, because the check above was asked of the model** (#38). A host using
        // `SoftDeletes` -- or any other global scope -- hides a row from `findByEmail()` that the
        // `users.email` unique index still sees, so without this the create below raises an
        // integrity error and the developer is told nothing but `409`, forever, with no action that
        // would fix it.
        if ($email !== null && $hostUsers->emailIsHeld($email)) {
            Log::warning('robot-council refused a GitHub account whose email is held by a user the host model cannot see.', [
                'github_id' => $githubId,
            ]);

            throw new ConflictHttpException(
                'Your GitHub email address already belongs to an account in this application that has been '
                .'deleted or is otherwise hidden. An administrator has to restore or remove that account, or '
                .'change its email address, before you can sign in.'
            );
        }

        try {
            // Through `createFor()`, so a host that bound its own `SuppliesUserAttributes` decides
            // what the row carries. The package still writes it (#36).
            return $hostUsers->createFor(new NewDeveloper($githubId, $login, $email, $avatarUrl));
        } catch (UniqueConstraintViolationException) {
            // **Two callbacks raced**, which is now the only state that reaches here: both read no
            // user, both tried to create one, and the loser is holding this exception. The
            // soft-deleted and otherwise-hidden cases are refused above with a message that says
            // what to do, rather than arriving here and being reported as a race that did not
            // happen.
            //
            // A lost race still has to find the winner's row, and it looks for the identity rather
            // than the email, because the identity is what says who somebody is.
            $identity = $hostUsers->findIdentity($githubId);
            $user = $identity instanceof GithubIdentity ? $hostUsers->findUserFor($identity) : null;

            if (! $user instanceof Model) {
                // The winner wrote a user this reader cannot see, or the violation was on a column
                // this package does not write. Neither is a state an explanation can be invented
                // for, so it stays a bare conflict -- and the log line above is what distinguishes
                // it from the case that now has one.
                Log::warning('robot-council could not resolve a user after a unique-constraint violation.', [
                    'github_id' => $githubId,
                ]);

                throw new ConflictHttpException;
            }

            return $user;
        }
    }
}
