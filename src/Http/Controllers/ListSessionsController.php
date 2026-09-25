<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\LiveSessions;
use RobotCouncil\Support\WorkIdentity;

/**
 * The sessions in the fleet right now, for an agent (#325).
 *
 * Needs no ability: a session's existence and state reach every agent, as presence events already
 * do. What a held task says goes through `TaskList`'s readability rule inside `LiveSessions`.
 */
final class ListSessionsController
{
    /**
     * Read a page of live sessions.
     *
     * @param  Request  $request  The incoming request.
     * @param  LiveSessions  $sessions  The reader.
     * @return JsonResponse The sessions, and the cursor to ask from next.
     */
    public function __invoke(Request $request, LiveSessions $sessions): JsonResponse
    {
        $request->validate(self::rules());

        $session = Principal::agentSession($request);

        return new JsonResponse($sessions->page(
            $session,
            Tokens::allows($session->currentAccessToken(), Ability::CoordinatorDirect),
            $request->filled('repository') ? $request->string('repository')->value() : null,
            $request->filled('role') ? Role::tryFrom($request->string('role')->value()) : null,
            $request->integer('limit', LiveSessions::MAX_PAGE),
            $request->filled('after') ? $request->integer('after') : null
        ));
    }

    /**
     * The arguments a read takes, shared with the tool so the two cannot drift.
     *
     * @return array<string, list<mixed>> The rules.
     */
    public static function rules(): array
    {
        return [
            // The same bound and charset the session start takes, so a filter can name exactly
            // what a session can hold and nothing else
            'repository' => ['sometimes', 'nullable', 'string', 'max:'.WorkIdentity::MAX_REPOSITORY, 'regex:'.WorkIdentity::REPOSITORY],
            'role' => ['sometimes', 'nullable', 'string', Rule::enum(Role::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.LiveSessions::MAX_PAGE],
            'after' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
