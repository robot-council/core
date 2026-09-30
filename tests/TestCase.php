<?php

declare(strict_types=1);

namespace RobotCouncil\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseTransactionsManager as ApplicationTransactionsManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Models\Installation;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\RobotCouncilServiceProvider;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\Installations;
use RobotCouncil\Support\RoleRequests;
use RobotCouncil\Support\Tasks;
use RuntimeException;

use function Orchestra\Testbench\default_migration_path;

/**
 * Base test case: boots a Testbench application with the package's service provider registered,
 * and provides the fixtures the suite shares — a users table carrying the package's columns,
 * throwaway directories, the access lists, and enrolled installations and agent sessions.
 *
 * The fixture helpers are public rather than protected. Pest exposes shared test helpers as global
 * functions, which are not bound to the test case and cannot reach a protected method.
 */
class TestCase extends Orchestra
{
    /**
     * The developer most tests act as.
     *
     * Declared rather than left to Pest's dynamic properties. A test closure is bound to the test
     * case, so a property assigned in `beforeEach` is inferred there -- but a shared helper that
     * takes the case as a parameter sees only what the class declares, and everything it reaches
     * for arrives as `mixed`. Declaring the fixtures is what lets those helpers be written at all.
     */
    public User $developer;

    /**
     * The installation most machine tests authenticate as.
     */
    public Installation $installation;

    /**
     * That installation's plaintext credential.
     */
    public string $credential;

    /**
     * The agent session most agent tests authenticate as.
     */
    public AgentSession $session;

    /**
     * That session's plaintext token.
     */
    public string $token;

    /**
     * A second session holding `coordinator:direct`, for the tests that need one.
     */
    public AgentSession $coordinatorSession;

    /**
     * That coordinator session's plaintext token.
     */
    public string $coordinatorToken;

    /**
     * Directories created for the current test, removed when it finishes.
     *
     * @var list<string> Absolute paths outside the repository.
     */
    private array $temporaryDirectories = [];

    /**
     * Configuration to apply while the application boots, rather than after it has.
     *
     * @var array<string, mixed> Values keyed by configuration key.
     */
    private array $bootConfiguration = [];

    /**
     * The route cache this test wrote, removed when it finishes.
     *
     * It lives in the Testbench skeleton and outlives the process, so leaving one behind makes
     * every later run in the checkout boot with routes cached. That happened while #219 was being
     * investigated, and made an unrelated test report a defect in a file it had created itself.
     */
    private ?string $cachedRoutesPath = null;

    /**
     * The substring in a test class name that asks for a cached route collection.
     *
     * Pest builds a class per test file from its path, so a file named `...CachedRoutes...Test.php`
     * selects this without any per-test wiring.
     */
    private const string CACHED_ROUTES_MARKER = 'CachedRoutes';

    /**
     * The connection this test's rolled-back transaction is open on, or null when it has none.
     *
     * Held so `rebootWith()` can roll it back before the application it belongs to is replaced,
     * and so the rollback registered for teardown can tell it was already done.
     */
    private ?Connection $testTransaction = null;

    /**
     * The directory holding this process's copy of the install stub, which the shared schema was
     * migrated from, created once and removed when the process ends.
     */
    private static ?string $processMigrations = null;

    /**
     * Register the package's service provider with the Testbench application.
     *
     * @param  Application  $app  The Testbench application.
     * @return array<int, class-string> The service providers to register.
     */
    protected function getPackageProviders($app)
    {
        return [
            // A host application discovers these through Composer; Testbench does not. The MCP
            // provider is not optional decoration: it registers the callback that copies a tool
            // call's arguments onto the `Request` a tool type-hints, so without it every tool runs
            // with no arguments and answers a validation error.
            McpServiceProvider::class,
            SocialiteServiceProvider::class,
            SanctumServiceProvider::class,

            // The dashboard's stack. A host discovers Livewire through Composer and Testbench does
            // not, exactly as with Socialite above.
            LivewireServiceProvider::class,

            RobotCouncilServiceProvider::class,
        ];
    }

