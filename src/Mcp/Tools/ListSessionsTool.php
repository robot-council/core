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
use RobotCouncil\Access\Role;
use RobotCouncil\Http\Controllers\ListSessionsController;
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Mcp\Arguments;
use RobotCouncil\Support\LiveSessions;

/**
 * The sessions in the fleet right now, as a tool (#325).
 *
 * Needs no ability, for the reason `ListSessionsController` gives.
 */
final class ListSessionsTool extends Tool
{
    use ActsAsAgent;

    /**
     * The tool's name.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'sessions_list';
    }

    /**
     * What the tool does.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return 'List the sessions in the fleet right now, newest first: every `active` and `stale` '
            .'session, never a `gone` one, with its developer, machine, role, repository, work '
            .'location, status, last contact, and the tasks it holds. Read from the session table, so '
            .'it is complete however far back the feed has been pruned. Page it with the `cursor` from '
            .'the previous call; it is null on the last page. Tasks you may not claim come back with '
            .'`readable` false and no title or description.';
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
            'repository' => $schema->string()->description('Only sessions working in this `owner/name` repository.'),
            'role' => $schema->string()
                ->enum(array_map(static fn (Role $role): string => $role->value, Role::cases()))
                ->description('Only sessions holding this role.'),
            'limit' => $schema->integer()->description(sprintf('How many to return, up to %d.', LiveSessions::MAX_PAGE)),
            'after' => $schema->integer()->description('The `cursor` from the previous call.'),
        ];
    }

    /**
     * Read the page.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  LiveSessions  $sessions  The reader.
     * @return ResponseFactory The page.
     */
    public function handle(Request $request, HttpRequest $http, LiveSessions $sessions): ResponseFactory
    {
        $request->validate(ListSessionsController::rules());

        $repository = $request->get('repository');
        $role = $request->get('role');
        $limit = $request->get('limit');
        $after = $request->get('after');

        return Response::structured($sessions->page(
            $this->session($http),
            $this->allows($http, Ability::CoordinatorDirect),
            // An empty string passes every non-implicit rule, and the endpoint reads it as absent
            // (`ConvertEmptyStringsToNull`), so the tool does the same
            \is_string($repository) && $repository !== '' ? $repository : null,
            \is_string($role) ? Role::tryFrom($role) : null,
            $limit === null ? LiveSessions::MAX_PAGE : Arguments::integer($limit),
            $after === null ? null : Arguments::integer($after)
        ));
    }
}
