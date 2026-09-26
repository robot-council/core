<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Http\Rules\BoundedMeta;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\PlacementRule;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\BranchName;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\PlacementRefused;
use RobotCouncil\Support\PlacementRules;
use RobotCouncil\Support\SubLabel;
use RobotCouncil\Support\TaskList;
use RobotCouncil\Support\Tasks;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Moves a task from one state to another.
 *
 * One endpoint for all eight transitions, because the rules that separate them are the same rules
 * the write enforces, and they live on `TaskTransition`. Splitting them across eight controllers
 * would put the ability each needs in a middleware declaration and the statuses each may start from
 * in a query, where the two could drift apart -- and the route's regex is built from the enum, so a
 * transition that does not exist is a 404 from the router.
 *
 * The ability check is here rather than in `RequireAbility` for the one transition that has two
 * ways in: a release is allowed to the session holding the task, *or* to a coordinator, and no
 * single ability names that.
 */
final class TransitionTaskController
{
    /**
     * Attempt the transition.
     *
     * @param  Request  $request  The incoming request.
     * @param  string  $task  The task's ID, from the route.
     * @param  string  $transition  The transition's name, from the route.
     * @param  Tasks  $tasks  The task store.
     * @return JsonResponse What came of it.
     *
     * @throws AccessDeniedHttpException When the session holds neither way in.
     */
    public function __invoke(Request $request, string $task, string $transition, Tasks $tasks, PlacementRules $rules): JsonResponse
    {
        // The router's constraint already refused anything else, so this cannot be null in a
        // request that reached here -- but a route registered by hand elsewhere could
        $move = TaskTransition::tryFrom($transition);

        if (! $move instanceof TaskTransition) {
            throw new AccessDeniedHttpException;
        }

        $session = Principal::agentSession($request);
        $token = $session->currentAccessToken();

        $asCoordinator = Tokens::allows($token, Ability::CoordinatorDirect);

        // A coordinator's own transitions need the coordinator's ability; a claimant's need
        // `tasks:claim`, unless this is the one a coordinator may also do
        $permitted = Tokens::allows($token, $move->ability())
            || ($move->coordinatorMayOverride() && $asCoordinator);

        if (! $permitted) {
            throw new AccessDeniedHttpException;
        }

        $assignee = $move === TaskTransition::Reassign ? $this->assignee($request) : null;

        try {
            $outcome = $tasks->transition(
                (int) $task,
                $move,
                $session,
                $asCoordinator,
                $assignee,
                $this->result($request, $move),
                $this->directive($request, $move),
                $move === TaskTransition::Reassign && $request->boolean('hand_back'),
                $this->branch($request, $move),
                $this->expect($request, $move),
                $this->subLabel($request, $move)
            );
        } catch (PlacementRefused $placementRefused) {
            // Every rule the placement broke, not only the first, so a coordinator can fix them all
            return new JsonResponse([
                'task_id' => (int) $task,
                'status' => null,
                'applied' => false,
                'refused' => array_map(static fn (PlacementRule $rule): array => ['rule' => $rule->value, 'reason' => $rule->reads()], $placementRefused->rules),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $body = [
            'task_id' => (int) $task,
            'status' => \in_array($outcome, [Outcome::Applied, Outcome::Added], true) ? $move->to()->value : null,
            'applied' => $outcome === Outcome::Applied,
        ];

        // #433: GitHub finished the task first, and the result was added to it rather than refused.
        // Only a completion reaches this, so `to()` above is the `done` GitHub left it in.
        if ($outcome === Outcome::Added) {
            $body['result_added'] = true;
        }

        // Soft invariants (#320) do not block; they say why a coordinator might think again
        if ($move === TaskTransition::Reassign && $outcome === Outcome::Applied) {
            $body['warnings'] = $this->warnings((int) $task, $rules);
        }

        return new JsonResponse($body, $outcome->status());
    }

    /**
     * The soft invariants a placement was warned about.
     *
     * @param  int  $taskId  The task.
     * @param  PlacementRules  $rules  The rules.
     * @return list<string> The warnings.
     */
    private function warnings(int $taskId, PlacementRules $rules): array
    {
        $placed = Task::query()->find($taskId);

        return $placed instanceof Task ? $rules->warnings($placed) : [];
    }

    /**
     * What the agent reports about a task it has finished.
     *
     * @param  Request  $request  The incoming request.
     * @param  TaskTransition  $move  The transition being attempted.
     * @return array<array-key, mixed>|null The result, where this transition records one.
     */
    private function result(Request $request, TaskTransition $move): ?array
    {
        if (! $move->takesAResult()) {
            return null;
        }

        $request->validate([
            'result' => ['sometimes', 'nullable', 'array', new BoundedMeta],
        ]);

        $result = $request->input('result');

        return \is_array($result) && $result !== [] ? $result : null;
    }

    /**
     * What a reassignment tells the session it hands the task to.
     *
     * Required, and bounded like any event body: #316 makes a placement and the telling of the lane
     * one write, so a reassignment without one is refused at the edge rather than stored. Since #331
     * these words travel to the lane alone as a `placement.instruction`; the broadcast directive
     * carries only what the package composes.
     *
     * @param  Request  $request  The incoming request.
     * @param  TaskTransition  $move  The transition being attempted.
     * @return string|null The directive, where this transition carries one.
     */
    private function directive(Request $request, TaskTransition $move): ?string
    {
        if (! $move->takesADirective()) {
            return null;
        }

        $request->validate([
            'directive' => ['required', 'string', 'max:'.FleetEvent::MAX_BODY],
            'hand_back' => ['sometimes', 'boolean'],
        ]);

        return $request->string('directive')->value();
    }

    /**
     * The status a placement insists the task is still in (#328).
     *
     * @param  Request  $request  The incoming request.
     * @param  TaskTransition  $move  The transition being attempted.
     * @return TaskStatus|null The status, when this is a reassignment that named one.
     */
    private function expect(Request $request, TaskTransition $move): ?TaskStatus
    {
        $request->validate([
            'expect' => $move === TaskTransition::Reassign
                ? ['sometimes', 'nullable', 'string', Rule::in(TaskStatus::values($move->startsFrom()))]
                : ['prohibited'],
        ]);

        return $request->filled('expect') ? TaskStatus::tryFrom($request->string('expect')->value()) : null;
    }

    /**
     * The branch a start reports.
     *
     * @param  Request  $request  The incoming request.
     * @param  TaskTransition  $move  The transition being attempted.
     * @return string|null The branch, where this transition may carry one and one was named.
     */
    private function branch(Request $request, TaskTransition $move): ?string
    {
        if (! $move->takesABranch()) {
            return null;
        }

        $request->validate([
            'branch' => ['sometimes', 'nullable', 'string', 'max:'.BranchName::MAX, 'regex:'.BranchName::PATTERN],
        ]);

        return $request->filled('branch') ? $request->string('branch')->value() : null;
    }

    /**
     * Which of the lane's subagents a start says took the task up (#409).
     *
     * @param  Request  $request  The incoming request.
     * @param  TaskTransition  $move  The transition being attempted.
     * @return string|null The sub-label, where this transition may carry one and one was named.
     */
    private function subLabel(Request $request, TaskTransition $move): ?string
    {
        if (! $move->takesABranch()) {
            return null;
        }

        $request->validate([
            'sub_label' => ['sometimes', 'nullable', 'string', 'max:'.SubLabel::MAX, 'regex:'.SubLabel::PATTERN],
        ]);

        return $request->filled('sub_label') ? $request->string('sub_label')->value() : null;
    }

    /**
     * The session a reassignment hands the task to.
     *
     * A session that has gone, or one that never existed, is a 422 rather than a 404: the request
     * is well formed and names something real-looking, and what is wrong is the value rather than
     * the route.
     *
     * @param  Request  $request  The incoming request.
     * @return AgentSession The session to hand the task to.
     *
     * @throws ValidationException When no session that can be worked carries that ID.
     */
    private function assignee(Request $request): AgentSession
    {
        $request->validate([
            'session_id' => ['required', 'integer', 'min:1'],

            // Accepted so a coordinator can say why, and bounded like every other structure a
            // client may attach
            'meta' => ['sometimes', 'array', new BoundedMeta],
        ]);

        $session = AgentSession::query()->whereKey($request->integer('session_id'))->first();

        if (! TaskList::canBeAssigned($session) || ! $session instanceof AgentSession) {
            throw ValidationException::withMessages([
                'session_id' => 'The session_id field must name a session that can still be worked.',
            ]);
        }

        return $session;
    }
}
