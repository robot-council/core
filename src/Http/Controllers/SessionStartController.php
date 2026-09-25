<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Capacity;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\Platform;
use RobotCouncil\Support\WorkIdentity;
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
     * @param  Capacity  $capacity  What the session may be given, against its seat's cap.
     * @return JsonResponse The session's ID and its token.
     */
    public function __invoke(Request $request, AgentSessions $sessions, Credentials $credentials, Capacity $capacity): JsonResponse
    {
        $installation = Principal::installation($request);

        $request->validate([
            // **A legacy input this endpoint translates, and no longer a field the package
            // stores.** `robot-council/core#285` dropped `robot_council_agent_sessions.project_id`;
            // the rule stays because the value is still read here, split below, and the halves
            // reach every agent in the fleet through the `session.joined` event -- from a
            // credential that holds no ability beyond starting sessions. Event content is untrusted
            // input to an agent that may have shell access, so it is charset-limited rather than
            // merely length-limited, exactly as `harness` and `machine_label` are.
            'project_id' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._\/-]{1,128}$/D'],

            // The two fields `project_id` became, bounded at the edge by the same rules
            // `Support\WorkIdentity` holds for the store. Both optional and independently nullable:
            // a request naming neither still starts a session, which is what keeps a client that
            // has not been upgraded working.
            'repository' => ['nullable', 'string', 'max:'.WorkIdentity::MAX_REPOSITORY, 'regex:'.WorkIdentity::REPOSITORY],
            'work_location' => ['nullable', 'string', 'max:'.WorkIdentity::MAX_LOCATION, 'regex:'.WorkIdentity::LOCATION],

            // Reported by the bridge, never typed in (#351). Optional, so an older bridge starts a
            // session as it always has; an OS family outside PHP's own set is refused, not stored
            'platform' => ['sometimes', 'nullable', 'array:os_family,arch', 'min:1'],
            'platform.os_family' => ['required_with:platform', 'string', Rule::in(Platform::OS_FAMILIES)],
            'platform.arch' => ['sometimes', 'nullable', 'string', 'max:'.Platform::MAX_ARCH, 'regex:'.Platform::ARCH],

            // How many tickets it will hold at once, for a harness that works through subagents
            // (#409). Optional, so an older bridge joins at one as before. Below one is refused, as
            // no capacity rather than a small one; above `Capacity::MAX` is accepted and clamped by
            // the store, the way the seat's cap is -- both mean "as many as this lane will take".
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:'.Capacity::DEFAULT],
        ]);

        $projectId = $request->filled('project_id') ? $request->string('project_id')->value() : null;
        $repository = $request->filled('repository') ? $request->string('repository')->value() : null;
        $workLocation = $request->filled('work_location') ? $request->string('work_location')->value() : null;

        // **Translated only when the client named NEITHER field.** This is the rule
        // `Support\AgentSessions::start()` applied while `project_id` was a column, moved to the
        // edge with the column's retirement (`robot-council/core#285`) so the store speaks only the
        // two fields the fleet reads. It is kept rather than dropped because `--project` is still a
        // flag on the client's `api`, `mcp` and `pending` commands: `robot-council/cli#137`
        // recorded sessions started that way against the production fleet, and a bridge with
        // nothing derivable from its checkout would otherwise join with no identity at all --
        // silently, since a session with two null fields is a legitimate state.
        //
        // Naming either field is taken as the client knowing its own mind: a repository with no
        // label is a real answer, and inventing a label for it from a string the client has stopped
        // maintaining would be worse than leaving it null.
        if ($repository === null && $workLocation === null) {
            [$repository, $workLocation] = WorkIdentity::fromProjectId($projectId);
        }

        $osFamily = $request->input('platform.os_family');
        $arch = $request->input('platform.arch');

        $issued = $sessions->start(
            $installation,
            $repository,
            $workLocation,
            \is_string($osFamily) ? $osFamily : null,
            \is_string($arch) ? $arch : null,
            $request->filled('capacity') ? $request->integer('capacity') : Capacity::DEFAULT
        );

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

            // What a coordinator may place on it now, and what it asked for (#409). A `capacity`
            // below `declared_capacity` is the developer's seat cap -- one until they raise it on
            // their seats page -- and a declaration past `Capacity::MAX` reads back as that bound.
            'capacity' => $capacity->of($issued->owner),
            'declared_capacity' => $issued->owner->declared_capacity,
        ], Response::HTTP_CREATED);
    }
}
