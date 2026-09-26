<?php

declare(strict_types=1);

/**
 * The unfiltered queue's display window for finished tasks (#420): a `done`, `cancelled` or
 * `failed` task leaves the board's default view once it is older than its status's window, stays
 * reachable through that status's filter, and is never deleted by it.
 *
 * @command  vendor/bin/pest --compact tests/FinishedTaskWindowTest.php
 */

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Livewire\TaskBoard;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\FinishedTaskWindows;
use RobotCouncil\Support\TaskList;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);

    [$this->session] = $this->startAgentSession($this->approveInstallation($this->developer));

    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00'));

    $this->actingAs($this->developer, 'web');
});

/**
 * A task in a given status, last changed a given number of seconds ago.
 *
 * Written through the store and then aged on the row, because `updated_at` is what the window
 * measures and the store stamps it with the current time.
 */
function agedTask(TestCase $case, TaskStatus $status, int $secondsAgo, string $title): Task
{
    $task = $case->service(Tasks::class)->create($case->session, ['title' => $title], false);

    DB::table('robot_council_tasks')->where('id', $task->id)->update([
        'status' => $status->value,
        'updated_at' => CarbonImmutable::now()->subSeconds($secondsAgo)->format('Y-m-d H:i:s'),
    ]);

    return $task;
}

/**
 * The titles the board shows, in its order.
 *
 * @return list<string>
 */
function boardTitles(string $html): array
{
    preg_match_all('/<div class="font-medium">([^<]*)<\/div>/', $html, $titles);

    return array_map(trim(...), $titles[1]);
}

it('hides each finished status just past its window, and keeps it just inside and exactly at it', function (TaskStatus $status, int $hours): void {
    $window = $hours * 3600;

    agedTask($this, $status, $window + 1, 'Just past');
    agedTask($this, $status, $window, 'Exactly at');
    agedTask($this, $status, $window - 60, 'Just inside');

    $titles = boardTitles(Livewire::test(TaskBoard::class)->html());

    expect($titles)->toContain('Exactly at', 'Just inside')
        ->not->toContain('Just past');
})->with([
    'done, one day' => [TaskStatus::Done, 24],
    'cancelled, one day' => [TaskStatus::Cancelled, 24],
    'failed, seven days' => [TaskStatus::Failed, 168],
]);

it('keeps every unfinished task, however old', function (TaskStatus $status): void {
    // Older than every window, and older than the prune: a window is for finished work only
    agedTask($this, $status, 400 * 86400, 'Old but open');

    expect(boardTitles(Livewire::test(TaskBoard::class)->html()))->toContain('Old but open');
})->with([TaskStatus::Pending, TaskStatus::Claimed, TaskStatus::InProgress, TaskStatus::Blocked]);

it('shows every task in a status when the board is filtered to it, past its window or not', function (TaskStatus $status): void {
    agedTask($this, $status, 30 * 86400, 'Long finished');
    agedTask($this, $status, 60, 'Just finished');

    Livewire::test(TaskBoard::class)
        ->assertDontSee('Long finished')
        ->call('showStatus', $status->value)
        ->assertSee('Long finished')
        ->assertSee('Just finished')
        ->assertDontSeeHtml('data-hidden-finished');
})->with([TaskStatus::Done, TaskStatus::Cancelled, TaskStatus::Failed]);

it('says how many finished tasks it hides, by status, and links to each', function (): void {
    agedTask($this, TaskStatus::Done, 2 * 86400, 'Old done one');
    agedTask($this, TaskStatus::Done, 3 * 86400, 'Old done two');
    agedTask($this, TaskStatus::Failed, 8 * 86400, 'Old failure');
    agedTask($this, TaskStatus::Cancelled, 60, 'Recent cancel');
    agedTask($this, TaskStatus::Pending, 60, 'Still waiting');

    $html = Livewire::test(TaskBoard::class)->html();

    preg_match('/<p [^>]*data-hidden-finished>(.*?)<\/p>/s', $html, $notice);

    $words = trim((string) preg_replace('/\s+/', ' ', strip_tags($notice[1] ?? '')));

    expect($words)->toBe('Hidden: 2 done and 1 failed tasks past their display window (done after 24 hours, failed after 7 days). Choose one to see every task in that status.')
        ->and($notice[1] ?? '')->toContain('href="'.route('robot-council.queue', ['status' => 'done']).'"')
        ->toContain('href="'.route('robot-council.queue', ['status' => 'failed']).'"')
        // Only the statuses that are hiding something are offered
        ->not->toContain('status=cancelled')
        ->and(boardTitles($html))->toBe(['Recent cancel', 'Still waiting']);

    // Hidden, not deleted: every row is still in the table
    expect(Task::query()->count())->toBe(5);
});

