<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\Backlog;
use RobotCouncil\Support\WorkIdentity;

/**
 * A session reports a repository's open-issue count (#339).
 *
 * Behind `events:post`: a count is something a session tells the fleet, like narration. It is kept
 * with who reported it, so a wrong number has an author.
 */
final class BacklogReadingController
{
    /**
     * Record the count.
     *
     * @param  Request  $request  The incoming request.
     * @param  Backlog  $backlog  The backlog store.
     * @return JsonResponse The recorded reading.
     */
    public function __invoke(Request $request, Backlog $backlog): JsonResponse
    {
        $request->validate([
            'repository' => ['required', 'string', 'max:'.WorkIdentity::MAX_REPOSITORY, 'regex:'.WorkIdentity::REPOSITORY],
            'open_issues' => ['required', 'integer', 'min:0', 'max:'.Backlog::MAX_COUNT],
        ]);

        $repository = $request->string('repository')->value();
        $count = $request->integer('open_issues');

        $backlog->report(Principal::agentSession($request), $repository, $count);

        return new JsonResponse(['repository' => $repository, 'open_issues' => $count], JsonResponse::HTTP_CREATED);
    }
}
