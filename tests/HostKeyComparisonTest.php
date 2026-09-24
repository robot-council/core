<?php

declare(strict_types=1);

/**
 * Two host users whose keys differ only in case are two developers, on every engine.
 *
 * **This decides who sees and claims what**, so it is an access-control test rather than a storage
 * one. MySQL 8's default collation is `utf8mb4_0900_ai_ci`, which compares case- and
 * accent-insensitively, and `#37` settled that a host user's key is stored as a string precisely
 * so a host keyed by a UUID, a ULID, a username or an email works -- all of which Laravel allows
 * as a primary key, and some of which are case-significant (#54).
 *
 * **Every assertion here runs on every engine, deliberately.** The property is "the package
 * compares a key byte-exactly", which SQLite and Postgres already satisfy, so a test gated on
 * MySQL would be a test nobody runs while developing. Written this way it passes everywhere and
 * failed only on MySQL before the collation migration -- which is what a regression test for a
 * one-engine defect should do.
 *
 * @command  vendor/bin/pest --compact tests/HostKeyComparisonTest.php
 */

// Selected by the `mysql` job, which runs `--group=engine-semantics` rather than the whole
// suite. `EngineSemanticsGroupGuardTest` fails when a file that gates itself on MySQL omits
// this line, so the group cannot silently stop covering a test.
pest()->group('engine-semantics');

use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\FleetAbilities;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\FleetFeed;
use RobotCouncil\Support\HostUsers;
use RobotCouncil\Support\Tasks;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);
});

/**
 * Every column holding a host user key, as `table.column`.
 *
 * Named here rather than derived, because the point is that the set is closed and reviewed: a
 * column added later that holds a key and is not on this list is the defect coming back.
 *
 * @return list<array{0: string, 1: string}>
 */
function hostKeyColumns(): array
{
    return [
        ['robot_council_installations', 'user_id'],
        ['robot_council_installations', 'approved_by'],
        ['robot_council_agent_sessions', 'user_id'],
        ['robot_council_events', 'user_id'],
        ['robot_council_events', 'actor_user_id'],
        ['robot_council_tasks', 'user_id'],
        ['robot_council_device_codes', 'decided_by'],
        ['robot_council_github_identities', 'user_id'],
    ];
}

/**
 * Force one session's host user key, and every row that denormalizes it.
 *
 * @param  AgentSession  $session  The session to re-key.
 * @param  string  $key  The host user key to give it.
 */
function keyedAs(AgentSession $session, string $key): void
{
    AgentSession::query()->whereKey($session->getKey())->update(['user_id' => $key]);

    $session->forceFill(['user_id' => $key])->syncOriginal();
}

it('gives every column holding a host user key a binary collation', function (string $table, string $column): void {
    // **The instrument for "every column", and it is MySQL-only because the question is.** Postgres
    // and SQLite compare bytes whatever is declared, so there is nothing to assert there; MySQL is
    // where a default collation decides an access-control question, and the collation is exactly
    // what the migration sets. The behavioral tests below cover the two comparisons that decide
    // something, on every engine.
    $collation = schemaField(DB::selectOne(
        'select collation_name as value from information_schema.columns
         where table_schema = database() and table_name = ? and column_name = ?',
        [$table, $column]
    ), 'value');

    // The control: the column was found at all. A misspelled table or a wrong schema returns no
    // row, and an empty collation would then read as "not case-insensitive" and pass.
    expect($collation)->not->toBe('', sprintf('%s.%s was not found in information_schema.', $table, $column));

    expect($collation)->toEndWith('_bin', sprintf(
        '%s.%s is `%s`. A case- or accent-insensitive collation makes two distinct host users '
        .'compare equal, which decides task claims and narration visibility.',
        $table,
        $column,
        $collation
    ));
})->with(hostKeyColumns())->skip(notMySqlFamily(...), 'Only MySQL lets a collation decide this.');

it('serves no narration across two developers whose keys differ only in case', function (): void {
    // The failure the column change exists to prevent, driven through the store that decides it.
    // `FleetFeed` reads `user_id` off the EVENT rather than looking it up from the session id, so
    // this is the comparison that actually gates whose words reach whose agent.
    //
    // The keys are forced onto the rows rather than produced by enrolling two host users, because
    // the harness keys its users table by an auto-incrementing integer -- and what is under test
    // is how the PACKAGE compares a key it was given, not how a host mints one.
    $developer = $this->enrollDeveloper(4242);

    [$mine] = $this->startAgentSession($this->approveInstallation($developer));
    [$theirs] = $this->startAgentSession($this->approveInstallation($developer));

    keyedAs($mine, 'bob');
    keyedAs($theirs, 'Bob');

    $secret = $this->service(FleetEvents::class)
        ->record(FleetEventType::Narration, $mine, 'a private note from bob');

    $page = $this->service(FleetFeed::class)->after($theirs, 0, 50);

    expect(array_column($page['events'], 'body'))->not->toContain('a private note from bob')
        ->and(FleetEvent::query()->whereKey($secret->id)->value('user_id'))->toBe('bob');

    // The control: the writer's OWN other session, keyed identically, does see it -- so the
    // absence above is the key being compared rather than the feed returning nothing at all.
    [$sibling] = $this->startAgentSession($this->approveInstallation($developer));

    keyedAs($sibling, 'bob');

    expect(array_column($this->service(FleetFeed::class)->after($sibling, 0, 50)['events'], 'body'))
        ->toContain('a private note from bob');
});

