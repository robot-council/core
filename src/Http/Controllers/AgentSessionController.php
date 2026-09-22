<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\FeedCursors;

/**
 * Tells an agent process what its own session is. The bridge calls it after starting or renewing,
 * to confirm the token it holds authenticates as the session it thinks it does, and to read the
 * abilities it actually has rather than the ones it asked for.
 *
 * It is the first route behind the agent guard, so it is also where that guard is exercised: an
 * installation credential, a browser session, and a token from a session that has gone are all
 * refused here.
 */
final class AgentSessionController
{
    /**
     * Describe the session this request authenticated as.
     *
     * @param  Request  $request  The incoming request.
     * @param  FeedCursors  $cursors  Where each session has read to.
     * @return JsonResponse The session, without anything secret in it.
     */
    public function __invoke(Request $request, FeedCursors $cursors): JsonResponse
    {
        $session = Principal::agentSession($request);

        return new JsonResponse([
            'session_id' => $session->getKey(),
            'installation_id' => $session->installation_id,
            'status' => $session->status->value,
            'project_id' => $session->project_id,

            // Read from the row rather than from this instance, which the guard hydrated before
            // the request ran. A process that has lost its position asks here and resumes,
            // instead of replaying the feed from the beginning (#86).
            'feed_cursor' => $cursors->of($session),

            // What this token carries, which an admin may have narrowed since it was issued
            'abilities' => Tokens::abilities($session->currentAccessToken()),
        ]);
    }
}
