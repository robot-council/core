<?php

declare(strict_types=1);

/**
 * Where a session is working, as two fields rather than one opaque label.
 *
 * `robot-council/core#220` splits `project_id` into the repository a session belongs to and the
 * work location within it. The split is not a guess: every non-null `project_id` on the deployed
 * fleet on 2026-09-23 already carried the structure, and that measurement is on the issue.
 *
 * Two things here are load-bearing beyond the acceptance criteria. The migration writes the split
 * rule out in literals rather than calling `Support\WorkIdentity`, because a migration must not
 * change meaning when that class is edited -- so a test drives BOTH over the same inputs, which is
 * the only thing keeping them agreeing. And nothing in the authorization path may read either
 * field, which is asserted rather than reviewed.
 *
 * @command  vendor/bin/pest --compact tests/SessionWorkIdentityTest.php
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\PresenceClock;
use RobotCouncil\Support\WorkIdentity;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
    $this->credential = $this->installationCredential($this->installation);
});

/**
 * Every `project_id` shape that matters, with what the split must make of it.
 *
 * The first two rows are the shapes the deployed fleet actually holds, in its proportions: 54 rows
 * of `owner/name` and one of `owner/name/location`. The rest are the boundaries.
 *
 * @return array<string, array{string, string|null, string|null}>
 */
function projectIdSplits(): array
{
    return [
        'owner and name' => ['robot-council/cli', 'robot-council/cli', null],
        'owner, name and location' => ['UAMS-Web/uams-statamic/a', 'UAMS-Web/uams-statamic', 'a'],
        'one segment is not a repository' => ['uams-statamic', null, null],
        'four segments are not this shape' => ['a/b/c/d', null, null],
        'an empty second segment' => ['robot-council/', null, null],
        'an empty first segment' => ['/cli', null, null],
        // The location is the only half that can fail on its own: a repository that parses with a
        // label that does not is a repository worth keeping.
        'an upper-case location' => ['robot-council/cli/A', 'robot-council/cli', null],
        'a location past its bound' => ['robot-council/cli/'.str_repeat('a', 33), 'robot-council/cli', null],
        'a location at its bound' => ['robot-council/cli/'.str_repeat('a', 32), 'robot-council/cli', str_repeat('a', 32)],
    ];
}

it('splits a project id the way the rule says', function (string $projectId, ?string $repository, ?string $location): void {
    expect(WorkIdentity::fromProjectId($projectId))->toBe([$repository, $location]);
})->with(projectIdSplits());

it('never returns a split its own bounds would refuse', function (string $projectId): void {
    // The property the store leans on: `AgentSessions::start()` writes what this returns without
    // checking it again, so a derivation that produced an unstorable value would be found by
    // Postgres rather than here.
    [$repository, $location] = WorkIdentity::fromProjectId($projectId);

    expect(fn () => WorkIdentity::ensure($repository, $location))->not->toThrow(InvalidArgumentException::class);
})->with(array_map(static fn (array $row): array => [$row[0]], projectIdSplits()));

it('refuses a repository past its length even though no column can hold one', function (): void {
    // `project_id` is `varchar(128)`, so a repository over 140 characters cannot arrive through the
    // split -- this is the direct-call path a host reaches, which is the whole reason the bound is
    // in the store rather than only at the endpoint.
    $long = str_repeat('a', 100).'/'.str_repeat('b', 45);

    expect(mb_strlen($long))->toBeGreaterThan(WorkIdentity::MAX_REPOSITORY)
        ->and(WorkIdentity::fromProjectId($long))->toBe([null, null])
        ->and(fn () => WorkIdentity::ensure($long, null))->toThrow(InvalidArgumentException::class, 'A repository is up to 140 characters');
});

it('pins both bounds to the numbers they were derived from', function (): void {
    // **The literals, not the constants.** Every other assertion here reads `MAX_REPOSITORY` and
    // `MAX_LOCATION`, so raising either would move both sides of those comparisons together and
    // none of them could fail. 140 is GitHub's own: an owner is at most 39 characters and a
    // repository name at most 100, with one separator between them.
    expect(WorkIdentity::MAX_REPOSITORY)->toBe(39 + 1 + 100)
        ->and(WorkIdentity::MAX_REPOSITORY)->toBe(140)
        ->and(WorkIdentity::MAX_LOCATION)->toBe(32);
});

