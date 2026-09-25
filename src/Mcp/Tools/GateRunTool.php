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
use RobotCouncil\Support\GateRuns;
use RobotCouncil\Support\IssueReference;
use RobotCouncil\Support\Outcome;

/**
 * A gate reports the pull request it is validating, or that it has finished, as a tool (#336).
 */
final class GateRunTool extends Tool
{
    use ActsAsAgent;

    /**
     * @param  bool  $clears  True for the instance that ends the run.
     */
    public function __construct(private readonly bool $clears = false) {}

    /**
     * The tool's name, which an agent calls it by.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return $this->clears ? 'gate_finish' : 'gate_start';
    }

    /**
     * What the tool does, as an agent reads it.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return $this->clears
            ? 'Say this gate has finished validating its pull request. A pull request that closes or merges ends the run on its own. Only a session in the `ci` role is a gate.'
            : 'Say this gate has started validating a pull request, named as owner/name#N. The lane board marks it running. Only a session in the `ci` role is a gate.';
    }

    /**
     * The arguments the tool takes.
     *
     * @param  JsonSchema  $schema  The schema factory.
     * @return array<string, mixed> The argument schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->clears ? [] : [
            'pull_request' => $schema->string()->max(IssueReference::MAX)->description('The pull request, as owner/name#N.')->required(),
        ];
    }

    /**
     * Record or end the run.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  GateRuns  $runs  The store.
     * @return Response|ResponseFactory The outcome.
     */
    public function handle(Request $request, HttpRequest $http, GateRuns $runs): Response|ResponseFactory
    {
        if (! $this->allows($http, Ability::TasksClaim)) {
            return $this->refuse(Ability::TasksClaim);
        }

        if ($this->clears) {
            return Response::structured(['cleared' => $runs->clear($this->session($http))]);
        }

        $request->validate([
            'pull_request' => ['required', 'string', 'max:'.IssueReference::MAX, 'regex:'.IssueReference::PATTERN],
        ]);

        $reference = Arguments::string($request->get('pull_request'));

        if ($runs->start($this->session($http), $reference) !== Outcome::Applied) {
            return Response::error('Only a gate -- a session in the `ci` role -- reports a pull request it is validating.');
        }

        return Response::structured(['pull_request' => $reference, 'applied' => true]);
    }
}
