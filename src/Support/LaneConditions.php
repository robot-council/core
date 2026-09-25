<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
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

/**
 * The lane conditions that go quiet, raised to the coordinator as fleet events (#319).
 *
 * **#314 decided the coordinator's to-do items reach it as events, not a page**, because reminders
 * armed inside a coordinator session did not fire. Each condition here is a `lane.condition` event,
 * restricted and addressed to the live coordinators, so it arrives through the feed and the stop
 * hook every other event does -- no new channel.
 *
 * **Once per occurrence.** An open row in `robot_council_lane_conditions` means the coordinator was
 * told and the condition still holds; a check that no longer finds it clears the row, and a
 * recurrence raises again. With no coordinator live nothing is raised or recorded, so the first
 * check after one appears tells it.
 *
 * The conditions #319 routes to the board rather than to an event -- an owed item settled by its
 * ticket, and a question not yet answered -- are the lane board's (#335), and a placement "never
 * sent" cannot occur since #316 made placement and directive one write.
 */
final class LaneConditions
{
    /**
     * A build lane idle, unparked and unheld for longer than its window.
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
     */
    public function __construct(
        private readonly FleetEvents $events,
        private readonly Repository $config,
        private readonly Seats $seats,
        private readonly GateRuns $gates
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
        ];

        $raised = 0;

        foreach ($current as [$condition, $subject, $body, $meta]) {
            $raised += $this->raise($condition, $subject, $body, $meta, $now) ? 1 : 0;
        }

        $this->clearAllBut([self::LANE_FREE, self::NOT_TAKEN_UP, self::PULL_REQUEST_UNPICKED], $current, $now);
        $this->clearAnswered($now);

