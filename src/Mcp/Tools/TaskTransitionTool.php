<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use RobotCouncil\Access\Ability;
use RobotCouncil\Http\Rules\BoundedMeta;
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Mcp\Arguments;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\BranchName;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\TaskList;
use RobotCouncil\Support\Tasks;

/**
 * One task transition, as a tool.
 *
 * Eight instances of this rather than eight classes: what separates a claim from a cancel is the
 * statuses it starts from, the ability it needs, and whether it takes the claim -- and all of that
 * is already on `Models\TaskTransition`, where the conditional update reads it too.
 */
final class TaskTransitionTool extends Tool
{
    use ActsAsAgent;

    /**
     * @param  TaskTransition  $transition  The transition this instance performs.
     */
    public function __construct(private readonly TaskTransition $transition) {}

    /**
     * The tool's name, which an agent calls it by.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return sprintf('task_%s', $this->transition->value);
    }

    /**
     * What the tool does, as an agent reads it.
     *
     * @return string The description.
     */
    public function description(): string
    {
        $from = implode(', ', array_map(
            static fn (TaskStatus $status): string => $status->value,
            $this->transition->startsFrom()
        ));

        return sprintf(
            'Move a task to `%s`. Works only from %s, and answers a conflict from anywhere else -- '
                .'including when another agent got there first. Needs `%s`.%s%s',
            $this->transition->to()->value,
            $from,
            $this->transition->ability()->value,
            $this->transition->needsTheClaim()
                ? ' Only the session holding the task may do this.'
                : '',
            $this->transition === TaskTransition::Reassign
                ? ' Pass `expect: "pending"` when placing unclaimed work, so a lane that claimed it first keeps it.'
                : ''
        );
    }

    /**
     * The arguments the tool takes.
     *
     * @param  JsonSchema  $schema  The schema factory.
     * @return array<string, mixed> The argument schema.
     */
    public function schema(JsonSchema $schema): array
    {
        $arguments = [
            'task_id' => $schema->integer()->description('The task to move.')->required(),
        ];

        if ($this->transition === TaskTransition::Reassign) {
            $arguments['session_id'] = $schema->integer()
                ->description("The agent session to hand the task to. It must not have gone, and the task must be its developer's or one a coordinator filed.")
                ->required();
        }

        if ($this->transition->takesADirective()) {
            $arguments['directive'] = $schema->string()
                ->max(FleetEvent::MAX_BODY)
                ->description('What to tell the session you are handing the task to. Written to the fleet in the same step as the handover, so the session is never holding work nobody told it about.')
                ->required();
            $arguments['expect'] = $schema->string()
                ->enum(TaskStatus::values($this->transition->startsFrom()))
                ->description('The status you read the task in. Set `pending` when placing unclaimed work, so that if a lane claimed it meanwhile nothing is taken from that lane and you get a conflict to re-read instead.');
            $arguments['hand_back'] = $schema->boolean()
                ->description("True when this returns a gate's pull request to the lane that made it, rather than placing new work.");
        }

        if ($this->transition->takesABranch()) {
            $arguments['branch'] = $schema->string()
                ->max(BranchName::MAX)
                ->description('The git branch you are working on for this task, as [A-Za-z0-9._/-], if it already exists. Usually it does not yet: omit it here and call `task_branch` once you have created the branch.');
        }

        if ($this->transition->takesAResult()) {
            $arguments['result'] = $schema->object()
                ->description('What you are reporting about the finished task. Bounded in size.');
        }

        return $arguments;
    }

