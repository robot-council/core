<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\BranchName;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\Tasks;

/**
 * The lane holding a task says which branch it is working on, once it has one.
 *
 * `Support\Tasks::reportBranch()` records why this is a report after the start rather than a
 * value read at it. The route sits behind `tasks:claim`, which every role preset holds; what
 * decides whether a report lands is holding the task, and the store's conditional update is where
 * that is tested.
 */
final class ReportTaskBranchController
{
    /**
     * Record the branch.
     *
     * @param  Request  $request  The incoming request.
     * @param  string  $task  The task's ID, from the route.
     * @param  Tasks  $tasks  The task store.
     * @return JsonResponse What came of it.
     */
    public function __invoke(Request $request, string $task, Tasks $tasks): JsonResponse
    {
        $request->validate([
            'branch' => ['required', 'string', 'max:'.BranchName::MAX, 'regex:'.BranchName::PATTERN],
        ]);

        $branch = $request->string('branch')->toString();

        $outcome = $tasks->reportBranch((int) $task, Principal::agentSession($request), $branch);

        return new JsonResponse([
            'task_id' => (int) $task,
            'branch' => $outcome === Outcome::Applied ? $branch : null,
            'applied' => $outcome === Outcome::Applied,
        ], $outcome->status());
    }
}
