<?php

declare(strict_types=1);

namespace RobotCouncil;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\ReorderJsonAccept;
use Livewire\Livewire;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\ApiGuards;
use RobotCouncil\Access\Guard;
use RobotCouncil\Console\GrantAbilityCommand;
use RobotCouncil\Console\InstallCommand;
use RobotCouncil\Console\PruneDeviceCodesCommand;
use RobotCouncil\Console\PruneEventsCommand;
use RobotCouncil\Console\PruneTasksCommand;
use RobotCouncil\Console\RevokeAbilityCommand;
use RobotCouncil\Console\RevokeInstallationCommand;
use RobotCouncil\Console\RevokeSessionCommand;
use RobotCouncil\Console\SweepSessionsCommand;
use RobotCouncil\Http\Controllers\DashboardStylesheetController;
use RobotCouncil\Http\Middleware\DenyFraming;
use RobotCouncil\Http\Middleware\EnsureAgentSession;
use RobotCouncil\Http\Middleware\EnsureAllowlistedDeveloper;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Livewire\ChangeFeed;
use RobotCouncil\Livewire\FleetPresence as FleetPresenceComponent;
use RobotCouncil\Livewire\TaskBoard;
use RobotCouncil\Mcp\CouncilServer;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\Contracts\DrawsUserCodes;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\HostUsers;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\SessionReleases;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Support\UserCodes;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers the package with a Laravel application through `spatie/laravel-package-tools`: its
 * configuration and views, its console commands, its web and machine routes, its two Sanctum
 * guards, its rate limits, and the `robot-council-admin` ability.
 */
final class RobotCouncilServiceProvider extends PackageServiceProvider
{
    /**
     * The ability name that gates admin-only actions.
     */
    public const string ADMIN_ABILITY = 'robot-council-admin';

    /**
     * What a route accepts where it takes one of the package's own row IDs.
     *
     * Bounded in length as well as in character set. `whereNumber` is `[0-9]+`, which lets through
     * a number no bigint can hold: Postgres answers `22003 value out of range` -- a 500 -- where
     * SQLite quietly matches no rows, so a suite on SQLite cannot see it. Eighteen digits fit in a
     * signed 64-bit integer whatever they are.
     */
    public const string ROUTE_ID = '[0-9]{1,18}';

    /**
     * The named rate limit on the unauthenticated device-code endpoint.
     */
    public const string DEVICE_CODE_LIMITER = 'robot-council-device-code';

    /**
     * The named rate limit on the unauthenticated token endpoint, which helpers poll.
     */
    public const string DEVICE_TOKEN_LIMITER = 'robot-council-device-token';

    /**
     * The named rate limit on a developer's approve and deny posts.
     */
    public const string VERIFICATION_LIMITER = 'robot-council-verification';

    /**
     * The named rate limit on starting and renewing agent sessions.
     */
    public const string SESSIONS_LIMITER = 'robot-council-sessions';

    /**
     * The named rate limit on the agent routes, keyed on the session making the request.
     */
    public const string AGENT_LIMITER = 'robot-council-agent';

    /**
     * The named rate limit the Slack mirror job runs through, shared by every worker.
     */
    public const string SLACK_LIMITER = 'robot-council-slack';

    /**
     * Declare the package's name and the resources it registers.
     *
     * @param  Package  $package  The package definition to configure.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('robot-council')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommands([
                InstallCommand::class,
                GrantAbilityCommand::class,
                RevokeAbilityCommand::class,
                RevokeInstallationCommand::class,
                RevokeSessionCommand::class,
                PruneDeviceCodesCommand::class,
                PruneEventsCommand::class,
                PruneTasksCommand::class,
                SweepSessionsCommand::class,
            ]);
    }

    /**
     * Register what has to exist before the authentication factory resolves.
     */
    public function packageRegistered(): void
    {
        $this->registerMorphAliases();
        $this->registerGuards();

        $this->app->bind(DrawsUserCodes::class, UserCodes::class);

        // A singleton, because it is a registry: a release step registered from another service
        // provider has to be there for the sweep that runs later in the same process
        $this->app->singleton(SessionReleases::class);
    }

