<?php

declare(strict_types=1);

/**
 * An enrollment and a rename racing to one machine identity, which only a second connection can show
 * (#550).
 *
 * `Installations::createFrom()` and `Installations::rename()` both hold the developer's whole live set
 * for the harness before deciding anything about a label. Before #550 an enrollment held only the
 * rows already carrying the label, so with none it held nothing, and an enrollment and a rename to
 * the same label both committed a live installation with one identity -- which the next approval for
 * either machine then supersedes together (#106).
 *
 * **The other side of each race runs in a child process**, because the interleaving that matters
 * has this connection blocked in a statement while the other one commits, and one PHP process cannot
 * commit on one connection while it waits on another. The child opens its transaction, takes the
 * rows, writes, and commits only once Postgres reports this connection waiting on a lock -- or after
 * a few seconds when it never does, and then says so in its exit code, so a path that takes no lock
 * fails on the rows AND on the wait rather than hanging.
 *
 * The `cross-connection` group runs only in CI's `postgres` job, and these tests skip themselves on
 * any other engine as well, because the child speaks `pgsql` and reads `pg_stat_activity`.
 *
 * **The child says it is ready by renaming its connection after its statements ran**, not by being
 * idle in a transaction: `BEGIN` alone reads as idle in transaction, so a parent polling for that
 * could start its side before the child held anything, and both would commit. The name carries the
 * parent's pid, and the probe reads only this database, so two runs against one server cannot
 * mistake each other's child for their own.
 *
 * @command  DB_CONNECTION=pgsql vendor/bin/pest --compact --group=cross-connection tests/InstallationIdentityRaceTest.php
 */

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\Installations;
use RobotCouncil\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * The child's program: run the statements in one transaction, then commit once the parent waits.
 *
 * Exit 0 when the parent was seen waiting on a lock before the commit, 4 when it never was and the
 * child committed anyway, and anything else for a failure of the child itself.
 */
const RACE_CHILD = <<<'PHP'
    $config = json_decode(getenv('RC_RACE'), true, flags: JSON_THROW_ON_ERROR);
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
    $connect = static fn (): PDO => new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $writer = $connect();
    $writer->beginTransaction();

    foreach ($config['statements'] as [$sql, $bindings]) {
        $writer->prepare($sql)->execute($bindings);
    }

    // Only now, with every row it took held, does the child say so
    $writer->prepare('select set_config(?, ?, false)')->execute(['application_name', 'rc-race-ready-'.$config['parent']]);

    $watcher = $connect();
    $waiting = $watcher->prepare("select count(*) from pg_stat_activity where pid = ? and wait_event_type = 'Lock'");
    $waited = false;

    for ($tick = 0; $tick < 60; $tick++) {
        $waiting->execute([$config['parent']]);

        if ((int) $waiting->fetchColumn() > 0) {
            $waited = true;

            break;
        }

        usleep(50_000);
    }

    $writer->commit();

    exit($waited ? 0 : 4);
    PHP;

/**
 * Start the child, and return once it holds what its statements took.
 *
 * @param  list<array{string, list<int|string|null>}>  $statements  The SQL and its bindings.
 * @return Process The running child.
 */
function raceChild(array $statements): Process
{
    $connection = Arr::only(
        config()->array('database.connections.'.DB::getDefaultConnection()),
        ['host', 'port', 'database', 'username', 'password']
    );

    $parent = DB::scalar('select pg_backend_pid()');

    if (! is_int($parent)) {
        throw new RuntimeException('Postgres reported no backend pid.');
    }

    $child = new Process([PHP_BINARY, '-r', RACE_CHILD], null, ['RC_RACE' => json_encode([
        ...$connection,
        'statements' => $statements,
        'parent' => $parent,
    ], JSON_THROW_ON_ERROR)]);

    $child->start();

    // The child is ready once its writer sits idle inside the transaction, holding what it took
    for ($tick = 0; $tick < 200; $tick++) {
        $ready = DB::table('pg_stat_activity')
            ->whereRaw('datname = current_database()')
            ->where('application_name', 'rc-race-ready-'.$parent)
            ->where('state', 'idle in transaction')
            ->exists();

        if ($ready) {
            return $child;
        }

        if (! $child->isRunning()) {
            throw new RuntimeException('The race child exited early: '.$child->getErrorOutput().$child->getOutput());
        }

        usleep(25_000);
    }

    $child->stop();

    throw new RuntimeException('The race child never took its rows.');
}

/**
 * The statements that hold one developer's live claude-code set, as either writer takes it.
 *
 * @param  string  $userId  The developer's key.
 * @return array{string, list<int|string|null>} The statement.
 */
function holdTheSet(string $userId): array
{
    return [
        "select id from robot_council_installations where user_id = ? and harness = 'claude-code' and revoked_at is null order by id for update",
        [$userId],
    ];
}

/**
 * The statement an enrollment writes: one live installation of claude-code with that label.
 *
 * @param  string  $userId  The developer's key.
 * @param  string  $label  The label.
 * @return array{string, list<int|string|null>} The statement.
 */
function enrollmentInsert(string $userId, string $label): array
{
    $now = Carbon::now()->toDateTimeString();

    return [
        "insert into robot_council_installations (user_id, harness, machine_label, approved_by, expires_at, created_at, updated_at) values (?, 'claude-code', ?, ?, ?, ?, ?)",
        [$userId, $label, $userId, Carbon::now()->addDays(30)->toDateTimeString(), $now, $now],
    ];
}

