<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\FleetFeed;

/**
 * Serves the change feed to one agent session, from an ID cursor.
 *
 * Paging by ID rather than by time is what makes this safe to read from a process that may sleep,
 * crash, and come back: the cursor is exact, and the recording helper guarantees events commit in
 * the order their IDs imply, so nothing can appear behind a cursor already passed.
 */
final class FleetFeedController
{
    /**
     * Read the events after a cursor.
     *
     * @param  Request  $request  The incoming request.
     * @param  FleetFeed  $feed  The feed reader, which applies the visibility rule.
     * @return JsonResponse The events, and the cursor to ask from next.
     */
    public function __invoke(Request $request, FleetFeed $feed): JsonResponse
    {
        $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.FleetFeed::MAX_PAGE],
        ]);

        // `null` rather than `integer('after')`, which answers 0 for a missing argument and is
        // indistinguishable from a client asking for the whole history. Null means resume from
        // where this session last acknowledged (#86).
        $page = $feed->after(
            Principal::agentSession($request),
            $request->has('after') ? $request->integer('after') : null,
            $request->integer('limit', FleetFeed::MAX_PAGE)
        );

        // The cursor is how far the feed was examined, not the last row returned: a page may be
        // short or empty because of the visibility rule, and a reader still has to make progress
        return new JsonResponse([
            'events' => $page['events'],
            'cursor' => $page['cursor'],
        ]);
    }
}
