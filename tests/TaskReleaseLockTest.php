<?php

declare(strict_types=1);

/**
 * The lock the release step takes on a session row, which only a second connection can show.
 *
 * `Support\Tasks::releaseOrphaned()` chooses its candidates with one read and then releases each in
 * its own transaction, so between the two a session could in principle stop being gone. It cannot
 * in practice -- `gone` is terminal -- but the release does not rely on that being true: it locks
 * the session row and re-reads it, so the decision belongs to the write rather than to a read taken
 * a moment earlier. #26 registers a second step against the same rows, and a task and a lock
 * released under different rules would be worse than either.
 *
 * The test is built to fail against the two changes that would quietly remove the guarantee:
 *
 * 1. **Taking no lock at all.** Caught by holding the session row on another connection: an
 *    unlocked release sails past and gives the task back.
 * 2. **Downgrading to a shared lock.** Caught by holding a SHARED lock there -- two shared locks do
 *    not conflict, so a downgraded release would also sail past, while `for update` must wait.
 *
 * The `cross-connection` group runs only in CI's `postgres` job: SQLite serializes writers and
 * gives each connection its own in-memory database, so neither test can run there.
 *
 * @command  DB_CONNECTION=pgsql vendor/bin/pest --compact --group=cross-connection
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\SessionPresence;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

/**
 * A gone session holding one claimed task.
 *
 * @param  TestCase  $case  The test case.
 * @return array{int, int} The task's ID and the session's ID.
 */
function aTaskHeldByAGoneSession(TestCase $case): array
{
    $case->migrateUsersTableWithPackageColumns();
    $case->setAccessLists(developers: [4242]);

    $developer = $case->enrollDeveloper(4242);

    $installation = $case->approveInstallation($developer, [
        Ability::TasksCreate->value,
        Ability::TasksClaim->value,
    ]);

    [$session] = $case->startAgentSession($installation);

    $task = $case->service(Tasks::class)->create($session, ['title' => 'Held when it went'], false);

    $case->service(Tasks::class)->transition($task->id, TaskTransition::Claim, $session, false);

    $case->service(SessionPresence::class)->end($session);

    return [$task->id, $session->id];
}

it('releases nothing while another connection holds the session row', function (): void {
    [$task, $session] = aTaskHeldByAGoneSession($this);

    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    try {
        // The control, and it is load-bearing: it proves the fixture really is a gone session
        // holding a claimed task, so that the blocked run below means the lock held rather than
        // that there was nothing to release in the first place.
        expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Claimed);

        // A SHARED lock, deliberately. An exclusive reader conflicts with it and must wait; two
        // shared locks would not, so a step that downgraded its own lock would sail through.
        $other->beginTransaction();
        $other->select('select * from robot_council_agent_sessions where id = ? for share', [$session]);

        // Bounded, so a blocked step reports it rather than hanging the suite
        DB::statement("set lock_timeout = '750ms'");

        expect(fn (): int => $this->service(Tasks::class)->releaseOrphaned())
            ->toThrow(QueryException::class);

        // The assertion the file exists for: the step never reached its write
        $held = Task::query()->findOrFail($task);

        expect($held->status)->toBe(TaskStatus::Claimed)
            ->and($held->claimed_by)->toBe($session);
    } finally {
        DB::statement('set lock_timeout = default');

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }

        DB::purge("{$default}_other");
    }
})->group('cross-connection')
    ->skip(notPostgres(...), 'Postgres only: this file sets `lock_timeout`, which MySQL spells differently, so elsewhere it stalls rather than failing.');

it('releases the task as soon as the other connection lets go', function (): void {
    [$task, $session] = aTaskHeldByAGoneSession($this);

    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    try {
        $other->beginTransaction();
        $other->select('select * from robot_council_agent_sessions where id = ? for update', [$session]);

        DB::statement("set lock_timeout = '500ms'");

        expect(fn (): int => $this->service(Tasks::class)->releaseOrphaned())
            ->toThrow(QueryException::class);

        // Released by a rollback, with no hook to forget
        $other->rollBack();

        DB::statement('set lock_timeout = default');

        expect($this->service(Tasks::class)->releaseOrphaned())->toBe(1);

        $released = Task::query()->findOrFail($task);

        expect($released->status)->toBe(TaskStatus::Pending)
            ->and($released->claimed_by)->toBeNull();
    } finally {
        DB::statement('set lock_timeout = default');

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }

        DB::purge("{$default}_other");
    }
})->group('cross-connection')
    ->skip(notPostgres(...), 'Postgres only: this file sets `lock_timeout`, which MySQL spells differently, so elsewhere it stalls rather than failing.');