it('lets no agent claim a task belonging to a developer whose key differs only in case', function (): void {
    // #16's eligibility rule travels in the claim's own `where`, so a collation comparing the two
    // keys equal makes the conditional update match and the claim succeed. `isClaimableBy()` would
    // have refused it, but it only runs to diagnose a write that matched nothing -- the divergence
    // fails OPEN, which is why this asserts on the row rather than on a returned outcome.
    $developer = $this->enrollDeveloper(4242);

    [$ownerSession] = $this->startAgentSession($this->approveInstallation($developer));
    [$rivalSession] = $this->startAgentSession($this->approveInstallation($developer));

    keyedAs($ownerSession, 'bob');
    keyedAs($rivalSession, 'Bob');

    $task = $this->service(Tasks::class)
        ->create($ownerSession, ['title' => "bob's task"], withCoordinator: false);

    // Narrowed rather than cast at the call site: a model key is `mixed` to the analyzer.
    $taskId = $task->id;

    $this->service(Tasks::class)
        ->transition($taskId, TaskTransition::Claim, $rivalSession, asCoordinator: false);

    $row = Task::query()->whereKey($task->getKey())->sole();

    expect($row->user_id)->toBe('bob')
        ->and($row->claimed_by)->toBeNull();

    // The control: the owner's own session, keyed `bob`, does claim it -- so the refusal above is
    // the key comparison and not a transition that never works.
    $this->service(Tasks::class)
        ->transition($taskId, TaskTransition::Claim, $ownerSession, asCoordinator: false);

    expect(Task::query()->whereKey($task->getKey())->value('claimed_by'))->toBe($ownerSession->getKey());
});

/**
 * Record two identities whose keys are different text and the same number.
 *
 * `'5'` and `'5x'` are chosen because they bracket the two engines: they are distinct to every
 * text comparison, and `'5x'` is what MySQL turns into `5` when it casts a column to a number.
 *
 * @param  string  $first  The key looked up.
 * @param  string  $second  The key that must not come back with it.
 */
function twoNumericallyEqualKeys(string $first = '5', string $second = '5x'): void
{
    GithubIdentity::query()->create(['user_id' => $first, 'github_id' => 5000, 'github_login' => 'five']);
    GithubIdentity::query()->create(['user_id' => $second, 'github_id' => 5001, 'github_login' => 'five-ish']);
}

it('binds every host key as text, on every engine', function (): void {
    // **The guard that can actually fail in CI, and the reason it asserts bindings rather than
    // rows.** The defect below is visible only on MySQL. When this was written there was no
    // `mysql` job, so a test written against the rows it returns was green in CI whether or not the
    // fix was present. `robot-council/core#253` added one -- this file is in the `engine-semantics`
    // group and the job now answers the rows too -- but the bindings assertion stays, because it is
    // the half every engine can answer and it fails in the developing run rather than only in CI.
    // Measured by reverting the fix: the `mysql` job fails 3 tests here and the SQLite suite fails
    // this one.
    //
    // `Support\HostKey::tryFrom()` returns a string for every key. The one thing that turns one
    // back into an integer is a PHP array key, so this is the property that has to hold: nothing
    // between `tryFrom()` and the query reintroduces a number.
    twoNumericallyEqualKeys();

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $this->service(HostUsers::class)->githubIdsForKeys(['5', '42', '5x']);

    DB::connection()->disableQueryLog();

    $bindings = [];

    foreach (DB::connection()->getQueryLog() as $entry) {
        foreach (arrayValue(arrayValue($entry)['bindings'] ?? []) as $binding) {
            $bindings[] = $binding;
        }
    }

    // The control first: a run that captured nothing would satisfy `each->toBeString()` vacuously,
    // and that is exactly the shape this whole ticket is about.
    expect($bindings)->toHaveCount(3)
        ->and($bindings)->each->toBeString()
        ->and($bindings)->toEqualCanonicalizing(['5', '42', '5x']);
});

