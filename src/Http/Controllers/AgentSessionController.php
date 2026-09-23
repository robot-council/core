<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\FeedCursors;
use RobotCouncil\Support\FleetAbilities;

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
     * @param  FleetAbilities  $fleet  What this fleet can do, as opposed to this session.
     * @return JsonResponse The session, without anything secret in it.
     */
    public function __invoke(Request $request, FeedCursors $cursors, FleetAbilities $fleet): JsonResponse
    {
        $session = Principal::agentSession($request);

        return new JsonResponse([
            'session_id' => $session->getKey(),
            'installation_id' => $session->installation_id,
            'status' => $session->status->value,

            // What this session is for, and the reason `abilities` below says what it says. A
            // bridge that reported only the ability list could tell a developer what it may do and
            // not why, which is the difference between "ask an admin" and "this is a build agent".
            'role' => $session->role->value,

            // What it asked to be, so a bridge can tell "nobody has decided yet" from "it was
            // refused" -- the two look identical from the role alone, and a client that could not
            // tell them apart would either ask forever or give up the first time.
            'requested_role' => $session->requested_role?->value,
            'project_id' => $session->project_id,

            // Where the work is, as two fields rather than one label a reader has to parse.
            // `project_id` stays beside them until the epic's final slice retires it, so a client
            // that reads only the old key keeps working.
            'repository' => $session->repository,
            'work_location' => $session->work_location,

            // Read from the row rather than from this instance, which the guard hydrated before
            // the request ran. A process that has lost its position asks here and resumes,
            // instead of replaying the feed from the beginning (#86).
            'feed_cursor' => $cursors->of($session),

            // What this token carries, which an admin may have narrowed since it was issued
            'abilities' => Tokens::abilities($session->currentAccessToken()),

            // **Whether the FLEET can post a directive, not whether this session can.** A bridge
            // waiting on its sink is waiting on somebody else's directive, and `abilities` above
            // cannot answer that: a receive-only session holding no `coordinator:direct` is the
            // normal case, so a client warning on its own abilities would warn on almost every
            // session. False here means nothing will ever arrive, which is a finding (#159).
            'fleet_can_direct' => $fleet->anyInstallationHolds(Ability::CoordinatorDirect),
        ]);
    }
}
