<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\WorkIdentity;

/**
 * A session says it now works somewhere else, without leaving the fleet (#535).
 *
 * `Support\AgentSessions::move()` records what moves and what stays. The route names no session:
 * it moves the one the request authenticated as, so there is nothing here that could name another.
 * It needs no ability, as the heartbeat needs none, because every session may say where it is.
 */
final class MoveSessionController
{
    /**
     * Move the session.
     *
     * @param  Request  $request  The incoming request.
     * @param  AgentSessions  $sessions  The session store.
     * @return JsonResponse Where the session now is.
     */
    public function __invoke(Request $request, AgentSessions $sessions): JsonResponse
    {
        // The rules `SessionStartController` applies at join, so a value refused there is refused
        // here with the same message. Each is optional and neither may be blank or null: leaving a
        // field out keeps it, and clearing one is not something a move does.
        $request->validate([
            'repository' => ['required_without:work_location', 'string', 'max:'.WorkIdentity::MAX_REPOSITORY, 'regex:'.WorkIdentity::REPOSITORY],
            'work_location' => ['required_without:repository', 'string', 'max:'.WorkIdentity::MAX_LOCATION, 'regex:'.WorkIdentity::LOCATION],
        ]);

        $place = [];

        if ($request->has('repository')) {
            $place['repository'] = $request->string('repository')->value();
        }

        if ($request->has('work_location')) {
            $place['work_location'] = $request->string('work_location')->value();
        }

        $session = Principal::agentSession($request);
        $outcome = $sessions->move($session, $place);

        // Read back from the row, so the answer is what the fleet now sees rather than what was asked
        $row = AgentSession::query()->whereKey($session->getKey())->first(['repository', 'work_location']);

        return new JsonResponse([
            'session_id' => $session->getKey(),
            'repository' => $row?->repository,
            'work_location' => $row?->work_location,
            'applied' => $outcome === Outcome::Applied,
        ], $outcome->status());
    }
}
