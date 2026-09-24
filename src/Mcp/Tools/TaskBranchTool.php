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
use RobotCouncil\Support\Tasks;

/**
 * Report the branch a task is being worked on, as a tool.
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
            .'replaces the first. Needs `tasks:claim`.';
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
                ->description('The branch, as [A-Za-z0-9._/-].')
                ->required(),
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
            'branch' => ['required', 'string', 'max:'.BranchName::MAX, 'regex:'.BranchName::PATTERN],
        ]);

        $taskId = Arguments::integer($request->get('task_id'));
        $branch = Arguments::string($request->get('branch'));

        $outcome = $tasks->reportBranch($taskId, $this->session($http), $branch);

        if ($outcome !== Outcome::Applied) {
            return Response::error(match ($outcome) {
                Outcome::NotFound => 'No task with that id.',
                Outcome::Conflict => 'That task is not in progress or blocked. Report a branch after starting the task.',
                Outcome::Forbidden => 'This session does not hold that task.',
            });
        }

        return Response::structured(['task_id' => $taskId, 'branch' => $branch, 'applied' => true]);
    }
}
