<?php

declare(strict_types=1);

/**
 * The lock the lock-release step takes on a session row, which only a second connection can show.
 *
 * `Support\Locks::releaseOrphaned()` chooses its candidates with one read and releases each in its
 * own transaction, locking the holder's session row and re-reading it first. The lock is what makes
 * the decision belong to the write rather than to a read taken a moment earlier, and #25's task
 * step does the same against the same rows -- a task and a lock released under different rules
 * would be worse than either.
 *
 * Built to fail against the two changes that would quietly remove it: taking no lock, and
 * downgrading to a shared one. The other connection holds a SHARED lock, which conflicts with
 * `for update` and not with another `for share`.
 *
 * @command  DB_CONNECTION=pgsql vendor/bin/pest --compact --group=cross-connection
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\Lock;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\SessionPresence;
use RobotCouncil\Tests\TestCase;

/**
 * A gone session still holding one lock.
 *
 * @param  TestCase  $case  The test case.
 * @return array{string, int} The lock's name and the session's ID.
 */
function aLockHeldByAGoneSession(TestCase $case): array
{
    $case->migrateUsersTableWithPackageColumns();
    $case->setAccessLists(developers: [4242]);

    $developer = $case->enrollDeveloper(4242);

    $installation = $case->approveInstallation($developer);

    [$session] = $case->startAgentSession($installation);

    $case->service(Locks::class)->acquire($session, 'deploy', 600, false);

    $case->service(SessionPresence::class)->end($session);

    return ['deploy', $session->id];
}

it('releases nothing while another connection holds the session row', function (): void {
    [$name, $session] = aLockHeldByAGoneSession($this);

    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    try {
        // The control, and it is what makes the blocked run below mean the lock held rather than
        // that there was nothing to release: the fixture really is a gone session holding a lock.
        expect(Lock::query()->where('name', $name)->sole()->holder_id)->toBe($session);

        $other->beginTransaction();
        $other->select('select * from robot_council_agent_sessions where id = ? for share', [$session]);

        DB::statement("set lock_timeout = '750ms'");

        expect(fn (): int => $this->service(Locks::class)->releaseOrphaned())
            ->toThrow(QueryException::class)
            ->and(Lock::query()->where('name', $name)->sole()->holder_id)->toBe($session);
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

it('releases the lock as soon as the other connection lets go', function (): void {
    [$name, $session] = aLockHeldByAGoneSession($this);

    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    try {
        $other->beginTransaction();
        $other->select('select * from robot_council_agent_sessions where id = ? for update', [$session]);

        DB::statement("set lock_timeout = '500ms'");

        expect(fn (): int => $this->service(Locks::class)->releaseOrphaned())
            ->toThrow(QueryException::class);

        $other->rollBack();

        DB::statement('set lock_timeout = default');

        expect($this->service(Locks::class)->releaseOrphaned())->toBe(1);

        $released = Lock::query()->where('name', $name)->sole();

        // Freed, and the fence kept: an action still running under the old lease has to be
        // refused once the name is taken again
        expect($released->holder_id)->toBeNull()
            ->and($released->expires_at)->toBeNull()
            ->and($released->fence)->toBe(1);
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
