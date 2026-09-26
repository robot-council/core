<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;

/**
 * Reads tasks for an agent session.
 *
 * **Every agent sees that every task exists. Not every agent sees what it says.** A task's row --
 * its id, status, priority, project and provenance -- is fleet state, and withholding it would give
 * a queue half the fleet is blind to. Its title, description, payload and result are something else:
 * they are instructions, and task content is untrusted input to an agent that may have shell access.
 * #29 settled that question for narration, and this applies the same answer: content reaches the
 * reader's own developer's work, work a coordinator opened to the fleet, and a reader holding
 * `coordinator:direct`. Everyone else sees the row and not the words.
 *
 * Without that split a session holding only `tasks:create` -- the narrowest ability there is -- could
 * put arbitrary text in front of every agent in the fleet, which is exactly what #29 stops it doing
 * through narration.
 *
 * **The page is a cursor, not a window.** Ordering by priority with a plain limit would make the
 * tail of the queue permanently unreachable: a hundred tasks at the top priority, which one token
 * can file in a minute, and nothing filed afterwards could ever be seen. `FleetFeed` pages the ID
 * space for the same reason, and nothing prunes either table.
 *
 * Provenance travels with every task, derived on read and never taken from what a creator claimed.
 */
final class TaskList
{
    /**
     * The most tasks one read returns, whatever the caller asks for.
     */
    public const int MAX_PAGE = 100;

    /**
     * @param  AgentLogins  $logins  Who each session belongs to.
     */
    /**
     * @param  AgentLogins  $logins  Who each session belongs to.
     * @param  SessionLabels  $labels  Each session named for a person, for the dashboard's read only (#421).
     */
    public function __construct(private readonly AgentLogins $logins, private readonly SessionLabels $labels) {}

    /**
     * Read a page of tasks, most urgent first, from a cursor.
     *
     * The cursor is the last row returned, as the pair the ordering is built on. Paging on that
     * pair rather than on an offset means a task filed or claimed between two reads cannot make a
     * reader skip a row or see one twice.
     *
     * @param  TaskStatus|null  $status  The status to filter by, or null for every status.
     * @param  int  $limit  How many to return.
     * @param  AgentSession  $reader  The session doing the reading.
     * @param  bool  $asCoordinator  Whether the reader holds `coordinator:direct`.
     * @param  array{priority: int, id: int}|null  $after  The last row the reader has seen.
     * @return array{tasks: list<array<string, mixed>>, cursor: array{priority: int, id: int}|null}
     *                                                                                              The page, and where to read from next.
     */
    public function page(
        ?TaskStatus $status,
        int $limit,
        AgentSession $reader,
        bool $asCoordinator,
        ?array $after = null
    ): array {
        $tasks = $this->queue($status, $limit, $after);

        $logins = $this->logins->forSessions([
            ...$tasks->pluck('created_by')->all(),
            ...$tasks->pluck('claimed_by')->all(),
        ]);

        $last = $tasks->last();

        return [
            'tasks' => array_values(array_map(
                // The same audience #16 decided may act on this task, plus a coordinator, which is
                // the pair #29 uses for narration. Everyone else is told the task exists and not
                // what it says.
                fn (Task $task): array => $this->describe(
                    $task,
                    $logins,
                    $asCoordinator || $task->isClaimableBy($reader)
                ),
                $tasks->all()
            )),
            'cursor' => $last instanceof Task ? ['priority' => $last->priority, 'id' => $last->id] : null,
        ];
    }