    /**
     * Give the package's token owners stable morph aliases.
     *
     * Two reasons, and the first one is fatal without this. A host that calls
     * `Relation::enforceMorphMap()` -- the usual convention in a large application that wants its
     * `*_type` columns to survive a namespace change -- makes `getMorphClass()` throw for any model
     * outside the map, so issuing any credential would die with `No morph map defined`. The second
     * is that a host adding these classes to its own map later would change what `tokenable_type`
     * holds and orphan every live token, so the package names them itself and keeps the name.
     *
     * Merged rather than set, so nothing a host already registered is lost.
     */
    private function registerMorphAliases(): void
    {
        Relation::morphMap([
            'robot-council-installation' => Installation::class,
            'robot-council-agent-session' => AgentSession::class,
        ], merge: true);
    }

    /**
     * Register what needs the application's own bindings.
     */
    public function packageBooted(): void
    {
        $this->registerMigrations();
        $this->registerReleases();
        $this->registerRateLimits();
        $this->registerRoutes();
        $this->registerAbilities();
        $this->registerSchedule();
    }

    /**
     * Register what the presence sweep releases when a session has gone.
     *
     * A step rather than a `SessionGone` listener, and the two are not equivalent. The event fires
     * once, so anything that swallows it -- a worker that died mid-job, a listener that threw --
     * leaves a task held by a process that no longer exists, with nothing to notice. A step that
     * runs on every sweep finds whatever is still held, so the worst case is one sweep interval
     * rather than forever.
     *
     * Resolved when the step runs rather than now, because this is registered at boot and the store
     * it needs depends on configuration a host may still be changing.
     */
    private function registerReleases(): void
    {
        $releases = $this->app->make(SessionReleases::class);

        $releases->register(function (): void {
            $this->app->make(Tasks::class)->releaseOrphaned();
        });

        // A second step beside the first. Every step runs even when one throws, so a task release
        // that fails cannot leave the locks of every gone session held.
        $releases->register(function (): void {
            $this->app->make(Locks::class)->releaseOrphaned();
        });
    }

    /**
     * Define the two Sanctum guards the package authenticates machines on.
     *
     * Each has an authentication provider naming one model, which is what makes the guards refuse
     * each other's tokens: Sanctum reads `auth.providers.<provider>.model` and requires the token's
     * owner to be an instance of it. A host that leaves `auth.guards.sanctum.provider` null keeps
     * Sanctum's own default, which accepts any owner, so the host sets that itself.
     *
     * Written into the host's configuration rather than published, because a guard the package
     * cannot find is a guard that fails open into whatever the host's default guard admits.
     */
    private function registerGuards(): void
    {
        $config = $this->app->make(Repository::class);

        $defaults = [
            'auth.providers.'.ApiGuards::INSTALLATION_PROVIDER => ['driver' => 'eloquent', 'model' => Installation::class],
            'auth.guards.'.ApiGuards::INSTALLATION => ['driver' => 'sanctum', 'provider' => ApiGuards::INSTALLATION_PROVIDER],
            'auth.providers.'.ApiGuards::AGENT_PROVIDER => ['driver' => 'eloquent', 'model' => AgentSession::class],
            'auth.guards.'.ApiGuards::AGENT => ['driver' => 'sanctum', 'provider' => ApiGuards::AGENT_PROVIDER],
        ];

        foreach ($defaults as $key => $default) {
            $configured = $config->get($key);

            // Merged the way Sanctum merges its own guard, so a host that has already pointed one
            // of these at another connection or model keeps what it set
            $config->set($key, \is_array($configured) ? array_merge($default, $configured) : $default);
        }
    }

    /**
     * Load the migrations for the package's own tables.
     *
     * They are loaded rather than published, so `php artisan migrate` picks up an upgrade and a
     * host cannot end up running both a published copy and the package's own. The migrations that
     * change or belong to the host's tables are the opposite case, and `robot-council:install`
     * writes them.
     */
    private function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Mount the package's routes under their configured prefixes and middleware groups.
     *
     * A host that has cached its routes already holds these, and re-registering them means parsing
     * and grouping them on every request only for `Router::setCompiledRoutes()` to discard the lot.
     *
     * A mistyped value falls back to the documented default rather than throwing. Throwing from a
     * service provider takes down every request AND every artisan command, including the
     * `config:clear` that would fix it, so the host would have to edit the file by hand.
     */
    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $config = $this->app->make(Repository::class);

        $webPrefix = $this->routeString($config, 'web_prefix', 'robot-council');
        $webMiddleware = $this->routeMiddleware($config, 'web_middleware', ['web']);
        $apiPrefix = $this->routeString($config, 'api_prefix', 'robot-council/api');
        $apiMiddleware = $this->routeMiddleware($config, 'api_middleware', []);

