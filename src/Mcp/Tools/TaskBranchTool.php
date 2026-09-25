<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use RobotCouncil\Access\Ability;
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Mcp\Arguments;
use RobotCouncil\Support\BranchName;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\SubLabel;
use RobotCouncil\Support\Tasks;

/**
 * Report the branch a task is being worked on, and which subagent is working it, as a tool.
 *
 * The same store call as `Http\Controllers\ReportTaskBranchController`; `Support\Tasks::reportBranch()`
 * records why a branch is reported after the start rather than read at it.
 */
final class TaskBranchTool extends Tool
{
    use ActsAsAgent;

    /**
     * The tool's name, which an agent calls it by.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'task_branch';
    }

    /**
     * What the tool does, as an agent reads it.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return 'Record the git branch you are working on for a task you hold, once you have created it. '
            .'Call it after creating or switching to the branch, not before -- at `task_start` the branch '
            .'usually does not exist yet. Works while the task is in progress or blocked; a second call '
            .'replaces the first. If you hand tasks you hold to subagents, also pass `sub_label` -- for '
            ."example the subagent's worktree -- so the lane board and the feed can tell your tasks apart; "
            .'it is display only, and it is visible to every session in the fleet, so it must not name an '
            .'issue, a branch or anything confidential -- use something like `subagent-2` or a worktree '
            .'slot name. Send either or both. Needs `tasks:claim`.';
    }

    /**
     * The arguments the tool takes.
     *
     * @param  JsonSchema  $schema  The schema factory.
     * @return array<string, mixed> The argument schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task you hold.')->required(),
            'branch' => $schema->string()
                ->max(BranchName::MAX)
                ->description('The branch, as [A-Za-z0-9._/-]. Required unless you send `sub_label`.'),
            'sub_label' => $schema->string()
                ->max(SubLabel::MAX)
                ->description('Which of your subagents works this task, as [A-Za-z0-9._-] starting with a letter or digit. Display only. It is visible to every session in the fleet, so it must not name an issue, a branch or anything confidential -- use something like `subagent-2` or a worktree slot name.'),
        ];
    }

    /**
     * Record it.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  Tasks  $tasks  The task store.
     * @return Response|ResponseFactory The outcome.
     */
    public function handle(Request $request, HttpRequest $http, Tasks $tasks): Response|ResponseFactory
    {
        if (! $this->allows($http, Ability::TasksClaim)) {
            return $this->refuse(Ability::TasksClaim);
        }

        $request->validate([
            'task_id' => ['required', 'integer', 'min:1'],
            'branch' => ['required_without:sub_label', 'nullable', 'string', 'max:'.BranchName::MAX, 'regex:'.BranchName::PATTERN],
            'sub_label' => ['sometimes', 'nullable', 'string', 'max:'.SubLabel::MAX, 'regex:'.SubLabel::PATTERN],
        ]);

        $taskId = Arguments::integer($request->get('task_id'));

        // `trim()` for the reason `TaskTransitionTool` gives: without the framework's `TrimStrings`
        // a blank value would reach the store, which refuses it as an internal error
        $branch = $request->get('branch');
        $branch = \is_string($branch) && trim($branch) !== '' ? $branch : null;

        $subLabel = $request->get('sub_label');
        $subLabel = \is_string($subLabel) && trim($subLabel) !== '' ? $subLabel : null;

        // No "neither" case to answer here: `required_without` counts a blank as absent and both
        // `regex` rules refuse whitespace, so a call naming neither never passes validation

        $outcome = $tasks->reportBranch($taskId, $this->session($http), $branch, $subLabel);

        if ($outcome !== Outcome::Applied) {
            return Response::error(match ($outcome) {
                Outcome::NotFound => 'No task with that id.',
                Outcome::Conflict => 'That task is not in progress or blocked. Report a branch after starting the task.',
                Outcome::Forbidden => 'This session does not hold that task.',
            });
        }

        return Response::structured(['task_id' => $taskId, 'branch' => $branch, 'sub_label' => $subLabel, 'applied' => true]);
    }
}
