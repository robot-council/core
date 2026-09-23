<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RobotCouncil\Access\DeveloperSignOut;

/**
 * Ends the signed-in developer's session, at their own request.
 *
 * **POST only, like approve and deny.** A sign-out reachable by following a link is a sign-out
 * another site can have a developer perform by embedding an image, and one a link prefetcher can
 * perform without anybody clicking anything.
 *
 * It redirects rather than rendering, so a refresh of the resulting page does not re-post. The
 * target is the signed-out page rather than the dashboard: the dashboard would send the developer
 * straight back to GitHub, which with a live GitHub session signs them in again and makes the
 * control look broken.
 */
final class SignOutController
{
    /**
     * @param  DeveloperSignOut  $signOut  Ends a session on the package's own guard.
     */
    public function __construct(private readonly DeveloperSignOut $signOut) {}

    /**
     * Sign the developer out.
     *
     * @param  Request  $request  The incoming request.
     * @return RedirectResponse The signed-out page.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $this->signOut->end($request);

        return redirect()->to(route('robot-council.signed-out'));
    }
}
