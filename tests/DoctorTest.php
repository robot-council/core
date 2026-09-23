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

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use RobotCouncil\Access\Ability;
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
        ->and(app(Doctor::class)->examine())->toHaveCount(10);
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

it('reports whether anything on the fleet can post a directive, passing either way', function (): void {
    // **It reports rather than fails, and both directions are asserted.** A fleet whose agents
    // only ever receive is a legitimate configuration, so a check that failed on one is a check
    // people switch off -- but a check that only ever said the same thing would pass identically
    // against a stub, which is what this file's opening note is about.
    $this->migrateUsersTableWithPackageColumns();

    $quiet = diagnosis('fleet coordination');

    expect($quiet->status)->toBe(DiagnosisStatus::Passed)
        ->and($quiet->detail)->toContain('No installation holds');

    $developer = $this->enrollDeveloper(4242);

    $this->approveInstallation($developer, [Ability::CoordinatorDirect->value], 'coordinator-machine');

    $granted = diagnosis('fleet coordination');

    expect($granted->status)->toBe(DiagnosisStatus::Passed)
        ->and($granted->detail)->toContain('At least one installation holds');
});

it('says fleet coordination is undetermined when it cannot read the installations', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // The same probe the migration check uses, and for the same reason: pointing the default
    // connection at an empty database rather than dropping the real schema, which would corrupt
    // whichever test ran next on an engine that persists.
    config()->set('database.connections.rc_empty_probe', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $default = config('database.default');

    config()->set('database.default', 'rc_empty_probe');

    try {
        $unknown = diagnosis('fleet coordination');

        expect($unknown->status)->toBe(DiagnosisStatus::Undetermined)
            ->and($unknown->detail)->toContain('migrate');
    } finally {
        config()->set('database.default', $default);

        DB::purge('rc_empty_probe');
    }

    // The control: back on the real connection it concludes, so the undetermined above is the
    // table being unreadable rather than the check never reaching an answer.
    expect(diagnosis('fleet coordination')->status)->toBe(DiagnosisStatus::Passed);
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

it('names an installation whose stored abilities cannot be read back, and passes when none is', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $developer = $this->enrollDeveloper(4242);
    $installation = $this->approveInstallation($developer, [Ability::TasksCreate->value]);

    // The control first, and before anything is planted: a clean fleet has to pass, or the failure
    // asserted below could be this check failing on every fleet it will ever see.
    expect(diagnosis('stored abilities')->status)->toBe(DiagnosisStatus::Passed);

    DB::table('robot_council_installations')
        ->where('id', $installation->getKey())
        ->update(['granted_abilities' => (string) json_encode([Ability::TasksCreate->value, null])]);

    $failed = diagnosis('stored abilities');

    expect($failed->status)->toBe(DiagnosisStatus::Failed)
        ->and($failed->detail)->toContain((string) $installation->id)
        ->and($failed->detail)->toContain('not a list of ability names');
});

it('tells a retired ability name apart from a malformed value, because the repairs differ', function (): void {
    // **The discrimination is the point of the check, not a refinement of it.** A well-formed
    // string the fixed list no longer holds means the row was written when that ability existed and
    // wants rewriting; a value that is not a string means something wrote a shape this package
    // never writes, and the repair is to find what did. Reported together, the reader has to guess.
    $this->migrateUsersTableWithPackageColumns();

    $developer = $this->enrollDeveloper(4242);
    $retired = $this->approveInstallation($developer, [Ability::TasksCreate->value], 'retired-machine');

    // `sessions:start` is a real enum member that `grantable()` deliberately excludes, which is
    // exactly the shape a retired name takes: present, spelled correctly, no longer granted.
    DB::table('robot_council_installations')
        ->where('id', $retired->getKey())
        ->update(['granted_abilities' => (string) json_encode([Ability::SessionsStart->value])]);

    $only = diagnosis('stored abilities');

    expect($only->status)->toBe(DiagnosisStatus::Failed)
        ->and($only->detail)->toContain('does not grant')
        ->and($only->detail)->toContain('robot-council:grant-ability')
        // And NOT the other cause, which is the half a single combined message would blur.
        ->and($only->detail)->not->toContain('not a list of ability names');

    $malformed = $this->approveInstallation($developer, [Ability::TasksCreate->value], 'malformed-machine');

    DB::table('robot_council_installations')
        ->where('id', $malformed->getKey())
        ->update(['granted_abilities' => 'null']);

    // **A third installation, ordered after the malformed one, and that ordering is the point.**
    // The scan `continue`s past a row whose container is unreadable. Turn that into a `break` and
    // everything with a higher id goes unreported -- which a fixture whose malformed row happens to
    // be last cannot see, because stopping there and carrying on give the same answer.
    $later = $this->approveInstallation($developer, [Ability::TasksCreate->value], 'later-machine');

    DB::table('robot_council_installations')
        ->where('id', $later->id)
        ->update(['granted_abilities' => (string) json_encode([Ability::SessionsStart->value])]);

    $both = diagnosis('stored abilities');

    expect($both->detail)->toContain('does not grant')
        ->and($both->detail)->toContain('not a list of ability names')
        ->and($both->detail)->toContain((string) $retired->id)
        ->and($both->detail)->toContain((string) $malformed->id)
        ->and($both->detail)->toContain((string) $later->id);
});

it('reports one row under one cause, whichever of its entries comes first', function (): void {
    // **Each row stops at its first fault, and both `break`s are load-bearing.** Let the scan carry
    // on and a row holding a bad entry of each kind is reported under BOTH causes -- which reads as
    // two problems where there is one, and tells the operator to do two different repairs.
    //
    // Asserted in both orders, because a single order only pins whichever `break` that order
    // reaches first.
    $this->migrateUsersTableWithPackageColumns();

    $developer = $this->enrollDeveloper(4242);
    $installation = $this->approveInstallation($developer, [Ability::TasksCreate->value]);

    // Malformed entry first: reported as malformed, and NOT also as retired.
    DB::table('robot_council_installations')
        ->where('id', $installation->id)
        ->update(['granted_abilities' => (string) json_encode([null, Ability::SessionsStart->value])]);

    $malformedFirst = diagnosis('stored abilities');

    expect($malformedFirst->detail)->toContain('not a list of ability names')
        ->and($malformedFirst->detail)->not->toContain('does not grant');

    // Retired entry first: reported as retired, and NOT also as malformed.
    DB::table('robot_council_installations')
        ->where('id', $installation->id)
        ->update(['granted_abilities' => (string) json_encode([Ability::SessionsStart->value, null])]);

    $retiredFirst = diagnosis('stored abilities');

    expect($retiredFirst->detail)->toContain('does not grant')
        ->and($retiredFirst->detail)->not->toContain('not a list of ability names');
});

it('says nothing about an installation that is revoked, nor one that has expired', function (): void {
    // It reads `Installation::usable()`, so a credential that already cannot act is not a fault to
    // report. Without this the check would name every historical row a fleet ever held.
    $this->migrateUsersTableWithPackageColumns();

    $developer = $this->enrollDeveloper(4242);
    $revoked = $this->approveInstallation($developer, [Ability::TasksCreate->value], 'revoked-machine');

    DB::table('robot_council_installations')
        ->where('id', $revoked->getKey())
        ->update([
            'granted_abilities' => (string) json_encode([null]),
            'revoked_at' => Carbon::now(),
        ]);

    expect(diagnosis('stored abilities')->status)->toBe(DiagnosisStatus::Passed);

    // The control: the same row, unrevoked, is reported -- so the pass above is the `usable()`
    // filter and not the check failing to see a planted value at all.
    DB::table('robot_council_installations')->where('id', $revoked->id)->update(['revoked_at' => null]);

    expect(diagnosis('stored abilities')->status)->toBe(DiagnosisStatus::Failed);

    // **The expired half, which the name promised and an earlier version did not exercise.**
    // `usable()` reads two columns, and a filter written against one passes a test that only moves
    // the other -- so both are moved here, with the revoked flag put back first.
    DB::table('robot_council_installations')
        ->where('id', $revoked->id)
        ->update(['expires_at' => Carbon::now()->subMinute()]);

    expect(diagnosis('stored abilities')->status)->toBe(DiagnosisStatus::Passed);
});

it('says the stored-abilities check is undetermined when it cannot read the installations', function (): void {
    // **The third direction, and the one the other database-reading checks both have.** Without it
    // the entire catch branch could be deleted and the suite would stay green.
    //
    // The same probe `migrations()` and `coordination()` use: point the default connection at an
    // empty database rather than dropping the real schema, which would corrupt whichever test ran
    // next on an engine that persists.
    config()->set('database.connections.rc_empty_probe', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $default = config('database.default');

    config()->set('database.default', 'rc_empty_probe');

    try {
        $unknown = diagnosis('stored abilities');

        expect($unknown->status)->toBe(DiagnosisStatus::Undetermined)
            ->and($unknown->detail)->toContain('migrate');
    } finally {
        config()->set('database.default', $default);
    }

    // The control, back on the real connection: a migrated database with nothing wrong answers
    // Passed, so the Undetermined above is the missing table and not this check's resting state.
    $this->migrateUsersTableWithPackageColumns();

    expect(diagnosis('stored abilities')->status)->toBe(DiagnosisStatus::Passed);
});

it('prints no row contents, only the installation that holds them', function (): void {
    // `Diagnosis` output is read by whoever is worried. An abilities column is not a secret, but
    // the habit is: say which row, never what is in it.
    $this->migrateUsersTableWithPackageColumns();

    $developer = $this->enrollDeveloper(4242);
    $installation = $this->approveInstallation($developer, [Ability::TasksCreate->value]);

    DB::table('robot_council_installations')
        ->where('id', $installation->getKey())
        ->update(['granted_abilities' => (string) json_encode(['a-distinctive-planted-value'])]);

    $detail = diagnosis('stored abilities')->detail;

    expect($detail)->not->toContain('a-distinctive-planted-value')
        // The positive control for the search itself: the id it SHOULD name is present, so an
        // assertion passing above cannot mean the detail was empty.
        ->and($detail)->toContain((string) $installation->id);
});

it('cuts the list of named installations and counts the rest', function (): void {
    // **This surface was added to bound one console line, and arrived with no test at all.** Twelve
    // mutants survived in the helper -- unwrapping the slice, moving the offset, flipping the
    // remainder's sign and its comparison -- because nothing in the suite had more than a handful of
    // faulty rows. A bound nobody exercises is a bound nobody has.
    $this->migrateUsersTableWithPackageColumns();

    $developer = $this->enrollDeveloper(4242);
    $key = keyValue($developer->getKey());
    $limit = Doctor::MAX_NAMED_INSTALLATIONS;

    $rows = [];

    for ($i = 0; $i < $limit + 2; $i++) {
        $rows[] = [
            'user_id' => $key,
            'harness' => 'claude-code',
            'machine_label' => 'machine-'.$i,
            'granted_abilities' => 'null',
            'approved_by' => $key,
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }

    DB::table('robot_council_installations')->insert($rows);

    $ids = DB::table('robot_council_installations')->orderBy('id')->pluck('id')->all();

    $detail = diagnosis('stored abilities')->detail;

    // **The listed ids are parsed out rather than searched for as substrings.** A first version
    // asserted `not->toContain('22')` for the last id and failed on the COUNT, which is also 22 --
    // the aggregate and the detail sharing a number is exactly what a substring match cannot tell
    // apart.
    preg_match('/find what did: ([0-9, ]+?)(?: and \d+ more)?\./', $detail, $matches);

    $listed = array_map(intval(...), explode(', ', trim($matches[1] ?? '')));

    expect($detail)
        // The count is exact even though the list is not.
        ->toContain(($limit + 2).' installation(s)')
        // The remainder is counted, and counted right -- a sign flip reads 44 here rather than 2.
        ->and($detail)->toContain('and 2 more')
        // Exactly `$limit` ids, taken from the front: moving the slice's offset drops the first.
        ->and($listed)->toHaveCount($limit)
        ->and($listed[0])->toBe($ids[0])
        ->and($listed)->not->toContain($ids[$limit])
        ->and($listed)->not->toContain($ids[$limit + 1]);

    // **One over the limit, which is the only input that separates `> 0` from `> 1`.** With two
    // extra rows above and none below, an off-by-one in the remainder is invisible.
    DB::table('robot_council_installations')->where('id', $ids[$limit + 1])->delete();

    expect(diagnosis('stored abilities')->detail)->toContain('and 1 more');

    // Exactly at the limit: nothing is cut, so no remainder clause at all. This is what separates
    // `> 0` from `>= 0`, which otherwise differ on no input the suite supplies.
    DB::table('robot_council_installations')->where('id', $ids[$limit])->delete();

    $atLimit = diagnosis('stored abilities')->detail;

    // Parsed, not searched: the empty branch of that ternary is what runs here, and a non-empty
    // string in its place appends a character to the last id rather than adding a word -- which
    // `not->toContain(' more')` cannot see, and which breaks this parse.
    preg_match('/find what did: ([0-9, ]+?)(?: and \d+ more)?\./', $atLimit, $exact);

    expect($atLimit)->not->toContain(' more')
        ->and(array_map(intval(...), explode(', ', trim($exact[1] ?? ''))))->toHaveCount($limit);
});
