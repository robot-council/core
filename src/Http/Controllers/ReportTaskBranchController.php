<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\BranchName;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\SubLabel;
use RobotCouncil\Support\Tasks;

/**
 * The lane holding a task says which branch it is working on, once it has one, and which of its
 * subagents is working it (#409).
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
        // Either alone, or both: a lane that dispatches a subagent before its branch exists labels
        // the task first, and one with no subagents reports only the branch as it always has
        $request->validate([
            'branch' => ['required_without:sub_label', 'nullable', 'string', 'max:'.BranchName::MAX, 'regex:'.BranchName::PATTERN],
            'sub_label' => ['sometimes', 'nullable', 'string', 'max:'.SubLabel::MAX, 'regex:'.SubLabel::PATTERN],
        ]);

        $branch = $request->filled('branch') ? $request->string('branch')->toString() : null;
        $subLabel = $request->filled('sub_label') ? $request->string('sub_label')->toString() : null;

        $outcome = $tasks->reportBranch((int) $task, Principal::agentSession($request), $branch, $subLabel);

        return new JsonResponse([
            'task_id' => (int) $task,
            'branch' => $outcome === Outcome::Applied ? $branch : null,
            'sub_label' => $outcome === Outcome::Applied ? $subLabel : null,
            'applied' => $outcome === Outcome::Applied,
        ], $outcome->status());
    }
}
