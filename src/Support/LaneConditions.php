<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\LaneHold;
use RobotCouncil\Models\Placement;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use Throwable;

/**
 * The lane conditions that go quiet, raised to the coordinator as fleet events (#319).
 *
 * **#314 decided the coordinator's to-do items reach it as events, not a page**, because reminders
 * armed inside a coordinator session did not fire. Each condition here is a `lane.condition` event,
 * restricted and addressed to the live coordinators, so it arrives through the feed and the stop
 * hook every other event does -- no new channel.
 *
 * **Once per occurrence.** An open row in `robot_council_lane_conditions` means the condition
 * holds; `raised_at` says whether the coordinator was told. A check that no longer finds it clears
 * the row, and a recurrence raises again. With no coordinator live the row waits unraised, so the
 * first check after one appears tells it -- **for the conditions a check recomputes**, which is
 * every one but `merge_behind` and a lane that ended: those are told on the event itself or not at
 * all. Rows are per occurrence, not per coordinator, so a coordinator that restarts is not told
 * again what its predecessor already was; `lane.quiet` behaves the same.
 *
 * **Every raise holds the feed before it reads this table**, which puts the table after the feed
 * sentinel in the package's lock order and makes the check-then-insert atomic -- `for update` on a
 * row that does not exist locks nothing on Postgres. And a raise from inside another write (a
 * presence transition, a GitHub delivery) runs **after that write commits and cannot fail it**: a
 * coordinator not being told is a lesser failure than a session that cannot go stale.
 *
 * The conditions #319 routes to the board rather than to an event -- an owed item settled by its
 * ticket, and a question not yet answered -- are the lane board's (#335), and a placement "never
 * sent" cannot occur since #316 made placement and directive one write.
 */
final class LaneConditions
{
    /**
     * A build lane with room for more work, unparked and unheld, for longer than its window: one
     * holding nothing, or, since #409 lets a lane hold several tickets, one holding fewer than its
     * capacity (#436).
     */
    public const string LANE_FREE = 'lane_free';

    /**
     * A placement the lane has not taken up within its window -- a hand-back owed included.
     */
    public const string NOT_TAKEN_UP = 'not_taken_up';

    /**
     * A lane holding work whose session stopped answering, or ended.
     */
    public const string WORKING_UNOBSERVED = 'working_unobserved';

    /**
     * A ready pull request no gate has picked up within its window.
     */
    public const string PULL_REQUEST_UNPICKED = 'pull_request_unpicked';

    /**
     * A merged pull request, and the live sessions in its repository now behind.
     */
    public const string MERGE_BEHIND = 'merge_behind';

    /**
     * @param  FleetEvents  $events  The change feed.
     * @param  Repository  $config  The application's configuration, for the windows.
     * @param  Seats  $seats  Whether a lane is parked.
     * @param  GateRuns  $gates  Which pull requests a gate is on.
     * @param  ExceptionHandler  $handler  Where a raise that failed after its write is reported.
     */
    public function __construct(
        private readonly FleetEvents $events,
        private readonly Repository $config,
        private readonly Seats $seats,
        private readonly GateRuns $gates,
        private readonly ExceptionHandler $handler
    ) {}

    /**
     * Raise every scheduled condition that holds and has not been raised, and clear those that
     * no longer hold.
     *
     * @param  DateTimeInterface  $at  The moment the check runs.
     * @return int How many it raised.
     */
    public function check(DateTimeInterface $at): int
    {
        $now = CarbonImmutable::instance($at)->utc();

        $current = [
            ...$this->freeLanes($now),
            ...$this->notTakenUp($now),
            ...$this->unpickedPullRequests($now),
            ...$this->unobservedLanes(),
        ];

        $raised = 0;

        foreach ($current as [$condition, $subject, $body, $meta, $due]) {
            $raised += $this->raise($condition, $subject, $body, $meta, $now, $due) ? 1 : 0;
        }

        // `merge_behind` and an ended lane are never recomputed, so every open row of theirs is
        // cleared here: they were told, or could not be, and nothing would clear them otherwise
        $this->clearAllBut(
            [self::LANE_FREE, self::NOT_TAKEN_UP, self::PULL_REQUEST_UNPICKED, self::WORKING_UNOBSERVED, self::MERGE_BEHIND],
            $current,
            $now
        );

        return $raised;
    }