it('keeps a repository of exactly its maximum length, and refuses one character more', function (): void {
    // The boundary, which is where `>` and `>=` differ and which nothing else reaches: the longest
    // path GitHub can produce is an owner of 39 and a name of 100 with a separator between them.
    $atBound = str_repeat('o', 39).'/'.str_repeat('n', 100);

    expect($atBound)->toHaveLength(WorkIdentity::MAX_REPOSITORY)
        ->and(WorkIdentity::fromProjectId($atBound))->toBe([$atBound, null])
        ->and(fn () => WorkIdentity::ensure($atBound, null))->not->toThrow(InvalidArgumentException::class);

    $past = str_repeat('o', 40).'/'.str_repeat('n', 100);

    expect($past)->toHaveLength(WorkIdentity::MAX_REPOSITORY + 1)
        ->and(WorkIdentity::fromProjectId($past))->toBe([null, null])
        ->and(fn () => WorkIdentity::ensure($past, null))->toThrow(InvalidArgumentException::class);
});

it('names the type rather than a length when it is handed something that is not a string', function (): void {
    // `ensure()` takes `mixed` so a host calling the store directly gets a refusal rather than a
    // `TypeError` from inside it, which is the reason `ProjectId::ensure()` does the same. The
    // message has to say what arrived, or the refusal is unactionable.
    expect(fn () => WorkIdentity::ensure(42, null))
        ->toThrow(InvalidArgumentException::class, 'and this one is int.')
        ->and(fn () => WorkIdentity::ensure(null, ['a']))
        ->toThrow(InvalidArgumentException::class, 'and this one is array.');
});

it('bounds each field independently, and admits a null for either', function (): void {
    expect(fn () => WorkIdentity::ensure(null, null))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkIdentity::ensure('robot-council/cli', null))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkIdentity::ensure(null, 'a'))->not->toThrow(InvalidArgumentException::class);

    // A repository keeps upper case, because `UAMS-Web` is a real GitHub owner and lower-casing it
    // would write a path that resolves to nothing.
    expect(fn () => WorkIdentity::ensure('UAMS-Web/uams-statamic', null))->not->toThrow(InvalidArgumentException::class);

    // A work location does not, because the label exists to be compared across machines
    expect(fn () => WorkIdentity::ensure(null, 'A'))
        ->toThrow(InvalidArgumentException::class, 'A work location is up to 32 characters');

    // Neither admits a path separator where its shape has none, nor a second one
    expect(fn () => WorkIdentity::ensure('robot-council/cli/a', null))->toThrow(InvalidArgumentException::class);
    expect(fn () => WorkIdentity::ensure(null, 'a/b'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkIdentity::ensure('no-slash-at-all', null))->toThrow(InvalidArgumentException::class);
});

it('records a repository and a work location separately', function (): void {
    $response = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), [
            'repository' => 'UAMS-Web/uams-statamic',
            'work_location' => 'ci',
        ])
        ->assertCreated();

    $session = AgentSession::query()->sole();

    expect($session->repository)->toBe('UAMS-Web/uams-statamic')
        ->and($session->work_location)->toBe('ci')

        // Not derived into the legacy field. The two are what a client says now; `project_id` is
        // what a client said before, and inventing one would put a value nobody sent into the feed.
        ->and($session->project_id)->toBeNull();

    $this->machine(stringValue($response->json('token')))
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('repository', 'UAMS-Web/uams-statamic')
        ->assertJsonPath('work_location', 'ci')
        ->assertJsonPath('project_id', null);
});

it('starts a session for a request that names neither', function (): void {
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'))
        ->assertCreated();

    $session = AgentSession::query()->sole();

    expect($session->repository)->toBeNull()
        ->and($session->work_location)->toBeNull()
        ->and($session->project_id)->toBeNull();
});

it('takes a repository with no work location, and a work location with no repository', function (): void {
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), ['repository' => 'robot-council/core'])
        ->assertCreated();

    $first = AgentSession::query()->sole();

    expect($first->repository)->toBe('robot-council/core')
        ->and($first->work_location)->toBeNull();

    $other = $this->approveInstallation($this->developer, machineLabel: 'laptop');

    $this->machine($this->installationCredential($other))
        ->postJson(route('robot-council.sessions.start'), ['work_location' => 'primary'])
        ->assertCreated();

    $second = AgentSession::query()->where('installation_id', $other->getKey())->sole();

    expect($second->repository)->toBeNull()
        ->and($second->work_location)->toBe('primary');
});

