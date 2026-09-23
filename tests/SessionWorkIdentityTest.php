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
    //
    // **Not written as `->not->toThrow(...)`.** Pest's `OppositeExpectation::__call()` catches the
    // assertion failure that `toThrow()` raises for a MISMATCHED throwable and reads the catch as a
    // pass -- so `not->toThrow(InvalidArgumentException::class)` is satisfied by a `TypeError` as
    // readily as by nothing being thrown, and this test's whole subject is that nothing goes wrong
    // in `ensure()`. Calling it directly and asserting afterwards distinguishes the two: an
    // unexpected throwable leaves the test erroring rather than passing.
    [$repository, $location] = WorkIdentity::fromProjectId($projectId);

    // `ensure()` throws on anything outside the charset, so reaching the line below is half the
    // property. The other half is the length bound, asserted rather than assumed -- and asserted as
    // a measurement rather than a type check, which PHPStan can already prove and therefore calls
    // redundant. `(string) null` is the empty string, so a null side passes trivially and honestly.
    WorkIdentity::ensure($repository, $location);

    expect(mb_strlen((string) $repository))->toBeLessThanOrEqual(WorkIdentity::MAX_REPOSITORY)
        ->and(mb_strlen((string) $location))->toBeLessThanOrEqual(WorkIdentity::MAX_LOCATION);
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

it('refuses a trailing newline in either field, which is what the /D modifier is for', function (): void {
    // Without `/D`, `$` matches before a final newline and both fields would admit one -- straight
    // into the `session.joined` event every agent in the fleet reads. `ProjectId`'s docblock calls
    // that "the whole point of a charset limit at an edge", and nothing exercised it for these two.
    foreach (["robot-council/core\n", "robot-council/core\r\n"] as $value) {
        expect(fn () => WorkIdentity::ensure($value, null))->toThrow(InvalidArgumentException::class)
            ->and(WorkIdentity::fromProjectId($value))->toBe([null, null]);
    }

    foreach (["ci\n", "ci\r\n"] as $value) {
        expect(fn () => WorkIdentity::ensure(null, $value))->toThrow(InvalidArgumentException::class);
    }

    // The control: the same values without the newline are accepted, so the refusals above are the
    // modifier doing its job rather than the charset refusing `robot-council/core` outright.
    expect(fn () => WorkIdentity::ensure('robot-council/core', 'ci'))->not->toThrow(InvalidArgumentException::class);

    // **Through the endpoint it is accepted, and that is not a hole -- it is a second edge.**
    // Laravel's `TrimStrings` is GLOBAL rather than part of a route group, and this package's
    // `api_middleware` is empty, so the newline is gone before validation sees it and the stored
    // value is clean. Asserted on the COLUMN rather than on the status, because a 201 alone would
    // not say whether the newline landed.
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), ['repository' => "robot-council/core\n"])
        ->assertCreated();

    expect(AgentSession::query()->sole()->repository)->toBe('robot-council/core');

    // Which makes `/D` in the pattern the guarantee for every caller that is NOT a request: a host
    // resolving the store and passing an untrimmed value, which the assertions above cover.
});

it('refuses a traversal-shaped or flag-shaped value in either field', function (string $repository, ?string $location): void {
    // Both values are broadcast to every agent in the fleet through `session.joined`, where
    // `CLAUDE.md` records that event content is untrusted input to something that may have shell
    // access. A consumer joining `../..` into a path gets traversal; one passing `-rf` to a command
    // gets a flag. Neither is a repository or a checkout label, so neither is admitted.
    expect(fn () => WorkIdentity::ensure($repository === '' ? null : $repository, $location))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'a repository of dots' => ['../..', null],
    'a single-dot repository' => ['./.', null],
    'a repository segment of dots' => ['a/..', null],
    'a leading hyphen in a repository' => ['-x/-y', null],
    'a work location of dots' => ['', '..'],
    'a single-dot work location' => ['', '.'],
    'a flag-shaped work location' => ['', '-rf'],
]);

