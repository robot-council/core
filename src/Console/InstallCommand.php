<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use RobotCouncil\Support\Credentials;

/**
 * Performs the setup a host application needs: writes the migrations the package cannot load
 * itself, because they change or belong to tables the host owns. Run it once per installation and
 * commit what it writes. It recognizes its earlier output by file-name suffix, so a re-run writes
 * nothing -- including a re-run months later, when a fresh publish would otherwise arrive under a
 * new timestamp and leave the host with two copies of one table.
 */
#[Description('Write the migrations robot-council needs in this application')]
#[Signature('robot-council:install')]
final class InstallCommand extends Command
{
    /**
     * The `users` columns an insert by this package already accounts for.
     *
     * `name` and `email` are what `Support\UserAttributes` writes; `id` is the key the database
     * assigns; the timestamps are Eloquent's. Anything else that is NOT NULL with no default is
     * something only the host can fill.
     *
     * @var list<string>
     */
    private const array COLUMNS_THE_PACKAGE_FILLS = ['id', 'name', 'email', 'created_at', 'updated_at'];

    /**
     * The suffix every generated users-columns migration carries.
     */
    private const string USERS_MIGRATION_SUFFIX = '_add_robot_council_columns_to_users_table.php';

    /**
     * The suffix Sanctum's own migration carries, whatever timestamp it is published under.
     */
    private const string SANCTUM_MIGRATION_SUFFIX = '_create_personal_access_tokens_table.php';

