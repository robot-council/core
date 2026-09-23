<?php

declare(strict_types=1);

/**
 * `robot-council:doctor`, and the property that every check can actually fail.
 *
 * **A checker that always passes is indistinguishable from a healthy application**, which is the
 * failure mode this file exists to prevent. Every check is exercised in both directions: a
 * configuration it passes and one it fails. A test that only ever asserted the healthy case would
 * pass identically against a command that returned `passed` unconditionally (#103).
 *
 * @command  vendor/bin/pest --compact tests/DoctorTest.php
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use RobotCouncil\Support\Diagnosis;
use RobotCouncil\Support\DiagnosisStatus;
use RobotCouncil\Support\Doctor;

beforeEach(function (): void {
    // **The schema is migrated per test that needs one, not in this hook.**
    // `migrateUsersTableWithPackageColumns()` runs a full `migrate:fresh`, which costs about 12s
    // per test on CI's MySQL container against 4s for this whole file locally. Eight of the
    // fifteen tests below read only configuration and need no tables at all; running it for them
    // took the `mysql` job's test step from 409s to 585s and past its timeout, with every step
    // reporting success.

    // A configuration with nothing wrong with it, so each test below changes exactly one thing and
    // the failure it asserts can only have come from that.
    $this->setAccessLists(developers: [4242]);

    config()->set('auth.guards.sanctum', ['driver' => 'sanctum', 'provider' => 'users']);
    config()->set('sanctum.expiration');
    config()->set('app.timezone', 'UTC');
    config()->set('robot-council.slack.connection', 'redis');
    config()->set('robot-council.slack.webhook_url');

    // Testbench defaults this to `sync`, which the queue check correctly fails. Set to a driver
    // that is not sync so the baseline really is healthy -- otherwise every test below is
    // asserting against a configuration that already has a fault in it.
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.driver', 'database');
});

/**
 * One check's diagnosis, by name.
 *
 * @param  string  $check  The check's name.
 * @return Diagnosis The diagnosis.
 */
function diagnosis(string $check): Diagnosis
{
    foreach (app(Doctor::class)->examine() as $diagnosis) {
        if ($diagnosis->check === $check) {
            return $diagnosis;
        }
    }

    throw new RuntimeException(sprintf('No check named %s. The name changed or the check was dropped.', $check));
}

it('passes every check on a configuration with nothing wrong with it', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // The baseline the rest of the file depends on. If this drifts, every "one thing changed"
    // assertion below is testing two things.
    // Named rather than counted: when this breaks, the diff says which check and why, instead of
    // leaving the next reader to re-derive it.
    $failed = array_values(array_map(
        static fn (Diagnosis $diagnosis): string => $diagnosis->check.': '.$diagnosis->detail,
        array_filter(
            app(Doctor::class)->examine(),
            static fn (Diagnosis $diagnosis): bool => $diagnosis->status === DiagnosisStatus::Failed
        )
    ));

    expect($failed)->toBeEmpty()
        ->and(app(Doctor::class)->examine())->toHaveCount(9);
});

it('fails when the sanctum guard names no provider, and passes when it does', function (): void {
    // The one that was actually wrong on the deployment: a null provider accepts a token belonging
    // to any model, so an agent session token authenticated on the host's own `auth:sanctum`
    // routes -- measured there as 200 where it should have been 401.
    expect(diagnosis('sanctum guard provider')->status)->toBe(DiagnosisStatus::Passed);

    config()->set('auth.guards.sanctum', ['driver' => 'sanctum', 'provider' => null]);

    $failed = diagnosis('sanctum guard provider');

    expect($failed->status)->toBe(DiagnosisStatus::Failed)
        ->and($failed->detail)->toContain('any model');

    // A host with no sanctum guard at all has nothing to leave open, which is not the same as
    // having one that is open
    config()->set('auth.guards.sanctum');

    expect(diagnosis('sanctum guard provider')->status)->toBe(DiagnosisStatus::Passed);
});

