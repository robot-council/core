<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\PollInterval;
use RobotCouncil\Support\TaskList;

/**
 * The queue, as a developer sees it.
 *
 * It reads through `Support\TaskList::everything()` rather than querying `robot_council_tasks`, so
 * the ordering and the keyset predicate stay in one place and cannot drift from what an agent pages
 * through. `everything()` rather than `page()` because a signed-in developer sees the whole fleet
 * unredacted -- the decision on robot-council/core#73 -- and the method is named rather than flagged
 * so that no agent-facing call can reach it by passing a boolean.
 *
 * **This component displays another developer's agent's prose.** A task's title and description are
 * written by a machine on somebody else's laptop and rendered on this developer's screen, which is
 * why the guards in #67 and #70 exist and why nothing here renders anything unescaped.
 */
#[Layout('robot-council::layouts.dashboard')]
final class TaskBoard extends Component
{
    /**
     * How many tasks one page holds.
     *
     * Well under `TaskList::MAX_PAGE`, because this is a screen rather than an API response and a
     * hundred rows is not a thing anybody reads.
     */
    public const int PER_PAGE = 25;

    /**
     * The status being shown, or null for every task.
     *
     * Deliberately not `#[Locked]`: this is the page's own filter and the developer sets it.
     *
     * **Nullable deliberately.** Livewire answers a client that sends `null` for a typed property by
     * catching the `TypeError` and calling `unset()`, which leaves a non-nullable typed property
     * *uninitialized* -- and the next read throws `must not be accessed before initialization`,
     * uncaught, as a 500. Nullable, that same path yields `null` and is handled below.
     */
    #[Url(as: 'status', keep: false)]
    public ?string $status = null;

    /**
     * The cursor's priority half, or null at the head of the queue.
     */
    #[Locked]
    public ?int $afterPriority = null;

    /**
     * The cursor's id half, or null at the head of the queue.
     *
     * Locked with its partner, which stops the `updates` map from writing them. It does **not** stop
     * `showNext()` below, which is a public action and is how the rendered button moves the cursor:
     * a client may call it with any pair it likes. That is deliberate, because the reader may
     * already see every task -- #73 decided a signed-in developer sees the whole fleet -- so a
     * cursor they made up reaches nothing they could not have paged to. What locking buys is that
     * the cursor is not silently rewritten underneath a render.
     *
     * It is bounded all the same, because the pair no longer reaches a comparison with the column
     * directly: `TaskList` converts it through `MAX_PRIORITY - $priority` first, and an unbounded
     * `int` from the client overflows that subtraction to a float. Unlike the two API paths, this
     * action carries no `between:0,9`, so it does its own.
     */
    #[Locked]
    public ?int $afterId = null;

    /**
     * The interval this page refreshes on, in seconds.
     */
    #[Locked]
    public int $pollSeconds = PollInterval::DEFAULT;

    /**
     * Take the polling interval, from a parent when there is one and from the host otherwise.
     *
     * A route mounts this component now, so `$pollSeconds` is null on every visit through the
     * dashboard; it stays a parameter because a host may embed the component in a page of its own.
     * `PollInterval::orConfig()` bounds both paths.
     *
     * @param  Repository  $config  The application's configuration repository.
     * @param  int|null  $pollSeconds  The interval a parent passed, or null to read the host's.
     */
    public function mount(Repository $config, ?int $pollSeconds = null): void
    {
        // Null when a route mounted this directly rather than the overview passing it down,
        // which is every visit now that each panel has a page of its own.
        $this->pollSeconds = PollInterval::orConfig($pollSeconds, $config);
    }

    /**
     * Show the next page, from the cursor the last read handed back.
     *
     * @param  int  $priority  The cursor's priority half.
     * @param  int  $id  The cursor's id half.
     */
    public function showNext(int $priority, int $id): void
    {
        $this->afterPriority = max(0, min(Task::MAX_PRIORITY, $priority));
        $this->afterId = max(0, $id);
    }

    /**
     * Go back to the head of the queue.
     */
    public function showFirst(): void
    {
        $this->afterPriority = null;
        $this->afterId = null;
    }

    /**
     * Narrow to one status, or widen to every task.
     *
     * The cursor is dropped, because a position in one filtered ordering means nothing in another.
     *
     * @param  string  $status  The status to show, or an empty string for all of them.
     */
    public function showStatus(string $status): void
    {
        $this->status = $status === '' ? null : $status;

        $this->showFirst();
    }

    /**
     * Render the queue.
     *
     * @param  TaskList  $tasks  The task store.
     * @return View The board.
     */
    public function render(TaskList $tasks): View
    {
        // One more than the page, so that whether a next page exists is known rather than guessed.
        // Deciding it from `count($tasks) === PER_PAGE` is a page behind: on a queue that is an
        // exact multiple of the page size it offers a next page that turns out to be empty.
        $page = $tasks->everything(
            $this->selectedStatus(),
            self::PER_PAGE + 1,
            $this->afterPriority === null || $this->afterId === null
                ? null
                : ['priority' => $this->afterPriority, 'id' => $this->afterId],
        );

        $rows = $page['tasks'];

        $hasMore = \count($rows) > self::PER_PAGE;

        $rows = \array_slice($rows, 0, self::PER_PAGE);

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.task-board';

        return view($template, [
            'tasks' => array_map($this->withAge(...), $rows),
            'cursor' => $this->cursorFor($rows),
            'hasMore' => $hasMore,
            'statuses' => TaskStatus::cases(),
        ]);
    }

    /**
     * The cursor the next page reads from, taken from the last row actually shown.
     *
     * Not the store's, which describes the extra row fetched to detect a next page.
     *
     * @param  list<array<string, mixed>>  $rows  The rows being shown.
     * @return array{priority: int, id: int}|null The cursor, or null when nothing is shown.
     */
    private function cursorFor(array $rows): ?array
    {
        $last = end($rows);

        if (! \is_array($last)) {
            return null;
        }

        return [
            'priority' => \is_int($last['priority'] ?? null) ? $last['priority'] : 0,
            'id' => \is_int($last['id'] ?? null) ? $last['id'] : 0,
        ];
    }

    /**
     * One row, with how long it has been waiting rather than when it arrived.
     *
     * #74 asks for a task's age. An ISO 8601 timestamp is when it was filed, which a reader has to
     * subtract from now themselves.
     *
     * @param  array<string, mixed>  $task  The task as the store described it.
     * @return array<string, mixed> The task, with an `age`.
     */
    private function withAge(array $task): array
    {
        $created = $task['created_at'] ?? null;

        $task['age'] = \is_string($created) ? Carbon::parse($created)->diffForHumans() : null;

        return $task;
    }

    /**
     * The status to filter by, refusing anything that is not one.
     *
     * `$status` is a public property, so it arrives from the client on every update and reaches a
     * `where`. `tryFrom` rather than `from`, so an unknown value widens to every task rather than
     * throwing -- a filter nobody can name is not an error worth a stack trace.
     *
     * @return TaskStatus|null The status, or null for every task.
     */
    private function selectedStatus(): ?TaskStatus
    {
        return $this->status === null || $this->status === ''
            ? null
            : TaskStatus::tryFrom($this->status);
    }
}
