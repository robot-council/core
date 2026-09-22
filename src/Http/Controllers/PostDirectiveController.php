<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Http\Rules\BoundedMeta;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\DirectiveTargets;
use RobotCouncil\Support\FleetEvents;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issues an instruction to the whole fleet.
 *
 * A directive reaches every agent regardless of whose developer posted it, which is exactly why it
 * needs `coordinator:direct` and why that ability is the one thing enrollment can never request.
 * The route's middleware is what enforces it.
 */
final class PostDirectiveController
{
    /**
     * Record one directive.
     *
     * @param  Request  $request  The incoming request.
     * @param  FleetEvents  $events  The change feed.
     * @return JsonResponse The recorded event's ID and type.
     */
    public function __invoke(Request $request, FleetEvents $events): JsonResponse
    {
        $request->validate([
            'body' => ['required', 'string', 'max:'.FleetEvent::MAX_BODY],
            'meta' => ['sometimes', 'array', new BoundedMeta],
            'targets' => ['sometimes', 'array', 'max:'.DirectiveTargets::MAX],
            'targets.*' => ['integer', 'min:1'],
        ]);

        $meta = $request->input('meta');

        // Resolved before the event is recorded, so a directive naming an unknown or departed
        // session writes nothing at all rather than reaching the fleet with a target list the
        // server could not stand behind.
        $targets = DirectiveTargets::resolve($request->input('targets'));

        $event = $events->record(
            FleetEventType::Directive,
            Principal::agentSession($request),
            $request->string('body')->value(),
            array_filter([
                'client' => \is_array($meta) && $meta !== [] ? $meta : null,
                'targets' => $targets === [] ? null : $targets,
            ], static fn (mixed $value): bool => $value !== null),

            // True by construction: the route admits nobody without the ability
            withCoordinator: true
        );

        return new JsonResponse([
            'event_id' => $event->id,
            'type' => $event->type->value,
        ], Response::HTTP_CREATED);
    }
}
