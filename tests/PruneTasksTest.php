<?php

declare(strict_types=1);

/**
 * Retention on the task queue, which nothing deleted from before #55.
 *
 * The property that matters most is not the deletion. **A task nobody has finished is never
 * removed, whatever its age** -- it is work the fleet still owes somebody, and age is the opposite
 * of a reason to delete it. An old pending task is the one most worth looking at.
 *
 * @command  vendor/bin/pest --compact tests/PruneTasksTest.php
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);

    [$this->session] = $this->startAgentSession($this->installation);
});

/**
 * A task in one status, last written at a given age.
 *
 * Written through the store and then aged by hand, because `updated_at` is what decides and no
 * store method can travel in time.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  TaskStatus  $status  The status to leave it in.
 * @param  int  $daysAgo  How long ago it was last written.
 * @param  string  $title  What to call it.
 * @param  Task|null  $parent  The task to file it under, when the case is about a tree.
 * @return Task The task.
 */
function taskAged(TestCase $case, TaskStatus $status, int $daysAgo, string $title, ?Task $parent = null): Task
{
    $task = $case->service(Tasks::class)->create($case->session, ['title' => $title], withCoordinator: false);

    // `forceFill` plus `timestamps = false`, or saving would rewrite the very column under test
    $task->timestamps = false;

    $task->forceFill([
        'status' => $status,
        'updated_at' => Carbon::now()->subDays($daysAgo),
        'parent_task_id' => $parent?->id,
    ])->save();

    return $task;
}

it('deletes a finished task past the retention and keeps one newer', function (): void {
    $retention = app(Credentials::class)->taskRetentionDays();

    $old = taskAged($this, TaskStatus::Done, $retention + 1, 'finished long ago');
    $edge = taskAged($this, TaskStatus::Done, $retention, 'finished exactly at the cutoff');
    $fresh = taskAged($this, TaskStatus::Done, 0, 'finished today');

    $deleted = app(Tasks::class)->prune(Carbon::now()->subDays($retention));

    expect($deleted)->toBe(1)
        ->and(Task::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Task::query()->whereKey($edge->id)->exists())->toBeTrue()
        ->and(Task::query()->whereKey($fresh->id)->exists())->toBeTrue();
});

it('never deletes a task nobody has finished, however old it is', function (string $status): void {
    // The point of the ticket. Every non-terminal status, aged far past any retention.
    $task = taskAged($this, TaskStatus::from($status), 3650, 'ancient and unfinished');

    $deleted = app(Tasks::class)->prune(Carbon::now()->subDays(1));

    expect($deleted)->toBe(0)
        ->and(Task::query()->whereKey($task->id)->exists())->toBeTrue();
})->with(TaskStatus::values(array_values(array_filter(
    TaskStatus::cases(),
    static fn (TaskStatus $status): bool => ! $status->isTerminal()
))));

it('deletes every status nothing leaves, so a terminal status cannot be forgotten', function (string $status): void {
    // The other half. Without this, a rule that pruned only `done` would satisfy the test above
    // and quietly keep every cancelled and failed task forever.
    $task = taskAged($this, TaskStatus::from($status), 3650, 'finished long ago');

    expect(app(Tasks::class)->prune(Carbon::now()->subDays(1)))->toBe(1)
        ->and(Task::query()->whereKey($task->id)->exists())->toBeFalse();
})->with(TaskStatus::values(TaskStatus::terminal()));

it('measures age from when the task finished, not from when it was filed', function (): void {
    // `updated_at` is correct precisely BECAUSE the status is terminal: nothing transitions out of
    // one, so the last write is the moment it finished. Using `created_at` would delete a
    // long-running task that ended yesterday and keep one filed and cancelled this morning.
    $longRunning = taskAged($this, TaskStatus::Done, 0, 'filed long ago, finished today');

    $longRunning->timestamps = false;
    $longRunning->forceFill(['created_at' => Carbon::now()->subDays(400)])->save();

    $quick = taskAged($this, TaskStatus::Cancelled, 200, 'filed and cancelled the same day');

    $quick->timestamps = false;
    $quick->forceFill(['created_at' => Carbon::now()->subDays(200)])->save();

    app(Tasks::class)->prune(Carbon::now()->subDays(90));

    // The one that FINISHED recently survives, though it was filed first
    expect(Task::query()->whereKey($longRunning->id)->exists())->toBeTrue()
        ->and(Task::query()->whereKey($quick->id)->exists())->toBeFalse();
});

it('keeps everything when the retention is zero, and says so', function (): void {
    config()->set('robot-council.retention.tasks_days', 0);

    taskAged($this, TaskStatus::Done, 4000, 'ancient');

    $before = Task::query()->count();

    $command = $this->artisan('robot-council:prune-tasks');

    expect($command)->toBeInstanceOf(PendingCommand::class);

    if ($command instanceof PendingCommand) {
        $command->expectsOutputToContain('keep everything')->assertSuccessful();
    }

    expect(Task::query()->count())->toBe($before);
});

it('clamps a batch or ceiling that makes no sense, and stops when nothing is left', function (): void {
    foreach (range(1, 3) as $n) {
        taskAged($this, TaskStatus::Done, 200, 'clamped '.$n);
    }

    $tasks = app(Tasks::class);

    expect($tasks->prune(Carbon::now()->subDays(90), batch: 0, maxBatches: 1))->toBe(1);

    // A batch SMALLER than what is left, or one iteration and two delete the same rows and the
    // ceiling's floor cannot be seen to move
    expect($tasks->prune(Carbon::now()->subDays(90), batch: 1, maxBatches: 0))->toBe(1)
        ->and(Task::query()->where('title', 'like', 'clamped%')->count())->toBe(1);

    expect($tasks->prune(Carbon::now()->subDays(90), batch: 10, maxBatches: 5))->toBe(1);

    // And with nothing left it stops on the first empty page rather than running its ceiling out.
    // `break` and `continue` delete the same rows; only the query count separates them, and a
    // prune that kept scanning fifty times a night against an empty table is waste nothing would
    // ever report. The same assertion pins `FleetEvents::prune()`, and it was missed here first.
    $selects = 0;

    DB::listen(function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
            $selects++;
        }
    });

    expect($tasks->prune(Carbon::now()->subDays(90), batch: 10, maxBatches: 50))->toBe(0)
        ->and($selects)->toBe(1);
});

