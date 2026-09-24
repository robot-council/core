<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Http\Rules\BoundedMeta;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\NarrationAddressees;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records what an agent says about its own work.
 *
 * The type is never taken from the request. Narration and a state change are different kinds of
 * claim -- one is an agent's account of itself, the other is something the service observed -- and
 * an agent that could choose between them could dress its own opinion as fleet state.
 */
final class PostNarrationController
{
    /**
     * Record one piece of narration.
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
            'to' => ['sometimes', 'array', 'max:'.NarrationAddressees::MAX],
            'to.*' => ['integer', 'min:1'],
            'to_tasks' => ['sometimes', 'array', 'max:'.NarrationAddressees::MAX],
            'to_tasks.*' => ['integer', 'min:1'],
        ]);

        $session = Principal::agentSession($request);

        $meta = $request->input('meta');

        // Read from the token as it posts, and stored on the event. Revoking the ability later
        // must not hide what was said while it was held.
        $asCoordinator = Tokens::allows($session->currentAccessToken(), Ability::CoordinatorDirect);

        // Resolved before the event is recorded, so a narration naming anything unreachable writes
        // nothing at all
        $addressees = NarrationAddressees::resolve(
            $request->input('to'),
            $request->input('to_tasks'),
            $session,
            $asCoordinator
        );

        $event = $events->record(
            // Always narration, whatever a `type` field in the request said
            FleetEventType::Narration,
            $session,
            $request->string('body')->value(),

            // What the client sent is kept apart under `client`, so nothing a caller sends can be
            // mistaken later for something the server derived
            NarrationAddressees::meta(\is_array($meta) ? $meta : null, $addressees),
            $asCoordinator,
            addressees: $addressees['sessions']
        );

        return new JsonResponse([
            'event_id' => $event->id,
            'type' => $event->type->value,
        ], Response::HTTP_CREATED);
    }
}
