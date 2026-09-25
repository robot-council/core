<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\Watchers;

/**
 * The bridge's watcher reports that it is still watching its session (#337).
 *
 * Its own route, and the only writer of `watcher_seen_at`, because the ordinary heartbeat is the
 * session's and any request refreshes that. No ability beyond being the session: a watcher reports
 * only about the session its token names.
 */
final class WatcherHeartbeatController
{
    /**
     * Record the heartbeat.
     *
     * @param  Request  $request  The incoming request.
     * @param  Watchers  $watchers  The watcher store.
     * @return JsonResponse The session it was recorded for.
     */
    public function __invoke(Request $request, Watchers $watchers): JsonResponse
    {
        $session = Principal::agentSession($request);

        $watchers->beat($session);

        return new JsonResponse(['session_id' => $session->getKey(), 'watching' => true]);
    }
}
