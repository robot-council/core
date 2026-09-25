<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\LaneHold;
use RobotCouncil\Models\Placement;
use RobotCouncil\Models\Seat;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;

/**
 * The lane board #314 asked for, read from measured state and never typed (#317).
 *
 * **Every cell is derived, and a missing field fails toward the honest reading.** A lane's `State`
 * is one of five and is computed here, never stored: a lane with no task is never `Working`,
 * whatever anything else says, and a cell whose data has no model yet reads "not reported" rather
 * than a stand-in -- #317's decision, with the model each such cell waits on named where it is.
 *
 * **`Parked` is `Seats::of()`, the rule a placement refuses on (#320)**, so the label and the
 * refusal cannot disagree.
 *
 * @phpstan-type LaneRow array{id: int, repository: string|null, developer: string|null, machine: string, harness: string, slot: string|null, is_gate: bool, state: string, watcher: null, on_what: array<string, mixed>|null, known_since: Carbon}
 *
 * The reader is for a developer on the dashboard, who #73 decided sees the whole fleet, so issue
 * references and branches are shown here though `TaskList` withholds them from agents that may not
 * claim the task.
 */
final class LaneBoard
{
    /**
     * The most lanes the board lists. A fleet has one lane per live checkout, which is tens.
     */
    public const int MAX_LANES = 200;

    /**
     * The most open pull requests listed per repository.
     */
    public const int MAX_PULL_REQUESTS = 50;

    /**
     * The five states a lane can be in, and nothing else renders.
     *
     * @var list<string>
     */
    public const array STATES = ['Working', 'Idle', 'Parked', 'Blocked', 'not observed'];

    /**
     * @param  Seats  $seats  The seat store, for `Parked`.
     * @param  AgentLogins  $logins  Resolves host user keys to GitHub logins.
     * @param  GateRuns  $gates  What each gate is validating (#336).
     * @param  Backlog  $backlog  The backlog counts, for the meters.
     */
    public function __construct(
        private readonly Seats $seats,
        private readonly AgentLogins $logins,
        private readonly GateRuns $gates,
        private readonly Backlog $backlog
    ) {}

    /**
     * The board.
     *
     * @return array{
     *     lanes: array<string, list<LaneRow>>,
     *     pull_requests: array<string, list<array{number: int, reference: string, title: string, state: string}>>,
     *     queue_depth: array<string, int>,
     *     meters: array<string, array{count: int|null, delta: int|null, age_seconds: int|null}>,
     *     counts: array<string, int>,
     *     truncated: bool,
     *     last_change: Carbon|null,
     *     observed_at: Carbon
     * }
     */
    public function read(): array
    {
        [$rows, $truncated] = $this->lanes();

        // The board's last change: the latest a held task, a hold or a park moved. Not the read time,
        // which on a page that polls is always now; not a session's contact, which moves with every
        // heartbeat and would call an unchanged board changed
        $changes = array_filter([
            Task::query()->whereIn('status', TaskStatus::values(TaskStatus::held()))->max('updated_at'),
            LaneHold::query()->max('held_at'),
            Seat::query()->max('updated_at'),
        ], \is_string(...));

        $pulls = $this->pullRequests(array_keys($rows));

        return [
            'lanes' => $rows,
            'pull_requests' => $pulls,

            // A repository's queue depth: open, not a draft, and no gate on it (#336)
            'queue_depth' => array_map(
                static fn (array $queue): int => \count(array_filter($queue, static fn (array $pull): bool => $pull['state'] === 'queued')),
                $pulls
            ),
            'meters' => $this->meters(array_keys($rows)),
            'counts' => self::tally($rows),
            'truncated' => $truncated,
            'last_change' => $changes === [] ? null : Carbon::parse(max($changes)),
            'observed_at' => Carbon::now(),
        ];
    }

