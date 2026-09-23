<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Models\Installation;
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
     * How many installation ids one diagnosis will name before it stops listing them.
     *
     * `lazyById()` bounds what is hydrated and not what is collected, so a fleet where many rows
     * went bad at once would otherwise put every id on one console line. The count is still exact;
     * only the list is cut, because the operator needs to know the size of the problem even when
     * they cannot read every id from the output.
     */
    public const int MAX_NAMED_INSTALLATIONS = 20;

    /**
     * @param  Repository  $config  The host's configuration.
     * @param  Allowlist  $allowlist  Read rather than re-parsed, so this cannot disagree with the
     *                                thing it is checking.
     * @param  FleetAbilities  $fleet  What this fleet can do, for the coordination check.
     * @param  string|null  $migrationDirectory  Where this package's migrations live. Null means
     *                                           the real one, which is every case but a test: the
     *                                           two migration checks are otherwise only testable
     *                                           by writing a probe file into the tracked tree,
     *                                           which a parallel run or a second session in the
     *                                           same checkout would read.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly Allowlist $allowlist,
        private readonly FleetAbilities $fleet,
        private readonly ?string $migrationDirectory = null
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
            $this->retiredMigrations(),
            $this->queue(),
            $this->developers(),
            $this->coordination(),
            $this->storedAbilities(),
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
        $shipped = PackageMigrations::shipped($this->migrationDirectory);

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
     * How many usable installations hold an abilities value the package cannot read back.
     *
     * **A value `Models\Installation::abilities()` drops is invisible everywhere else, and what it
     * changes is fleet-wide.** `Support\FleetAbilities::anyInstallationHolds()` reads every usable
     * installation to answer `fleet_can_direct`, so one unreadable row can tell every agent on the
     * fleet that no directive will ever arrive. That answer is correct and its cause is not
     * recorded anywhere: before #167 the same row raised, which was at least loud. This is the
     * check that names it (#171).
     *
     * **It separates two causes, because they have different fixes.** A stored element that is a
     * well-formed string the fixed list no longer holds is a RETIRED name -- the row was written
     * when that ability existed, and the repair is to rewrite the row. A stored element that is not
     * a string, or a stored value that is not a list at all, is MALFORMED -- nothing in this package
     * writes one, so the repair is to find what did. Reporting them together would leave the reader
     * to guess which they have.
     *
     * **Nothing here prints what a row contains.** `DoctorTest` proves no check prints a secret by
     * planting a credential and searching the output, and an abilities column is not a secret -- but
     * the rule this follows is that output is read by whoever is worried, and an installation id is
     * what they need to act.
     *
     * Read in chunks for the reason `FleetAbilities::CHUNK` records: a fleet's installations are
     * few, and hydrating all of them to answer one question is a habit worth not forming.
     *
     * @return Diagnosis What the check concluded.
     */
    private function storedAbilities(): Diagnosis
    {
        $known = Ability::values(Ability::grantable());

        /** @var list<int> $retired */
        $retired = [];

        /** @var list<int> $malformed */
        $malformed = [];

        try {
            $installations = Installation::usable()
                ->select(['id', 'granted_abilities'])
                ->orderBy('id')
                ->lazyById(FleetAbilities::CHUNK);

            foreach ($installations as $installation) {
                $stored = $installation->getAttribute('granted_abilities');

                if (! \is_array($stored)) {
                    $malformed[] = $installation->id;

                    continue;
                }

                foreach ($stored as $value) {
                    if (! \is_string($value)) {
                        $malformed[] = $installation->id;

                        break;
                    }

                    // The strict flag cannot be killed from here, and it is not dead. `is_string()`
                    // above guarantees a string reaches this, and no string can make strict and
                    // loose membership disagree against this list, because none of the ability
                    // names is numeric -- measured over ten probe strings including `'0'`, `'1e2'`
                    // and `'100'`, zero disagreements. The control for that probe: allow a bool
                    // through and the two answers do differ, so it can discriminate. Move or
                    // weaken the `is_string()` guard and this becomes killable again.
                    // @pest-mutate-ignore: TrueToFalse
                    if (! \in_array($value, $known, true)) {
                        $retired[] = $installation->id;

                        break;
                    }
                }
            }
        } catch (QueryException) {
            // **`QueryException`, not `Throwable`, and this one had it wrong first.** `coordination()`
            // below records the reason: the only cause this message can honestly name is a table it
            // could not read, and a blanket catch reports anything else as "run `php artisan migrate`"
            // on a schema that is already migrated. It matters more here than there, because that
            // check wraps one call into an already-total accessor and this wraps a loop that
            // classifies rows by hand -- so the wider surface had the wider net, which is backwards.
            // A `JsonException` from a host's own `Json::decodeUsing()` decoder is the concrete case:
            // it belongs to the row this check exists to name, and would have been reported as an
            // unmigrated schema.
            return Diagnosis::undetermined(
                'stored abilities',
                'The installations table could not be read, so whether any holds an unreadable abilities '
                .'value is unknown. Run `php artisan migrate` first.'
            );
        }

        if ($retired === [] && $malformed === []) {
            return Diagnosis::passed(
                'stored abilities',
                "Every usable installation's granted abilities read back as stored."
            );
        }

        $parts = [];

        if ($malformed !== []) {
            $parts[] = sprintf(
                '%d installation(s) hold a value that is not a list of ability names, which nothing in this '
                .'package writes -- find what did: %s',
                \count($malformed),
                self::named($malformed)
            );
        }

        if ($retired !== []) {
            $parts[] = sprintf(
                '%d installation(s) name something this version does not grant -- a retired ability, or a '
                .'value like `*` that never was one -- so it is dropped on every read. Repair it with any '
                .'`robot-council:grant-ability` or `robot-council:revoke-ability` that CHANGES the '
                .'readable list; one whose answer is what is already readable writes nothing, which '
                .'includes revoking an ability the row does not readably hold: %s',
                \count($retired),
                self::named($retired)
            );
        }

        return Diagnosis::failed(
            'stored abilities',
            ucfirst(implode('; ', $parts))
            .'. Until then those entries are invisible on every read, so the installation acts with '
            .'fewer abilities than its row claims. Whether repairing one changes `fleet_can_direct` '
            .'depends on a gate this check does not apply: that answer also requires the '
            ."installation's developer to still be on the access list."
        );
    }

    /**
     * A bounded, readable list of installation ids for a diagnosis message.
     *
     * @param  list<int>  $ids  The installations to name, in the order they were found.
     * @return string The ids, cut to `MAX_NAMED_INSTALLATIONS` with the remainder counted.
     */
    private static function named(array $ids): string
    {
        $shown = \array_slice($ids, 0, self::MAX_NAMED_INSTALLATIONS);
        $rest = \count($ids) - \count($shown);

        return implode(', ', $shown).($rest > 0 ? sprintf(' and %d more', $rest) : '');
    }

    /**
     * Which of this package's retired migrations this database has run.
     *
     * **A recorded name whose file is gone is permanent and, until now, unexplained.**
     * `Migrator::rollbackMigrations()` skips it with a warning and never deletes the row, so a host
     * that upgraded through a rename carries it forever. #132 produced two such names, and an
     * operator reading the `migrations` table had no way to tell whose they were or whether they
     * mattered. That is the silence this answers (#169).
     *
     * **It passes when it finds them, because they are inert.** The rows record work that was done;
     * what was removed is the file, not the effect. A check that failed on a state every upgrading
     * host is in would be a check people switch off.
     *
     * **What it fails on is a stale manifest**, which is the one condition that makes its own answer
     * untrustworthy: if this version ships a migration `PackageMigrations::EVER_SHIPPED` does not
     * list, then the retired set is computed against an incomplete list and can hide a row.
     * `tests/MigrationManifestGuardTest.php` catches that at the source; this catches an install
     * where the two got out of step some other way.
     *
     * **It cannot see a database that is ahead of its code.** The manifest ships with the package,
     * so a name from a later version is absent from it and no comparison against it can find one.
     * #169 records that, and it is why this check does not claim to.
     *
     * @return Diagnosis What the check concluded.
     */
    private function retiredMigrations(): Diagnosis
    {
        // **The same guard `migrations()` takes, and leaving it out was the defect.** `glob()` on an
        // unreadable directory returns `[]` rather than `false`, so `shipped()` comes back empty and
        // `retired()` degenerates to the entire manifest -- at which point this check reported all
        // fifteen names, the thirteen this version ships included, as retired and inert. A
        // confident PASS computed from a directory it could not read, printed directly beneath
        // `migrations()` saying it could not read that same directory.
        if (PackageMigrations::shipped($this->migrationDirectory) === []) {
            return Diagnosis::undetermined(
                'retired migrations',
                "The package's migration directory could not be read, so which recorded rows belong to "
                .'this package cannot be answered.'
            );
        }

        $unlisted = PackageMigrations::unlisted($this->migrationDirectory);

        if ($unlisted !== []) {
            return Diagnosis::failed(
                'retired migrations',
                sprintf(
                    'This version ships %d migration(s) its own manifest does not list: %s. Add them to '
                    .'`%s::EVER_SHIPPED`; until then, which recorded rows belong to this package cannot '
                    .'be answered.',
                    \count($unlisted),
                    implode(', ', $unlisted),
                    PackageMigrations::class
                )
            );
        }

        try {
            $recorded = DB::table('migrations')->pluck('migration')->all();
            // **`Throwable`, matching `migrations()` for the identical read**, and the distinction
            // from the narrow catches elsewhere in this file is what the try CONTAINS. Those wrap a
            // loop that classifies rows by hand, where a `TypeError` must not be reported as an
            // unmigrated schema. This wraps one query and an `is_string()` map. A host whose
            // `database.default` names no configured connection gets an `InvalidArgumentException`
            // from `DatabaseManager::configuration()`, which is not a `QueryException` -- and
            // `DoctorCommand` has no try, so narrowing here would print a stack trace and no checks.
        } catch (Throwable) {
            return Diagnosis::undetermined(
                'retired migrations',
                "The `migrations` table could not be read, so which of this package's retired migrations "
                .'this database has run is unknown. Run `php artisan migrate` first.'
            );
        }

        $present = PackageMigrations::retiredAmong(array_values(array_filter(
            array_map(static fn (mixed $name): ?string => \is_string($name) ? $name : null, $recorded),
            static fn (?string $name): bool => $name !== null
        )), $this->migrationDirectory);

        if ($present === []) {
            return Diagnosis::passed(
                'retired migrations',
                "None of this package's retired migrations are recorded here."
            );
        }

        return Diagnosis::passed(
            'retired migrations',
            sprintf(
                '%d recorded migration(s) belong to this package and are no longer shipped: %s. They are '
                .'inert -- the work was done and only the file was removed -- and Laravel never deletes '
                .'such a row, so they stay. Nothing to do.',
                \count($present),
                implode(', ', $present)
            )
        );
    }

    /**
     * Whether anything on this fleet can reach a waiting agent.
     *
     * **A fleet can be wired correctly and still deliver nothing.** The CLI's follower treats a
     * directive as the one event that always reaches an idle agent, and posting one needs
     * `coordinator:direct`, which enrollment can never request. Unless an admin has granted it to
     * some installation, every stop hook on the fleet finds an empty sink forever -- and an empty
     * sink is byte-identical to a fleet that genuinely has nothing to say, which is why nothing
     * reports it today.
     *
     * **It passes when the answer is no.** A fleet whose agents only ever receive is a legitimate
     * configuration, and a check that failed on one would be a check people switch off. What it
     * does is say which of the two a deployment is, so the absence is not read as quiet.
     *
     * @return Diagnosis What the check concluded.
     */
    private function coordination(): Diagnosis
    {
        try {
            $anyone = $this->fleet->anyInstallationHolds(Ability::CoordinatorDirect);
        } catch (QueryException) {
            // **`QueryException`, not `Throwable`.** The only cause this message can honestly name
            // is a table it could not read, and a blanket catch would report a `TypeError` from a
            // malformed row as "run `php artisan migrate`" on a schema that is already migrated --
            // the misdiagnosis `DiagnosisStatus::Undetermined` exists to prevent rather than cause.
            return Diagnosis::undetermined(
                'fleet coordination',
                'The installations table could not be read, so whether anything on this fleet can post a '
                .'directive is unknown. Run `php artisan migrate` first.'
            );
        }

        if (! $anyone) {
            return Diagnosis::passed(
                'fleet coordination',
                'No installation holds `coordinator:direct`, so no directive can be posted and nothing '
                .'will reach an agent waiting on one. That is correct for a fleet whose agents only '
                .'receive. Grant it with `php artisan robot-council:grant-ability` if it is not.'
            );
        }

        return Diagnosis::passed(
            'fleet coordination',
            'At least one installation holds `coordinator:direct`, so a directive can reach a waiting agent.'
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
     * **Neither presence nor a lock lease is part of this any more.** #51 moved contact times and
     * their cutoffs onto `Support\PresenceClock`, a fixed zone; #149 moved a lock's `acquired_at`
     * and `expires_at` onto the same one, with `Support\PresenceTimestamp` making both columns read
     * back on the clock they were written on. A daylight-saving transition no longer ages a session
     * or lapses a lease.
     *
     * **What remains is credential expiry, and the two halves of it are not alike.**
     * `Support\Credentials::installationExpiry()` and `sessionTokenExpiry()` write `expires_at` on
     * tokens that **Sanctum** compares against its own `now()`, which is the application's.
     * Writing those on a fixed clock while Sanctum keeps reading the host's would introduce the
     * mismatch rather than remove it, in the place that decides whether a credential still works.
     * #149 put that out of scope for exactly that reason.
     *
     * A **device code's** expiry had no such coupling and moved in #160, so what remains is the
     * token half alone.
     *
     * **Credentials are what this reports, not everything on the application clock.** Three
     * retention cutoffs are measured on it too -- `robot-council:prune-tasks`,
     * `robot-council:prune-events`, and `Support\InstallationList` -- and each is self-consistent,
     * written and compared on the same clock, so nothing lapses and no row is lost. What moves is
     * the boundary, by an hour, once. That is a different severity from a credential that stops
     * working, which is why the message names the credentials and this docblock names the rest:
     * a check that reports more than is true is one people learn to discount, and one that
     * reports less leaves a reader believing the rest is handled.
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
                "app.timezone is `%s`. Presence, lock leases and a device code's expiry are unaffected, "
                .'but a token expiry is still measured on the application clock, because Sanctum compares it '
                .'against that clock and moving only one side would be worse. A daylight-saving '
                .'transition can therefore expire or extend a credential by an hour. Set it to UTC.',
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