it('fails when sanctum.expiration is set, and passes when it is null', function (): void {
    expect(diagnosis('sanctum expiration')->status)->toBe(DiagnosisStatus::Passed);

    config()->set('sanctum.expiration', 60);

    expect(diagnosis('sanctum expiration')->status)->toBe(DiagnosisStatus::Failed);
});

it('fails when a package migration has not run, and passes when they all have', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    expect(diagnosis('package migrations')->status)->toBe(DiagnosisStatus::Passed);

    // Removing the row rather than rolling the migration back: the question is what the
    // `migrations` table says has run, and rolling back would also change the schema, which is a
    // second variable this check does not read.
    $removed = DB::table('migrations')->orderByDesc('id')->value('migration');

    // Narrowed rather than cast: a builder's `value()` is `mixed`, and a name that came back as
    // something else would make the assertion below meaningless rather than failing honestly.
    expect($removed)->toBeString();

    $name = is_string($removed) ? $removed : '';

    DB::table('migrations')->where('migration', $name)->delete();

    $failed = diagnosis('package migrations');

    expect($failed->status)->toBe(DiagnosisStatus::Failed)
        ->and($failed->detail)->toContain($name);
});

it('says the migration check is undetermined when it cannot read what has run', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // **The third state, and the reason it exists.** Three of the wrong readings taken on the
    // deployment came from instruments that could not see what they were reporting on and reported
    // clean. A check that cannot reach its answer has to read differently from one that looked.
    //
    // **The default connection is pointed at an empty database rather than the real schema being
    // dropped.** Dropping `migrations` works on SQLite, where every test gets a fresh in-memory
    // database, and corrupts the next test on anything that persists -- measured on Postgres as
    // `relation "users" already exists` in whichever test ran next. A transaction would not rescue
    // it either, because MySQL commits DDL implicitly. Nothing here touches the real schema.
    config()->set('database.connections.rc_empty_probe', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $default = config('database.default');

    config()->set('database.default', 'rc_empty_probe');

    try {
        $unknown = diagnosis('package migrations');

        expect($unknown->status)->toBe(DiagnosisStatus::Undetermined)
            // It has to say what would make it reachable, not merely that it could not look
            ->and($unknown->detail)->toContain('migrate');
    } finally {
        config()->set('database.default', $default);

        DB::purge('rc_empty_probe');
    }

    // The control: back on the real connection the same check concludes, so the undetermined above
    // is the table being unreadable rather than the check never reaching an answer at all.
    expect(diagnosis('package migrations')->status)->toBe(DiagnosisStatus::Passed);
});

it('fails when the queue would run the mirror inside the request, and passes when it would not', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.driver', 'database');

    expect(diagnosis('queue worker')->status)->toBe(DiagnosisStatus::Passed);

    config()->set('queue.default', 'sync');
    config()->set('queue.connections.sync.driver', 'sync');

    expect(diagnosis('queue worker')->status)->toBe(DiagnosisStatus::Failed);
});

it('says the queue check is undetermined on a driver that keeps no table to read', function (): void {
    config()->set('queue.default', 'sqs');
    config()->set('queue.connections.sqs.driver', 'sqs');

    $unknown = diagnosis('queue worker');

    expect($unknown->status)->toBe(DiagnosisStatus::Undetermined)
        // It has to say what would make it determinable, not merely that it could not
        ->and($unknown->detail)->toContain('sqs');
});

it('fails when a job has waited past the threshold, and passes when one has not', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.driver', 'database');

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'available_at' => now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ]);

    expect(diagnosis('queue worker')->status)->toBe(DiagnosisStatus::Passed);

    DB::table('jobs')->update([
        'created_at' => now()->getTimestamp() - (Doctor::STALE_JOB_SECONDS + 60),
    ]);

    expect(diagnosis('queue worker')->status)->toBe(DiagnosisStatus::Failed);
});

