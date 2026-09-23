<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Confirms that a developer's session has ended.
 *
 * **Outside the allowlist gate, necessarily.** Whoever sees this page is by definition not signed
 * in, so mounting it behind the gate would redirect them to GitHub and undo what they just asked
 * for. It renders its own document for the same reason the expired-sign-in page does: the
 * dashboard's shell names the signed-in developer and lists what they can reach, and there is no
 * developer here.
 *
 * Nothing from the request is printed.
 */
final class SignedOutController
{
    /**
     * Show the confirmation.
     *
     * @return View The signed-out page.
     */
    public function __invoke(): View
    {
        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::signed-out';

        return view($template);
    }
}