    /**
     * Raise at once when a lane holding work stops answering or ends -- on the presence
     * transition, not on the next check.
     *
     * The work is read here, inside the transition's transaction, so an ended lane is described
     * with what it held before the release takes it. The raise itself waits for the commit.
     *
     * @param  AgentSession  $session  The session that went stale or gone.
     * @param  AgentSessionStatus  $into  Which.
     */
    public function sessionUnobserved(AgentSession $session, AgentSessionStatus $into): void
    {
        $held = $this->heldBy([$session->id])[$session->id] ?? [];

        if ($held === []) {
            return;
        }

        [$condition, $subject, $body, $meta] = $this->unobserved($session->id, $into, $held);
        $at = CarbonImmutable::now('UTC');

        $this->afterCommit(fn (): bool => $this->raise($condition, $subject, $body, $meta, $at));
    }

    /**
     * Raise when a pull request merges, naming the live sessions in its repository now behind.
     *
     * #319 also names "machines whose primary no gate refreshes", which nothing in the fleet
     * defines or records; the sessions are what can be named.
     *
     * @param  string  $repository  The pull request's repository.
     * @param  int  $number  Its number.
     */
    public function merged(string $repository, int $number): void
    {
        // Lanes only: an ephemeral session is a single read, not a checkout that falls behind (#424)
        $behind = AgentSession::announced()
            ->where('status', '!=', AgentSessionStatus::Gone->value)
            ->whereRaw('lower(repository) = ?', [mb_strtolower($repository)])
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        if ($behind === []) {
            return;
        }

        $at = CarbonImmutable::now('UTC');

        $this->afterCommit(fn (): bool => $this->raise(
            self::MERGE_BEHIND,
            $repository.'#'.$number,
            sprintf('%s#%d merged; %d live session(s) in that repository are now behind.', $repository, $number, \count($behind)),
            ['pull_request' => $repository.'#'.$number, 'session_ids' => $behind],
            $at
        ));
    }

    /**
     * Record a condition as holding, and tell the coordinators once it is due.
     *
     * @param  string  $condition  Which condition.
     * @param  string  $subject  What it is about.
     * @param  string  $body  The event's text.
     * @param  array<string, mixed>  $meta  The event's detail.
     * @param  CarbonImmutable  $now  When.
     * @param  bool  $due  Whether its window has passed, so the coordinator should be told now.
     * @return bool True when it was raised.
     */
    private function raise(string $condition, string $subject, string $body, array $meta, CarbonImmutable $now, bool $due = true): bool
    {
        return DB::transaction(function () use ($condition, $subject, $body, $meta, $now, $due): bool {
            // The feed first, for the lock order and for atomicity both -- see the class docblock
            $this->events->hold();

            $open = DB::table('robot_council_lane_conditions')
                ->where('condition', $condition)
                ->where('subject', $subject)
                ->whereNull('cleared_at')
                ->first(['id', 'raised_at']);

            if ($open === null) {
                $id = DB::table('robot_council_lane_conditions')->insertGetId([
                    'condition' => $condition,
                    'subject' => $subject,
                    'observed_at' => $now,
                ]);
            } elseif ($open->raised_at !== null) {
                return false;
            } else {
                $id = $open->id;
            }

            if (! $due) {
                return false;
            }

            $coordinators = AgentSession::query()
                ->where('role', Role::Coordinator->value)
                ->where('status', '!=', AgentSessionStatus::Gone->value)
                ->get()
                ->all();

            if ($coordinators === []) {
                return false;
            }

            DB::table('robot_council_lane_conditions')->where('id', $id)->update(['raised_at' => $now]);

            $this->events->record(
                FleetEventType::LaneCondition,
                null,
                $body,
                ['condition' => $condition, 'subject' => $subject, ...$meta],
                addressees: array_values($coordinators)
            );

            return true;
        });
    }

    /**
     * Run a raise once the surrounding write has committed, reporting rather than throwing.
     *
     * Outside a transaction it runs at once, which is still after everything it follows.
     *
     * @param  Closure(): bool  $raise  The raise.
     */
    private function afterCommit(Closure $raise): void
    {
        DB::afterCommit(function () use ($raise): void {
            try {
                $raise();
            } catch (Throwable $throwable) {
                $this->handler->report($throwable);
            }
        });
    }

    /**
     * Clear the open rows of scheduled conditions no longer found.
     *
     * @param  list<string>  $conditions  The conditions this check computes.
     * @param  list<array{string, string, string, array<string, mixed>, bool}>  $current  What it found.
     * @param  CarbonImmutable  $now  When.
     */
    private function clearAllBut(array $conditions, array $current, CarbonImmutable $now): void
    {
        $holding = [];

        foreach ($current as [$condition, $subject]) {
            $holding[$condition."\n".$subject] = true;
        }

        // Only what was observed before this check began: a row a presence transition inserted
        // while the check ran is not one the check could have found
        $open = DB::table('robot_council_lane_conditions')
            ->whereIn('condition', $conditions)
            ->whereNull('cleared_at')
            ->where('observed_at', '<=', $now)
            ->get(['id', 'condition', 'subject']);

        foreach ($open as $row) {
            $key = (\is_string($row->condition) ? $row->condition : '')."\n".(\is_string($row->subject) ? $row->subject : '');

            if (! isset($holding[$key])) {
                DB::table('robot_council_lane_conditions')->where('id', $row->id)->update(['cleared_at' => $now]);
            }
        }
    }

