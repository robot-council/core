<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Allowlist;
use Throwable;

/**
 * What is wrong with a host application's configuration, reported without being asked a question.
 *
 * **Every fault here is invisible until something else goes wrong.** Three were found on the
 * deployed application on 2026-09-18 and two of them by accident, while looking at something else:
 * a null `sanctum` provider that let an agent's session token authenticate on the host's own
 * `auth:sanctum` routes, no queue worker so the Slack mirror would have queued forever, and two
 * indexes a fresh install no longer creates. None of them breaks a request (#103).
 *
 * **This lives in the host application rather than the CLI, and what is observable says why.** Over
 * HTTP a remote caller can establish that the service is reachable and that the routes are served,
 * and nothing else on this list. The guard's provider, whether anything consumes the queue, whether
 * the schema matches this version -- every one is a server-side fact, and an artisan command in the
 * host is the only place that can see them.
 *
 * **Nothing here writes, and nothing here prints a secret.** A command people run when worried has
 * to be safe to run when worried, and it reports whether the webhook is set rather than what it is.
 */
final class Doctor
{
    /**
     * How old the oldest waiting job may be before it reads as nothing consuming the queue.
     *
     * A proxy, because "is a worker running" cannot be asked directly. Generous enough that a
     * momentary backlog on a busy queue does not read as a fault.
     */
    public const int STALE_JOB_SECONDS = 300;

    /**
     * @param  Repository  $config  The host's configuration.
     * @param  Allowlist  $allowlist  Read rather than re-parsed, so this cannot disagree with the
     *                                thing it is checking.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly Allowlist $allowlist
    ) {}

    /**
     * Run every check.
     *
     * @return list<Diagnosis> One entry per check, in the order they are reported.
     */
    public function examine(): array
    {
        return [
            $this->sanctumProvider(),
            $this->sanctumExpiration(),
            $this->migrations(),
            $this->queue(),
            $this->developers(),
            $this->slackConnection(),
            $this->timezone(),
            $this->slackWebhook(),
        ];
    }

    /**
     * Whether a `sanctum` guard names a provider.
     *
     * A null provider accepts a token belonging to **any** model, so an agent session's token
     * authenticates on the host's own `auth:sanctum` routes. Measured on the deployment: 200 where
     * it should have been 401.
     *
     * @return Diagnosis What the check concluded.
     */
    private function sanctumProvider(): Diagnosis
    {
        $guard = $this->config->get('auth.guards.sanctum');

        if (! \is_array($guard)) {
            return Diagnosis::passed(
                'sanctum guard provider',
                'No `sanctum` guard is configured, so there is none to leave open.'
            );
        }

        $provider = $guard['provider'] ?? null;

        if (\is_string($provider) && $provider !== '') {
            return Diagnosis::passed('sanctum guard provider', sprintf('Named `%s`.', $provider));
        }

        return Diagnosis::failed(
            'sanctum guard provider',
            'auth.guards.sanctum.provider is not set. A null provider accepts a token belonging to any '
            ."model, so an agent session token authenticates on this application's own `auth:sanctum` "
            .'routes. Set it to the provider your users use.'
        );
    }

    /**
     * Whether `sanctum.expiration` is null.
     *
     * Sanctum measures it from a token's creation, so a non-null value cuts off a renewed session
     * token and the agent holding it, whatever the token's own expiry says. `robot-council:install`
     * refuses while it is set; this catches it being set afterwards.
     *
     * @return Diagnosis What the check concluded.
     */
    private function sanctumExpiration(): Diagnosis
    {
        $expiration = $this->config->get('sanctum.expiration');

        if ($expiration === null) {
            return Diagnosis::passed('sanctum expiration', 'Null, which is what session renewal needs.');
        }

        return Diagnosis::failed(
            'sanctum expiration',
            "sanctum.expiration is set. Sanctum measures it from a token's creation, so it cuts off a "
            .'renewed session token and the agent holding it. Leave it null; the package expires its own.'
        );
    }

