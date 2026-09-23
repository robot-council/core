<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\DeveloperSignOut;
use RobotCouncil\Access\Guard;
use RobotCouncil\Support\HostUsers;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Guards the package's human-facing routes. It re-checks the signed-in developer against the
 * access lists on every request, so removing an ID from configuration locks that developer out on
 * their next request rather than whenever their session happens to end.
 */
final class EnsureAllowlistedDeveloper
{
    /**
     * @param  Allowlist  $allowlist  The configured access lists.
     * @param  HostUsers  $hostUsers  The developer's host user row and GitHub identity.
     * @param  AuthFactory  $auth  The host application's authentication factory.
     * @param  Guard  $guard  The configured guard's name.
     * @param  DeveloperSignOut  $signOut  Ends the session of an account this refuses.
     */
    public function __construct(
        private readonly Allowlist $allowlist,
        private readonly HostUsers $hostUsers,
        private readonly AuthFactory $auth,
        private readonly Guard $guard,
        private readonly DeveloperSignOut $signOut
    ) {}

    /**
     * Admit an allowlisted developer, and turn anyone else away.
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure(Request): Response  $next  The rest of the pipeline.
     * @return Response The pipeline's response, or a redirect to GitHub for a visitor.
     *
     * @throws AccessDeniedHttpException When the signed-in account is on neither access list.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Read the developer from the package's configured guard, never the host's default
        $user = $this->auth->guard($this->guard->name())->user();

        // Send a visitor who is not signed in to GitHub, remembering the path they wanted.
        // The path alone, never `fullUrl()`, whose host comes from the request's own headers.
        if ($user === null) {
            // Only a request that can be resumed is worth remembering. Laravel resumes an intended
            // URL with a redirect, which the browser follows as a GET, so remembering a POST sends
            // the developer -- after a full round trip through GitHub -- to a 405 on a route that
            // accepts POST only. Sign-out, approve and deny are all in that shape.
            if ($request->isMethodSafe()) {
                $request->session()->put('url.intended', '/'.ltrim($request->path(), '/'));
            }

            return redirect()->to(route('robot-council.auth.redirect'));
        }

        $githubId = $this->hostUsers->githubId($user);

        // Turn away a developer whose ID configuration no longer lists
        if ($githubId === null || ! $this->allowlist->admits($githubId)) {
            Log::warning('robot-council refused a signed-in account on neither access list.', [
                'github_id' => $githubId,
            ]);

            $this->signOut->end($request);

            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
