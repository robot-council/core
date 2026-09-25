<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\GateRuns;
use RobotCouncil\Support\IssueReference;
use RobotCouncil\Support\Outcome;

/**
 * A gate reports the pull request it has started validating, or that it has finished (#336).
 *
 * Behind `tasks:claim`, which every role holds; what decides is the session's role, which
 * `Support\GateRuns` checks: only a `ci` session is a gate.
 */
final class GateRunController
{
    /**
     * Record the run.
     *
     * @param  Request  $request  The incoming request.
     * @param  GateRuns  $runs  The store.
     * @return JsonResponse What came of it.
     */
    public function store(Request $request, GateRuns $runs): JsonResponse
    {
        $request->validate([
            'pull_request' => ['required', 'string', 'max:'.IssueReference::MAX, 'regex:'.IssueReference::PATTERN],
        ]);

        $reference = $request->string('pull_request')->value();
        $outcome = $runs->start(Principal::agentSession($request), $reference);

        return new JsonResponse([
            'pull_request' => $outcome === Outcome::Applied ? $reference : null,
            'applied' => $outcome === Outcome::Applied,
        ], $outcome->status());
    }

    /**
     * End the run.
     *
     * @param  Request  $request  The incoming request.
     * @param  GateRuns  $runs  The store.
     * @return JsonResponse Whether one was running.
     */
    public function destroy(Request $request, GateRuns $runs): JsonResponse
    {
        return new JsonResponse(['cleared' => $runs->clear(Principal::agentSession($request))]);
    }
}