it('reads a host key back as a value rather than as an array key', function (): void {
    // The mechanism the test above guards, asserted directly so the REASON is pinned and not only
    // the outcome. A reader who changes `$narrowed[$key] = $key` back to `= true` should be able to
    // find out here why that is not a simplification.
    //
    // **It pins the LANGUAGE, not this package, and that is worth saying plainly**: no edit to
    // `Support\HostUsers` can turn it red. It fails only if PHP stops coercing a canonical numeric
    // string array key to an integer, which is the assumption the whole fix rests on -- so it is a
    // statement of a premise rather than a regression test, and `it('binds every host key as
    // text')` above is the one that guards the code.
    $keyed = [];

    foreach (['5', '42', '5x'] as $key) {
        $keyed[$key] = $key;
    }

    expect(array_map(get_debug_type(...), array_keys($keyed)))->toBe(['int', 'int', 'string'])
        ->and(array_map(get_debug_type(...), array_values($keyed)))->toBe(['string', 'string', 'string']);
});

it('resolves one developer per host key, not every key that is the same number', function (): void {
    // **The behavioral half, and it can only fail on MySQL.** An integer binding makes MySQL cast
    // the COLUMN to a number, so every row that is numerically five matches; SQLite 3.51.3 and
    // PostgreSQL 17.6 and 18.0 matched `'5'` alone whether or not the fix was in.
    //
    // **Two rows here, not five.** The recorder that measured this seeded `'5'`, `'5x'`, `'05'`,
    // `' 5'` and `'5.0'` and got all five back from a lookup of `'5'` alone -- the figure quoted on
    // the ticket. This keeps the smallest pair that still discriminates, because a fixture is a
    // thing every future reader has to hold in their head.
    //
    // It is written to run everywhere regardless, exactly as this file's other assertions are,
    // because a test gated on MySQL is a test nobody runs while developing. The `mysql` job added
    // by `robot-council/core#253` runs this file, so the rows are now answered in CI as well -- the
    // ungated form is what makes it answerable in a local SQLite run too.
    //
    // **`2026_09_22_000002`'s binary collation does not cover this.** Measured on MySQL 9.7.2: the
    // column reads `utf8mb4_bin` and the coercion happens anyway, because a numeric comparison
    // casts the column before any collation is consulted.
    twoNumericallyEqualKeys();

    $found = $this->service(HostUsers::class)->githubIdsForKeys(['5']);

    expect($found)->toHaveCount(1)
        ->and(array_values($found))->toBe([5000])

        // Asserted on the VALUES, because that is how `Support\FleetAbilities` reads this map: it
        // asks the allowlist about each value and ignores the keys, so a stray entry there is one
        // developer's listing answering for another developer's fleet.
        ->and(array_values($found))->not->toContain(5001);

    // The control: the same call asking for both keys returns both, so the single result above is
    // the comparison narrowing rather than the read finding nothing.
    expect($this->service(HostUsers::class)->githubIdsForKeys(['5', '5x']))->toHaveCount(2);
});

it('tells one developer from another whose key is the same number, through the fleet question', function (): void {
    // The consequence in the place it would actually be felt. `Support\FleetAbilities` answers
    // `fleet_can_direct`, and it decides by asking the allowlist about every GitHub ID this map
    // returns -- so an over-matching lookup reports a fleet able to direct on the strength of a
    // developer who holds no coordinator session at all. That is the reassuring direction, which is
    // the one that question exists to remove.
    //
    // **Which key goes where is the whole test, and the first draft had it backwards.** The
    // over-match fires on the key being LOOKED UP, because only a canonical numeric string becomes
    // an integer array key -- so the coordinator whose key is read out of the session row has to be
    // `'5'`, and the listed developer it must not be confused with has to be the one that merely
    // coerces to five. Written the other way round the fixture binds `'5x'` as text, matches one
    // row on every engine, and the test passes before the fix while proving nothing. Measured: it
    // did.
    $coordinator = $this->enrollDeveloper(4242, 'coordinator');

    [$session] = $this->startCoordinatorSession($this->approveInstallation($coordinator));

    // The coordinator's own key is NOT on the access list; the numerically equal one is.
    keyedAs($session, '5');

    GithubIdentity::query()->where('github_id', 4242)->update(['user_id' => '5']);
    GithubIdentity::query()->create(['user_id' => '5x', 'github_id' => 77, 'github_login' => 'listed']);

    $this->setAccessLists(developers: [77]);

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeFalse();

    // The control: list the coordinator's own developer and the same fleet answers true, so the
    // false above is the key comparison rather than a fixture that never had a coordinator.
    $this->setAccessLists(developers: [4242]);

    expect(app(FleetAbilities::class)->anyLiveSessionHolds(Ability::CoordinatorDirect))->toBeTrue();
});