        // The stylesheet, deliberately outside the web group. It holds nothing a signed-in
        // developer would not already see, and a page that needed authentication to load its own
        // styling would render unstyled to exactly the people being told to sign in. Outside the
        // group rather than merely public, because `StartSession` would otherwise run on every
        // request for a static file -- writing a session record per anonymous hit, running the
        // garbage-collection lottery, and attaching a `Set-Cookie` to a response this package tells
        // shared caches they may store for a year.
        Route::prefix($webPrefix)
            ->name('robot-council.')
            ->group(function (): void {
                Route::get('dashboard.css', DashboardStylesheetController::class)
                    ->name('dashboard.stylesheet');
            });

        Route::middleware($webMiddleware)
            ->prefix($webPrefix)
            ->name('robot-council.')
            ->group(__DIR__.'/../routes/web.php');

        // Livewire strips every middleware from its update endpoint that is not on its own fixed
        // persistent list, so `EnsureAllowlistedDeveloper` does not run there and a developer
        // removed from the access list keeps driving components from a page already open. Every
        // dashboard slice mounts inside one, so this is registered with the routes rather than
        // beside the component it happens to protect first.
        // Named so the dashboard page can mount them, and prefixed so a host's own component of the
        // same name is not shadowed -- the failure that ruled out a Blade component library on #30.
        Livewire::component('robot-council-administration', Administration::class);
        Livewire::component('robot-council-change-feed', ChangeFeed::class);
        Livewire::component('robot-council-fleet-presence', FleetPresenceComponent::class);
        Livewire::component('robot-council-task-board', TaskBoard::class);

        Livewire::addPersistentMiddleware([
            EnsureAllowlistedDeveloper::class,
            DenyFraming::class,
        ]);

        Route::middleware($apiMiddleware)
            ->prefix($apiPrefix)
            ->name('robot-council.')
            ->group(__DIR__.'/../routes/api.php');

