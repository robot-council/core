<?php

declare(strict_types=1);

/**
 * Retention on the lock table, which nothing deleted from before #63, and the fence redesign that
 * made deleting from it safe.
 *
 * **The fence is the whole difficulty.** Every acquisition must return a number greater than any
 * previously issued for that name, and while `fence` was a per-row counter the row was the only
 * record of what the name had issued -- so deleting it and taking the name again restarted at 1,
 * and whatever the old holder was guarding would accept its stale fence a second time. A single
 * sequence shared by every name makes per-name monotonicity hold trivially and the row disposable.
 *
 * @command  vendor/bin/pest --compact tests/PruneLocksTest.php
 */
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use RobotCouncil\Models\Lock;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    // Frozen, for the reason `PruneEventsTest` records: a row placed at `now() - retention` and a
    // cutoff computed from a later `now()` disagree on Postgres by the microseconds between them.
    $this->freezeTime();

    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);

    [$this->session] = $this->startAgentSession($this->installation);
});

/**
 * Take a lock through the store, and fail loudly rather than returning null.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  string  $name  The name to take.
 * @param  int  $ttl  How long to hold it.
 * @return Lock The lock as it was written.
 */
function takeLock(TestCase $case, string $name, int $ttl = 60): Lock
{
    $result = $case->service(Locks::class)->acquire($case->session, $name, $ttl, false);

    expect($result['outcome'])->toBe(Outcome::Applied)
        ->and($result['lock'])->toBeInstanceOf(Lock::class);

    $lock = $result['lock'];

    if (! $lock instanceof Lock) {
        throw new RuntimeException('The acquisition did not return a lock.');
    }

    return $lock;
}

/**
 * Age a lock row by hand, which is what the prune reads.
 *
 * @param  Lock  $lock  The row to age.
 * @param  int  $daysAgo  How long ago it was last touched.
 */
function ageLock(Lock $lock, int $daysAgo): void
{
    Lock::query()->whereKey($lock->getKey())->update([
        'updated_at' => Carbon::now()->subDays($daysAgo),
    ]);
}

/**
 * The number the fence sequence currently holds.
 *
 * Narrowed in a helper rather than cast at each reader, for the reason CLAUDE.md gives for
 * `Access\Tokens` and `Console\Argument`: a query builder's `value()` is `mixed`, and a cast at
 * the call site is what the analyzer refuses.
 *
 * @return int The sequence's current value.
 */
function fenceSequence(): int
{
    $value = DB::table(Locks::FENCE_TABLE)->where('id', Locks::FENCE_ROW)->value('value');

    if (! is_numeric($value)) {
        throw new RuntimeException('The lock fence sequence does not hold a number.');
    }

    return (int) $value;
}

it('gives a name a fence above every fence it ever had, even after its row was deleted', function (): void {
    // #63's first criterion, and the only one the prune is unsafe without. A stale holder carries
    // its fence into whatever it guards, and that thing refuses anything below the highest it has
    // seen -- so a name whose fence restarted would hand the old holder its authority back.
    $first = takeLock($this, 'deploy');

    $before = $first->fence;

    app(Locks::class)->release($this->session, 'deploy', false);

    ageLock($first, 400);

    expect(app(Locks::class)->prune(Carbon::now()->subDays(7)))->toBe(1)
        ->and(Lock::query()->where('name', 'deploy')->exists())->toBeFalse();

    // The same name again, on a row that does not exist any more
    $again = takeLock($this, 'deploy');

    expect($again->fence)->toBeGreaterThan($before);
});

it("keeps climbing across many names, so one name cannot reuse another name's number", function (): void {
    // The property that makes the guarantee above hold without reading any row: the sequence is
    // shared, so every number this fleet has ever issued is distinct and ascending.
    $fences = [];

    foreach (['alpha', 'beta', 'gamma'] as $name) {
        $fences[] = takeLock($this, $name)->fence;
    }

    expect($fences)->toBe(array_values(array_unique($fences)))
        ->and($fences[1])->toBeGreaterThan($fences[0])
        ->and($fences[2])->toBeGreaterThan($fences[1]);
});

