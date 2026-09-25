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
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Mcp\Arguments;
use RobotCouncil\Support\Backlog;
use RobotCouncil\Support\WorkIdentity;

/**
 * Report a repository's open-issue count, as a tool (#339).
 */
final class BacklogReportTool extends Tool
{
    use ActsAsAgent;

    /**
     * The tool's name, which an agent calls it by.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'backlog_report';
    }

    /**
     * What the tool does, as an agent reads it.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return "Report how many issues a repository has open, pull requests excluded, as you counted them now. The lane board's backlog meter shows the latest count and its age, and a count older than an hour reads as unreadable. Needs `events:post`.";
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
            'repository' => $schema->string()->max(WorkIdentity::MAX_REPOSITORY)->description('The repository, as owner/name.')->required(),
            'open_issues' => $schema->integer()->min(0)->max(Backlog::MAX_COUNT)->description('Its open issues, pull requests excluded.')->required(),
        ];
    }

    /**
     * Record the count.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  Backlog  $backlog  The backlog store.
     * @return Response|ResponseFactory The outcome.
     */
    public function handle(Request $request, HttpRequest $http, Backlog $backlog): Response|ResponseFactory
    {
        if (! $this->allows($http, Ability::EventsPost)) {
            return $this->refuse(Ability::EventsPost);
        }

        $request->validate([
            'repository' => ['required', 'string', 'max:'.WorkIdentity::MAX_REPOSITORY, 'regex:'.WorkIdentity::REPOSITORY],
            'open_issues' => ['required', 'integer', 'min:0', 'max:'.Backlog::MAX_COUNT],
        ]);

        $repository = Arguments::string($request->get('repository'));
        $count = Arguments::integer($request->get('open_issues'));

        $backlog->report($this->session($http), $repository, $count);

        return Response::structured(['repository' => $repository, 'open_issues' => $count]);
    }
}