it('fails when the developer allowlist is empty, and passes when it is not', function (): void {
    expect(diagnosis('developer allowlist')->status)->toBe(DiagnosisStatus::Passed);

    $this->setAccessLists(developers: []);

    $failed = diagnosis('developer allowlist');

    expect($failed->status)->toBe(DiagnosisStatus::Failed)
        // Including the reader, which is the part that makes it urgent rather than tidy
        ->and($failed->detail)->toContain('including');
});

it('fails when the slack mirror would run synchronously, and passes when it is queued', function (): void {
    expect(diagnosis('slack queue connection')->status)->toBe(DiagnosisStatus::Passed);

    config()->set('robot-council.slack.connection', 'sync');

    expect(diagnosis('slack queue connection')->status)->toBe(DiagnosisStatus::Failed);
});

it('fails when the application timezone can shift, and passes on UTC', function (): void {
    expect(diagnosis('application timezone')->status)->toBe(DiagnosisStatus::Passed);

    config()->set('app.timezone', 'America/Chicago');

    $failed = diagnosis('application timezone');

    expect($failed->status)->toBe(DiagnosisStatus::Failed)
        ->and($failed->detail)->toContain('America/Chicago');
});

it('reports the slack webhook either way without ever failing on it', function (): void {
    // Not a fault in either direction: a host that wants no mirror is correctly configured. It is
    // reported because "the mirror is off" and "the mirror is broken" are worth telling apart.
    expect(diagnosis('slack webhook')->status)->toBe(DiagnosisStatus::Passed)
        ->and(diagnosis('slack webhook')->detail)->toContain('Not set');

    config()->set('robot-council.slack.webhook_url', 'https://hooks.slack.test/services/PLANTED');

    expect(diagnosis('slack webhook')->status)->toBe(DiagnosisStatus::Passed)
        ->and(diagnosis('slack webhook')->detail)->toContain('Set');
});

it('prints no secret, searched for rather than reasoned about', function (): void {
    // The way `EnrollCommandTest` does it: plant a value that must not appear and search the
    // captured output for it. Reading the code proves nothing about what a future check prints.
    $planted = 'PLANTED-WEBHOOK-'.bin2hex(random_bytes(8));

    config()->set('robot-council.slack.webhook_url', 'https://hooks.slack.test/services/'.$planted);

    // `Artisan::call()` rather than `$this->artisan()`, because only the facade buffers the output
    // where it can be read back. A `PendingCommand` asserts against expectations given in advance,
    // which cannot express "and nothing else, in particular not this".
    Artisan::call('robot-council:doctor');

    $output = Artisan::output();

    expect($output)->not->toContain($planted)
        // The control: the command did produce output, so the absence above is the secret being
        // withheld rather than nothing having been printed at all
        ->and($output)->toContain('slack webhook');
});

it('changes nothing, asserted against every table the package owns', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // A command people run when worried has to be safe to run when worried.
    $tables = [
        'robot_council_installations',
        'robot_council_agent_sessions',
        'robot_council_device_codes',
        'robot_council_events',
        'robot_council_tasks',
        'robot_council_locks',
        'robot_council_github_identities',
    ];

    $before = array_map(static fn (string $table): int => DB::table($table)->count(), $tables);

    Artisan::call('robot-council:doctor');

    $after = array_map(static fn (string $table): int => DB::table($table)->count(), $tables);

    expect($after)->toBe($before);
});

it('exits non-zero when a check failed and zero when none did', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // **Each run is forced to completion before the next line changes anything.** `PendingCommand`
    // defers execution to `__destruct()`, so a command created here and left to the destructor runs
    // AFTER the configuration edits below -- the healthy case would then execute against the broken
    // configuration and fail, for a reason nothing in the test says. `run()` returns the exit code
    // and takes the deferral out of it.
    $healthy = $this->artisan('robot-council:doctor');

    expect($healthy)->toBeInstanceOf(PendingCommand::class);

    if ($healthy instanceof PendingCommand) {
        expect($healthy->run())->toBe(0);
    }

    config()->set('app.timezone', 'America/Chicago');

    $broken = $this->artisan('robot-council:doctor');

    if ($broken instanceof PendingCommand) {
        expect($broken->run())->toBe(1);
    }
});
