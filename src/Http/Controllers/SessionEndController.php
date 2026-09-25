<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Support\SessionPresence;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ends one of an installation's sessions when its harness exits. The session's tokens are deleted at
 * once; what the process held is released by the next presence sweep's release steps, within about a
 * minute, rather than after the gone threshold has passed (#368). Nothing here releases it directly,
 * and while the sweep is switched off nothing releases it at all.
 *
 * Reached with the installation credential rather than the session's own token, because the session
 * whose token it is may be the thing that has just died. The bridge holds the installation
 * credential for exactly this: it outlives any one process.
 *
 * **Ending is idempotent, and answers 200 either way.** A bridge that sends this on the way out has
 * no good response to a 409 -- it is already exiting -- and the honest statement is that the session
 * is gone, which is true whether this request or the sweep got there first. Renewal is the opposite
 * case and does answer 409, because the caller there is asking for something it cannot have.
 */
final class SessionEndController
{
    /**
     * End the session.
     *
     * @param  Request  $request  The incoming request.
     * @param  string  $session  The session's ID, from the route.
     * @param  SessionPresence  $presence  The presence store.
     * @return JsonResponse The session, once it has gone.
     *
     * @throws NotFoundHttpException When no such session exists.
     * @throws AccessDeniedHttpException When the session belongs to another installation.
     */
    public function __invoke(Request $request, string $session, SessionPresence $presence): JsonResponse
    {
        $installation = Principal::installation($request);

        // With the installation, which the `session.gone` event names, so describing the session
        // costs no second query inside the transaction that ends it.
        $record = AgentSession::query()->with('installation')->whereKey($session)->first();

        if (! $record instanceof AgentSession) {
            throw new NotFoundHttpException;
        }

        // An installation ends only what it started, and the check runs before anything is
        // written. The 404-versus-403 split does tell a caller which session IDs exist, which is
        // the same disclosure `SessionRenewController` already makes; it is accepted rather than
        // absent, because every holder of an installation credential is an allowlisted developer's
        // machine and a session ID on its own authorizes nothing.
        if ($record->installation_id !== $installation->getKey()) {
            throw new AccessDeniedHttpException;
        }

        $presence->end($record);

        return new JsonResponse([
            'session_id' => $record->getKey(),

            // Stated rather than read back. A concurrent sweep or a second DELETE may have been the
            // call that moved the row, in which case `end()` changed nothing and the instance still
            // carries the status it was loaded with -- and answering `active` for a session that
            // has gone is the one thing this endpoint must not do.
            'status' => AgentSessionStatus::Gone->value,
        ], Response::HTTP_OK);
    }
}
