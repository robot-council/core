<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RobotCouncil\Support\Shortlist;

/**
 * The tickets a coordinator could place, per repository, unranked (#321).
 *
 * Behind `coordinator:direct`: the shortlist is the coordinator's tool for choosing, and choosing is
 * the coordinator's alone.
 */
final class ShortlistController
{
    /**
     * List the placeable tickets.
     *
     * @param  Shortlist  $shortlist  The reader.
     * @return JsonResponse The tickets by repository.
     */
    public function __invoke(Shortlist $shortlist): JsonResponse
    {
        return new JsonResponse(['repositories' => $shortlist->read()]);
    }
}