    /**
     * Supply the GitHub OAuth credentials a host application configures.
     *
     * @param  Application  $app  The Testbench application.
     */
    protected function defineEnvironment($app)
    {
        // **A test whose class name carries `CachedRoutes` boots with its route collection already
        // cached.** Keyed on the class rather than on a PHPUnit group because `groups()` and
        // `name()` are both marked `@internal` and outside PHPUnit's compatibility promise, which
        // `composer analyse` refuses.
        // Written here rather than by a test body, because `routesAreCached()` is read while
        // providers boot -- and `refreshApplication()` cannot stand in for it: rebooting mid-test
        // leaves Livewire unable to resolve ANY component name, so a probe built that way reported
        // a name nobody had registered as resolvable. Measured, with a negative control (#219).
        //
        // The file is inert on purpose. `routesAreCached()` is a file-exists check and Laravel
        // `require`s the file at `booted()`, so one declaring no routes puts the application in the
        // state under test without pinning anything to the shape of a compiled route collection.
        if (str_contains(static::class, self::CACHED_ROUTES_MARKER)) {
            $path = $app->getCachedRoutesPath();

            File::ensureDirectoryExists(\dirname($path));
            File::put($path, '<?php // A cached route collection holding no routes.'.PHP_EOL);

            $this->cachedRoutesPath = $path;
        }

        // Sessions and cookies are encrypted, so the application needs a key of its own. Not
        // Testbench's `.env`, which only exists once something has copied `.env.example` over.
        $app['config']->set('app.key', 'base64:AckfSECXIvnK5r28GVIWUAxmbBSjTsmFAckfSECXIvk=');

        // The cookie session handler has no request in tests, so sessions live in memory
        $app['config']->set('session.driver', 'array');

        $app['config']->set('services.github', [
            'client_id' => 'github-client-id',
            'client_secret' => 'github-client-secret',
            'redirect' => 'http://localhost/robot-council/auth/github/callback',
        ]);

        // Last, so a test's own value wins over the defaults above
        foreach ($this->bootConfiguration as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /**
     * Boot a fresh application with one configuration value set before anything reads it.
     *
     * `config()->set()` is too late for anything a service provider decides at boot -- what it
     * schedules, what routes it mounts -- because the provider has already run by the time a test
     * body executes. This sets the value and boots again, so the provider sees it.
     *
     * The application that comes back is a new one: its database is empty, and anything resolved
     * out of the old container belongs to a container nothing else is using.
     *
     * @param  string  $key  The configuration key to set.
     * @param  mixed  $value  What to set it to.
     */
    public function rebootWith(string $key, mixed $value): void
    {
        $this->bootConfiguration[$key] = $value;

        // The replaced application's teardown callbacks never run, so its test transaction is
        // rolled back here. The new application starts outside one; whatever it writes before the
        // test asks for the schema again is committed, so the shared schema is marked stale
        if ($this->testTransaction instanceof Connection) {
            $this->rollBackTestTransaction();

            RefreshDatabaseState::$migrated = false;
        }

        $this->refreshApplication();
    }

    /**
     * Remove the directories the test created.
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        $this->temporaryDirectories = [];

        if ($this->cachedRoutesPath !== null) {
            File::delete($this->cachedRoutesPath);

            $this->cachedRoutesPath = null;
        }

        parent::tearDown();
    }

    /**
     * Create a throwaway directory outside the repository, removed when the test finishes.
     *
     * @param  string  $name  A short label describing what the directory holds.
     * @return string The directory's absolute path.
     */
    protected function temporaryDirectory(string $name): string
    {
        $directory = sprintf('%s/robot-council-%s-%s', sys_get_temp_dir(), $name, Str::random(8));

        File::ensureDirectoryExists($directory);

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    /**
     * Point the application's database path at a throwaway directory, never at vendor/.
     *
     * @return string The directory the application now treats as its database path.
     */
    protected function useTemporaryDatabasePath(): string
    {
        $directory = $this->temporaryDirectory('database');

        $this->app?->useDatabasePath($directory);

        return $directory;
    }

    /**
     * Drop whatever the last test left behind, then migrate Laravel's tables, Sanctum's, the
     * package's own, and any extra paths, outside any transaction.
     *
     * **This is the opt-out's mechanism, never the default** (#473). It leaves behind a schema or
     * committed rows the shared schema does not have, so it marks the shared schema stale and the
     * next transactional test migrates afresh before it begins.
     *
     * @param  string  ...$paths  Extra migration directories to run, in migration-name order.
     */
    protected function migrateFresh(string ...$paths): void
    {
        RefreshDatabaseState::$migrated = false;

        Artisan::call('migrate:fresh', [
            '--path' => [
                default_migration_path(),

                // A host application publishes this one with `robot-council:install`
                $this->sanctumMigrationPath(),
                __DIR__.'/../database/migrations',
                ...$paths,
            ],
            '--realpath' => true,
        ]);
    }

    /**
     * Where Sanctum keeps the migration a host publishes.
     *
     * Resolved from the installed class rather than written out, so moving or renaming the vendor
     * directory fails here instead of silently migrating one table fewer.
     *
     * @return string The absolute path to Sanctum's migrations directory.
     */
    protected function sanctumMigrationPath(): string
    {
        return \dirname((string) new ReflectionClass(Sanctum::class)->getFileName(), 2).'/database/migrations';
    }

    /**
     * Give the test the whole schema, including the users-table change `robot-council:install`
     * writes, inside a transaction rolled back when the test ends (#473).
     *
     * **The schema is migrated once per process and each test rolls back what it wrote**, the
     * decision on #467. `migrate:fresh` re-ran about 50 migrations for every test, which on Postgres
     * was most of the job's time and grew with every test added. The mechanics are Laravel's own
     * `RefreshDatabase`, written out rather than used because that trait runs for every test that
     * uses it, while here a test asks for the database by calling this: the same shared state
     * (`RefreshDatabaseState`), the same reuse of an in-memory SQLite connection across
     * applications, and the same testing transaction manager, so `DB::afterCommit()` callbacks run
     * when the code's own transaction commits rather than never.
     *
     * **A test that needs a real schema change or a real commit opts out by name**:
     * `migrateFreshSchema()` in place of this, or `leaveTestTransaction()` inside one test.
     * `MigrateOnceGuardTest` refuses a test that needs one and has neither.
     *
     * @return string The directory holding the copy of the install stub the schema was built from.
     */
    public function migrateUsersTableWithPackageColumns(): string
    {
        $directory = self::processMigrations();

        // Called again in the same test, it starts the test over from an empty schema, as the
        // `migrate:fresh` it replaces did: what the test wrote so far is rolled back
        $this->rollBackTestTransaction();

        $database = $this->app?->make('db');

        if (! $database instanceof DatabaseManager) {
            throw new RuntimeException('The database manager is not bound.');
        }

        $name = $database->getDefaultConnection();
        $inMemory = $database->connection($name)->getConfig('database') === ':memory:';

        // An in-memory SQLite database lives in its connection, and every test boots a new
        // application, so the connection that holds the migrated schema is handed to each one
        if ($inMemory && isset(RefreshDatabaseState::$inMemoryConnections[$name])) {
            $database->connection($name)->setPdo(RefreshDatabaseState::$inMemoryConnections[$name]);
        }

        if (! RefreshDatabaseState::$migrated) {
            $this->migrateFresh($directory);
            $this->app?->make(Kernel::class)->setArtisan(null);

            if ($inMemory) {
                RefreshDatabaseState::$inMemoryConnections[$name] = $database->connection($name)->getPdo();
            }

            RefreshDatabaseState::$migrated = true;
        }

        $this->beginTestTransaction($database->connection($name));

        return $directory;
    }

    /**
     * The opt-out (#473): give the test a schema built by `migrate:fresh`, outside any transaction,
     * exactly as every database test had before.
     *
     * For a test that changes the schema itself, rolls migrations back or forward, reads what a
     * second connection committed, or asserts when `DB::afterCommit()` callbacks run. Each of those
     * observes something different inside a transaction that is never committed, or, on MySQL,
     * ends it: DDL there commits implicitly.
     *
     * @return string The directory holding the copy of the install stub that ran.
     */
    public function migrateFreshSchema(): string
    {
        $directory = self::processMigrations();

        // Whatever the test wrote inside a transaction goes with it, as a fresh schema would take it
        $this->rollBackTestTransaction();

        $this->migrateFresh($directory);

        return $directory;
    }

    /**
     * The opt-out for one test whose file's `beforeEach` began a test transaction (#473): commit the
     * fixtures it already has, and run the rest of the test outside any transaction, exactly as
     * every database test did before.
     *
     * The rows it commits are in the shared schema from then on, so the schema is marked stale and
     * the next transactional test rebuilds it with `migrate:fresh` before it begins: no test sees
     * another's rows. For a test that asserts when `DB::afterCommit()` callbacks run, or that reads
     * `DB::transactionLevel()`, where the wrapping transaction would change the answer.
     */
    public function leaveTestTransaction(): void
    {
        $connection = $this->testTransaction;
        $this->testTransaction = null;

        if (! $connection instanceof Connection) {
            return;
        }

        RefreshDatabaseState::$migrated = false;

        $dispatcher = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();

        // Already committed underneath -- by DDL on MySQL -- leaves nothing to commit, only the
        // connection's count of levels to clear, which a guarded rollback does without a statement
        if (! $connection->getPdo()->inTransaction()) {
            $connection->rollBack(0);
        }

        while ($connection->transactionLevel() > 0) {
            $connection->commit();
        }

        if ($dispatcher !== null) {
            $connection->setEventDispatcher($dispatcher);
        }

        // Back to the application's own manager, so callbacks run when the code's own outermost
        // transaction commits, and not one level early as the testing manager arranges
        $manager = new ApplicationTransactionsManager;
        $this->app?->instance('db.transactions', $manager);
        $connection->setTransactionManager($manager);
    }

    /**
     * Begin the transaction a test's writes are rolled back from, and roll it back at teardown.
     *
     * @param  Connection  $connection  The default connection, holding the migrated schema.
     */
    private function beginTestTransaction(Connection $connection): void
    {
        $manager = new DatabaseTransactionsManager([$connection->getName()]);
        $this->app?->instance('db.transactions', $manager);
        $connection->setTransactionManager($manager);

        // Quietly, as Laravel's own trait does, so a listener counting transactions sees only the
        // code's own
        $dispatcher = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();
        $connection->beginTransaction();

        if ($dispatcher !== null) {
            $connection->setEventDispatcher($dispatcher);
        }

        $this->testTransaction = $connection;

        $this->beforeApplicationDestroyed(function (): void {
            $this->rollBackTestTransaction();
        });
    }

    /**
     * Roll back this test's transaction, if it has one still open.
     *
     * A transaction that is no longer open on the connection was committed underneath the test --
     * by DDL on MySQL, or by a test that should have opted out -- so its rows are in the shared
     * schema, which is marked stale for the next test to rebuild.
     */
    private function rollBackTestTransaction(): void
    {
        $connection = $this->testTransaction;
        $this->testTransaction = null;

        if (! $connection instanceof Connection) {
            return;
        }

        $dispatcher = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();

        if (! $connection->getPdo()->inTransaction()) {
            RefreshDatabaseState::$migrated = false;
        }

        $connection->rollBack(0);

        if ($dispatcher !== null) {
            $connection->setEventDispatcher($dispatcher);
        }

        $connection->disconnect();
    }

    /**
     * This process's copy of the install stub, made the first time a test asks for it.
     *
     * Once per process rather than per test, because the schema it builds is shared by every test
     * in the process: a per-test directory would be removed while the schema it named lived on.
     *
     * @return string The directory.
     */
    private static function processMigrations(): string
    {
        if (self::$processMigrations !== null && is_dir(self::$processMigrations)) {
            return self::$processMigrations;
        }

        $directory = sprintf('%s/robot-council-migrations-%d-%s', sys_get_temp_dir(), getmypid(), Str::random(8));

        File::ensureDirectoryExists($directory);

        // Run the stub itself, so the suite exercises what `robot-council:install` writes
        File::copy(
            __DIR__.'/../database/stubs/add_robot_council_columns_to_users_table.php.stub',
            $directory.'/2026_01_01_000000_add_robot_council_columns_to_users_table.php'
        );

        // Plain PHP rather than the `File` facade: at shutdown the application may be gone, and a
        // facade with no root fails the process after every test has passed -- on Laravel 13.23
        register_shutdown_function(static function () use ($directory): void {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        });

        return self::$processMigrations = $directory;
    }

    /**
     * Enroll a developer: a host user row, and the package's identity for a GitHub account.
     *
     * @param  int  $githubId  The developer's numeric GitHub user ID.
     * @param  string  $login  The GitHub login to record.
     * @param  string|null  $email  The email for the user row, defaulted from the ID.
     * @return User The saved user.
     */
    public function enrollDeveloper(int $githubId, string $login = 'octodev', ?string $email = null): User
    {
        $user = new User;

        $user->forceFill([
            'name' => $login,
            'email' => $email ?? sprintf('octo+%d@example.com', $githubId),
        ])->save();

        GithubIdentity::query()->create([
            'user_id' => $user->getKey(),
            'github_id' => $githubId,
            'github_login' => $login,
        ]);

        return $user;
    }

    /**
     * Set the access lists, in the comma-separated form the environment supplies.
     *
     * @param  list<int>  $developers  GitHub user IDs allowed to sign in.
     * @param  list<int>  $admins  GitHub user IDs that also hold admin rights.
     */
    public function setAccessLists(array $developers = [], array $admins = []): void
    {
        config()->set('robot-council.access.developers', implode(',', $developers));
        config()->set('robot-council.access.admins', implode(',', $admins));
    }

    /**
     * Create an approved installation for a developer, as the device-code flow would.
     *
     * **It no longer takes an ability list** (#239). An installation stored one until
     * `robot_council_installations.granted_abilities` was dropped, and the parameter outlived what
     * it set: `robot-council/core#221` gave a session token its `Access\Role` preset and `#222`
     * removed the column's one authorization reader, so for two releases a caller narrowing this
     * list changed nothing about what any session could do -- a test reaching a refusal through it
     * passed for having asserted nothing. `tokenFor()` is what narrows a session's abilities, and
     * its own docblock already says so.
     *
     * @param  User  $user  The developer who approved it.
     * @param  string  $machineLabel  The label the requester claimed.
     * @return Installation The saved installation.
     */
    public function approveInstallation(User $user, string $machineLabel = 'workbench'): Installation
    {
        return Installation::query()->create([
            'user_id' => $user->getKey(),
            'harness' => 'claude-code',
            'machine_label' => $machineLabel,
            'approved_by' => $user->getKey(),
            'requested_ip' => '203.0.113.10',
            'expires_at' => $this->credentials()->installationExpiry(),
        ]);
    }

    /**
     * Issue an installation's credential, exactly as the token endpoint does.
     *
     * @param  Installation  $installation  The installation to credential.
     * @return string The plaintext credential.
     */
    public function installationCredential(Installation $installation): string
    {
        return $installation->createToken(
            Installations::CREDENTIAL_NAME,
            [Ability::SessionsStart->value],
            $installation->expires_at
        )->plainTextToken;
    }

    /**
     * Start an agent session under an installation, as the session endpoint does.
     *
     * @param  Installation  $installation  The installation to start it under.
     * @return array{AgentSession, string} The session and its plaintext token.
     */
    public function startAgentSession(Installation $installation): array
    {
        $issued = $this->service(AgentSessions::class)->start($installation);

        return [$issued->owner, $issued->plainTextToken];
    }

    /**
     * Start a session whose id is not its installation's, for an assertion that names both.
     *
     * An assertion comparing a session's id and its installation's cannot tell the two apart when
     * they are the same number. They can be by chance: Postgres does not rewind a sequence on
     * rollback (#473), so the two tables' ids advance at their own rates across the suite and can
     * meet, which failed `postgres` at 26 and 26 on v0.7.3's changelog merge. The installation's id
     * is fixed and a session's only grows, so a second start always differs.
     *
     * @param  Installation  $installation  The installation to start it under.
     * @return array{AgentSession, string} The session and its plaintext token.
     */
    public function startAgentSessionWithOwnId(Installation $installation): array
    {
        [$session, $token] = $this->startAgentSession($installation);

        if ($session->getKey() === $installation->getKey()) {
            [$session, $token] = $this->startAgentSession($installation);
        }

        return [$session, $token];
    }

    /**
     * Start a session and have an administrator make it a coordinator.
     *
     * **Two steps, because since `robot-council/core#222` there is no other way to get one.** A
     * session starts as `build` whatever its installation holds; a coordinator exists only where an
     * administrator decided, which is what stops a checkout asserting the role by asking. Granting
     * the installation `coordinator:direct` reaches no session at all.
     *
     * The plaintext token is the one `startAgentSession()` issued and is still valid: `impose()`
     * rewrites the abilities on the token ROW rather than replacing the token, so a process holding
     * one does not have to renew to feel the change.
     *
     * @param  Installation  $installation  The installation to start it under.
     * @return array{AgentSession, string} The session and its plaintext token.
     */
    public function startCoordinatorSession(Installation $installation): array
    {
        [$session, $token] = $this->startAgentSession($installation);

        // **Checked rather than discarded.** A helper that swallowed a `false` here would hand back
        // a build session under a coordinator name, and every caller would fail somewhere further
        // on for a reason that says nothing about what went wrong.
        if (! $this->service(RoleRequests::class)->impose($session, Role::Coordinator, 'test-administrator')) {
            throw new RuntimeException('The administrator could not make this session a coordinator.');
        }

        return [$session->refresh(), $token];
    }

    /**
     * Start a session and re-mint its token to carry exactly the abilities named.
     *
     * **No role produces most of these lists, and that is the reason this exists.** Before
     * `Access\Role`, a test reached a refusal by narrowing the installation's `granted_abilities`
     * and starting a session under it. Roles ended that: every preset carries all four build
     * abilities, so the same setup now produces a token that is refused nothing and a test that
     * passes for having asserted nothing.
     *
     * The gates under test -- `Http\Middleware\RequireAbility` and the MCP tools' own checks --
     * read the TOKEN. So the token state is built here directly and the role is left out of it. A
     * refusal proved this way is a statement about the gate, and **not** a claim that any role a
     * fleet can run reaches it: today only `coordinator:direct` is genuinely withheld from a
     * session, and `Access\Role::Ci` is the case designed to diverge later.
     *
     * @param  Installation  $installation  The installation to start it under.
     * @param  list<string>  $abilities  Exactly what the token should carry.
     * @return array{AgentSession, string} The session and its plaintext token.
     */
    public function startAgentSessionWithAbilities(Installation $installation, array $abilities): array
    {
        [$session] = $this->startAgentSession($installation);

        // The session's own token, replaced rather than added to. **Not because a second token
        // could answer for this one** -- Sanctum resolves by the id and hash of the token that was
        // PRESENTED, so it could not -- but so the session is left in the state the name claims,
        // holding one token and that token narrow. A leftover wide token would be a session whose
        // abilities depend on which string the caller happened to keep.
        $session->tokens()->delete();

        return [$session, $session->createToken(
            AgentSessions::TOKEN_NAME,
            $abilities,
            $this->credentials()->sessionTokenExpiry()
        )->plainTextToken];
    }

    /**
     * The headers a machine sends: a bearer token, and a request for JSON.
     *
     * @param  string  $token  The plaintext bearer token.
     * @return array<string, string> The headers.
     */
    protected function bearer(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    /**
     * A task this test's own session holds, for the surfaces that need one to act on.
     *
     * Created and claimed through the store rather than over HTTP, because the tests that want one
     * are testing a different door, and a failure in the setup should not read as a failure in it.
     *
     * @param  string  $title  The task's title.
     * @return int The task's ID.
     */
    public function createClaimedTask(string $title = 'Held'): int
    {
        $tasks = $this->app?->make(Tasks::class);

        if (! $tasks instanceof Tasks) {
            throw new RuntimeException('The application is not booted.');
        }

        $task = $tasks->create($this->session, ['title' => $title], withCoordinator: false);

        $tasks->transition($task->id, TaskTransition::Claim, $this->session, asCoordinator: false);

        return $task->id;
    }

    /**
     * Make the next request as a machine holding this token.
     *
     * The forgotten guards are load-bearing. `Illuminate\Auth\RequestGuard::user()` caches the
     * principal it resolved, and one test process keeps one application across every request it
     * makes, so a second request in the same test would otherwise be answered as whoever the first
     * one authenticated -- a revoked token would keep working, and another installation's
     * credential would arrive as this one's. A real request boots its own application, and Octane
     * flushes the same state between requests.
     *
     * @param  string  $token  The plaintext bearer token.
     * @return $this The test case, with the machine's headers set.
     */
    public function machine(string $token): static
    {
        $this->app?->make('auth')->forgetGuards();

        return $this->withHeaders($this->bearer($token));
    }

    /**
     * Mark an agent session as gone, without going through revocation.
     *
     * @param  AgentSession  $session  The session to end.
     */
    protected function markSessionGone(AgentSession $session): void
    {
        $session->forceFill(['status' => AgentSessionStatus::Gone])->save();
    }

    /**
     * The booted application.
     *
     * @return Application The container, which is only null before a test boots one.
     *
     * @throws RuntimeException When the application has not booted.
     */
    public function container(): Application
    {
        $app = $this->app;

        if ($app === null) {
            throw new RuntimeException('The application was not booted.');
        }

        return $app;
    }

    /**
     * Resolve a service out of the booted application.
     *
     * @template TService of object
     *
     * @param  class-string<TService>  $abstract  The class to resolve.
     * @return TService The resolved service.
     *
     * @throws RuntimeException When the application has not booted.
     */
    public function service(string $abstract): object
    {
        $service = $this->container()->make($abstract);

        if (! $service instanceof $abstract) {
            throw new RuntimeException(sprintf('The container returned something other than %s.', $abstract));
        }

        return $service;
    }

    /**
     * The configured lifetimes.
     *
     * @return Credentials The credentials reader.
     */
    protected function credentials(): Credentials
    {
        return $this->service(Credentials::class);
    }
}
