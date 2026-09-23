<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Credentials;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts the session one agent process runs under. Reached with the installation credential, which
 * is the only thing that credential can do.
 */
final class SessionStartController
{
    /**
     * Create a session and issue its first token.
     *
     * @param  Request  $request  The incoming request.
     * @param  AgentSessions  $sessions  The session store.
     * @param  Credentials  $credentials  The configured lifetimes.
     * @return JsonResponse The session's ID and its token.
     */
    public function __invoke(Request $request, AgentSessions $sessions, Credentials $credentials): JsonResponse
    {
        $installation = Principal::installation($request);

        $request->validate([
            // The same restricted character set `harness` and `machine_label` carry, and for a
            // stronger reason: those two are shown to one developer on the verification page,
            // while this reaches every agent in the fleet through the `session.started` event,
            // from a credential that holds no ability beyond starting sessions. Event content is
            // untrusted input to an agent that may have shell access.
            'project_id' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._\/-]{1,128}$/D'],
        ]);

        $projectId = $request->filled('project_id') ? $request->string('project_id')->value() : null;

        $issued = $sessions->start($installation, $projectId);

        return new JsonResponse([
            'session_id' => $issued->owner->getKey(),
            'token' => $issued->plainTextToken,
            // What the token actually carries, not what the installation looked like when the
            // request arrived: an admin may have narrowed it in between
            'abilities' => $issued->abilities,

            // A duration rather than an instant, so a helper on a machine whose clock is off still
            // renews in time
            'expires_in' => $credentials->sessionTtlMinutes() * 60,

            // Where to begin reading the change feed. A fresh session starting from zero would walk
            // the whole table to reach the present -- history nobody asked for, bounded only by the
            // rate limit. A helper that does want history sends a lower cursor, and zero still
            // means everything.
            'feed_cursor' => $issued->feedCursor,
        ], Response::HTTP_CREATED);
    }
}