    /**
     * The whole queue, with every task's content, for a signed-in developer.
     *
     * **Named rather than flagged, and taking no session, deliberately.** The redaction `page()`
     * applies is a prompt-injection control: an agent that cannot act on a task should not read text
     * that might instruct it. A human reading a dashboard is not vulnerable that way, and a
     * supervisor who cannot read the queue is not supervising -- the decision on
     * robot-council/core#73. But a `bool $unfiltered` parameter on `page()` is exactly the shape
     * that is later passed `true` from an agent path by mistake, so this is a separate method whose
     * name says what it is and which cannot be reached by an agent-facing call at all.
     *
     * The caller is responsible for having established that the reader is an allowlisted developer.
     * Nothing here checks that, because nothing here can: there is no session to ask.
     *
     * **Finished tasks past their display window are left out of the unfiltered read** when the
     * caller passes cutoffs (#420), and never out of a read filtered to one status: that is how a
     * developer reaches finished work. The windows are `Support\FinishedTaskWindows`'s; this takes
     * the cutoffs rather than reading configuration, so the board decides when it is read.
     *
     * @param  TaskStatus|null  $status  The status to show, or null for every task.
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @param  array{priority: int, id: int}|null  $after  The last row the reader has seen.
     * @param  array<string, DateTimeInterface>  $hiddenBefore  By terminal status value, the moment before
     *                                                          which a task in it is left out of an
     *                                                          unfiltered read.
     * @return array{tasks: list<array<string, mixed>>, cursor: array{priority: int, id: int}|null}
     *                                                                                              The page, and where to read from next.
     */
    public function everything(?TaskStatus $status, int $limit, ?array $after = null, array $hiddenBefore = []): array
    {
        $tasks = $this->queue($status, $limit, $after, $status instanceof TaskStatus ? [] : $this->terminalOnly($hiddenBefore));

        // The dashboard names a session by where it works as well as by its developer's login
        // (#421), read in the same two queries the login alone cost. The label is added here and
        // not in `describe()`, which the agents' read shares: they keep the ids and logins they
        // pass back in tool calls.
        $people = $this->labels->forSessions([
            ...$tasks->pluck('created_by')->all(),
            ...$tasks->pluck('claimed_by')->all(),
        ]);

        $logins = array_filter(array_map(static fn (array $person): ?string => $person['login'], $people), is_string(...));
        $labels = array_filter(array_map(static fn (array $person): ?string => $person['label'], $people), is_string(...));

        $last = $tasks->last();

        return [
            'tasks' => array_values(array_map(
                fn (Task $task): array => $this->labelled($this->describe($task, $logins, readable: true), $labels),
                $tasks->all()
            )),
            'cursor' => $last instanceof Task ? ['priority' => $last->priority, 'id' => $last->id] : null,
        ];
    }

    /**
     * How many finished tasks an unfiltered read leaves out, by status (#420).
     *
     * One grouped count rather than one per status, because the board asks on every poll.
     *
     * @param  array<string, DateTimeInterface>  $hiddenBefore  By terminal status value, the cutoff `everything()` was given.
     * @return array<string, int> By status value, only those leaving something out, in `TaskStatus` order.
     */
    public function hiddenFinished(array $hiddenBefore): array
    {
        $hiddenBefore = $this->terminalOnly($hiddenBefore);

        if ($hiddenBefore === []) {
            return [];
        }

        $counted = Task::query()
            ->where(static function (Builder $query) use ($hiddenBefore): void {
                foreach ($hiddenBefore as $status => $before) {
                    $query->orWhere(static fn (Builder $older): Builder => $older->where('status', $status)->where('updated_at', '<', $before));
                }
            })
            ->toBase()
            ->selectRaw('status, count(*) as hidden')
            ->groupBy('status')
            ->pluck('hidden', 'status');

        $hidden = [];

        foreach (TaskStatus::terminal() as $status) {
            $count = $counted[$status->value] ?? 0;

            if (is_numeric($count) && (int) $count > 0) {
                $hidden[$status->value] = (int) $count;
            }
        }

        return $hidden;
    }