    /**
     * Whether every migration this version of the package ships has run.
     *
     * Schema drift from an edited create migration is silent and now has precedent: #94 dropped two
     * indexes by editing a create migration a host had already run, leaving the deployment carrying
     * indexes a fresh install no longer creates.
     *
     * **This compares what has run, not what the schema looks like.** A table altered by hand still
     * reads as current here, which is why the detail says so rather than implying more than it
     * checked.
     *
     * @return Diagnosis What the check concluded.
     */
    private function migrations(): Diagnosis
    {
        $shipped = array_map(
            static fn (string $path): string => basename($path, '.php'),
            glob(\dirname(__DIR__, 2).'/database/migrations/*.php') ?: []
        );

        if ($shipped === []) {
            return Diagnosis::undetermined(
                'package migrations',
                "The package's migration directory could not be read, so which have run cannot be compared."
            );
        }

        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            return Diagnosis::undetermined(
                'package migrations',
                'The `migrations` table could not be read. Run `php artisan migrate` first, then this check '
                .'can compare what has run against what this version ships.'
            );
        }

        $pending = array_values(array_diff($shipped, array_filter(
            array_map(static fn (mixed $name): ?string => \is_string($name) ? $name : null, $ran),
            static fn (?string $name): bool => $name !== null
        )));

        if ($pending === []) {
            return Diagnosis::passed(
                'package migrations',
                sprintf('All %d have run. This compares what ran, not the live schema.', \count($shipped))
            );
        }