it('still admits a leading dot, because .github is a real name', function (): void {
    // The narrowing above must not take a real value with it, which is what a bare "no dots" rule
    // would have done.
    expect(fn () => WorkIdentity::ensure('.github/workflows', '.hidden'))->not->toThrow(InvalidArgumentException::class);
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

it('bounds both fields on the session store too, which writes the same columns', function (): void {
    // **Through the store, never the endpoint.** `CLAUDE.md` states the rule and the suite already
    // has the sibling for `project_id` in `TaskLifecycleTest`: every bound here is a public method
    // on a `final` class a host can resolve and call, so a rule in the controller protects the
    // endpoint and nothing else. Deleting `WorkIdentity::ensure()` from `start()` leaves all seven
    // of the 422 rows above green, because none of them reaches the store.
    $sessions = $this->service(AgentSessions::class);

    expect(fn () => $sessions->start($this->installation, null, 'no-slash-at-all'))
        ->toThrow(InvalidArgumentException::class, 'A repository is up to 140 characters')
        ->and(fn () => $sessions->start($this->installation, null, null, 'Primary'))
        ->toThrow(InvalidArgumentException::class, 'A work location is up to 32 characters');

    // Nothing was written by either refusal, which is what makes the bound a guarantee rather than
    // a message
    expect(AgentSession::query()->count())->toBe(0);

    // The control beside it: an in-bound pair goes through, so the two refusals above are the
    // bound rather than the store being broken
    $sessions->start($this->installation, null, 'robot-council/core', 'ci');

    expect(AgentSession::query()->count())->toBe(1);
});

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

    // And the derived pair reaches the feed, which is the surface every other agent reads. The
    // event test above uses client-supplied values, so without this the path "three segments in,
    // a location out, into `meta`" is covered nowhere.
    $event = FleetEvent::query()->where('type', FleetEventType::SessionJoined->value)->sole();

    expect(arrayValue($event->meta)['repository'] ?? null)->toBe('UAMS-Web/uams-statamic')
        ->and(arrayValue($event->meta)['work_location'] ?? null)->toBe('a');
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
    //
    // **What it does NOT cover, said so it is not over-read.** It compares the values the two
    // derive, not the writes they perform, and those differ on one axis: the forward rule returns
    // `[null, null]` for a value that does not split, while the migration leaves the columns alone.
    // This test cannot see that, because it drops both columns first, so every row it compares
    // starts from null. The re-run test below is where the leaving-alone is pinned.
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
    [$session] = $this->startAgentSession($this->installation);

    DB::table('robot_council_agent_sessions')
        ->where('id', $session->getKey())
        ->update(['project_id' => 'UAMS-Web/uams-statamic/a', 'repository' => null, 'work_location' => null]);

    runTheWorkIdentityMigration('down');
    runTheWorkIdentityMigration('up');

    $token = stringValue($this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertOk()
        ->json('token'));

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('repository', 'UAMS-Web/uams-statamic')
        ->assertJsonPath('work_location', 'a')
        ->assertJsonPath('project_id', 'UAMS-Web/uams-statamic/a');

    // **The migration rewrites the session ROW and not the events already written**, which is worth
    // asserting rather than discovering. This session's own `session.joined` was recorded before
    // the split existed and still says what it said then; what carries the two fields into the feed
    // is a session starting afterwards, which every process does within one token lifetime.
    $other = $this->approveInstallation($this->developer, machineLabel: 'laptop');

    $this->machine($this->installationCredential($other))
        ->postJson(route('robot-council.sessions.start'), ['project_id' => 'robot-council/cli'])
        ->assertCreated();

    $joined = collect(arrayValue($this->machine($token)
        ->getJson(route('robot-council.events.index', ['after' => 0]))
        ->assertOk()
        ->json('events')))
        ->filter(static fn (mixed $event): bool => (arrayValue($event)['type'] ?? null) === FleetEventType::SessionJoined->value)
        ->map(static fn (mixed $event): array => arrayValue(arrayValue($event)['meta'] ?? []))
        ->values();

    // Both events, in the order they were written: the old one carrying nothing, the new one
    // carrying the derived pair. Asserted as the whole list rather than by searching for the row
    // that agrees, which would pass with the new event missing.
    expect($joined)->toHaveCount(2)
        ->and($joined->pluck('repository')->all())->toBe([null, 'robot-council/cli'])
        ->and($joined->pluck('work_location')->all())->toBe([null, null]);
});

it('leaves a client-supplied repository alone when the backfill runs again', function (): void {
    // **The state a crash between the ALTER and the updates leaves on SQLite and MySQL.** The host
    // is already serving the new code -- that is why the migration is running -- so a session can
    // start in the re-run window and write a repository the client named. An unguarded backfill
    // rewrites it from a `project_id` the client has stopped maintaining, which is exactly what
    // `AgentSessions::start()` refuses to do.
    [$session] = $this->startAgentSession($this->installation);

    DB::table('robot_council_agent_sessions')
        ->where('id', $session->getKey())
        ->update([
            'project_id' => 'UAMS-Web/uams-statamic/a',
            'repository' => 'robot-council/core',
            'work_location' => null,
        ]);

    runTheWorkIdentityMigration('up');

    $row = AgentSession::query()->whereKey($session->getKey())->sole();

    expect($row->repository)->toBe('robot-council/core')
        ->and($row->work_location)->toBeNull();

    // The control: a row whose columns really are empty IS filled by the same run, so the test
    // above is the guard working rather than the backfill doing nothing at all.
    $other = $this->approveInstallation($this->developer, machineLabel: 'laptop');

    [$untouched] = $this->startAgentSession($other);

    DB::table('robot_council_agent_sessions')
        ->where('id', $untouched->getKey())
        ->update(['project_id' => 'UAMS-Web/uams-statamic/a', 'repository' => null, 'work_location' => null]);

    runTheWorkIdentityMigration('up');

    expect(AgentSession::query()->whereKey($untouched->getKey())->sole()->repository)
        ->toBe('UAMS-Web/uams-statamic');
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