    /**
     * Write the package's migrations into the host application.
     *
     * @param  Filesystem  $files  The filesystem the command reads and writes through.
     * @param  Repository  $config  The host application's configuration repository.
     * @return int The command's exit code.
     */
    public function handle(Filesystem $files, Repository $config, Credentials $credentials): int
    {
        // Checked before anything is written, so a refusal leaves the application exactly as it
        // was. Writing two migrations and then exiting non-zero tells a deploy script the install
        // failed while half of it happened, and the re-run that fixes nothing exits non-zero too.
        if (! $this->checkSanctumExpiration($config, $credentials)) {
            return self::FAILURE;
        }

        $migrations = $this->laravel->databasePath('migrations');

        $files->ensureDirectoryExists($migrations);

        $this->writeUsersMigration($files, $migrations);

        $published = $this->publishSanctumMigration($files, $migrations);

        // After the writes, because it is advice rather than a gate: a column this package cannot
        // fill is the host's to decide about, and refusing the install would leave them unable to
        // proceed at all.
        $this->reportUnfillableColumns();

        return $published ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Name the `users` columns a first GitHub sign-in would fail on.
     *
     * **This turns a first-sign-in failure into an install-time sentence** (#36). The package writes
     * `name` and `email`, and this command relaxes nullability on `users.password` and
     * `users.email` because those are the two the framework's own skeleton makes `NOT NULL`. A host
     * with any other `NOT NULL` column and no default -- `tenant_id`, `role_id`, a
     * `first_name`/`last_name` pair -- used to find out after the OAuth round trip, from a raw
     * integrity error, with nothing saying which column it was.
     *
     * Reported rather than enforced, and reported as a list of names rather than a verdict: a host
     * that binds `Contracts\SuppliesUserAttributes` fills these, and this command cannot tell
     * whether they have. What it can do is say which columns that binding has to cover.
     *
     * A column the package could not read is not reported, which is the honest direction: this runs
     * before any migration it just wrote has been applied, and on a host whose `users` table does
     * not exist yet there is nothing to inspect.
     */
    private function reportUnfillableColumns(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $unfillable = [];

        foreach (Schema::getColumns('users') as $column) {
            $name = \is_array($column) && \is_string($column['name'] ?? null) ? $column['name'] : null;

            if ($name === null || \in_array($name, self::COLUMNS_THE_PACKAGE_FILLS, true)) {
                continue;
            }

            // Nullable, defaulted, or generated by the database: all three mean an insert that
            // names neither the column nor a value still succeeds.
            if (($column['nullable'] ?? true) === true) {
                continue;
            }

            if (($column['default'] ?? null) !== null || ($column['auto_increment'] ?? false) === true) {
                continue;
            }

            $unfillable[] = $name;
        }

        if ($unfillable === []) {
            return;
        }

        $this->components->warn(sprintf(
            'These `users` columns are NOT NULL with no default, and robot-council does not write them: %s. '
            .'A developer signing in with GitHub for the first time would fail on them. Bind '
            .'`RobotCouncil\\Support\\Contracts\\SuppliesUserAttributes` to supply them.',
            implode(', ', $unfillable)
        ));
    }

    /**
     * Write the migration that relaxes the host's users table, unless an earlier run wrote it.
     *
     * @param  Filesystem  $files  The filesystem to read and write through.
     * @param  string  $migrations  The host application's migrations directory.
     */
    private function writeUsersMigration(Filesystem $files, string $migrations): void
    {
        if ($this->existing($files, $migrations, self::USERS_MIGRATION_SUFFIX) !== null) {
            $this->components->info(sprintf('The users columns migration already exists: %s.', $this->existing($files, $migrations, self::USERS_MIGRATION_SUFFIX)));

            return;
        }

        // Written under a fresh timestamp, so it runs after the host's own migrations
        $target = sprintf('%s/%s%s', $migrations, Carbon::now()->format('Y_m_d_His'), self::USERS_MIGRATION_SUFFIX);

        $files->put($target, $files->get($this->stubPath()));

        $this->components->info(sprintf('Wrote %s. Review it, commit it, and run `php artisan migrate`.', basename($target)));
        $this->components->warn('It makes `users.password` and `users.email` nullable, which rewrites those column definitions. Read it before migrating a database that has custom collations, defaults, or triggers on that table.');
    }

    /**
     * Copy Sanctum's tokens migration into the host application, unless it is already there.
     *
     * The host owns this table: its own API tokens live there too, and Sanctum publishes rather
     * than loads it. The check is by suffix rather than by name, because the Laravel skeleton sets
     * `database.migrations.update_date_on_publish`, so a publish rewrites the timestamp and
     * comparing names would add a second migration creating one table on every run.
     *
     * The file is copied rather than published through `vendor:publish`, which resolves its
     * destination from the path Sanctum's provider captured when it booted. That is the same
     * directory in an ordinary application and a different one wherever the database path moves
     * afterwards, and the difference is invisible: the publish reports success having written
     * somewhere this command does not look. Copying keeps one source of truth for where it lands.
     *
     * The name Sanctum ships with is kept, so a host that later runs
     * `vendor:publish --tag=sanctum-migrations` is told the file exists instead of getting a second
     * copy under a new timestamp.
     *
     * @param  Filesystem  $files  The filesystem to read and write through.
     * @param  string  $migrations  The host application's migrations directory.
     * @return bool False once a failure has been reported.
     */
    private function publishSanctumMigration(Filesystem $files, string $migrations): bool
    {
        $existing = $this->existing($files, $migrations, self::SANCTUM_MIGRATION_SUFFIX);

        if ($existing !== null) {
            $this->components->info(sprintf("Sanctum's tokens migration already exists: %s.", $existing));

            return true;
        }

        $source = $this->sanctumMigrationPath($files);

        if ($source === null) {
            $this->components->error("Could not find Sanctum's tokens migration in the installed package. Run `php artisan vendor:publish --tag=sanctum-migrations` by hand.");

            return false;
        }

        $files->copy($source, $migrations.'/'.basename($source));

        $this->components->info(sprintf('Wrote %s. Commit it, and run `php artisan migrate`.', basename($source)));

        return true;
    }

    /**
     * Where the installed Sanctum keeps its tokens migration.
     *
     * Resolved from the loaded class rather than from a written-out vendor path, so a package that
     * moved or renamed it is reported instead of quietly skipped.
     *
     * @param  Filesystem  $files  The filesystem to read through.
     * @return string|null The migration's absolute path, or null when it is not where it was.
     */
    private function sanctumMigrationPath(Filesystem $files): ?string
    {
        $installed = new ReflectionClass(Sanctum::class)->getFileName();

        if (! \is_string($installed)) {
            return null;
        }

        $matches = array_values(array_filter(
            $files->glob(\dirname($installed, 2).'/database/migrations/*'.self::SANCTUM_MIGRATION_SUFFIX),
            is_string(...)
        ));

        return $matches === [] ? null : $matches[0];
    }

    /**
     * Check that `sanctum.expiration` cannot cut an installation credential off early.
     *
     * Sanctum applies that one setting to every guard on its driver -- `SanctumServiceProvider`
     * builds each one with `config('sanctum.expiration')` -- and measures it from a token's
     * `created_at`. Session tokens survive it, because a renewal issues a new row with a new
     * creation time. An installation credential does not: it is created once and expected to live
     * for `installation_max_age_days`, so any shorter global setting retires it silently, and the
     * machine can only come back by enrolling again.
     *
     * The requirement is therefore "null, or at least as long as an installation lives" rather
     * than "null", which leaves a host free to keep an expiry for its own tokens.
     *
     * @param  Repository  $config  The host application's configuration repository.
     * @param  Credentials  $credentials  The configured lifetimes.
     * @return bool False once the refusal has been reported.
     */
    private function checkSanctumExpiration(Repository $config, Credentials $credentials): bool
    {
        $expiration = $config->get('sanctum.expiration');

        if ($expiration === null) {
            return true;
        }

        $needed = $credentials->installationMaxAgeDays() * 24 * 60;

        $minutes = \is_int($expiration) || (\is_string($expiration) && ctype_digit($expiration))
            ? (int) $expiration
            : 0;

        if ($minutes >= $needed) {
            return true;
        }

        $this->components->error(sprintf(
            "Set `sanctum.expiration` to null, or to at least %d minutes. Sanctum measures it from a token's creation and applies it to every guard on its driver, so a shorter value retires robot-council installation credentials before their %d-day life is up, and the machines holding them can only return by enrolling again.",
            $needed,
            $credentials->installationMaxAgeDays()
        ));

        return false;
    }

    /**
     * The name of the migration already in the host's migrations directory with a given suffix.
     *
     * @param  Filesystem  $files  The filesystem to read through.
     * @param  string  $migrations  The host application's migrations directory.
     * @param  string  $suffix  The file-name suffix to look for.
     * @return string|null The file's base name, or null when there is none.
     */
    private function existing(Filesystem $files, string $migrations, string $suffix): ?string
    {
        $matches = array_values(array_filter($files->glob($migrations.'/*'.$suffix), is_string(...)));

        return $matches === [] ? null : basename($matches[0]);
    }

    /**
     * The path to the users-columns migration stub the package ships.
     *
     * @return string The stub's absolute path.
     */
    private function stubPath(): string
    {
        return \dirname(__DIR__, 2).'/database/stubs/add_robot_council_columns_to_users_table.php.stub';
    }
}
