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
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\DirectiveTargets;
use RobotCouncil\Support\FleetEvents;

/**
 * Tells the fleet something, from a session that may direct other developers' agents.
 */
final class PostDirectiveTool extends Tool
{
    use ActsAsAgent;

    /**
     * The tool's name.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'directive_post';
    }

    /**
     * What the tool does.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return 'Tell the whole fleet something. Needs `coordinator:direct`, which enrollment can never '
            .'ask for and an admin grants afterwards. Every agent reads it, including other '
            ."developers' agents. Name `targets` to record which sessions are expected to act; it "
            .'changes who is expected to act, never who receives it.';
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
            'body' => $schema->string()->max(FleetEvent::MAX_BODY)->description('The instruction.')->required(),
            'meta' => $schema->object()->description('Structured detail. Bounded in size.'),
            'targets' => $schema->array()
                ->items($schema->integer()->min(1))
                ->max(DirectiveTargets::MAX)
                ->unique()
                ->description('Session ids expected to act. Optional; every agent still receives it.'),
        ];
    }

    /**
     * Record it.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  FleetEvents  $events  The change feed.
     * @return Response|ResponseFactory The recorded event.
     */
    public function handle(Request $request, HttpRequest $http, FleetEvents $events): Response|ResponseFactory
    {
        if (! $this->allows($http, Ability::CoordinatorDirect)) {
            return $this->refuse(Ability::CoordinatorDirect);
        }

        $request->validate([
            'body' => ['required', 'string', 'max:'.FleetEvent::MAX_BODY],
            'meta' => ['sometimes', 'array', new BoundedMeta],
            'targets' => ['sometimes', 'array', 'max:'.DirectiveTargets::MAX],
            'targets.*' => ['integer', 'min:1'],
        ]);

        $meta = Arguments::structure($request->get('meta'));

        // Resolved before the event is recorded, so a directive naming an unknown or departed
        // session writes nothing at all.
        $targets = DirectiveTargets::resolve($request->get('targets'));

        $event = $events->record(
            FleetEventType::Directive,
            $this->session($http),
            Arguments::string($request->get('body')),
            array_filter([
                'client' => $meta,
                'targets' => $targets === [] ? null : $targets,
            ], static fn (mixed $value): bool => $value !== null),
            true
        );

        return Response::structured(['event_id' => $event->id, 'type' => $event->type->value]);
    }
}
