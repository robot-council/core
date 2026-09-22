<?php

declare(strict_types=1);

/**
 * Retention on the fleet's change feed, which nothing deleted from before #47.
 *
 * The feed is the fastest-growing table the package owns: every task transition, lock, session
 * change and line of narration is a row, and almost none of it is read twice -- an agent pages
 * forward and a developer reads the head. So it grew for the life of a deployment.
 *
 * Two properties are worth more than the deletion itself. **A reader holding a cursor inside the
 * deleted range still makes progress**, because a cursor is a number rather than a row and paging
 * is `id > cursor`. And **the prune takes no feed lock**: that lock exists so inserts commit in id
 * order, which a delete cannot disturb, and taking it would serialise the prune against every
 * writer in the fleet.
 *
 * @command  vendor/bin/pest --compact tests/PruneEventsTest.php
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\FleetFeed;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);

    [$this->session] = $this->startAgentSession($this->installation);
});

/**
 * Write one event at a given age, through the store rather than by inserting a row.
 *
 * The case and the session are passed rather than reached for through `test()`, which widens to
 * `TestCall|HigherOrderTapProxy` and leaves the analyzer unable to see either.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  AgentSession  $session  The session the event belongs to.
 * @param  int  $daysAgo  How old the event should be.
 * @param  string  $body  What it says, so a test can name the one it is looking for.
 * @return FleetEvent The recorded event.
 */
function eventAged(TestCase $case, AgentSession $session, int $daysAgo, string $body): FleetEvent
{
    return Carbon::withTestNow(
        Carbon::now()->subDays($daysAgo),
        fn (): FleetEvent => $case->service(FleetEvents::class)
            ->record(FleetEventType::Narration, $session, $body)
    );
}

it('deletes events past the retention and keeps every event newer', function (): void {
    $retention = app(Credentials::class)->eventRetentionDays();

    // Either side of the boundary, and one far past it. The middle row is the one that separates
    // `<` from `<=`: written exactly at the cutoff, it must survive.
    $old = eventAged($this, $this->session, $retention + 1, 'older than the retention');
    $edge = eventAged($this, $this->session, $retention, 'exactly at the retention');
    $fresh = eventAged($this, $this->session, 0, 'newer than the retention');

    $deleted = app(FleetEvents::class)->prune(Carbon::now()->subDays($retention));

    expect($deleted)->toBe(1)
        ->and(FleetEvent::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(FleetEvent::query()->whereKey($edge->id)->exists())->toBeTrue()
        ->and(FleetEvent::query()->whereKey($fresh->id)->exists())->toBeTrue();
});

it('lets a reader whose cursor points at a deleted event keep making progress', function (): void {
    // #47's sharpest criterion, and the reason it is asserted rather than reasoned about: the
    // claim is that a cursor is a number and not a row, so `id > cursor` still answers for an id
    // that no longer exists. That is easy to believe and cheap to check.
    $stale = eventAged($this, $this->session, 90, 'the event the cursor points at');

    eventAged($this, $this->session, 89, 'also pruned');

    $survivor = eventAged($this, $this->session, 0, 'still here');

    // The reader is parked exactly on the row about to be deleted
    $cursor = $stale->id;

    app(FleetEvents::class)->prune(Carbon::now()->subDays(30));

    expect(FleetEvent::query()->whereKey($cursor)->exists())->toBeFalse();

    $page = app(FleetFeed::class)->after($this->session, $cursor, 20);

    // It moved forward rather than returning nothing or starting again
    expect(array_column($page['events'], 'id'))->toContain($survivor->id)
        ->and($page['cursor'])->toBeGreaterThan($cursor);
});

it('keeps everything when the retention is zero, and says so rather than deleting nothing quietly', function (): void {
    config()->set('robot-council.retention.events_days', 0);

    eventAged($this, $this->session, 400, 'ancient');

    $before = FleetEvent::query()->count();

    $command = $this->artisan('robot-council:prune-events');

    expect($command)->toBeInstanceOf(PendingCommand::class);

    if ($command instanceof PendingCommand) {
        $command->expectsOutputToContain('keep everything')->assertSuccessful();
    }

    expect(FleetEvent::query()->count())->toBe($before);
});

it('deletes in batches rather than one statement', function (): void {
    // Batched because every writer takes the feed's sentinel row before inserting, and a delete
    // large enough to matter is a delete long enough to hold a transaction across the table. The
    // count is what shows the loop ran more than once; a single-statement delete would report the
    // same total from one query.
    foreach (range(1, 7) as $n) {
        eventAged($this, $this->session, 60, 'batched '.$n);
    }

    $statements = 0;

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        if (str_starts_with(strtolower(trim($query->sql)), 'delete')) {
            $statements++;
        }
    });

    $deleted = app(FleetEvents::class)->prune(Carbon::now()->subDays(30), batch: 3);

    expect($deleted)->toBe(7)
        ->and($statements)->toBeGreaterThan(1);
});

