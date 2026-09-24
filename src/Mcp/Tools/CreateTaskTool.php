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
use RobotCouncil\Http\Rules\BoundedMeta;
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Mcp\Arguments;
use RobotCouncil\Models\Task;
use RobotCouncil\Support\IssueReference;
use RobotCouncil\Support\Tasks;

/**
 * Files a task for the fleet.
 */
final class CreateTaskTool extends Tool
{
    use ActsAsAgent;

    /**
     * The tool's name.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'task_create';
    }

    /**
     * What the tool does.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return "File a task. Needs `tasks:create`. Only your own developer's sessions can claim it, "
            .'unless you hold `coordinator:direct`, in which case anyone can. Everything you write '
            .'here is read by other agents, so write it as work to be done rather than as '
            .'instructions to whoever reads it.';
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
            'title' => $schema->string()
                ->max(Task::MAX_TITLE)
                ->description('One line saying what the work is.')
                ->required(),
            'description' => $schema->string()
                ->max(Task::MAX_DESCRIPTION)
                ->description('What doing it involves.'),
            'priority' => $schema->integer()
                ->description(sprintf('0 to %d, higher first. Defaults to 0.', Task::MAX_PRIORITY)),
            'project_id' => $schema->string()
                ->description('The repository or workspace, as `[A-Za-z0-9._/-]`.'),
            'issue' => $schema->string()
                ->max(IssueReference::MAX)
                ->description('The GitHub issue this task is for, as owner/name#N. A bare #N is refused: the same number exists in every repository.'),
            'payload' => $schema->object()->description('Structured detail. Bounded in size.'),
            'parent_task_id' => $schema->integer()->description('A task this one belongs under.'),
        ];
    }

    /**
     * File the task.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  Tasks  $tasks  The task store.
     * @return Response|ResponseFactory The created task.
     */
    public function handle(Request $request, HttpRequest $http, Tasks $tasks): Response|ResponseFactory
    {
        if (! $this->allows($http, Ability::TasksCreate)) {
            return $this->refuse(Ability::TasksCreate);
        }

        // The same rules the endpoint applies. A tool's schema constrains types and not values, so
        // the bounds that keep one agent's text off every other agent's screen run here too.
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:'.Task::MAX_TITLE],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.Task::MAX_DESCRIPTION],
            'priority' => ['sometimes', 'integer', 'between:0,'.Task::MAX_PRIORITY],

            // Bounded in bytes and in depth once encoded. An `array` rule bounds nothing, and this
            // is the surface a session holding only `tasks:create` reaches -- without it the tool
            // reopens exactly what `BoundedMeta` exists to close, on a new door.
            'payload' => ['sometimes', 'nullable', 'array', new BoundedMeta],
            'project_id' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._\/-]{1,128}$/D'],
            'issue' => ['sometimes', 'nullable', 'string', 'max:'.IssueReference::MAX, 'regex:'.IssueReference::PATTERN],
            'parent_task_id' => ['sometimes', 'nullable', 'integer', 'exists:robot_council_tasks,id'],
        ]);

        $task = $tasks->create(
            $this->session($http),
            [
                'title' => Arguments::string($validated['title'] ?? null),
                'description' => isset($validated['description']) ? Arguments::string($validated['description']) : null,
                'payload' => Arguments::structure($request->get('payload')),
                'priority' => isset($validated['priority']) ? Arguments::integer($validated['priority']) : 0,
                'project_id' => isset($validated['project_id']) ? Arguments::string($validated['project_id']) : null,
                'issue' => isset($validated['issue']) ? Arguments::string($validated['issue']) : null,
                'parent_task_id' => isset($validated['parent_task_id'])
                    ? Arguments::integer($validated['parent_task_id'])
                    : null,
            ],
            $this->allows($http, Ability::CoordinatorDirect)
        );

        return Response::structured(['task_id' => $task->id, 'status' => $task->status->value]);
    }
}