/**
 * The live installations of claude-code carrying a label.
 *
 * @param  string  $userId  The developer's key.
 * @param  string  $label  The label.
 * @return int How many.
 */
function liveWithLabel(string $userId, string $label): int
{
    return Installation::query()
        ->where('user_id', $userId)
        ->where('harness', 'claude-code')
        ->where('machine_label', $label)
        ->whereNull('revoked_at')
        ->count();
}

/**
 * A developer with one live claude-code installation, committed where the child can see it.
 *
 * @param  TestCase  $case  The test case.
 * @return array{string, Installation} The developer's key and the installation.
 */
function aDeveloperWithOneMachine(TestCase $case): array
{
    // Another connection has to see these rows committed, so no test transaction (#473)
    $case->migrateFreshSchema();
    $case->setAccessLists(developers: [5501]);

    $developer = $case->enrollDeveloper(5501);

    $installation = $case->approveInstallation($developer, 'office-mac');

    return [HostKey::from($developer->getKey()), $installation];
}

/**
 * Run one side of a race on this connection while the child holds the other.
 *
 * @param  Closure(): mixed  $act  The side this connection runs.
 * @param  Process  $child  The other side.
 * @return array{mixed, int|null} What `$act` returned or threw, and the child's exit code.
 */
function raceAgainst(Closure $act, Process $child): array
{
    try {
        // Bounded, so a deadlock or a missed commit reports itself rather than hanging the suite
        DB::statement("set lock_timeout = '10s'");

        try {
            $outcome = $act();
        } catch (Throwable $thrown) {
            $outcome = $thrown;
        }
    } finally {
        DB::statement('set lock_timeout = default');

        $child->wait();
    }

    return [$outcome, $child->getExitCode()];
}

it('refuses a rename to a label an enrollment committed while the rename waited', function (): void {
    [$userId, $office] = aDeveloperWithOneMachine($this);

    // The enrollment holds the set and creates `laptop`, uncommitted
    $child = raceChild([holdTheSet($userId), enrollmentInsert($userId, 'laptop')]);

    // The rename waits on the set, the enrollment commits, and the rename must then see `laptop`
    [$outcome, $exit] = raceAgainst(
        fn (): mixed => $this->service(Installations::class)->rename($office->id, 'laptop', $userId, false),
        $child
    );

    expect($exit)->toBe(0)
        ->and($outcome)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($outcome instanceof InvalidArgumentException ? $outcome->getMessage() : null)->toContain('already called laptop')
        ->and(liveWithLabel($userId, 'laptop'))->toBe(1)
        ->and($office->refresh()->machine_label)->toBe('office-mac');
})->group('cross-connection')
    ->skip(notPostgres(...), 'Postgres only: the child speaks `pgsql` and reads `pg_stat_activity`.');

it('supersedes a machine a rename gave the label while the enrollment waited', function (): void {
    [$userId, $office] = aDeveloperWithOneMachine($this);

    // The rename holds the set and relabels `office-mac` to `laptop`, uncommitted
    $child = raceChild([
        holdTheSet($userId),
        ['update robot_council_installations set machine_label = ? where id = ?', ['laptop', $office->id]],
    ]);

    $code = new DeviceCode(['harness' => 'claude-code', 'machine_label' => 'laptop']);
    $code->decided_by = $userId;

    // The enrollment must wait on the renamed row, then supersede it as the machine it replaces
    [$outcome, $exit] = raceAgainst(
        fn (): mixed => $this->service(Installations::class)->createFrom($code)->owner,
        $child
    );

    expect($exit)->toBe(0)
        ->and($outcome)->toBeInstanceOf(Installation::class)
        ->and(liveWithLabel($userId, 'laptop'))->toBe(1)
        ->and($office->refresh()->machine_label)->toBe('laptop')
        ->and($office->revoked_at)->not->toBeNull();
})->group('cross-connection')
    ->skip(notPostgres(...), 'Postgres only: the child speaks `pgsql` and reads `pg_stat_activity`.');

it('supersedes an installation another enrollment committed for the label while this one waited', function (): void {
    [$userId, $office] = aDeveloperWithOneMachine($this);

    // Another approval for `laptop` holds the set and creates it, uncommitted
    $child = raceChild([holdTheSet($userId), enrollmentInsert($userId, 'laptop')]);

    $code = new DeviceCode(['harness' => 'claude-code', 'machine_label' => 'laptop']);
    $code->decided_by = $userId;

    [$outcome, $exit] = raceAgainst(
        fn (): mixed => $this->service(Installations::class)->createFrom($code)->owner,
        $child
    );

    expect($exit)->toBe(0)
        ->and($outcome)->toBeInstanceOf(Installation::class)
        ->and(liveWithLabel($userId, 'laptop'))->toBe(1)
        ->and(Installation::query()->whereKey($outcome instanceof Installation ? $outcome->id : null)->whereNull('revoked_at')->exists())->toBeTrue()
        // The machine neither side was about is untouched
        ->and($office->refresh()->revoked_at)->toBeNull();
})->group('cross-connection')
    ->skip(notPostgres(...), 'Postgres only: the child speaks `pgsql` and reads `pg_stat_activity`.');
