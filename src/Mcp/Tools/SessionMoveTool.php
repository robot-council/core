<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\WorkIdentity;

/**
 * Change where this session works without leaving, as a tool (#535).
 *
 * The same store call as `Http\Controllers\MoveSessionController`; `Support\AgentSessions::move()`
 * records what moves and what stays. It takes no session id, so it moves only the caller.
 */
final class SessionMoveTool extends Tool
{
    use ActsAsAgent;

    /**
     * The tool's name, which an agent calls it by.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'session_move';
    }

    /**
     * What the tool does, as an agent reads it.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return 'Change the work location or the repository this session reports, without leaving the fleet -- '
            .'for instance after moving to another checkout. Your session id, role, tasks and locks stay as they '
            .'are, and the fleet sees the new place at once. Pass `work_location` as a short label for the '
            .'checkout, such as `a`, `ci` or `primary` -- never a path -- and `repository` as `owner/name`. Send '
            .'either or both; one you leave out stays as it is. It moves only this session.';
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
            'work_location' => $schema->string()
                ->max(WorkIdentity::MAX_LOCATION)
                ->description('A short label for the checkout, as [a-z0-9._-]: `a`, `ci`, `primary`. Never a path. Required unless you send `repository`.'),
            'repository' => $schema->string()
                ->max(WorkIdentity::MAX_REPOSITORY)
                ->description('The repository, as `owner/name`. Required unless you send `work_location`.'),
        ];
    }

    /**
     * Move it.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  AgentSessions  $sessions  The session store.
     * @return Response|ResponseFactory The outcome.
     */
    public function handle(Request $request, HttpRequest $http, AgentSessions $sessions): Response|ResponseFactory
    {
        // The rules the join endpoint applies, so a value refused there is refused here with the
        // same message
        $request->validate([
            'repository' => ['required_without:work_location', 'string', 'max:'.WorkIdentity::MAX_REPOSITORY, 'regex:'.WorkIdentity::REPOSITORY],
            'work_location' => ['required_without:repository', 'string', 'max:'.WorkIdentity::MAX_LOCATION, 'regex:'.WorkIdentity::LOCATION],
        ]);

        $place = [];

        foreach (['repository', 'work_location'] as $field) {
            $value = $request->get($field);

            if (\is_string($value)) {
                $place[$field] = $value;
            }
        }

        $session = $this->session($http);
        $outcome = $sessions->move($session, $place);

        if ($outcome !== Outcome::Applied) {
            return Response::error(match ($outcome) {
                Outcome::NotFound, Outcome::Conflict => 'This session has ended. Join again to work somewhere else.',

                // `move()` answers neither
                Outcome::Forbidden, Outcome::Added => 'Applied.',
            });
        }

        $row = AgentSession::query()->whereKey($session->getKey())->first(['repository', 'work_location']);

        return Response::structured([
            'session_id' => $session->id,
            'repository' => $row?->repository,
            'work_location' => $row?->work_location,
            'applied' => true,
        ]);
    }
}