it('says nothing about hidden tasks when it hides none', function (): void {
    agedTask($this, TaskStatus::Done, 60, 'Recent');

    Livewire::test(TaskBoard::class)->assertDontSeeHtml('data-hidden-finished');
});

it('says the queue has nothing open, rather than that it is empty, when everything on it is hidden', function (): void {
    agedTask($this, TaskStatus::Done, 2 * 86400, 'Old');

    Livewire::test(TaskBoard::class)
        ->assertSee('Nothing open: every task still on the queue is finished and past its display window.')
        ->assertDontSee('Queue empty');
});

it('shows every finished task when a window is zero', function (): void {
    config()->set('robot-council.dashboard.hide_finished_after_hours.done', 0);

    agedTask($this, TaskStatus::Done, 365 * 86400, 'A year done');
    agedTask($this, TaskStatus::Cancelled, 2 * 86400, 'Old cancel');

    $titles = boardTitles(Livewire::test(TaskBoard::class)->html());

    // Zero for done alone: cancelled still hides on its own window
    expect($titles)->toContain('A year done')->not->toContain('Old cancel');
});

it('reads each window from its own setting, and falls back to the default for one it cannot use', function (): void {
    $windows = app(FinishedTaskWindows::class);

    expect($windows->hours())->toBe(['done' => 24, 'failed' => 168, 'cancelled' => 24]);

    config()->set('robot-council.dashboard.hide_finished_after_hours', ['done' => 2, 'cancelled' => 0, 'failed' => 'a week']);

    // `failed` was not a whole number of hours, so it keeps its default rather than hiding at once
    expect($windows->hours())->toBe(['done' => 2, 'failed' => 168, 'cancelled' => 0]);

    config()->set('robot-council.dashboard.hide_finished_after_hours', ['done' => -1, 'cancelled' => FinishedTaskWindows::MAX_HOURS + 1, 'failed' => 12]);

    expect($windows->hours())->toBe(['done' => 24, 'failed' => 12, 'cancelled' => 24])
        // A window of zero has no cutoff at all
        ->and(array_keys($windows->cutoffs(CarbonImmutable::now())))->toBe(['done', 'failed', 'cancelled']);

    config()->set('robot-council.dashboard.hide_finished_after_hours.done', 0);

    expect(array_keys($windows->cutoffs(CarbonImmutable::now())))->toBe(['failed', 'cancelled']);
});

it('never hides an unfinished task, whatever cutoff a caller passes', function (): void {
    agedTask($this, TaskStatus::Pending, 10 * 86400, 'Open and old');
    agedTask($this, TaskStatus::Done, 10 * 86400, 'Done and old');

    $list = app(TaskList::class);
    $cutoffs = ['pending' => CarbonImmutable::now(), 'done' => CarbonImmutable::now(), 'no_such_status' => CarbonImmutable::now()];

    $titles = array_column($list->everything(null, 25, null, $cutoffs)['tasks'], 'title');

    expect($titles)->toBe(['Open and old'])
        ->and($list->hiddenFinished($cutoffs))->toBe(['done' => 1])
        // Filtered to a status, no cutoff applies
        ->and(array_column($list->everything(TaskStatus::Done, 25, null, $cutoffs)['tasks'], 'title'))->toBe(['Done and old']);
});

it('counts as hidden only the tasks the board hides, so the notice agrees with the rows at the cutoff', function (): void {
    agedTask($this, TaskStatus::Done, 86400, 'Exactly at');
    agedTask($this, TaskStatus::Done, 86401, 'Just past');

    $list = app(TaskList::class);
    $cutoffs = app(FinishedTaskWindows::class)->cutoffs(CarbonImmutable::now());

    expect($list->hiddenFinished($cutoffs))->toBe(['done' => 1])
        ->and(array_column($list->everything(null, 25, null, $cutoffs)['tasks'], 'title'))->toBe(['Exactly at']);
});