    /**
     * Build lanes free -- live, holding fewer tasks than their capacity, not parked, not held --
     * and whether each has been free for longer than its window.
     *
     * **Free means room for another placement, which is what `PlacementRules` asks** (#436): a
     * lane holding 1 of 3 is placeable, so it is reported, with how many it holds of how many.
     * Capacity is `Capacity::effective()`, the same number the placement refuses on. A lane at
     * capacity 1 holding nothing reads exactly as it did before #409 -- the same body, the same
     * `meta` -- so a coordinator that never raised a cap sees no change. The occupancy goes in
     * `meta` only where the capacity is above 1, since it is the only case it says anything.
     *
     * **Measured from when a check first saw the lane free**, not from the lane's history. A lane
     * is freed by its own completion, by a pull request merging, by a coordinator moving its work,
     * or by the service releasing it, and those events do not all name the lane -- a reading taken
     * from them started the clock at whatever the lane last did itself, which on a merge-freed lane
     * is hours early. The first sighting is late by at most one check and never early.
     *
     * @param  CarbonImmutable  $now  When.
     * @return list<array{string, string, string, array<string, mixed>, bool}> Every free lane, and
     *                                                                         whether it is due.
     */
    private function freeLanes(CarbonImmutable $now): array
    {
        $window = $this->minutes('free_after_minutes', 30);
        $found = [];

        // Lanes only, which an ephemeral session is not (#424)
        $lanes = AgentSession::announced()
            ->where('role', Role::Build->value)
            ->where('status', AgentSessionStatus::Active->value)
            ->orderBy('id')
            ->get();

        $working = $this->heldBy($lanes->pluck('id')->all());
        $held = LaneHold::query()->whereIn('agent_session_id', $lanes->pluck('id')->all())->pluck('agent_session_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();
        $seats = $this->seats->forSessions($lanes);

        $observed = [];

        foreach (DB::table('robot_council_lane_conditions')->where('condition', self::LANE_FREE)->whereNull('cleared_at')->get(['subject', 'observed_at']) as $row) {
            if (\is_string($row->subject) && \is_string($row->observed_at)) {
                $observed[$row->subject] = CarbonImmutable::parse($row->observed_at, 'UTC');
            }
        }

        foreach ($lanes as $lane) {
            $seat = $seats[$lane->id] ?? null;
            $holding = \count($working[$lane->id] ?? []);
            $capacity = Capacity::effective($lane, $seat);

            if ($holding >= $capacity || \in_array($lane->id, $held, true) || $seat?->isParked() === true) {
                continue;
            }

            $subject = 'session:'.$lane->id;
            $since = $observed[$subject] ?? $now;
            $minutes = (int) $since->diffInMinutes($now, true);

            $found[] = [
                self::LANE_FREE,
                $subject,
                $holding === 0
                    ? sprintf('Session #%d has been free, with no stated hold, for at least %d minutes.', $lane->id, $minutes)
                    : sprintf('Session #%d has had room for more work, holding %d of %d, with no stated hold, for at least %d minutes.', $lane->id, $holding, $capacity, $minutes),
                [
                    'session_id' => $lane->id,
                    'free_since' => $since->toIso8601String(),
                    ...($capacity > 1 ? ['holding' => $holding, 'capacity' => $capacity] : []),
                ],
                $minutes >= $window,
            ];
        }

        return $found;
    }

    /**
     * Stale lanes still holding work, recomputed on every check.
     *
     * The presence transition raises this at once; recomputing it is what tells a coordinator that
     * was not live at the transition, and what clears the row once the lane answers again.
     *
     * @return list<array{string, string, string, array<string, mixed>, bool}> The conditions.
     */
    private function unobservedLanes(): array
    {
        $stale = AgentSession::query()->where('status', AgentSessionStatus::Stale->value)->orderBy('id')->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();

        $found = [];

        foreach ($this->heldBy($stale) as $session => $tasks) {
            $found[] = [...$this->unobserved($session, AgentSessionStatus::Stale, $tasks), true];
        }

        return $found;
    }

    /**
     * One unobserved lane, as a condition.
     *
     * An ended lane is a subject of its own, so a lane that went stale and then ended is told both.
     *
     * @param  int  $session  The session.
     * @param  AgentSessionStatus  $into  Stale or gone.
     * @param  list<int>  $tasks  What it holds.
     * @return array{string, string, string, array<string, mixed>} The condition.
     */
    private function unobserved(int $session, AgentSessionStatus $into, array $tasks): array
    {
        return [
            self::WORKING_UNOBSERVED,
            'session:'.$session.($into === AgentSessionStatus::Gone ? ':gone' : ''),
            sprintf('Session #%d holds work and is %s.', $session, $into->value),
            ['session_id' => $session, 'status' => $into->value, 'task_ids' => $tasks],
        ];
    }

    /**
     * The held tasks of each of these sessions, by session.
     *
     * @param  array<mixed>  $sessions  The session ids.
     * @return array<int, list<int>> Task ids by session, for the sessions holding any.
     */
    private function heldBy(array $sessions): array
    {
        $held = [];

        if ($sessions === []) {
            return $held;
        }

        foreach (Task::query()->whereIn('claimed_by', $sessions)->whereIn('status', TaskStatus::values(TaskStatus::held()))->orderBy('id')->get(['id', 'claimed_by']) as $task) {
            $held[(int) $task->claimed_by][] = $task->id;
        }

        return $held;
    }

    /**
     * Coordinator placements still `claimed` past their take-up window -- a hand-back owed among
     * them, which the event says.
     *
     * @param  CarbonImmutable  $now  When.
     * @return list<array{string, string, string, array<string, mixed>, bool}> The conditions.
     */
    private function notTakenUp(CarbonImmutable $now): array
    {
        // On the application's clock, because `claimed_at` is written with `Carbon::now()` and a
        // bound date is sent as its digits: a UTC cutoff on a host at UTC-5 would put every
        // placement five hours past its window
        $cutoff = $now->subMinutes($this->minutes('take_up_within_minutes', 15))->setTimezone(date_default_timezone_get());
        $found = [];

        $tasks = Task::query()
            ->where('status', TaskStatus::Claimed->value)
            ->where('placed_by', Placement::Coordinator->value)
            ->where('claimed_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        foreach ($tasks as $task) {
            $found[] = [
                self::NOT_TAKEN_UP,
                'task:'.$task->id,
                sprintf('Task #%d was placed on session #%d and has not been taken up%s.', $task->id, (int) $task->claimed_by, $task->hand_back ? ' -- it is a hand-back the lane owes' : ''),
                ['task_id' => $task->id, 'session_id' => $task->claimed_by, 'hand_back' => $task->hand_back],
                true,
            ];
        }

        return $found;
    }

    /**
     * Open, ready pull requests no gate is on, unchanged for longer than the pickup window.
     *
     * "Went ready" is read as the last change GitHub reported, which is when a pull request left
     * draft or was last pushed -- the fleet stores no separate ready time.
     *
     * @param  CarbonImmutable  $now  When.
     * @return list<array{string, string, string, array<string, mixed>, bool}> The conditions.
     */
    private function unpickedPullRequests(CarbonImmutable $now): array
    {
        $cutoff = $now->subMinutes($this->minutes('gate_pickup_within_minutes', 30));
        $running = array_map(mb_strtolower(...), array_values($this->gates->running()));

        // Only where a live gate works. "No gate picked it up" is news where a gate could have,
        // and a repository no gate serves would otherwise raise one event for every open pull
        // request it has -- the whole backfill, on the first run
        $served = AgentSession::query()
            ->where('role', Role::Ci->value)
            ->where('status', '!=', AgentSessionStatus::Gone->value)
            ->whereNotNull('repository')
            ->pluck('repository')
            ->map(static fn (mixed $repository): string => mb_strtolower(\is_string($repository) ? $repository : ''))
            ->all();

        $found = [];

        $pulls = GitHubItem::query()
            ->where('is_pull_request', true)
            ->where('state', 'open')
            ->where('draft', false)
            ->where('github_updated_at', '<=', $cutoff)
            ->orderBy('repository')
            ->orderBy('number')
            ->get();

        foreach ($pulls as $pull) {
            if (! \in_array(mb_strtolower($pull->repository), $served, true) || \in_array(mb_strtolower($pull->reference()), $running, true)) {
                continue;
            }

            $found[] = [
                self::PULL_REQUEST_UNPICKED,
                $pull->reference(),
                sprintf('%s is ready and no gate has picked it up.', $pull->reference()),
                ['pull_request' => $pull->reference()],
                true,
            ];
        }

        return $found;
    }

    /**
     * A configured window, in minutes, at least one.
     *
     * @param  string  $key  The key under `robot-council.lane_conditions`.
     * @param  int  $default  Its default.
     * @return int Minutes.
     */
    private function minutes(string $key, int $default): int
    {
        $value = $this->config->get('robot-council.lane_conditions.'.$key, $default);

        return max(1, \is_int($value) ? $value : $default);
    }
}
