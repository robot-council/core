<?php

declare(strict_types=1);

/**
 * Retention on the agent sessions table, the last of the four nothing deleted from.
 *
 * **The hazard here is not what #113 first said it was.** That ticket asked for an assertion that
 * session id reuse cannot re-point a dead session's narration at another developer. Measured
 * before building: `robot_council_agent_sessions.id` is `primary key autoincrement` on SQLite,
 * which keeps a high-water mark in `sqlite_sequence`, so deleting the highest row and starting a
 * session gives the next id rather than the deleted one. Resetting a sequence is what `TRUNCATE`
 * does, not `DELETE` -- so a test for the criterion as worded would have passed whether or not any
 * guard existed.
 *
 * What a delete actually disturbs is two foreign keys: `robot_council_tasks.claimed_by` and
 * `robot_council_locks.holder_id`, both `nullOnDelete`. Those are what these tests pin, and they
 * pin them by forcing the state rather than by relying on the constraint, because Testbench leaves
 * `foreign_key_constraints` false and the nulling happens only in the `postgres` job.
 *
 * @command  vendor/bin/pest --compact tests/PruneSessionsTest.php
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Lock;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\SessionPresence;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    // Frozen for the reason `PruneEventsTest` records: a row placed at `now() - retention` and a
    // cutoff computed from a later `now()` disagree on Postgres by the microseconds between them.
    $this->freezeTime();

    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
});

/**
 * A session in one status, last heard from at a given age.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  AgentSessionStatus  $status  The status to leave it in.
 * @param  int  $daysAgo  How long ago it was last heard from.
 * @return AgentSession The session.
 */
function sessionAged(TestCase $case, AgentSessionStatus $status, int $daysAgo): AgentSession
{
    [$session] = $case->startAgentSession($case->installation);

    // Written straight to the row: `last_seen_at` is what the prune reads, and no store method
    // can travel in time.
    AgentSession::query()->whereKey($session->getKey())->update([
        'status' => $status,
        'last_seen_at' => Carbon::now()->subDays($daysAgo),
    ]);

    return $session->refresh();
}

it('deletes a session that ended past the retention and keeps one newer', function (): void {
    $retention = app(Credentials::class)->sessionRetentionDays();

    $old = sessionAged($this, AgentSessionStatus::Gone, $retention + 1);
    $edge = sessionAged($this, AgentSessionStatus::Gone, $retention);
    $fresh = sessionAged($this, AgentSessionStatus::Gone, 0);

    expect(app(SessionPresence::class)->prune(Carbon::now()->subDays($retention)))->toBe(1)
        ->and(AgentSession::query()->whereKey($old->getKey())->exists())->toBeFalse()
        // Exactly at the cutoff, which separates `<` from `<=`
        ->and(AgentSession::query()->whereKey($edge->getKey())->exists())->toBeTrue()
        ->and(AgentSession::query()->whereKey($fresh->getKey())->exists())->toBeTrue();
});

it('never deletes a session that has not gone, however old it is', function (string $status): void {
    // An `active` session is live and a `stale` one is a single request from active again, so age
    // is the wrong question for both.
    $session = sessionAged($this, AgentSessionStatus::from($status), 3650);

    expect(app(SessionPresence::class)->prune(Carbon::now()->subDays(1)))->toBe(0)
        ->and(AgentSession::query()->whereKey($session->getKey())->exists())->toBeTrue();
})->with(array_map(
    static fn (AgentSessionStatus $status): string => $status->value,
    array_filter(
        AgentSessionStatus::cases(),
        static fn (AgentSessionStatus $status): bool => $status !== AgentSessionStatus::Gone
    )
));

it('never deletes a session that still holds a task, however long ago it ended', function (): void {
    // `claimed_by` is `nullOnDelete`, so deleting this row would strip the task of its claimant
    // while its status still says it is held -- a row no release path can then reach. Forced
    // rather than produced, because SQLite enforces no foreign key in this suite, so a test that
    // waited for the engine to null the column would describe nothing here and only bite in the
    // `postgres` job.
    $session = sessionAged($this, AgentSessionStatus::Gone, 400);

    $task = app(Tasks::class)
        ->create($session, ['title' => 'still claimed'], withCoordinator: false);

    Task::query()->whereKey($task->getKey())->update([
        'status' => TaskStatus::InProgress,
        'claimed_by' => $session->getKey(),
    ]);

    expect(app(SessionPresence::class)->prune(Carbon::now()->subDays(30)))->toBe(0)
        ->and(AgentSession::query()->whereKey($session->getKey())->exists())->toBeTrue()
        ->and(Task::query()->whereKey($task->getKey())->value('claimed_by'))->toBe($session->getKey());

    // And it is a delay rather than an exemption: once the task reaches a status nothing holds,
    // the session goes. `claimed_by` deliberately stays set -- it is provenance, and a relation
    // that treated it as "held" would keep every session that ever finished work forever.
    Task::query()->whereKey($task->getKey())->update(['status' => TaskStatus::Done]);

    expect(app(SessionPresence::class)->prune(Carbon::now()->subDays(30)))->toBe(1)
        ->and(AgentSession::query()->whereKey($session->getKey())->exists())->toBeFalse();
});