it('stops at the batch ceiling rather than running until the table is empty', function (): void {
    // A run is bounded even on a table nobody has pruned, so the first run after an upgrade cannot
    // be an unbounded delete. What is left over is deleted by the next run.
    foreach (range(1, 6) as $n) {
        eventAged($this, $this->session, 60, 'ceiling '.$n);
    }

    $deleted = app(FleetEvents::class)->prune(Carbon::now()->subDays(30), batch: 2, maxBatches: 2);

    expect($deleted)->toBe(4)
        ->and(FleetEvent::query()->where('body', 'like', 'ceiling%')->count())->toBe(2);
});

it('takes no feed lock, so it cannot serialise against the fleet writers', function (): void {
    // The sentinel exists so inserts commit in id order. A delete draws no id, and the readers
    // page `id > cursor`, so nothing a delete does can reorder anything -- taking the lock would
    // stop every agent's narration for the length of the prune and buy nothing.
    eventAged($this, $this->session, 60, 'to be pruned');

    $lockReads = 0;

    DB::listen(function (QueryExecuted $query) use (&$lockReads): void {
        if (str_contains(strtolower($query->sql), FleetEvents::LOCK_TABLE)) {
            $lockReads++;
        }
    });

    app(FleetEvents::class)->prune(Carbon::now()->subDays(30));

    expect($lockReads)->toBe(0);

    // The control: recording an event DOES take it, so the zero above is an absence rather than a
    // listener that never fired
    app(FleetEvents::class)->record(FleetEventType::Narration, $this->session, 'takes the lock');

    expect($lockReads)->toBeGreaterThan(0);
});

/**
 * The scheduled entries naming one command.
 *
 * @param  string  $command  The signature to look for.
 * @return list<Event> The entries.
 */
function scheduledFor(TestCase $case, string $command): array
{
    return array_values(collect($case->service(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, $command))
        ->all());
}

it('schedules the prune daily, and lets a host turn it off', function (): void {
    $scheduled = scheduledFor($this, 'robot-council:prune-events');

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]->expression)->toBe('10 3 * * *');

    // Off through configuration, the way `schedule.prune_device_codes` can be. A reboot rather
    // than a `config()->set`, because the schedule is registered once at boot -- setting the key
    // afterwards would leave the entry in place and the assertion would pass for the wrong reason.
    $this->rebootWith('robot-council.schedule.prune_events', false);

    expect(scheduledFor($this, 'robot-council:prune-events'))->toBeEmpty();

    // The control: the device-code prune is still scheduled, so the empty result above is this one
    // entry being absent rather than the schedule being empty
    expect(scheduledFor($this, 'robot-council:prune-device-codes'))->toHaveCount(1);
});

it('clamps a batch or ceiling that makes no sense, and stops as soon as nothing is left', function (): void {
    // Three things no other test here separates. Both `max(1, …)` floors look identical to every
    // call that passes a sensible number, and the early `break` looks identical to running the
    // loop out -- the deleted total is the same either way, which is why this counts queries.
    foreach (range(1, 3) as $n) {
        eventAged($this, $this->session, 60, 'clamped '.$n);
    }

    $events = app(FleetEvents::class);

    // The batch floor. Asking for nothing deletes one row, not none -- a zero batch that clamped
    // to zero would select nothing, break immediately, and report a prune that did nothing.
    expect($events->prune(Carbon::now()->subDays(30), batch: 0, maxBatches: 1))->toBe(1);

    // The ceiling floor, with a batch SMALLER than what is left. A batch large enough to take
    // everything in one pass cannot see this floor move: one iteration and two would both delete
    // the lot. With one row per batch, a floor of one deletes one and a floor of two deletes two,
    // and the loop starting at one rather than nought deletes none.
    expect($events->prune(Carbon::now()->subDays(30), batch: 1, maxBatches: 0))->toBe(1);

    expect(FleetEvent::query()->where('body', 'like', 'clamped%')->count())->toBe(1);

    // Then the rest, so the table is empty for the query-count assertion below
    expect($events->prune(Carbon::now()->subDays(30), batch: 10, maxBatches: 5))->toBe(1)
        ->and(FleetEvent::query()->where('body', 'like', 'clamped%')->count())->toBe(0);

    // And with nothing left, the loop stops on the first empty page rather than running its
    // ceiling out. `break` and `continue` delete the same rows; only the query count tells them
    // apart, and a prune that kept scanning fifty times a night on an empty table is the kind of
    // waste nothing would ever report.
    $selects = 0;

    DB::listen(function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
            $selects++;
        }
    });

    expect($events->prune(Carbon::now()->subDays(30), batch: 10, maxBatches: 50))->toBe(0)
        ->and($selects)->toBe(1);
});
