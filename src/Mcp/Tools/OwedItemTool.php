<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use RobotCouncil\Access\Ability;
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Mcp\Arguments;
use RobotCouncil\Support\IssueReference;
use RobotCouncil\Support\OwedItems;

/**
 * Record, or settle, what the fleet is waiting on a developer for, as a tool (#335).
 */
final class OwedItemTool extends Tool
{
    use ActsAsAgent;

    /**
     * @param  bool  $settles  True for the instance that settles an item.
     */
    public function __construct(private readonly bool $settles = false) {}

    /**
     * The tool's name, which an agent calls it by.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return $this->settles ? 'owed_settle' : 'owed_record';
    }

    /**
     * What the tool does, as an agent reads it.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return $this->settles
            ? 'Settle an item the fleet was waiting on a developer for. An item settles on its own when its ticket closes or loses its `hitl` label. Needs `coordinator:direct`.'
            : 'Record something the fleet is waiting on a developer for, shown on the lane board: the ticket it is about as owner/name#N, the question, and why it matters. Name the developer by GitHub login, or omit it for something nobody in particular owes. Needs `coordinator:direct`.';
    }

    /**
     * The arguments the tool takes.
     *
     * @param  JsonSchema  $schema  The schema factory.
     * @return array<string, mixed> The argument schema.
     */
    public function schema(JsonSchema $schema): array
    {
        if ($this->settles) {
            return ['item_id' => $schema->integer()->description('The item to settle.')->required()];
        }

        return [
            'developer' => $schema->string()->max(39)->description('The developer, by GitHub login. Omit for General.'),
            'ticket' => $schema->string()->max(IssueReference::MAX)->description('The ticket, as owner/name#N.')->required(),
            'question' => $schema->string()->max(OwedItems::MAX_TEXT)->description('What the developer is asked.')->required(),
            'why' => $schema->string()->max(OwedItems::MAX_TEXT)->description('Why it matters.')->required(),
        ];
    }

    /**
     * Record or settle the item.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  OwedItems  $owed  The store.
     * @return Response|ResponseFactory The outcome.
     */
    public function handle(Request $request, HttpRequest $http, OwedItems $owed): Response|ResponseFactory
    {
        if (! $this->allows($http, Ability::CoordinatorDirect)) {
            return $this->refuse(Ability::CoordinatorDirect);
        }

        if ($this->settles) {
            $request->validate(['item_id' => ['required', 'integer', 'min:1']]);

            return Response::structured(['settled' => $owed->settle(Arguments::integer($request->get('item_id')))]);
        }

        $request->validate([
            'developer' => ['sometimes', 'nullable', 'string', 'max:39'],
            'ticket' => ['required', 'string', 'max:'.IssueReference::MAX],
            'question' => ['required', 'string', 'max:'.OwedItems::MAX_TEXT],
            'why' => ['required', 'string', 'max:'.OwedItems::MAX_TEXT],
        ]);

        $developer = $request->get('developer');

        try {
            $id = $owed->record(
                $this->session($http),
                \is_string($developer) && $developer !== '' ? $developer : null,
                Arguments::string($request->get('ticket')),
                Arguments::string($request->get('question')),
                Arguments::string($request->get('why'))
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return Response::error($invalidArgumentException->getMessage());
        }

        return Response::structured(['id' => $id]);
    }
}