it('never deletes a session that still holds a live lock, but does once the lease has lapsed', function (): void {
    // `holder_id` is `nullOnDelete` too, so deleting the row would free a held lock without the
    // feed event a release writes -- the fleet would see a name become available with nothing
    // saying who gave it up.
    $session = sessionAged($this, AgentSessionStatus::Gone, 400);

    // Inserted rather than created through the store, because the state under test is one the
    // store will not produce: a lock held by a session that has already gone.
    Lock::query()->insert([
        'name' => 'deploy',
        'holder_id' => $session->getKey(),
        'fence' => 1,
        'acquired_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addHour(),
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    $lock = Lock::query()->where('name', 'deploy')->sole();

    expect(app(SessionPresence::class)->prune(Carbon::now()->subDays(30)))->toBe(0)
        ->and(AgentSession::query()->whereKey($session->getKey())->exists())->toBeTrue()
        ->and(Lock::query()->whereKey($lock->getKey())->value('holder_id'))->toBe($session->getKey());

    // A lapsed lease is already free -- the next acquisition would take it without asking -- so
    // freeing it again costs nothing and the session stops being held back.
    Lock::query()->whereKey($lock->getKey())->update(['expires_at' => Carbon::now()->subMinute()]);

    expect(app(SessionPresence::class)->prune(Carbon::now()->subDays(30)))->toBe(1)
        ->and(AgentSession::query()->whereKey($session->getKey())->exists())->toBeFalse();
});

it('keeps everything when the retention is zero, and says so', function (): void {
    config()->set('robot-council.retention.sessions_days', 0);

    sessionAged($this, AgentSessionStatus::Gone, 4000);

    $before = AgentSession::query()->count();

    $command = $this->artisan('robot-council:prune-sessions');

    expect($command)->toBeInstanceOf(PendingCommand::class);

    if ($command instanceof PendingCommand) {
        $command->expectsOutputToContain('keep everything')->assertSuccessful();
    }

    expect(AgentSession::query()->count())->toBe($before);
});

it('clamps a batch or ceiling that makes no sense, and stops when nothing is left', function (): void {
    foreach (range(1, 3) as $ignored) {
        sessionAged($this, AgentSessionStatus::Gone, 200);
    }

    $presence = app(SessionPresence::class);

    $cutoff = fn (): Carbon => Carbon::now()->subDays(30);

    expect($presence->prune($cutoff(), batch: 0, maxBatches: 1))->toBe(1);

    // A batch SMALLER than what is left, or one iteration and two delete the same rows and the
    // ceiling's floor cannot be seen to move
    expect($presence->prune($cutoff(), batch: 1, maxBatches: 0))->toBe(1)
        ->and(AgentSession::query()->where('status', AgentSessionStatus::Gone)->count())->toBe(1);

    expect($presence->prune($cutoff(), batch: 10, maxBatches: 5))->toBe(1);

    // And with nothing left it stops on the first empty page rather than running its ceiling out.
    // `break` and `continue` delete the same rows; only the query count separates them.
    $selects = 0;

    DB::listen(function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
            $selects++;
        }
    });

    expect($presence->prune($cutoff(), batch: 10, maxBatches: 50))->toBe(0)
        ->and($selects)->toBe(1);
});

/**
 * The scheduled entries naming one command.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  string  $command  The signature to look for.
 * @return list<Event> The entries.
 */
function sessionScheduleFor(TestCase $case, string $command): array
{
    return array_values(collect($case->service(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, $command))
        ->all());
}

it('schedules the prune daily, and lets a host turn it off', function (): void {
    $scheduled = sessionScheduleFor($this, 'robot-council:prune-sessions');

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]->expression)->toBe('40 3 * * *');

    $this->rebootWith('robot-council.schedule.prune_sessions', false);

    expect(sessionScheduleFor($this, 'robot-council:prune-sessions'))->toBeEmpty()
        // The control: the feed's prune is still scheduled, so the empty result is this entry
        // being absent rather than the schedule being empty
        ->and(sessionScheduleFor($this, 'robot-council:prune-events'))->toHaveCount(1);
});