    /**
     * The cutoffs for terminal statuses only.
     *
     * **An unfinished task is never hidden, whatever a caller passes.** The window is for finished
     * work; a `pending` or `blocked` task older than any window is exactly what the board is for.
     *
     * @param  array<string, DateTimeInterface>  $hiddenBefore  Cutoffs by status value.
     * @return array<string, DateTimeInterface> Those whose status is terminal.
     */
    private function terminalOnly(array $hiddenBefore): array
    {
        return array_filter(
            $hiddenBefore,
            static fn (string $status): bool => TaskStatus::tryFrom($status)?->isTerminal() === true,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * One page of the queue, in the order the queue is read.
     *
     * Shared by both readers, so the ordering and the keyset predicate cannot drift between what an
     * agent pages through and what a developer sees.
     *
     * @param  TaskStatus|null  $status  The status to filter to, or null for every task.
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @param  array{priority: int, id: int}|null  $after  The last row the reader has seen.
     * @param  array<string, DateTimeInterface>  $hiddenBefore  By terminal status, the moment before which a task is left out.
     * @return Collection<int, Task> The page.
     */
    private function queue(?TaskStatus $status, int $limit, ?array $after, array $hiddenBefore = []): Collection
    {
        return Task::query()
            ->when($status instanceof TaskStatus, fn (Builder $query) => $query->where('status', $status?->value))
            ->when($hiddenBefore !== [], function (Builder $query) use ($hiddenBefore): void {
                // One exclusion per status rather than a disjunction over them, so a task is kept
                // unless it is BOTH in that status AND older than its window. `updated_at` is when
                // it finished; a task exactly at the cutoff is kept. A row with no `updated_at`
                // (only a raw insert makes one) is kept rather than hidden, because SQL's `NOT` of
                // an unknown comparison is unknown, and the count below would not have counted it.
                //
                // **The cost this accepts** (#420): the exclusion is a filter on the queue index's
                // walk, so a board with fewer open tasks than a page reads every retained row.
                // Measured on PostgreSQL 17.0 at 20,000 rows with five open: 2,918 buffers and
                // about 4ms. `retention.tasks_days` bounds the table, and the board polls it.
                foreach ($hiddenBefore as $hidden => $before) {
                    $query->whereNot(static fn (Builder $older): Builder => $older->where('status', $hidden)->whereNotNull('updated_at')->where('updated_at', '<', $before));
                }
            })
            ->when($after !== null, function (Builder $query) use ($after): void {
                $priority = \is_int($after['priority'] ?? null) ? $after['priority'] : 0;
                $id = \is_int($after['id'] ?? null) ? $after['id'] : 0;

                // The cursor arrives in the client's vocabulary and is converted here. Nothing
                // outside this class knows `queue_rank` exists, which is what lets the ordering
                // change without the wire format changing.
                $rank = Task::MAX_PRIORITY - $priority;

                // A row-value comparison rather than the `rank > ? or (rank = ? and id > ?)`
                // disjunction it replaces. Both express the same keyset, but a disjunction is the
                // shape planners handle worst, while `(a, b) > (?, ?)` is a range seek down an
                // index whose columns all ascend.
                //
                // **Syntax support and optimization are different questions.** Row values parse on
                // SQLite since 3.15 (2016), and on Postgres and MySQL throughout. That the planner
                // turns one into a seek is measured HERE ON SQLITE ONLY. MySQL gained range-scan
                // support for row constructors in 5.7.3, and its documented example is a bare
                // `(a, b) > (?, ?)` rather than the leading equality this shape has, so even a
                // modern MySQL wants measuring; that is #39. MariaDB is a separate question again.
                $query->whereRaw('(queue_rank, id) > (?, ?)', [$rank, $id]);
            })

            // The queue's order: the most urgent first, and among equals the oldest, so a task
            // nobody claims does not sink under everything filed after it. Both columns ascend, so
            // an index can be walked rather than sorted -- which `priority desc, id asc` could not
            // be, in either scan direction. Which index depends on whether `status` was given, and
            // the table carries one for each shape; the migration records the plans.
            ->orderBy('queue_rank')
            ->orderBy('id')
            ->limit(max(1, min($limit, self::MAX_PAGE)))
            ->get();
    }

    /**
     * One task as an agent sees it.
     *
     * @param  Task  $task  The task.
     * @param  array<int, string>  $logins  GitHub logins, keyed by agent session ID.
     * @param  bool  $readable  Whether this reader may see the task's content.
     * @return array<string, mixed> The task.
     */
    private function describe(Task $task, array $logins, bool $readable): array
    {
        return [
            'id' => $task->id,
            'parent_task_id' => $task->parent_task_id,
            'title' => $readable ? $task->title : null,
            'description' => $readable ? $task->description : null,
            'status' => $task->status->value,
            'priority' => $task->priority,
            'payload' => $readable ? $task->payload : null,
            'result' => $readable ? $task->result : null,
            'readable' => $readable,
            'project_id' => $task->project_id,

            // **What the task is about, so behind the same rule as its title.** Charset-limited as
            // they are, a ticket and a branch say what the work is, and the redaction here is a
            // prompt-injection control: an agent that cannot act on a task should not read what
            // might instruct it. It costs no real reader anything -- a coordinator, the lane holding
            // the task, and the human dashboard all read with `$readable` true.
            'issue' => $readable ? $task->issue : null,
            'branch' => $readable ? $task->branch : null,

            // How it came to be held, which is state rather than content, beside `status`
            'placed_by' => $task->placed_by?->value,
            'hand_back' => $task->hand_back,
            'created_at' => $task->created_at?->toIso8601String(),
            'claimed_at' => $task->claimed_at?->toIso8601String(),

            // Derived by the server on every read. `coordinator_direct` is what the creating
            // session held at the time, which is also what decides who may claim this.
            'created_by' => $this->actor($task->created_by, $logins, $task->created_with_coordinator),
            'claimed_by' => $this->actor($task->claimed_by, $logins, null),
        ];
    }

    /**
     * A described task with a `label` on each session it names, where that session has one.
     *
     * @param  array<string, mixed>  $described  The task, as `describe()` put it.
     * @param  array<int, string>  $labels  Labels, keyed by agent session id.
     * @return array<string, mixed> The same task.
     */
    private function labelled(array $described, array $labels): array
    {
        foreach (['created_by', 'claimed_by'] as $side) {
            $actor = $described[$side] ?? null;

            if (\is_array($actor)) {
                $session = $actor['session_id'] ?? null;
                $actor['label'] = \is_int($session) ? ($labels[$session] ?? null) : null;
                $described[$side] = $actor;
            }
        }

        return $described;
    }

    /**
     * One session, as provenance.
     *
     * @param  int|null  $sessionId  The session, when there is one.
     * @param  array<int, string>  $logins  GitHub logins, keyed by agent session ID.
     * @param  bool|null  $coordinator  Whether the session held `coordinator:direct`, where that is
     *                                  recorded.
     * @return array<string, mixed>|null The actor, or null where there is none.
     */
    private function actor(?int $sessionId, array $logins, ?bool $coordinator): ?array
    {
        if ($sessionId === null) {
            return null;
        }

        $actor = [
            'session_id' => $sessionId,
            'github_login' => $logins[$sessionId] ?? null,
        ];

        if ($coordinator !== null) {
            $actor['coordinator_direct'] = $coordinator;
        }

        return $actor;
    }

    /**
     * Whether a session may be handed a task.
     *
     * A session that has gone is refused: it will never make another request, so a task assigned to
     * one would sit held until the sweep released it. A `stale` session is accepted, because it is
     * a process that has been quiet rather than one that has stopped -- refusing it would make an
     * agent unable to receive work for as long as its build runs, and if it really has died the
     * sweep releases the task soon enough.
     *
     * @param  AgentSession|null  $session  The proposed assignee.
     * @return bool True when the session can be handed work.
     */
    public static function canBeAssigned(?AgentSession $session): bool
    {
        return $session instanceof AgentSession && ! $session->hasGone();
    }

    /**
     * How many tasks the fleet still has to deal with.
     *
     * **Everything that is not terminal**, which is `pending`, `claimed`, `in_progress` and
     * `blocked`. Derived from `TaskStatus::terminal()` rather than listed here, for the reason that
     * method's own docblock gives: adding a status forces the decision rather than silently
     * landing on one side.
     *
     * A counting query rather than a `count()` over a paged read, which is bounded by `MAX_PAGE` --
     * so measuring a page would report the bound rather than the total on any queue deep enough for
     * the number to matter.
     *
     * @return int The number of tasks in a non-terminal status.
     */
    public function openTasks(): int
    {
        return Task::query()
            ->whereNotIn('status', TaskStatus::values(TaskStatus::terminal()))
            ->count();
    }
}