        return $raised;
    }

    /**
     * Raise at once when a lane holding work stops answering or ends -- on the presence
     * transition, not on the next check.
     *
     * Called inside the transition's own transaction, after its feed event, so the two commit
     * together.
     *
     * @param  AgentSession  $session  The session that went stale or gone.
     * @param  AgentSessionStatus  $into  Which.
     */
    public function sessionUnobserved(AgentSession $session, AgentSessionStatus $into): void
    {
        $held = Task::query()
            ->where('claimed_by', $session->getKey())
            ->whereIn('status', TaskStatus::values(TaskStatus::held()))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($held === []) {
            return;
        }

        $this->raise(
            self::WORKING_UNOBSERVED,
            'session:'.$session->id,
            sprintf('Session #%d holds work and is %s.', $session->id, $into->value),
            ['session_id' => $session->id, 'status' => $into->value, 'task_ids' => $held],
            CarbonImmutable::now('UTC')
        );
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
        $behind = AgentSession::query()
            ->where('status', '!=', AgentSessionStatus::Gone->value)
            ->whereRaw('lower(repository) = ?', [mb_strtolower($repository)])
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        if ($behind === []) {
            return;
        }

        $this->raise(
            self::MERGE_BEHIND,
            $repository.'#'.$number,
            sprintf('%s#%d merged; %d live session(s) in that repository are now behind.', $repository, $number, \count($behind)),
            ['pull_request' => $repository.'#'.$number, 'session_ids' => $behind],
            CarbonImmutable::now('UTC')
        );
    }

    /**
     * Raise one condition unless it is already open.
     *
     * @param  string  $condition  Which condition.
     * @param  string  $subject  What it is about.
     * @param  string  $body  The event's text.
     * @param  array<string, mixed>  $meta  The event's detail.
     * @param  CarbonImmutable  $now  When.
     * @return bool True when it was raised.
     */
    private function raise(string $condition, string $subject, string $body, array $meta, CarbonImmutable $now): bool
    {
        $coordinators = AgentSession::query()
            ->where('role', Role::Coordinator->value)
            ->where('status', '!=', AgentSessionStatus::Gone->value)
            ->get()
            ->all();

        if ($coordinators === []) {
            return false;
        }

        return DB::transaction(function () use ($condition, $subject, $body, $meta, $now, $coordinators): bool {
            $open = DB::table('robot_council_lane_conditions')
                ->where('condition', $condition)
                ->where('subject', $subject)
                ->whereNull('cleared_at')
                ->lockForUpdate()
                ->exists();

            if ($open) {
                return false;
            }

            DB::table('robot_council_lane_conditions')->insert([
                'condition' => $condition,
                'subject' => $subject,
                'raised_at' => $now,
            ]);

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
     * Clear the open rows of scheduled conditions no longer found.
     *
     * @param  list<string>  $conditions  The conditions this check computes.
     * @param  list<array{string, string, string, array<string, mixed>}>  $current  What it found.
     * @param  CarbonImmutable  $now  When.
     */
    private function clearAllBut(array $conditions, array $current, CarbonImmutable $now): void
    {
        $holding = [];

        foreach ($current as [$condition, $subject]) {
            $holding[$condition."\n".$subject] = true;
        }

        $open = DB::table('robot_council_lane_conditions')->whereIn('condition', $conditions)->whereNull('cleared_at')->get(['id', 'condition', 'subject']);

        foreach ($open as $row) {
            $key = (\is_string($row->condition) ? $row->condition : '')."\n".(\is_string($row->subject) ? $row->subject : '');

            if (! isset($holding[$key])) {
                DB::table('robot_council_lane_conditions')->where('id', $row->id)->update(['cleared_at' => $now]);
            }
        }
    }

    /**
     * Clear an unobserved-lane condition once that session answers again.
     *
     * @param  CarbonImmutable  $now  When.
     */
    private function clearAnswered(CarbonImmutable $now): void
    {
        $answering = AgentSession::query()->where('status', AgentSessionStatus::Active->value)->pluck('id')
            ->map(static fn (mixed $id): string => 'session:'.(is_numeric($id) ? (int) $id : 0))
            ->all();

        if ($answering !== []) {
            DB::table('robot_council_lane_conditions')
                ->where('condition', self::WORKING_UNOBSERVED)
                ->whereIn('subject', $answering)
                ->whereNull('cleared_at')
                ->update(['cleared_at' => $now]);
        }
    }

    /**
     * Build lanes free -- live, holding nothing, not parked, not held -- for longer than their
     * window, and since when.
     *
     * @param  CarbonImmutable  $now  When.
     * @return list<array{string, string, string, array<string, mixed>}> The conditions.
     */
    private function freeLanes(CarbonImmutable $now): array
    {
        $window = $this->minutes('free_after_minutes', 30);
        $found = [];

        $lanes = AgentSession::query()
            ->where('role', Role::Build->value)
            ->where('status', AgentSessionStatus::Active->value)
            ->orderBy('id')
            ->get();

        $working = Task::query()->whereIn('claimed_by', $lanes->pluck('id')->all())
            ->whereIn('status', TaskStatus::values(TaskStatus::held()))->pluck('claimed_by')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();
        $held = LaneHold::query()->whereIn('agent_session_id', $lanes->pluck('id')->all())->pluck('agent_session_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();
        $seats = $this->seats->forSessions($lanes);

        foreach ($lanes as $lane) {
            if (\in_array($lane->id, $working, true) || \in_array($lane->id, $held, true) || ($seats[$lane->id] ?? null)?->isParked() === true) {
                continue;
            }

            $since = $this->freeSince($lane);
            $minutes = (int) $since->diffInMinutes($now, true);

            if ($minutes >= $window) {
                $found[] = [
                    self::LANE_FREE,
                    'session:'.$lane->id,
                    sprintf('Session #%d has been free, with no stated hold, for %d minutes.', $lane->id, $minutes),
                    ['session_id' => $lane->id, 'free_since' => $since->toIso8601String()],
                ];
            }
        }

        return $found;
    }

    /**
     * When a lane last finished or gave back work, or started if it never has.
     *
     * Read from its own task events, matched on the event's session and developer both, since
     * session ids are reused.
     *
     * @param  AgentSession  $lane  The lane.
     * @return CarbonImmutable The moment.
     */
    private function freeSince(AgentSession $lane): CarbonImmutable
    {
        $latest = DB::table('robot_council_events')
            ->where('agent_session_id', $lane->getKey())
            ->where('user_id', $lane->user_id)
            ->whereIn('type', [FleetEventType::TaskCompleted->value, FleetEventType::TaskFailed->value, FleetEventType::TaskReleased->value])
            ->max('created_at');

        $started = $lane->getAttributes()['created_at'] ?? null;

        return CarbonImmutable::parse(\is_string($latest) ? $latest : (\is_string($started) ? $started : 'now'), 'UTC');
    }

    /**
     * Coordinator placements still `claimed` past their take-up window -- a hand-back owed among
     * them, which the event says.
     *
     * @param  CarbonImmutable  $now  When.
     * @return list<array{string, string, string, array<string, mixed>}> The conditions.
     */
    private function notTakenUp(CarbonImmutable $now): array
    {
        $cutoff = $now->subMinutes($this->minutes('take_up_within_minutes', 15));
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
     * @return list<array{string, string, string, array<string, mixed>}> The conditions.
     */
    private function unpickedPullRequests(CarbonImmutable $now): array
    {
        $cutoff = $now->subMinutes($this->minutes('gate_pickup_within_minutes', 30));
        $running = array_map(mb_strtolower(...), array_values($this->gates->running()));
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
            if (\in_array(mb_strtolower($pull->reference()), $running, true)) {
                continue;
            }

            $found[] = [
                self::PULL_REQUEST_UNPICKED,
                $pull->reference(),
                sprintf('%s is ready and no gate has picked it up.', $pull->reference()),
                ['pull_request' => $pull->reference()],
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