    /**
     * Every live lane's row, grouped by repository and ordered as #314 orders the board.
     *
     * A fixed number of queries however many lanes there are: the seats and the issues are read in
     * one query each rather than one per lane.
     *
     * @return array{array<string, list<LaneRow>>, bool} The rows, and whether the list was cut short.
     */
    private function lanes(): array
    {
        $sessions = AgentSession::query()
            ->with('installation')
            ->where('status', '!=', AgentSessionStatus::Gone->value)
            ->orderBy('id')
            ->limit(self::MAX_LANES + 1)
            ->get();

        $truncated = $sessions->count() > self::MAX_LANES;
        $sessions = $sessions->take(self::MAX_LANES);

        $ids = $sessions->pluck('id')->all();

        $tasks = Task::query()
            ->whereIn('claimed_by', $ids)
            ->whereIn('status', TaskStatus::values(TaskStatus::held()))
            ->orderBy('id')
            ->get()
            // Grouped, not keyed: nothing holds a lane to one task, and keying would keep one of
            // them and drop the rest without a word
            ->groupBy('claimed_by');

        $holds = LaneHold::query()->whereIn('agent_session_id', $ids)->get()->keyBy('agent_session_id');

        // Batched, so the board costs the same few queries however many lanes it lists
        $seats = $this->seats->forSessions($sessions);
        $issues = $this->issues($tasks->flatten()->all());
        $logins = $this->logins->forUsers($sessions->pluck('user_id')->all());
        $runs = $this->gates->running(array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids)));

        $rows = [];

        foreach ($sessions as $session) {
            $held = array_values($tasks->get($session->id)?->all() ?? []);
            $hold = $holds->get($session->id);
            $row = $this->row($session, $held, $hold instanceof LaneHold ? $hold : null, $seats[$session->id] ?? null, $issues, $logins, $runs[$session->id] ?? null);
            $rows[$row['repository'] ?? ''][] = $row;
        }

        // Builds before gates; then developer, machine and slot -- #314's order
        foreach ($rows as $repository => $group) {
            usort($group, static fn (array $a, array $b): int => [$a['is_gate'], $a['developer'] ?? '', $a['machine'], $a['slot'] ?? '']
                <=> [$b['is_gate'], $b['developer'] ?? '', $b['machine'], $b['slot'] ?? '']);
            $rows[$repository] = $group;
        }

        ksort($rows, SORT_STRING);

        return [$rows, $truncated];
    }

    /**
     * How many lanes are in each state, for the overview's summary.
     *
     * The same rows the board renders, without the pull-request queues the summary never shows.
     *
     * @return array<string, int> Counts by state, every state present.
     */
    public function counts(): array
    {
        return self::tally($this->lanes()[0]);
    }

    /**
     * Counts by state.
     *
     * @param  array<string, list<LaneRow>>  $rows  The lanes, grouped.
     * @return array<string, int> Counts by state, every state present.
     */
    private static function tally(array $rows): array
    {
        $counts = array_fill_keys(self::STATES, 0);

        foreach ($rows as $group) {
            foreach ($group as $row) {
                $counts[$row['state']]++;
            }
        }

        return $counts;
    }

    /**
     * One lane's row.
     *
     * @param  AgentSession  $session  The lane.
     * @param  list<Task>  $held  The tasks it holds, oldest first.
     * @param  LaneHold|null  $hold  Its hold.
     * @param  Seat|null  $parked  Its seat, which says whether it is parked -- `Seats::of()`'s match.
     * @param  array<string, GitHubItem>  $issues  Stored issues by lower-cased reference.
     * @param  array<string, string>  $logins  GitHub logins by host key.
     * @param  string|null  $run  The pull request a gate is validating, `owner/name#N`.
     * @return LaneRow The row.
     */
    private function row(AgentSession $session, array $held, ?LaneHold $hold, ?Seat $parked, array $issues, array $logins, ?string $run): array
    {
        $task = $held[0] ?? null;

        $parker = $parked?->parked_by;

        // The state and its `On what` decided together, in #314's order: a lane that is not being
        // observed says so first, and a lane with a task is working whatever else is true of it
        [$state, $onWhat] = match (true) {
            $session->status === AgentSessionStatus::Stale => ['not observed', null],
            $task instanceof Task => ['Working', [...$this->working($task, $issues), 'also_holds' => \count($held) - 1]],
            // A gate's work is a pull request rather than a task: it is working while it runs one
            $run !== null => ['Working', ['gate_pull_request' => $run]],
            $parked?->isParked() === true => ['Parked', ['party' => ($parker === null ? null : ($logins[$parker] ?? null)) ?? 'its developer', 'what' => 'parked this seat']],
            $hold instanceof LaneHold => ['Blocked', ['party' => $hold->party, 'what' => $hold->reason->reads()]],
            default => ['Idle', null],
        };

        return [
            'id' => $session->id,
            'repository' => $session->repository,
            'developer' => $logins[$session->user_id] ?? null,
            'machine' => $session->installation->machine_label,
            'harness' => $session->installation->harness,
            'slot' => $session->work_location,
            'is_gate' => $session->role === Role::Ci,
            'state' => $state,

            // Its own column, and not reported until #337 records a watcher apart from the session
            'watcher' => null,

            'on_what' => $onWhat,

            // How long ago the state was OBSERVED -- the session's last contact -- not how long it
            // has held, which nothing records honestly
            'known_since' => $session->last_seen_at,
        ];
    }

    /**
     * What a working lane is on.
     *
     * @param  Task  $task  The task it holds.
     * @param  array<string, GitHubItem>  $issues  Stored issues by lower-cased reference.
     * @return array<string, mixed> The cell.
     */
    private function working(Task $task, array $issues): array
    {
        $item = \is_string($task->issue) ? ($issues[mb_strtolower($task->issue)] ?? null) : null;

        $packet = $item instanceof GitHubItem && \in_array('decision-fork', $item->labels, true);

        return [
            'task_id' => $task->id,
            'ticket' => $task->issue,
            'title' => $task->title,
            'branch' => $task->branch ?? ($packet ? 'packet, no branch expected' : 'branch not reported'),
            'branch_reported' => $task->branch !== null,

            // A directive sent is not a lane building: take-up is the lane's own `start`
            'taken_up' => $task->status === TaskStatus::InProgress,
            'blocked' => $task->status === TaskStatus::Blocked,
            'hand_back' => $task->hand_back,

            // "Never sent" cannot occur since #316 made placement and directive one write, and
            // "placed by a developer directly" has no writer; see `Models\Placement`
            'provenance' => match ($task->placed_by) {
                Placement::Coordinator => 'placed and told',
                Placement::Lane => 'chosen by the lane',
                null => 'not recorded',
            },
        ];
    }

    /**
     * The stored issues the held tasks name, in one query.
     *
     * @param  array<array-key, mixed>  $tasks  The held tasks.
     * @return array<string, GitHubItem> By lower-cased `owner/name#N`.
     */
    private function issues(array $tasks): array
    {
        $numbers = [];

        foreach ($tasks as $task) {
            if ($task instanceof Task && \is_string($task->issue)) {
                $numbers[] = (int) explode('#', $task->issue, 2)[1];
            }
        }

        if ($numbers === []) {
            return [];
        }

        $issues = [];

        // By number, then matched on the whole reference in PHP, without case as GitHub names repositories
        foreach (GitHubItem::query()->whereIn('number', array_values(array_unique($numbers)))->where('is_pull_request', false)->get() as $item) {
            $issues[mb_strtolower($item->reference())] = $item;
        }

        return $issues;
    }

    /**
     * Each repository's open pull requests, from what GitHub has told the fleet (#318).
     *
     * `running` when a gate reported it is validating it (#336), and otherwise `draft` or `queued`.
     *
     * @param  list<string|int>  $repositories  The repositories on the board.
     * @return array<string, list<array{number: int, reference: string, title: string, state: string}>> By repository.
     */
    private function pullRequests(array $repositories): array
    {
        // Each board repository under the name it is shown by, looked up without case: GitHub names
        // `Robot-Council/core` and `robot-council/core` one repository, while a session reports
        // whichever spelling its checkout has
        $shown = [];
        $queues = [];

        foreach ($repositories as $repository) {
            if (\is_string($repository) && $repository !== '') {
                $shown[mb_strtolower($repository)] = $repository;
                $queues[$repository] = [];
            }
        }

        if ($shown === []) {
            return [];
        }

        // Which open pull requests a gate is on, compared without case like the repositories
        $running = array_map(mb_strtolower(...), array_values($this->gates->running()));

        // One query for every repository, rather than one each
        $placeholders = implode(', ', array_fill(0, \count($shown), '?'));

        $pulls = GitHubItem::query()
            ->whereRaw('lower(repository) in ('.$placeholders.')', array_keys($shown))
            ->where('is_pull_request', true)
            ->where('state', 'open')
            ->orderBy('number')
            ->get();

        foreach ($pulls as $pull) {
            $board = $shown[mb_strtolower($pull->repository)] ?? null;

            if ($board === null || \count($queues[$board]) >= self::MAX_PULL_REQUESTS) {
                continue;
            }

            $queues[$board][] = [
                'number' => $pull->number,
                'reference' => $pull->reference(),
                'title' => $pull->title,
                'state' => \in_array(mb_strtolower($pull->reference()), $running, true) ? 'running' : ($pull->draft ? 'draft' : 'queued'),
            ];
        }

        return $queues;
    }

    /**
     * Each repository's backlog meter, from the counts sessions report (#339).
     *
     * **Unreadable is a dash, never a number.** An absent count, and one older than
     * `backlog.stale_after_minutes`, both arrive here as a null count; a meter that showed the last
     * number it had, or zero, would say something nobody measured.
     *
     * @param  list<string|int>  $repositories  The repositories on the board.
     * @return array<string, array{count: int|null, delta: int|null, age_seconds: int|null}> By repository.
     */
    private function meters(array $repositories): array
    {
        return $this->backlog->meters(
            array_values(array_filter($repositories, static fn (mixed $repository): bool => \is_string($repository) && $repository !== '')),
            Carbon::now()
        );
    }
}