    /**
     * Attempt the transition.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  Tasks  $tasks  The task store.
     * @return Response|ResponseFactory The outcome.
     */
    public function handle(Request $request, HttpRequest $http, Tasks $tasks): Response|ResponseFactory
    {
        $session = $this->session($http);
        $coordinator = $this->allows($http, Ability::CoordinatorDirect);

        // The same two ways in the REST controller allows: the transition's own ability, or the
        // coordinator's where it is an alternative
        $permitted = $this->allows($http, $this->transition->ability())
            || ($this->transition->coordinatorMayOverride() && $coordinator);

        if (! $permitted) {
            return $this->refuse($this->transition->ability());
        }

        // The schema advertises these; nothing enforces it, so the tool does. Without this an
        // absent or non-numeric `task_id` reaches `Arguments::integer()`, throws, and comes back
        // to the agent as `An internal server error occurred.` with an exception in the host's log.
        $request->validate([
            'task_id' => ['required', 'integer', 'min:1'],
            'session_id' => $this->transition === TaskTransition::Reassign
                ? ['required', 'integer', 'min:1']
                : ['prohibited'],
            'result' => $this->transition->takesAResult()
                ? ['sometimes', 'nullable', 'array', new BoundedMeta]
                : ['prohibited'],
            'directive' => $this->transition->takesADirective()
                ? ['required', 'string', 'max:'.FleetEvent::MAX_BODY]
                : ['prohibited'],
            'hand_back' => $this->transition->takesADirective()
                ? ['sometimes', 'boolean']
                : ['prohibited'],
            'expect' => $this->transition === TaskTransition::Reassign
                ? ['sometimes', 'nullable', 'string', Rule::in(TaskStatus::values($this->transition->startsFrom()))]
                : ['prohibited'],
            'branch' => $this->transition->takesABranch()
                ? ['sometimes', 'nullable', 'string', 'max:'.BranchName::MAX, 'regex:'.BranchName::PATTERN]
                : ['prohibited'],
        ]);

        $assignee = null;

        if ($this->transition === TaskTransition::Reassign) {
            $assignee = AgentSession::query()->whereKey(Arguments::integer($request->get('session_id')))->first();

            if (! TaskList::canBeAssigned($assignee)) {
                return Response::error('That session cannot be handed a task. It has gone, or it never existed.');
            }
        }

        $result = $request->get('result');
        $taskId = Arguments::integer($request->get('task_id'));

        $directive = $request->get('directive');
        $branch = $request->get('branch');

        $outcome = $tasks->transition(
            $taskId,
            $this->transition,
            $session,
            $coordinator,
            $assignee,
            Arguments::structure($result),
            $this->transition->takesADirective() ? Arguments::string($directive) : null,
            // Every true form the `boolean` rule admits -- `true`, `1` and `'1'` -- not only a JSON
            // `true`: an agent sending `1` passed validation, and reading that as false would
            // quietly place new work where a hand-back was meant
            $this->transition->takesADirective()
                && \in_array($request->get('hand_back'), [true, 1, '1'], true),
            // `trim()` as the HTTP path's `filled()` does: a host that removed the framework's
            // `TrimStrings` would otherwise hand the store a blank branch, which it refuses with an
            // exception the agent reads as an internal error
            \is_string($branch) && trim($branch) !== '' ? $branch : null,
            \is_string($request->get('expect')) ? TaskStatus::tryFrom($request->get('expect')) : null
        );

        // A refusal is an error, not a result. A client cannot tell a result that describes a
        // failure from one that describes success, so anything the service refused has to arrive
        // marked as an error or the model reads it as having worked.
        if ($outcome !== Outcome::Applied) {
            return Response::error($this->explain($outcome));
        }

        return Response::structured([
            'task_id' => $taskId,
            'status' => $this->transition->to()->value,
            'applied' => true,
        ]);
    }

    /**
     * Why a transition did not happen, in words an agent can act on.
     *
     * @param  Outcome  $outcome  What came of it.
     * @return string The reason.
     */
    private function explain(Outcome $outcome): string
    {
        return match ($outcome) {
            Outcome::NotFound => 'No task with that id.',
            Outcome::Conflict => sprintf(
                'That task is not in a status `%s` starts from. Read it again before retrying: '
                    .'somebody else may have moved it.',
                $this->transition->value
            ),
            // For a reassignment the write tested the assignee, not the caller, so saying "this
            // session may not" would send a coordinator looking at its own abilities for no reason
            Outcome::Forbidden => $this->transition === TaskTransition::Reassign
                ? 'That session could not have claimed this task itself, so it cannot be handed it. The task belongs to another developer and was not filed by a coordinator.'
                : 'This session may not do that to that task.',
            Outcome::Applied => 'Applied.',
        };
    }
}