it('refuses to hand out a fence at all when the sequence row is missing', function (): void {
    // A silently absent sequence would restart every name at whatever the row said, which is the
    // failure the fence exists to prevent arriving through the fix for it. `FleetEvents` refuses
    // on its own missing sentinel for the same reason.
    DB::table(Locks::FENCE_TABLE)->where('id', Locks::FENCE_ROW)->delete();

    expect(fn (): array => app(Locks::class)->acquire($this->session, 'deploy', 60, false))
        ->toThrow(RuntimeException::class, 'lock fence row');
});

it('deletes a free lock past the retention and keeps one newer', function (): void {
    $retention = app(Credentials::class)->lockRetentionDays();

    $old = takeLock($this, 'old');
    $edge = takeLock($this, 'edge');
    $fresh = takeLock($this, 'fresh');

    foreach ([$old, $edge, $fresh] as $lock) {
        app(Locks::class)->release($this->session, $lock->name, false);
    }

    ageLock($old, $retention + 1);
    ageLock($edge, $retention);

    expect(app(Locks::class)->prune(Carbon::now()->subDays($retention)))->toBe(1)
        ->and(Lock::query()->whereKey($old->getKey())->exists())->toBeFalse()
        // Written exactly at the cutoff, which is what separates `<` from `<=`
        ->and(Lock::query()->whereKey($edge->getKey())->exists())->toBeTrue()
        ->and(Lock::query()->whereKey($fresh->getKey())->exists())->toBeTrue();
});

it('never deletes a lock somebody is holding, however old the row is', function (): void {
    // The criterion that matters after the fence: a held lock is the live answer to who is
    // guarding what, and age is no reason to remove it. The lease here runs an hour and the row
    // is a year old, which is the state a long-lived guard leaves behind.
    $held = takeLock($this, 'deploy', 3600);

    ageLock($held, 400);

    expect(app(Locks::class)->prune(Carbon::now()->subDays(7)))->toBe(0)
        ->and(Lock::query()->whereKey($held->getKey())->exists())->toBeTrue();

    // The control, in the same test: an equally old row whose lease has LAPSED is deleted, so the
    // zero above is the holder being respected rather than the prune matching nothing
    $lapsed = takeLock($this, 'stale', 1);

    ageLock($lapsed, 400);

    // Travelled rather than passing the moment in, so the store keeps one way of reading the
    // clock and no parameter exists only for a test
    $this->travelTo(Carbon::now()->addSeconds(5));

    expect(app(Locks::class)->prune(Carbon::now()->subDays(7)))->toBe(1)
        ->and(Lock::query()->whereKey($lapsed->getKey())->exists())->toBeFalse()
        ->and(Lock::query()->whereKey($held->getKey())->exists())->toBeTrue();
});

it('keeps everything when the retention is zero, and says so', function (): void {
    config()->set('robot-council.retention.locks_days', 0);

    $lock = takeLock($this, 'deploy');

    app(Locks::class)->release($this->session, 'deploy', false);

    ageLock($lock, 4000);

    $command = $this->artisan('robot-council:prune-locks');

    expect($command)->toBeInstanceOf(PendingCommand::class);

    if ($command instanceof PendingCommand) {
        $command->expectsOutputToContain('keep everything')->assertSuccessful();
    }

    expect(Lock::query()->count())->toBe(1);
});

it('clamps a batch or ceiling that makes no sense, and stops when nothing is left', function (): void {
    foreach (['one', 'two', 'three'] as $name) {
        $lock = takeLock($this, $name);

        app(Locks::class)->release($this->session, $name, false);

        ageLock($lock, 200);
    }

    $locks = app(Locks::class);

    $cutoff = fn (): Carbon => Carbon::now()->subDays(7);

    expect($locks->prune($cutoff(), batch: 0, maxBatches: 1))->toBe(1);

    // A batch SMALLER than what is left, or one iteration and two delete the same rows and the
    // ceiling's floor cannot be seen to move
    expect($locks->prune($cutoff(), batch: 1, maxBatches: 0))->toBe(1)
        ->and(Lock::query()->count())->toBe(1);

    expect($locks->prune($cutoff(), batch: 10, maxBatches: 5))->toBe(1);

    // And with nothing left it stops on the first empty page rather than running its ceiling out.
    // `break` and `continue` delete the same rows; only the query count separates them.
    $selects = 0;

    DB::listen(function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
            $selects++;
        }
    });

    expect($locks->prune($cutoff(), batch: 10, maxBatches: 50))->toBe(0)
        ->and($selects)->toBe(1);
});

