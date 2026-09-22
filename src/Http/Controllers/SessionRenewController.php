<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Credentials;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renews a session's token, which is how an agent process keeps working across a token lifetime
 * without a restart and without a human. The harness reruns its header helper after a 401, and the
 * helper comes here.
 *
 * A session that has gone is never renewed. The helper starts a new session instead, because the
 * old one's claims and locks were already released.
 */
final class SessionRenewController
{
    /**
     * Issue a fresh token for a session, and refuse the old one from now on.
     *
     * @param  Request  $request  The incoming request.
     * @param  string  $session  The session's ID, from the route.
     * @param  AgentSessions  $sessions  The session store.
     * @param  Credentials  $credentials  The configured lifetimes.
     * @return JsonResponse The session's ID, its new token, and where it has read to.
     *
     * @throws NotFoundHttpException When no such session exists.
     * @throws AccessDeniedHttpException When the session belongs to another installation.
     * @throws ConflictHttpException When the session has gone.
     */
    public function __invoke(
        Request $request,
        string $session,
        AgentSessions $sessions,
        Credentials $credentials
    ): JsonResponse {
        $installation = Principal::installation($request);

        $record = AgentSession::query()->whereKey($session)->first();

        if (! $record instanceof AgentSession) {
            throw new NotFoundHttpException;
        }

        // An installation renews only what it started
        if ($record->installation_id !== $installation->getKey()) {
            throw new AccessDeniedHttpException;
        }

        if ($record->hasGone()) {
            throw new ConflictHttpException;
        }

        $issued = $sessions->renew($installation, $record);

        return new JsonResponse([
            'session_id' => $issued->owner->getKey(),
            'token' => $issued->plainTextToken,
            'abilities' => $issued->abilities,
            'expires_in' => $credentials->sessionTtlMinutes() * 60,

            // The position this session last acknowledged, restated rather than moved. A process
            // that restarted while its token was still valid renews rather than re-enrolling, and
            // this is the only thing that lets it resume instead of choosing between replaying the
            // whole feed and guessing (#86).
            'feed_cursor' => $issued->feedCursor,
        ], Response::HTTP_OK);
    }
}
