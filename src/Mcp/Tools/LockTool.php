<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use RobotCouncil\Access\Ability;
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Mcp\Arguments;
use RobotCouncil\Models\Lock;
use RobotCouncil\Models\LockAction;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\Outcome;

/**
 * One lock action, as a tool.
 *
 * Four instances of this rather than four classes, for the reason the task transitions are: the
 * ability each needs and whether it takes a lease already live on `Models\LockAction`.
 */
final class LockTool extends Tool
{
    use ActsAsAgent;

    /**
     * @param  LockAction  $action  The action this instance performs.
     */
    public function __construct(private readonly LockAction $action) {}

    /**
     * The tool's name.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'lock_'.str_replace('-', '_', $this->action->value);
    }

    /**
     * What the tool does.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return match ($this->action) {
            LockAction::Acquire => 'Take a named lock. Succeeds when the name is free or its lease has '
                .'lapsed, and conflicts when somebody holds it. **Carry the `fence` it returns into '
                .'whatever the lock guards, and have that thing refuse anything below the highest fence '
                .'it has seen** -- nothing here can stop you acting after your lease is gone, so the '
                .'fence is what makes the lock safe. Needs `locks:acquire`.',
            LockAction::Renew => 'Extend a lease you hold, from now. The fence does not change, because '
                .'this is the same hold continuing. There is a ceiling on how long one session may hold '
                .'one name, measured from when you first took it.',
            LockAction::Release => 'Give up a lease you hold, so somebody else can take the name.',
            LockAction::ForceRelease => 'Take a lock away from whoever holds it. Needs `coordinator:direct`. '
                .'The holder is not told; it finds out because its fence stops being the highest.',
        };
    }

    /**
     * The arguments the tool takes.
     *
     * @param  JsonSchema  $schema  The schema factory.
     * @return array<string, mixed> The argument schema.
     */
    public function schema(JsonSchema $schema): array
    {
        $arguments = [
            'name' => $schema->string()
                ->max(Lock::MAX_NAME)
                ->description('The name to lock, as `[A-Za-z0-9._:/-]`, such as `branch:feature/foo`.')
                ->required(),
        ];

        if ($this->action->takesATtl()) {
            $arguments['ttl'] = $schema->integer()
                ->description('How many seconds to hold it for, from now.')
                ->required();
        }

        return $arguments;
    }

    /**
     * Do the thing.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  Locks  $locks  The lock store.
     * @param  Credentials  $credentials  The configured bounds.
     * @return Response|ResponseFactory The outcome.
     */
    public function handle(
        Request $request,
        HttpRequest $http,
        Locks $locks,
        Credentials $credentials
    ): Response|ResponseFactory {
        if (! $this->allows($http, $this->action->ability())) {
            return $this->refuse($this->action->ability());
        }

        // The same bounds the endpoint applies, for the same reason: the name reaches a feed body
        // every agent reads, and a schema constrains types rather than values
        $request->validate([
            'name' => ['required', 'string', 'max:'.Lock::MAX_NAME, 'regex:/^[A-Za-z0-9._:\/-]+$/D'],
            'ttl' => $this->action->takesATtl()
                ? ['required', 'integer', 'min:1', 'max:'.$credentials->lockMaxTtlSeconds()]
                : ['prohibited'],
        ]);

        $session = $this->session($http);
        $name = Arguments::string($request->get('name'));
        $coordinator = $this->allows($http, Ability::CoordinatorDirect);

        $result = match ($this->action) {
            LockAction::Acquire => $locks->acquire($session, $name, Arguments::integer($request->get('ttl')), $coordinator),
            LockAction::Renew => $locks->renew($session, $name, Arguments::integer($request->get('ttl')), $coordinator),
            LockAction::Release => ['outcome' => $locks->release($session, $name, $coordinator), 'lock' => null],
            LockAction::ForceRelease => ['outcome' => $locks->forceRelease($session, $name), 'lock' => null],
        };

        if ($result['outcome'] !== Outcome::Applied) {
            return Response::error($this->explain($result['outcome'], $name));
        }

        $lock = $result['lock'];

        return Response::structured(array_filter([
            'name' => $name,
            'held' => $lock instanceof Lock,
            'fence' => $lock?->fence,
            'expires_at' => $lock?->expires_at?->toIso8601String(),

            // The same duration the REST response carries, and for the same reason: a bridge on a
            // machine whose clock is wrong can use it and cannot use the instant
            'expires_in' => $lock?->expires_at === null
                ? null
                : max(0, Carbon::now()->diffInSeconds($lock->expires_at, false)),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Why the action did not happen.
     *
     * @param  Outcome  $outcome  What came of it.
     * @param  string  $name  The lock's name.
     * @return string The reason.
     */
    private function explain(Outcome $outcome, string $name): string
    {
        return match ($outcome) {
            Outcome::NotFound => sprintf('Nobody has ever taken `%s`.', $name),
            Outcome::Forbidden => sprintf('Another session holds `%s`, and you have never held it.', $name),
            Outcome::Conflict => match ($this->action) {
                LockAction::Acquire => sprintf('`%s` is held and its lease is still running.', $name),
                default => sprintf(
                    'Your hold on `%s` is gone -- it lapsed, or somebody took it. Acquire it again '
                        .'before doing anything else you were guarding with it.',
                    $name
                ),
            },
            // A lock never answers `Added`, which is a task's alone
            Outcome::Applied, Outcome::Added => 'Applied.',
        };
    }
}
