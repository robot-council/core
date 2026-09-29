<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use RobotCouncil\Access\Ability;
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Mcp\Arguments;
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Support\IssueReference;
use RobotCouncil\Support\LaneHolds;
use RobotCouncil\Support\Outcome;

/**
 * Record, or lift, why a lane is idle on purpose, as a tool (#334).
 *
 * Two instances rather than two classes, the way the task transitions are one class: the only
 * difference is whether it writes a hold or deletes one.
 */
final class LaneHoldTool extends Tool
{
    use ActsAsAgent;

    /**
     * @param  bool  $lifts  True for the instance that lifts a hold.
     */
    public function __construct(private readonly bool $lifts = false) {}

    /**
     * The tool's name, which an agent calls it by.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return $this->lifts ? 'lane_clear_hold' : 'lane_hold';
    }

    /**
     * What the tool does, as an agent reads it.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return $this->lifts
            ? 'Lift the hold on a lane, so the board shows it as idle rather than waiting on something. Placing work on a lane lifts its hold on its own. Needs `coordinator:direct`.'
            : 'Record why a lane is idle on purpose, shown on the lane board as `<party> — <what>`. The party is a developer by GitHub login, a ticket as owner/name#N, or the lane\'s own repository as owner/name; the reason is one of a fixed set, each for one kind of party. `nothing_startable` takes the lane\'s repository and shows the lane as idle rather than blocked. Free text is refused. A lane holding work cannot be held. Needs `coordinator:direct`.';
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
            'session_id' => $schema->integer()->description('The lane: the agent session to hold.')->required(),
        ];

        if (! $this->lifts) {
            $arguments['party'] = $schema->string()->max(IssueReference::MAX)
                ->description("A developer by GitHub login, a ticket as owner/name#N, or the lane's own repository as owner/name.")
                ->required();
            $arguments['reason'] = $schema->string()->enum(HoldReason::values())
                ->description(implode('; ', array_map(
                    static fn (HoldReason $reason): string => sprintf('`%s` (%s): %s', $reason->value, $reason->party()->value, $reason->reads()),
                    HoldReason::cases()
                )).'.')
                ->required();
        }

        return $arguments;
    }

    /**
     * Record or lift the hold.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  LaneHolds  $holds  The store.
     * @return Response|ResponseFactory The outcome.
     */
    public function handle(Request $request, HttpRequest $http, LaneHolds $holds): Response|ResponseFactory
    {
        if (! $this->allows($http, Ability::CoordinatorDirect)) {
            return $this->refuse(Ability::CoordinatorDirect);
        }

        $request->validate([
            'session_id' => ['required', 'integer', 'min:1'],
            'party' => $this->lifts ? ['prohibited'] : ['required', 'string', 'max:'.IssueReference::MAX],
            'reason' => $this->lifts ? ['prohibited'] : ['required', 'string', Rule::in(HoldReason::values())],
        ]);

        $laneId = Arguments::integer($request->get('session_id'));

        if ($this->lifts) {
            return Response::structured(['session_id' => $laneId, 'cleared' => $holds->clear($laneId)]);
        }

        try {
            $outcome = $holds->hold(
                $this->session($http),
                $laneId,
                Arguments::string($request->get('party')),
                HoldReason::from(Arguments::string($request->get('reason')))
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return Response::error($invalidArgumentException->getMessage());
        }

        if ($outcome !== Outcome::Applied) {
            return Response::error(match ($outcome) {
                Outcome::NotFound => 'No lane with that session id.',
                default => 'That lane has gone, or holds work. A lane with a task is not idle, so it cannot be held.',
            });
        }

        return Response::structured(['session_id' => $laneId, 'applied' => true, 'on_what' => $holds->of($laneId)?->reads()]);
    }
}
