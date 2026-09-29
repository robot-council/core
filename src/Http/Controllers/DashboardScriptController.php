<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\Request;
use RobotCouncil\Support\DashboardAssets;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The dashboard's one script, which keeps the reader's place across a poll (#472), served by the
 * package as its stylesheet is.
 *
 * **Served from a route for the stylesheet's reasons** (`DashboardStylesheetController`): a
 * published copy goes stale or missing when a host upgrades without re-publishing, and the file that
 * ships should be the file that runs. Served from the page's own origin, `script-src 'self'` covers
 * it, which an inline script would not.
 *
 * It is not a build artifact. `resources/js/dashboard.js` is written by hand and shipped as it is, so
 * there is nothing for a consuming application to compile.
 *
 * The route is public and outside the web group, as the stylesheet's is: the file is this package's
 * own source and holds nothing about anyone.
 */
final class DashboardScriptController
{
    /**
     * How long a browser may keep the script before revalidating.
     */
    public const int MAX_AGE = DashboardStylesheetController::MAX_AGE;

    /**
     * Serve the script.
     *
     * @param  Request  $request  The incoming request.
     * @return BinaryFileResponse The script, or a revalidation response.
     *
     * @throws RuntimeException When the file is missing, which means the package was installed
     *                          incomplete.
     */
    public function __invoke(Request $request): BinaryFileResponse
    {
        $path = DashboardAssets::path(DashboardAssets::SCRIPT);

        if (! is_file($path)) {
            // Loud rather than silent, as for the stylesheet: without it every poll moves the page
            // under the reader again, and nothing would report it
            throw new RuntimeException('robot-council: the dashboard script is missing from the installed package.');
        }

        $response = new BinaryFileResponse($path);

        $response->setAutoEtag();
        $response->headers->set('Content-Type', 'text/javascript; charset=utf-8');
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);

        // The page names the file by a hash of its bytes (`DashboardAssets::version()`), so a new
        // release is a new URL; the ETag only answers a browser that revalidates the same one
        $response->isNotModified($request);

        return $response;
    }
}