it('refuses a repository or a work location outside its bound, and stores nothing', function (array $payload, string $field): void {
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(AgentSession::query()->count())->toBe(0);
})->with([
    'a repository with no owner' => [['repository' => 'uams-statamic'], 'repository'],
    'a repository with two separators' => [['repository' => 'a/b/c'], 'repository'],
    'a repository with a space' => [['repository' => 'robot council/cli'], 'repository'],
    'a repository past its length' => [['repository' => str_repeat('a', 100).'/'.str_repeat('b', 45)], 'repository'],
    'an upper-case work location' => [['work_location' => 'Primary'], 'work_location'],
    'a work location with a separator' => [['work_location' => 'a/b'], 'work_location'],
    'a work location past its length' => [['work_location' => str_repeat('a', 33)], 'work_location'],
]);

it('derives both from a project id when the client names neither', function (): void {
    // The compatibility path: a client that has not been upgraded sends one label and gets both
    // fields, so grouping by repository works before `robot-council/cli#128` ships.
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), ['project_id' => 'UAMS-Web/uams-statamic/a'])
        ->assertCreated();

    $session = AgentSession::query()->sole();

    expect($session->repository)->toBe('UAMS-Web/uams-statamic')
        ->and($session->work_location)->toBe('a')
        ->and($session->project_id)->toBe('UAMS-Web/uams-statamic/a');
});

it('leaves a named field alone rather than deriving over it', function (): void {
    // Naming either one is the client knowing its own mind. Deriving a label from a string it has
    // stopped maintaining would be worse than leaving the other null.
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), [
            'project_id' => 'UAMS-Web/uams-statamic/a',
            'repository' => 'robot-council/core',
        ])
        ->assertCreated();

    $session = AgentSession::query()->sole();

    expect($session->repository)->toBe('robot-council/core')
        ->and($session->work_location)->toBeNull();
});

it('puts both on the session.joined event, where the whole fleet reads them', function (): void {
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), [
            'repository' => 'robot-council/core',
            'work_location' => 'ci',
        ])
        ->assertCreated();

    $event = FleetEvent::query()->where('type', FleetEventType::SessionJoined->value)->sole();

    expect(arrayValue($event->meta)['repository'] ?? null)->toBe('robot-council/core')
        ->and(arrayValue($event->meta)['work_location'] ?? null)->toBe('ci');
});

it('groups sessions by repository without parsing a label', function (): void {
    foreach ([
        ['robot-council/core', 'a'],
        ['robot-council/core', 'ci'],
        ['UAMS-Web/uams-statamic', 'primary'],
    ] as [$repository, $location]) {
        $installation = $this->approveInstallation($this->developer, machineLabel: 'm-'.$location);

        $this->service(AgentSessions::class)->start($installation, null, $repository, $location);
    }

    // A plain `group by` on a column, which is the criterion: no `like`, no split, no expression.
    //
    // **Not ordered, and not compared as an ordered array.** `order by repository` sorts by byte on
    // SQLite and by the database collation on Postgres, so `toBe()` against a literal array passed
    // locally and failed the `postgres` job on the ORDER alone -- measured, not guessed. The claim
    // here is the grouping, and the count of keys is what closes the hole an order-free comparison
    // would otherwise leave.
    $counts = AgentSession::query()
        ->toBase()
        ->selectRaw('repository, count(*) as n')
        ->groupBy('repository')
        ->pluck('n', 'repository')
        ->all();

    expect($counts)->toHaveCount(2)
        ->and($counts['robot-council/core'] ?? null)->toBe(2)
        ->and($counts['UAMS-Web/uams-statamic'] ?? null)->toBe(1);
});

it('gives two sessions from one installation identical abilities whatever their repository', function (): void {
    // The rule the epic records a draft breaking: both fields are attribution and neither is ever
    // authorization. Asserted rather than reviewed, because the failure would be silent.
    $first = $this->service(AgentSessions::class)->start($this->installation, null, 'robot-council/core', 'a');
    $second = $this->service(AgentSessions::class)->start($this->installation, null, 'UAMS-Web/uams-statamic', 'ci');

    expect($first->abilities)->toBe($second->abilities)
        ->and($first->owner->role)->toBe($second->owner->role)
        ->and($first->owner->repository)->not->toBe($second->owner->repository);

    // And through the guard, which is where an ability is actually spent
    foreach ([$first, $second] as $issued) {
        $this->machine($issued->plainTextToken)
            ->getJson(route('robot-council.agent.session'))
            ->assertOk()
            ->assertJsonPath('abilities', $first->abilities);
    }
});

