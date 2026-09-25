<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\IssueReference;
use RobotCouncil\Support\OwedItems;

/**
 * A coordinator records what the fleet is waiting on a developer for, or settles it (#335).
 *
 * Behind `coordinator:direct`. `Support\OwedItems` refuses a developer the fleet does not know and a
 * bare `#N`; the refusal is returned as a validation error so a client reads it like any other.
 */
final class OwedItemController
{
    /**
     * Record an item.
     *
     * @param  Request  $request  The incoming request.
     * @param  OwedItems  $owed  The store.
     * @return JsonResponse The item's id.
     *
     * @throws ValidationException When a value is refused.
     */
    public function store(Request $request, OwedItems $owed): JsonResponse
    {
        $request->validate([
            'developer' => ['sometimes', 'nullable', 'string', 'max:39'],
            'ticket' => ['required', 'string', 'max:'.IssueReference::MAX],
            'question' => ['required', 'string', 'max:'.OwedItems::MAX_TEXT],
            'why' => ['required', 'string', 'max:'.OwedItems::MAX_TEXT],
        ]);

        try {
            $id = $owed->record(
                Principal::agentSession($request),
                $request->filled('developer') ? $request->string('developer')->value() : null,
                $request->string('ticket')->value(),
                $request->string('question')->value(),
                $request->string('why')->value()
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw ValidationException::withMessages(['developer' => $invalidArgumentException->getMessage()]);
        }

        return new JsonResponse(['id' => $id], JsonResponse::HTTP_CREATED);
    }

    /**
     * Settle an item.
     *
     * @param  string  $item  The item's id, from the route.
     * @param  OwedItems  $owed  The store.
     * @return JsonResponse Whether it was open.
     */
    public function destroy(string $item, OwedItems $owed): JsonResponse
    {
        return new JsonResponse(['settled' => $owed->settle((int) $item)]);
    }
}
