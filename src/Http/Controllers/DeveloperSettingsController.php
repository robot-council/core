<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use RobotCouncil\Support\CoordinatorSettings;

/**
 * Every developer's parked seats and assignment hours, for a coordinator to read.
 *
 * **Read-only, and there is no route that writes them.** #314 decided the coordinator reads these
 * to place and to render and never writes them, because they are the developers' constraints on
 * the coordinator. The only writer is the dashboard's settings page, which a session cannot reach:
 * it reads the developer off the package's web guard, and a token names a session, not a person.
 *
 * Behind `coordinator:direct` rather than open to every session, because a developer's hours and
 * days off say when they are away, and only the session placing work needs to know. What it
 * returns is `CoordinatorSettings`, which the `developer_settings` MCP tool returns too (#440).
 */
final class DeveloperSettingsController
{
    /**
     * List every developer's settings and every recorded seat.
     *
     * @param  CoordinatorSettings  $settings  The description both ways in share.
     * @return JsonResponse The settings.
     */
    public function __invoke(CoordinatorSettings $settings): JsonResponse
    {
        // The clock the placement check reads (`Tasks::placementRefusals()`), so `inside_hours` is
        // the answer a placement made now would get
        return new JsonResponse($settings->describe(Carbon::now()));
    }
}
