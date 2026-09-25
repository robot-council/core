<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * Sends the retired presence page to the Agents page.
 *
 * Agents and locks shared `dashboard/presence` until #308 gave each a page of its own. A bookmark is
 * the only thing that still reaches the old path, and this lands it rather than answering 404.
 *
 * **A controller rather than `Route::redirect()`, for the reason `PrefixRootController` records.**
 * That helper takes a literal path and `RedirectController` never applies the group's prefix to
 * it. Measured on `laravel/framework` v13.32.0 with the prefix set to `council`: a destination of
 * `dashboard/agents` went out as the RELATIVE `Location: dashboard/agents`, which a browser resolves
 * against `/council/dashboard/presence` to `/council/dashboard/dashboard/agents`, a 404. Naming the
 * route resolves whichever prefix the host configured.
 *
 * Nothing from the request is read -- the old page's `sessions` and `locks` filters are not carried
 * over, because neither name means anything on the page this lands on -- so there is nothing here
 * to escape.
 */
final class PresenceRedirectController
{
    /**
     * Redirect to the Agents page.
     *
     * @return RedirectResponse A 301 to `robot-council.agents`.
     */
    public function __invoke(): RedirectResponse
    {
        // 301 rather than the 302 `PrefixRootController` answers with. That one is 302 because its
        // answer depends on configuration a host may change; this one records that a page moved
        // for good, and a browser caching it can only ever send a reader to where the page now is
        // under the same prefix.
        return redirect()->route('robot-council.agents', status: 301);
    }
}
