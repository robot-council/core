<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use RobotCouncil\Access\Ability;
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Support\CoordinatorSettings;

/**
 * Every developer's hours and days off, and every seat's settings, read live (#440).
 *
 * What `GET {prefix}/api/developers/settings` returns, for a coordinator in a harness that reaches
 * the fleet only through these tools. **Read at the call, so a coordinator never works from a
 * remembered value**: on 2026-09-25 one kept reporting assignment hours an operator had removed
 * three quarters of an hour earlier, because it had no way to read them.
 */
final class DeveloperSettingsTool extends Tool
{
    use ActsAsAgent;

    /**
     * The tool's name.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'developer_settings';
    }

    /**
     * What the tool does.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return "Read every developer's assignment hours and days off, and every seat's parked, exempt "
            .'and ticket-cap settings, as they are right now. Needs `coordinator:direct`. Each developer '
            .'and seat carries `inside_hours` -- true, false, or "ungated" when no hours apply -- which '
            .'is the answer the placement check would give at this moment, and `next_opens_at` when it '
            ."is false. Settings change on the developer's own page at any time: call this when you "
            .'decide, rather than relying on what you read earlier.';
    }

    /**
     * The arguments the tool takes: none.
     *
     * @param  JsonSchema  $schema  The schema factory.
     * @return array<string, mixed> The argument schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Read them.
     *
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  CoordinatorSettings  $settings  The description the endpoint returns too.
     * @return Response|ResponseFactory The settings.
     */
    public function handle(HttpRequest $http, CoordinatorSettings $settings): Response|ResponseFactory
    {
        if (! $this->allows($http, Ability::CoordinatorDirect)) {
            return $this->refuse(Ability::CoordinatorDirect);
        }

        // The clock the placement check reads, as the endpoint does
        return Response::structured($settings->describe(Carbon::now()));
    }
}