        return Diagnosis::failed(
            'package migrations',
            sprintf(
                "%d of this version's migrations have not run: %s. Run `php artisan migrate`.",
                \count($pending),
                implode(', ', $pending)
            )
        );
    }

    /**
     * Whether anything appears to be consuming the queue.
     *
     * **A proxy, because the question cannot be asked directly.** Nothing records that a worker
     * exists; the oldest waiting job's age is the observable that stands in for it. On a driver
     * that keeps no such table there is nothing to read, and that is reported rather than passed.
     *
     * @return Diagnosis What the check concluded.
     */
    private function queue(): Diagnosis
    {
        $default = $this->config->get('queue.default');

        $driver = \is_string($default) ? $this->config->get(sprintf('queue.connections.%s.driver', $default)) : null;

        if ($driver === 'sync') {
            return Diagnosis::failed(
                'queue worker',
                'The default queue connection is `sync`, so a queued job runs inside the request that '
                ."dispatched it. The Slack mirror would then run inside an agent's request."
            );
        }

        if ($driver !== 'database') {
            return Diagnosis::undetermined(
                'queue worker',
                sprintf(
                    'The default queue driver is `%s`, which keeps no table here to read a backlog from. '
                    ."Checking whether a worker is running needs that driver's own tooling.",
                    \is_string($driver) ? $driver : 'not configured'
                )
            );
        }

        try {
            $oldest = DB::table('jobs')->min('created_at');
        } catch (Throwable) {
            return Diagnosis::undetermined(
                'queue worker',
                'The `jobs` table could not be read, so a backlog cannot be measured. Run the queue '
                .'migration, then this check can see it.'
            );
        }

        if ($oldest === null) {
            return Diagnosis::passed('queue worker', 'No job is waiting, so nothing is backing up.');
        }

        // Narrowed rather than cast: the column's type is the host's, and a driver that hands back
        // something unexpected must read as undetermined rather than as a backlog of zero.
        if (! is_numeric($oldest)) {
            return Diagnosis::undetermined(
                'queue worker',
                "The oldest waiting job's timestamp could not be read as a number, so its age cannot be "
                ."measured. The `jobs` table may not be this application's own."
            );
        }

        $waited = Carbon::now()->getTimestamp() - (int) $oldest;

        if ($waited <= self::STALE_JOB_SECONDS) {
            return Diagnosis::passed('queue worker', sprintf('The oldest waiting job is %ds old.', $waited));
        }

        return Diagnosis::failed(
            'queue worker',
            sprintf(
                'The oldest waiting job is %ds old, past the %ds this reads as a backlog. That is what it '
                .'looks like when nothing is consuming the queue.',
                $waited,
                self::STALE_JOB_SECONDS
            )
        );
    }

    /**
     * Whether anybody may sign in.
     *
     * An empty list locks out everyone, including whoever is reading this output.
     *
     * @return Diagnosis What the check concluded.
     */
    private function developers(): Diagnosis
    {
        $developers = $this->allowlist->developers();

        if ($developers === []) {
            return Diagnosis::failed(
                'developer allowlist',
                'robot-council.access.developers is empty, so no GitHub account may sign in -- including '
                .'yours. Set ROBOT_COUNCIL_DEVELOPERS to the numeric GitHub user IDs allowed in.'
            );
        }

        return Diagnosis::passed(
            'developer allowlist',
            sprintf('%d GitHub account(s) may sign in.', \count($developers))
        );
    }

    /**
     * Whether the Slack mirror would run inside an agent's request.
     *
     * On `sync` the mirror runs in the request that wrote the event, so a Slack failure surfaces on
     * a write that has already committed.
     *
     * @return Diagnosis What the check concluded.
     */
    private function slackConnection(): Diagnosis
    {
        $connection = $this->config->get('robot-council.slack.connection');

        if ($connection === 'sync') {
            return Diagnosis::failed(
                'slack queue connection',
                'robot-council.slack.connection is `sync`, so the mirror runs inside the agent request that '
                .'wrote the event and a Slack failure surfaces on a write that already committed.'
            );
        }

        return Diagnosis::passed(
            'slack queue connection',
            \is_string($connection) && $connection !== ''
                ? sprintf('Queued on `%s`.', $connection)
                : 'Queued on the default connection.'
        );
    }

    /**
     * Whether a clock the fleet depends on can shift under it.
     *
     * **Presence is no longer part of this.** #51 moved contact times and their cutoffs onto
     * `Support\PresenceClock`, a fixed zone, and `Support\PresenceTimestamp` makes the column read
     * back on the same one -- so a daylight-saving transition no longer moves a session's age.
     *
     * A lock's lease still runs on the application clock: `Support\Locks` writes `expires_at` with
     * `Carbon::now()` and compares it the same way. `locks.max_ttl_seconds` defaults to 900, so an
     * hour's jump is longer than any lease can be -- at spring-forward **every held lock reads as
     * expired at once**, and another session can take a name its holder still believes it owns. The
     * fence value is what lets that holder find out, which is a detection rather than a
     * prevention. Credential expiry has the same shape, since Sanctum compares `expires_at` against
     * the application clock too.
     *
     * So this still fails outside UTC, for the leases rather than for presence.
     *
     * @return Diagnosis What the check concluded.
     */
    private function timezone(): Diagnosis
    {
        $timezone = $this->config->get('app.timezone');

        if ($timezone === 'UTC') {
            return Diagnosis::passed('application timezone', 'UTC, which does not shift.');
        }

        return Diagnosis::failed(
            'application timezone',
            sprintf(
                'app.timezone is `%s`. Presence is unaffected, but a lock lease and a credential expiry are '
                .'still measured on the application clock, and a lease cannot outlast an hour -- so a '
                .'daylight-saving transition makes every held lock read as expired at once. Set it to UTC.',
                \is_string($timezone) ? $timezone : 'not a string'
            )
        );
    }

    /**
     * Whether the Slack webhook is configured.
     *
     * **Not a fault either way**, which is why it passes in both directions: a host that wants no
     * mirror is correctly configured. Reported because "the mirror is off" and "the mirror is
     * broken" are worth telling apart, and nothing else states which one this is.
     *
     * Reports only whether it is set. The value is a credential and never reaches the output.
     *
     * @return Diagnosis What the check concluded.
     */
    private function slackWebhook(): Diagnosis
    {
        $webhook = $this->config->get('robot-council.slack.webhook_url');

        return Diagnosis::passed(
            'slack webhook',
            \is_string($webhook) && $webhook !== ''
                ? 'Set, so events are mirrored.'
                : 'Not set, so the mirror is off. That is a choice, not a fault.'
        );
    }
}