/**
 * The scheduled entries naming one command.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  string  $command  The signature to look for.
 * @return list<Event> The entries.
 */
function taskScheduleFor(TestCase $case, string $command): array
{
    return array_values(collect($case->service(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, $command))
        ->all());
}

it('schedules the prune daily, and lets a host turn it off', function (): void {
    $scheduled = taskScheduleFor($this, 'robot-council:prune-tasks');

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]->expression)->toBe('20 3 * * *');

    $this->rebootWith('robot-council.schedule.prune_tasks', false);

    expect(taskScheduleFor($this, 'robot-council:prune-tasks'))->toBeEmpty()
        // The control: the feed's prune is still scheduled, so the empty result is this entry
        // being absent rather than the schedule being empty
        ->and(taskScheduleFor($this, 'robot-council:prune-events'))->toHaveCount(1);
});

it('leaves a finished parent in place while a child of it is still open', function (): void {
    // `parent_task_id` is `nullOnDelete`, so deleting a parent rewrites a row the prune never
    // selected. Here that row is a task nobody has finished: it would keep its place in the queue
    // and silently lose the grouping an agent reads it by, with nothing to say what happened.
    $parent = taskAged($this, TaskStatus::Done, 400, 'finished, with work still under it');
    $child = taskAged($this, TaskStatus::Pending, 400, 'still open', parent: $parent);

    expect(app(Tasks::class)->prune(Carbon::now()->subDays(90)))->toBe(0)
        ->and(Task::query()->whereKey($parent->id)->exists())->toBeTrue();

    // Asserted on the ROW rather than on `$child`, which reports whatever PHP last handed it and
    // would say `parent_task_id` survived however the database had answered
    expect(Task::query()->whereKey($child->id)->value('parent_task_id'))->toBe($parent->id);

    // And it is a delay rather than an exemption. Once the child is finished and old, ONE run
    // takes both: the first batch deletes the child, and the parent is childless by the time the
    // second batch selects. The delay is a batch per level of the tree, not a night per level.
    $child->timestamps = false;
    $child->forceFill(['status' => TaskStatus::Done])->save();

    expect(app(Tasks::class)->prune(Carbon::now()->subDays(90)))->toBe(2)
        ->and(Task::query()->whereKey($child->id)->exists())->toBeFalse()
        ->and(Task::query()->whereKey($parent->id)->exists())->toBeFalse();
});

it('takes one batch per level of a finished tree, so the ceiling bounds the depth a run clears', function (): void {
    // The consequence of the guard that a caller has to know: a parent is only selectable once its
    // children are gone, so clearing a chain of N costs N batches. With the ceiling at 2, a chain
    // of three loses its bottom two and keeps its top until the next run.
    $top = taskAged($this, TaskStatus::Done, 400, 'top');
    $middle = taskAged($this, TaskStatus::Done, 400, 'middle', parent: $top);
    $bottom = taskAged($this, TaskStatus::Done, 400, 'bottom', parent: $middle);

    expect(app(Tasks::class)->prune(Carbon::now()->subDays(90), batch: 500, maxBatches: 2))->toBe(2)
        ->and(Task::query()->whereKey($bottom->id)->exists())->toBeFalse()
        ->and(Task::query()->whereKey($middle->id)->exists())->toBeFalse()
        ->and(Task::query()->whereKey($top->id)->exists())->toBeTrue()
        // Nothing is stranded by that: the next run takes what the ceiling left
        ->and(app(Tasks::class)->prune(Carbon::now()->subDays(90), batch: 500, maxBatches: 2))->toBe(1)
        ->and(Task::query()->whereKey($top->id)->exists())->toBeFalse();
});

it('holds a whole branch back for one live task at the bottom of it', function (): void {
    // The case that separates this guard from the exact-looking one. "Delete a parent when every
    // child is going in this run too" passes the grandparent here -- its only child is terminal
    // and old -- while that child is itself held back by the live grandchild. The grandchild's
    // parent link would survive and its parent's would not, which is the orphan the guard exists
    // to prevent, one level further down than the obvious test reaches.
    $grandparent = taskAged($this, TaskStatus::Done, 400, 'top of the branch');
    $parent = taskAged($this, TaskStatus::Done, 400, 'middle of the branch', parent: $grandparent);
    $child = taskAged($this, TaskStatus::InProgress, 400, 'still being worked on', parent: $parent);

    expect(app(Tasks::class)->prune(Carbon::now()->subDays(90)))->toBe(0);

    $rows = Task::query()->whereKey([$grandparent->id, $parent->id, $child->id])->pluck('id');

    expect($rows)->toHaveCount(3);

    // The control, in the same test: a sibling with no children of its own, identical in status
    // and age, IS deleted -- so the three survivors above are the guard holding rather than a
    // prune that did nothing.
    $sibling = taskAged($this, TaskStatus::Done, 400, 'nothing filed under it');

    expect(app(Tasks::class)->prune(Carbon::now()->subDays(90)))->toBe(1)
        ->and(Task::query()->whereKey($sibling->id)->exists())->toBeFalse()
        ->and(Task::query()->whereKey($grandparent->id)->exists())->toBeTrue();
});
