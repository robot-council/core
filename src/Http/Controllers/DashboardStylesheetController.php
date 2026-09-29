<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\Request;
use RobotCouncil\Support\DashboardAssets;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The dashboard's compiled stylesheet, served by the package rather than published by the host.
 *
 * A consuming application runs no asset build, which is the decision recorded on #30. That leaves
 * two ways to get the file in front of a browser: publish it into the host's `public/` and
 * reference it there, or serve it from a route. Publishing is the conventional choice and has a
 * failure mode this package has already decided against twice -- a host that never runs the publish,
 * or that upgrades without re-running it, serves a stale file or a 404, and the dashboard renders
 * unstyled with nothing reporting it. That is the same silent cross-repository coupling that ruled
 * out asking the host to run Tailwind.
 *
 * Serving it from here is correct by default: the file that ships is the file that renders, always.
 * The cost is one request through PHP, bounded by a long cache lifetime and an ETag the browser
 * revalidates against, and a host that would rather serve it statically can still put a copy in
 * front of this route.
 *
 * The route is public. The file is a stylesheet compiled from this package's own sources, holds
 * nothing a signed-in developer would not already see, and is needed by the sign-in page itself.
 */
final class DashboardStylesheetController
{
    /**
     * How long a browser may keep the stylesheet before revalidating.
     */
    public const int MAX_AGE = 31536000;

    /**
     * Serve the compiled stylesheet.
     *
     * @param  Request  $request  The incoming request.
     * @return BinaryFileResponse The stylesheet, or a revalidation response.
     *
     * @throws RuntimeException When the compiled artifact is missing, which means the package was
     *                          installed from a tree where `npm run build` had never been run.
     */
    public function __invoke(Request $request): BinaryFileResponse
    {
        $path = DashboardAssets::path(DashboardAssets::STYLESHEET);

        if (! is_file($path)) {
            // Loud rather than a blank page. A missing artifact is a packaging fault, and a
            // dashboard that renders unstyled is exactly the failure this route exists to prevent.
            throw new RuntimeException('robot-council: the compiled dashboard stylesheet is missing. Run `npm run build` in the package.');
        }

        $response = new BinaryFileResponse($path);

        $response->setAutoEtag();
        $response->headers->set('Content-Type', 'text/css');
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);

        // The page names the file by a hash of its bytes (`DashboardAssets::version()`), so a new
        // release is a new URL; the ETag only answers a browser that revalidates the same one
        $response->isNotModified($request);

        return $response;
    }
}