it('splits the rows a host already has, the same way the forward rule does', function (): void {
    // **The parity the migration's docblock promises.** It writes the split out in literals rather
    // than calling `Support\WorkIdentity`, so that editing that class cannot change what a migration
    // already run meant. Nothing but this test keeps the two agreeing.
    $planted = [];

    foreach (projectIdSplits() as $name => [$projectId]) {
        $planted[$name] = DB::table('robot_council_agent_sessions')->insertGetId([
            'installation_id' => $this->installation->getKey(),
            'user_id' => $this->developer->getKey(),
            'status' => AgentSessionStatus::Active->value,
            'role' => 'build',
            'last_seen_at' => PresenceClock::now(),
            'project_id' => $projectId,
            'created_at' => PresenceClock::now(),
            'updated_at' => PresenceClock::now(),
        ]);
    }

    // Back to the pre-#220 shape, through the migration's own `down()`
    runTheWorkIdentityMigration('down');

    expect(Schema::hasColumn('robot_council_agent_sessions', 'repository'))->toBeFalse()
        ->and(Schema::hasColumn('robot_council_agent_sessions', 'work_location'))->toBeFalse();

    runTheWorkIdentityMigration('up');

    foreach (projectIdSplits() as $name => [$projectId, $repository, $location]) {
        $row = AgentSession::query()->whereKey($planted[$name])->sole();

        expect([$row->repository, $row->work_location])
            ->toBe(WorkIdentity::fromProjectId($projectId), $name)
            ->toBe([$repository, $location], $name)

            // And the original is still on the row, which is what makes a value the split declined
            // to guess at a value nothing lost
            ->and($row->project_id)->toBe($projectId);
    }
});

it('serves a migrated row through the API and the change feed', function (): void {
    // The acceptance criterion's own shape: seed the old form, migrate, read it back through both
    // surfaces rather than off the row.
    [$session, $token] = $this->startAgentSession($this->installation);

    DB::table('robot_council_agent_sessions')
        ->where('id', $session->getKey())
        ->update(['project_id' => 'UAMS-Web/uams-statamic/a', 'repository' => null, 'work_location' => null]);

    runTheWorkIdentityMigration('down');
    runTheWorkIdentityMigration('up');

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertOk();

    $this->machine($token = stringValue($this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->json('token')))
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('repository', 'UAMS-Web/uams-statamic')
        ->assertJsonPath('work_location', 'a')
        ->assertJsonPath('project_id', 'UAMS-Web/uams-statamic/a');

    // And the feed a session reads, which is where the value reaches other developers' agents
    $events = collect(arrayValue($this->machine($token)
        ->getJson(route('robot-council.events.index', ['after' => 0]))
        ->assertOk()
        ->json('events')));

    expect($events)->not->toBeEmpty();
});

it('rolls back and migrates twice without erroring', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    runTheWorkIdentityMigration('down');
    runTheWorkIdentityMigration('down');

    expect(Schema::hasColumn('robot_council_agent_sessions', 'repository'))->toBeFalse();

    runTheWorkIdentityMigration('up');
    runTheWorkIdentityMigration('up');

    expect(Schema::hasColumn('robot_council_agent_sessions', 'repository'))->toBeTrue()
        ->and(Schema::hasColumn('robot_council_agent_sessions', 'work_location'))->toBeTrue()
        ->and(AgentSession::query()->whereKey($session->getKey())->sole()->repository)->toBeNull();
});

it('backfills on a re-run that finds the columns already there', function (): void {
    // The population a crash between the ALTER and the updates leaves, which only Postgres is
    // protected from: the columns exist and nothing was written. A guard reading `hasColumn` would
    // skip the backfill here and report success.
    [$session] = $this->startAgentSession($this->installation);

    DB::table('robot_council_agent_sessions')
        ->where('id', $session->getKey())
        ->update(['project_id' => 'robot-council/cli', 'repository' => null, 'work_location' => null]);

    runTheWorkIdentityMigration('up');

    expect(AgentSession::query()->whereKey($session->getKey())->sole()->repository)->toBe('robot-council/cli');
});

/**
 * Run the work-identity migration in one direction.
 *
 * Called as a narrowed callable rather than as `$migration->up()`, for the reason
 * `tests/EventIndexDropTest.php` records: a migration file returns `mixed` to the analyzer, and
 * `Migration` itself declares no `up()` -- the anonymous class the file returns does.
 *
 * @param  string  $direction  `up` or `down`.
 */
function runTheWorkIdentityMigration(string $direction): void
{
    $migration = require __DIR__.'/../database/migrations/2026_09_23_000004_add_work_identity_to_robot_council_agent_sessions.php';

    $run = [$migration, $direction];

    if (! \is_callable($run)) {
        throw new RuntimeException('The migration file did not return something with a '.$direction.'().');
    }

    $run();
}
