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

use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\FleetFeed;
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
})->with(hostKeyColumns())->skip(notMySql(...), 'Only MySQL lets a collation decide this.');

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
