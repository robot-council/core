<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Http\Rules\BoundedMeta;
use RobotCouncil\Models\Task;
use RobotCouncil\Support\IssueReference;
use RobotCouncil\Support\Tasks;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files a task for the fleet.
 *
 * Everything bounded here reaches other developers' agents, and an agent may have shell access, so
 * the limits are the same kind as the ones on enrollment: a ceiling on what one caller can put in
 * front of everyone else, not a guess at what a reasonable task looks like.
 */
final class CreateTaskController
{
    /**
     * Create the task.
     *
     * @param  Request  $request  The incoming request.
     * @param  Tasks  $tasks  The task store.
     * @return JsonResponse The created task's ID and status.
     */
    public function __invoke(Request $request, Tasks $tasks): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:'.Task::MAX_TITLE],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.Task::MAX_DESCRIPTION],

            // Bounded in bytes and in depth once encoded, not merely required to be an array: an
            // `array` rule bounds nothing, and a JSON body is not subject to `max_input_vars`
            'payload' => ['sometimes', 'nullable', 'array', new BoundedMeta],

            'priority' => ['sometimes', 'integer', 'between:0,'.Task::MAX_PRIORITY],

            // The same restricted character set every other agent-facing identifier carries
            'project_id' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._\/-]{1,128}$/D'],

            // The GitHub issue the task is for, always repository-qualified: a bare `#N` names a
            // number that exists in every tracker the fleet works in
            'issue' => ['sometimes', 'nullable', 'string', 'max:'.IssueReference::MAX, 'regex:'.IssueReference::PATTERN],

            // Stored and nothing more, per #25 -- but it must at least name a task that exists,
            // or the column's foreign key would refuse the insert with a 500
            'parent_task_id' => ['sometimes', 'nullable', 'integer', Rule::exists('robot_council_tasks', 'id')],
        ]);

        $session = Principal::agentSession($request);

        $payload = $request->input('payload');

        $task = $tasks->create(
            $session,

            // Named one at a time rather than taken with `only()`, so the set of columns a request
            // can reach is an allowlist by construction and each arrives with a type
            [
                'title' => $request->string('title')->value(),
                'description' => $request->filled('description') ? $request->string('description')->value() : null,
                'payload' => \is_array($payload) && $payload !== [] ? $payload : null,
                'priority' => $request->integer('priority'),
                'project_id' => $request->filled('project_id') ? $request->string('project_id')->value() : null,
                'issue' => $request->filled('issue') ? $request->string('issue')->value() : null,
                'parent_task_id' => $request->filled('parent_task_id') ? $request->integer('parent_task_id') : null,
            ],

            // Read from the token as it creates, and stored on the task. It decides who may claim
            // this task, so revoking the ability later must not narrow that retroactively.
            Tokens::allows($session->currentAccessToken(), Ability::CoordinatorDirect)
        );

        return new JsonResponse([
            'task_id' => $task->id,
            'status' => $task->status->value,
        ], Response::HTTP_CREATED);
    }
}