/**
 * The scheduled entries naming one command.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  string  $command  The signature to look for.
 * @return list<Event> The entries.
 */
function lockScheduleFor(TestCase $case, string $command): array
{
    return array_values(collect($case->service(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, $command))
        ->all());
}

it('schedules the prune daily, and lets a host turn it off', function (): void {
    $scheduled = lockScheduleFor($this, 'robot-council:prune-locks');

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]->expression)->toBe('30 3 * * *');

    $this->rebootWith('robot-council.schedule.prune_locks', false);

    expect(lockScheduleFor($this, 'robot-council:prune-locks'))->toBeEmpty()
        // The control: the feed's prune is still scheduled, so the empty result is this entry
        // being absent rather than the schedule being empty
        ->and(lockScheduleFor($this, 'robot-council:prune-events'))->toHaveCount(1);
});

/**
 * Run the fence migration against whatever the schema currently looks like.
 *
 * Called as a narrowed callable for the reason `EventIndexDropTest` records: a migration file
 * returns `mixed` to the analyzer, and `Migration` itself declares no `up()`.
 */
function runTheFenceMigration(): void
{
    $migration = require __DIR__.'/../database/migrations/2026_09_22_000001_create_robot_council_lock_fence_table.php';

    $up = [$migration, 'up'];

    if (! is_callable($up)) {
        throw new RuntimeException('The migration file did not return something with an up().');
    }

    $up();
}

it('seeds the sequence above every fence an upgrading installation had already issued', function (): void {
    // **The riskiest line in this change.** An installation that has been running carries per-name
    // counters this sequence takes over from, and seeding at zero would hand out numbers those
    // names have already used -- reintroducing the exact failure the fence exists to prevent,
    // through the change meant to fix it. A fresh install has nothing to read and seeds at zero,
    // so the suite's own migration run cannot exercise this path at all.
    $lock = takeLock($this, 'deploy');

    Lock::query()->whereKey($lock->getKey())->update(['fence' => 9000]);

    // The upgrade, as a host would run it: the table is not there, and then it is
    Schema::drop(Locks::FENCE_TABLE);

    runTheFenceMigration();

    expect(fenceSequence())->toBe(9000);

    // And the guarantee that follows from it, which is the thing actually worth asserting
    app(Locks::class)->release($this->session, 'deploy', false);

    expect(takeLock($this, 'deploy')->fence)->toBeGreaterThan(9000);
});

it('leaves the sequence alone when it is already there, so a re-run cannot rewind it', function (): void {
    // All three populations run this file -- installed before the change, installed after it, and
    // rolled back -- which is the shape CLAUDE.md requires of a repair migration. A second run
    // that reseeded would drop the sequence back to the highest row fence, below numbers it had
    // already issued for names whose rows are gone.
    DB::table(Locks::FENCE_TABLE)->where('id', Locks::FENCE_ROW)->update(['value' => 4242]);

    runTheFenceMigration();

    expect(fenceSequence())->toBe(4242);
});

it('does not touch the shared sequence when an acquisition loses', function (): void {
    // The sequence is ONE row for the whole fleet, and an exclusive lock on it is held to commit.
    // Drawing on a losing attempt would put every failed acquisition of every contended name into
    // one global queue, so a single hot lock would serialize acquisitions of every other name.
    // Locks are this package's contention primitive; that is the last place to add a chokepoint.
    takeLock($this, 'deploy', 3600);

    [$rival] = $this->startAgentSession($this->installation);

    $writes = 0;

    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (str_contains(strtolower($query->sql), Locks::FENCE_TABLE)
            && ! str_starts_with(strtolower(trim($query->sql)), 'select')) {
            $writes++;
        }
    });

    $lost = app(Locks::class)->acquire($rival, 'deploy', 60, false);

    expect($lost['outcome'])->toBe(Outcome::Conflict)
        ->and($writes)->toBe(0);

    // The control, in the same test: a name that IS free does write the sequence, so the zero
    // above is the losing path leaving it alone rather than a listener that never fired
    $won = app(Locks::class)->acquire($rival, 'free-name', 60, false);

    expect($won['outcome'])->toBe(Outcome::Applied)
        ->and($writes)->toBeGreaterThan(0);
});
