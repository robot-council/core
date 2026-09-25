<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Builder;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;

/**
 * The sessions in the fleet right now, for an agent (#325).
 *
 * **Read from the session table, never rebuilt from the feed.** Pairing every `session.joined` with
 * a later `session.gone` is wrong in both directions: a reader whose cursor started late misses
 * every session that joined before it, and the feed is pruned (#47), so a session that joined
 * before the prune window has no join left to find. The row is what presence maintains, so the row
 * is the answer.
 *
 * **Every agent may read this**, on the rule presence already has for state events: that a session
 * exists, and what state it is in, reaches the whole fleet. Nothing here is free text an agent
 * wrote -- harness, machine label, repository and work location are all charset-limited at the edge
 * -- except the tasks' titles and descriptions, which go through the same readability rule
 * `TaskList` applies, so a reader sees words only on a task it may act on.
 *
 * `Support\FleetPresence` answers the same question for a signed-in developer and takes no session.
 * This one takes the reader, because what the tasks say depends on who is asking.
 */
final class LiveSessions
{
    /**
     * The most sessions one read returns.
     */
    public const int MAX_PAGE = 100;

    /**
     * @param  AgentLogins  $logins  Resolves the GitHub account behind a session.
     */
    public function __construct(private readonly AgentLogins $logins) {}

    /**
     * One page of the sessions that have not gone, newest first.
     *
     * **Paged by `id`, for the reason `FleetPresence::sessions()` gives**: contact time is rewritten
     * on every request, so a keyset built on it moves under the reader and skips whoever heartbeats
     * between two pages. `id` never moves.
     *
     * @param  AgentSession  $reader  The session asking.
     * @param  bool  $asCoordinator  Whether the reader holds `coordinator:direct`.
     * @param  string|null  $repository  Only sessions working in this repository, or null for all.
     * @param  Role|null  $role  Only sessions holding this role, or null for every role.
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @param  int|null  $after  The `cursor` from the previous page.
     * @return array{sessions: list<array<string, mixed>>, cursor: int|null}
     *                                                                       The page, and where to read from next -- null on the last page.
     */
    public function page(
        AgentSession $reader,
        bool $asCoordinator,
        ?string $repository,
        ?Role $role,
        int $limit,
        ?int $after = null
    ): array {
        $size = max(1, min($limit, self::MAX_PAGE));

        // One more than the page, so whether another page exists is known rather than guessed. A
        // cursor offered on a page that happened to be exactly full would lead to an empty one.
        // Fetching one MORE than one extra changes nothing observable, for the reason
        // `FleetPresence::sessions()` records; the exact-multiple test pins the other direction.
        // @pest-mutate-ignore: IncrementInteger
        $sessions = AgentSession::query()
            ->with('installation')
            ->where('status', '<>', AgentSessionStatus::Gone->value)

            // Without case, as GitHub compares repository names: a session reports whichever
            // spelling its checkout has, and the column's comparison differs by engine -- MySQL's
            // default collation ignores case where SQLite and Postgres do not
            ->when($repository !== null, fn (Builder $query) => $query->whereRaw('lower(repository) = ?', [mb_strtolower((string) $repository)]))
            ->when($role instanceof Role, fn (Builder $query) => $query->where('role', $role?->value))
            ->when($after !== null, fn (Builder $query) => $query->where('id', '<', $after))
            ->orderByDesc('id')
            ->limit($size + 1)
            ->get();

        $more = $sessions->count() > $size;

        $sessions = $sessions->take($size);

        // Keyed by the row's own `user_id`, never looked up by session id: a session id is reused
        // once its row goes, and `forUsers()` cannot be re-pointed that way
        $logins = $this->logins->forUsers($sessions->pluck('user_id')->all());

        // Every held task for the whole page in one query, rather than one per session. `claimed_by`
        // survives a task finishing, so the status is what says it is still held.
        $held = [];

        foreach (Task::query()
            ->whereIn('claimed_by', $sessions->pluck('id')->all())
            ->whereIn('status', TaskStatus::values(TaskStatus::held()))
            ->orderBy('id')
            ->get() as $task) {
            $held[(int) $task->claimed_by][] = $task;
        }

        return [
            'sessions' => array_values($sessions->map(fn (AgentSession $session): array => [
                'id' => $session->id,
                'github_login' => $logins[$session->user_id] ?? null,
                'harness' => $session->installation->harness,
                'machine_label' => $session->installation->machine_label,
                'role' => $session->role->value,
                'repository' => $session->repository,
                'work_location' => $session->work_location,

                // Read from the row, which is the decision the sweep made, rather than recomputed
                // from the contact time -- a reader deriving it would disagree with every
                // conditional update in the package until the next sweep
                'status' => $session->status->value,
                'last_seen_at' => $session->last_seen_at->toIso8601String(),
                'tasks' => array_map(
                    fn (Task $task): array => $this->task($task, $asCoordinator || $task->isClaimableBy($reader)),
                    $held[$session->id] ?? []
                ),
            ])->all()),
            'cursor' => $more ? $sessions->last()?->id : null,
        ];
    }

    /**
     * One held task, as this reader may see it.
     *
     * @param  Task  $task  The task.
     * @param  bool  $readable  Whether the reader may see what it says.
     * @return array<string, mixed> The task.
     */
    private function task(Task $task, bool $readable): array
    {
        return [
            'id' => $task->id,
            'status' => $task->status->value,
            'readable' => $readable,
            'title' => $readable ? $task->title : null,
            'description' => $readable ? $task->description : null,
        ];
    }
}
