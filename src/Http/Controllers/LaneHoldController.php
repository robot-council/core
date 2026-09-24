<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Support\IssueReference;
use RobotCouncil\Support\LaneHolds;
use RobotCouncil\Support\Outcome;

/**
 * A coordinator records or lifts why a lane is idle on purpose (#334).
 *
 * Behind `coordinator:direct`. The party and reason are checked by `Support\LaneHolds`, which
 * refuses a note, a bare `#N` and an unknown developer; the refusal is returned as a validation
 * error on `party` so a client reads it the way it reads every other.
 */
final class LaneHoldController
{
    /**
     * Record a hold.
     *
     * @param  Request  $request  The incoming request.
     * @param  string  $session  The lane's session, from the route.
     * @param  LaneHolds  $holds  The store.
     * @return JsonResponse What came of it.
     *
     * @throws ValidationException When the party or reason is refused.
     */
    public function store(Request $request, string $session, LaneHolds $holds): JsonResponse
    {
        $request->validate([
            'party' => ['required', 'string', 'max:'.IssueReference::MAX],
            'reason' => ['required', 'string', Rule::in(HoldReason::values())],
        ]);

        $reason = HoldReason::from($request->string('reason')->value());

        try {
            $outcome = $holds->hold(Principal::agentSession($request), (int) $session, $request->string('party')->value(), $reason);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw ValidationException::withMessages(['party' => $invalidArgumentException->getMessage()]);
        }

        $hold = $outcome === Outcome::Applied ? $holds->of((int) $session) : null;

        return new JsonResponse([
            'session_id' => (int) $session,
            'applied' => $outcome === Outcome::Applied,
            'on_what' => $hold?->reads(),
        ], $outcome->status());
    }

    /**
     * Lift a hold.
     *
     * @param  string  $session  The lane's session, from the route.
     * @param  LaneHolds  $holds  The store.
     * @return JsonResponse Whether there was one.
     */
    public function destroy(string $session, LaneHolds $holds): JsonResponse
    {
        return new JsonResponse(['session_id' => (int) $session, 'cleared' => $holds->clear((int) $session)]);
    }
}