        $this->registerMcpServer($apiPrefix, $apiMiddleware);
    }

    /**
     * Mount the MCP server beside the machine routes.
     *
     * Behind the same guard, principal middleware and rate limit as every other agent route. The
     * agent middleware is what makes a tool call arrive as a session -- `Http\Principal` refuses to
     * hand a tool anything otherwise, so a server mounted without it fails loudly rather than
     * serving the fleet's tools to whoever asked.
     *
     * **Declared as a group rather than applied to the returned route**, because `Mcp::web()`
     * registers three routes and returns only the POST one: it answers `GET` and `DELETE` on the
     * same URI with a constant 405 to satisfy the transport specification. Middleware applied to the
     * return value reaches POST alone, which leaves two unauthenticated, unthrottled endpoints a
     * host inherits and cannot put its own perimeter in front of. A group covers all three.
     *
     * It also orders the pipeline the right way round. The group's middleware merges *ahead* of the
     * transport's own, so the limiter and the guard run before `ValidateMcpHeaders` decodes the
     * body, and an unauthenticated caller no longer costs the host a JSON parse of up to
     * `post_max_size`.
     *
     * **`ReorderJsonAccept` is named here as well**, because that reordering has to happen before
     * anything can refuse the request. The transport specification asks a client to accept both
     * `application/json` and `text/event-stream` and does not fix their order, while
     * `Request::wantsJson()` reads only the first acceptable type -- so a client listing
     * `text/event-stream` first would take the guard's 401 and the limiter's 429 as an HTML error
     * page. It is idempotent, so running again inside the transport's own stack costs nothing.
     *
     * What does move inward is `AddWwwAuthenticateHeader`, and it costs the `realm="mcp"` and
     * `error="invalid_token"` detail on a guard 401. `Illuminate\Pipeline\Pipeline::carry()` wraps
     * each pipe in its own try/catch and renders the exception where it was thrown, so that
     * middleware did previously receive the guard's 401 as a response and decorate it. The refusal
     * still carries `WWW-Authenticate: Bearer` from `UnauthorizedHttpException`, and no OAuth
     * resource-metadata route exists for the fuller form to point at.
     *
     * `mcp:inspector` does not list this server while a host has cached its routes, because Laravel
     * skips a package's route files then and this registration runs inside that same guard.
     *
     * @param  string  $prefix  The configured machine-route prefix.
     * @param  array<int|string, mixed>  $middleware  The host's own machine middleware.
     */
    private function registerMcpServer(string $prefix, array $middleware): void
    {
        $uri = trim($prefix, '/').'/mcp';

        // `$middleware` has already been reduced to usable strings by `routeMiddleware()`, so this
        // route and the REST routes receive exactly the same list
        Route::middleware([
            ...array_values($middleware),
            ReorderJsonAccept::class,
            'throttle:'.self::AGENT_LIMITER,
            EnsureAgentSession::class,
        ])->group(function () use ($uri): void {
            Mcp::web($uri, CouncilServer::class)->name('robot-council.mcp');
        });
    }

    /**
     * Read one configured route prefix.
     *
     * @param  Repository  $config  The host application's configuration repository.
     * @param  string  $key  The key under `robot-council.routes`.
     * @param  string  $default  What to mount under when the value is unusable.
     * @return string The prefix to mount under.
     */
    private function routeString(Repository $config, string $key, string $default): string
    {
        $value = $config->get('robot-council.routes.'.$key, $default);

        if (\is_string($value)) {
            return $value;
        }

        Log::warning(sprintf('robot-council: `robot-council.routes.%s` must be a string; using `%s`.', $key, $default));

        return $default;
    }

    /**
     * Read one configured middleware group.
     *
     * @param  Repository  $config  The host application's configuration repository.
     * @param  string  $key  The key under `robot-council.routes`.
     * @param  list<string>  $default  What to run when the value is unusable.
     * @return array<int|string, mixed> The middleware to run.
     */
    private function routeMiddleware(Repository $config, string $key, array $default): array
    {
        $value = $config->get('robot-council.routes.'.$key, $default);

        if (! \is_array($value)) {
            Log::warning(sprintf('robot-council: `robot-council.routes.%s` must be an array; using the package default.', $key));

            return $default;
        }

        // Every entry has to survive being cast to a string, because that is what the router does
        // with it: `Route::middleware()` and `RouteRegistrar::attribute()` both force `(string)`
        // over each one. A closure raises `Object of class Closure could not be converted to
        // string` and an array raises a warning and stores the literal `Array`, and this runs
        // inside `packageBooted()` -- so a host that put the wrong shape in its config would take
        // down every request and every artisan command, including the `config:clear` that would
        // undo it.
        $usable = array_values(array_filter($value, \is_string(...)));

        if (\count($usable) !== \count($value)) {
            Log::warning(sprintf(
                'robot-council: `robot-council.routes.%s` must hold middleware names as strings; ignoring %d entry that is not one.',
                $key,
                \count($value) - \count($usable),
            ));
        }

        return $usable;
    }

    /**
     * Define the rate limits the routes name.
     *
     * Each is keyed on the subject it is protecting rather than on the address a request came
     * from, where there is one: a helper polling the token endpoint every few seconds is ordinary
     * traffic, and thousands of device codes from one address are not.
     */
    private function registerRateLimits(): void
    {
        $credentials = $this->app->make(Credentials::class);
        $guard = $this->app->make(Guard::class);

        RateLimiter::for(self::DEVICE_CODE_LIMITER, static fn (Request $request): Limit => Limit::perMinute(
            $credentials->rateLimit('device_code_per_ip', 10)
        )->by('ip:'.$request->ip()));

        RateLimiter::for(self::DEVICE_TOKEN_LIMITER, static function (Request $request) use ($credentials): array {
            $deviceCode = $request->input('device_code');
            $verifier = $request->input('code_verifier');

            // Keyed on the pair, not on the code alone. A thief holding a stolen device code but
            // not the verifier would otherwise share a bucket with the helper that owns it, and
            // could spend the allowance every minute until the code expired -- so a code that is
            // useless to them would still be useless to its owner. Hashed, so the cache holds
            // neither value even for the minute a bucket lives.
            $subject = \is_string($deviceCode) && \is_string($verifier)
                ? hash('sha256', $deviceCode.'|'.$verifier)
                : 'unreadable';

            return [
                Limit::perMinute($credentials->rateLimit('device_token_per_ip', 120))->by('ip:'.$request->ip()),
                Limit::perMinute($credentials->rateLimit('device_token_per_code', 30))->by('code:'.$subject),
            ];
        });

        RateLimiter::for(self::VERIFICATION_LIMITER, static function (Request $request) use ($credentials, $guard): Limit {
            $key = $request->user($guard->name())?->getAuthIdentifier();

            return Limit::perMinute($credentials->rateLimit('verification_per_user', 20))
                ->by('user:'.(\is_scalar($key) ? (string) $key : 'ip:'.$request->ip()));
        });

        RateLimiter::for(self::AGENT_LIMITER, static function (Request $request) use ($credentials): Limit {
            // Resolved through the guard for the reason the sessions limiter records: the limiter
            // runs ahead of the guard middleware, so nothing has been left on the request yet
            $session = $request->user(ApiGuards::AGENT);

            $subject = $session instanceof AgentSession
                ? (string) $session->id
                : 'ip:'.$request->ip();

            return Limit::perMinute($credentials->rateLimit('agent_per_session', 120))
                ->by('agent:'.$subject);
        });

        // One limit across every worker, because Slack's is per webhook rather than per process
        RateLimiter::for(self::SLACK_LIMITER, static fn (): Limit => Limit::perMinute(
            $credentials->rateLimit('slack_per_minute', 60)
        ));

        RateLimiter::for(self::SESSIONS_LIMITER, static function (Request $request) use ($credentials): Limit {
            // Resolved through the guard rather than read from what `EnsureInstallation` leaves on
            // the request: the routes declare this limiter ahead of that middleware, so the request
            // attribute is not set yet. Keyed on the address when no installation resolves, which
            // is what an unauthenticated flood looks like -- and what the route order makes
            // reachable, because a limiter declared after the guard never sees one.
            $installation = $request->user(ApiGuards::INSTALLATION);

            $subject = $installation instanceof Installation
                ? (string) $installation->id
                : 'ip:'.$request->ip();

            return Limit::perMinute($credentials->rateLimit('sessions_per_installation', 60))
                ->by('installation:'.$subject);
        });
    }

    /**
     * Define the admin ability, reading the access lists on every check.
     */
    private function registerAbilities(): void
    {
        Gate::define(self::ADMIN_ABILITY, function (Authenticatable $user): bool {
            $githubId = $this->app->make(HostUsers::class)->githubId($user);

            return $githubId !== null && $this->app->make(Allowlist::class)->isAdmin($githubId);
        });
    }

    /**
     * Schedule the prune of expired device codes and the presence sweep.
     *
     * Registered after the application has booted, because the scheduler is not bound until then,
     * and only in console, where the schedule is read.
     *
     * The sweep runs every minute and takes no overlap lock. It does not need one: every transition
     * it writes is conditional on the state its read saw, so two sweeps running at once produce one
     * transition and one event between them. A lock would add a failure mode strictly worse than
     * the one it prevented, because a sweep killed mid-run leaves the lock held until it expires,
     * and nothing is marked gone for as long as that lasts.
     */
    private function registerSchedule(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $config = $this->app->make(Repository::class);

        // A host that runs no scheduler, or that prunes on its own terms, turns these off rather
        // than finding entries in `schedule:list` it cannot remove
        $prune = $config->get('robot-council.schedule.prune_device_codes', true) === true;
        $sweep = $config->get('robot-council.schedule.sweep_sessions', true) === true;
        $pruneEvents = $config->get('robot-council.schedule.prune_events', true) === true;
        $pruneTasks = $config->get('robot-council.schedule.prune_tasks', true) === true;

        if (! $prune && ! $sweep && ! $pruneEvents && ! $pruneTasks) {
            return;
        }

        $this->app->booted(static function () use ($prune, $sweep, $pruneEvents, $pruneTasks): void {
            if ($prune) {
                Schedule::command(PruneDeviceCodesCommand::class)->hourly();
            }

            if ($sweep) {
                Schedule::command(SweepSessionsCommand::class)->everyMinute();
            }

            // Daily rather than hourly. The feed is pruned by age, so running it more often
            // deletes the same rows a little sooner and costs a scan each time; and a host that
            // wants it sooner after an incident can run the command by hand, which takes no lock.
            if ($pruneEvents) {
                Schedule::command(PruneEventsCommand::class)->dailyAt('03:10');
            }

            // Ten minutes after the feed's, so two prunes never start together on a host whose
            // scheduler runs them in one process
            if ($pruneTasks) {
                Schedule::command(PruneTasksCommand::class)->dailyAt('03:20');
            }
        });
    }
}
