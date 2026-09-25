<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;

/**
 * Tell the coordinator when a build lane has authored nothing for an hour (#332).
 *
 * **#323 decided what counts.** Substantive is anything the session itself authors -- a narration,
 * a task transition, a lock acquired or released, a directive it posts. A heartbeat is not (it
 * writes no event), nor is `session.joined`, nor a directive the session merely received, since
 * that event's author is the coordinator. Authorship is read from the event's recorded session and
 * developer both, because session ids are reused.
 *
 * **Build lanes only.** A gate is exempt while it holds no pull request, since waiting is its job,
 * and a coordinator is the audience rather than a subject.
 *
 * **Once per quiet stretch.** `quiet_noticed_at` records when the coordinator was told, and a later
 * substantive act starts a new stretch, so the scheduled check never repeats itself.
 */
final class QuietLanes
{
    /**
     * How long a build lane may author nothing before the coordinator is told.
     */
    public const int WINDOW_MINUTES = 60;

    /**
     * The event types a session authors by acting.
     *
     * @var list<FleetEventType>
     */
    public const array SUBSTANTIVE = [
        FleetEventType::Narration,
        FleetEventType::Directive,
        FleetEventType::TaskCreated,
        FleetEventType::TaskClaimed,
        FleetEventType::TaskStarted,
        FleetEventType::TaskBlocked,
        FleetEventType::TaskCompleted,
        FleetEventType::TaskFailed,
        FleetEventType::TaskReleased,
        FleetEventType::TaskReassigned,
        FleetEventType::TaskCancelled,
        FleetEventType::LockAcquired,
        FleetEventType::LockTakenOver,
        FleetEventType::LockReleased,
        FleetEventType::LockForceReleased,
    ];

    /**
     * @param  FleetEvents  $events  The change feed.
     * @param  GateRuns  $gates  Which gates hold a pull request.
     */
    public function __construct(
        private readonly FleetEvents $events,
        private readonly GateRuns $gates
    ) {}

    /**
     * Tell the coordinators about every lane that has gone quiet since it was last told.
     *
     * @param  DateTimeInterface  $at  The moment the check runs.
     * @return int How many lanes it told them about.
     */
    public function check(DateTimeInterface $at): int
    {
        $now = CarbonImmutable::instance($at)->utc();
        $coordinators = AgentSession::query()
            ->where('role', Role::Coordinator->value)
            ->where('status', '!=', AgentSessionStatus::Gone->value)
            ->get()
            ->all();

        // Nobody to tell: say nothing, and leave every lane unmarked so the next check, once a
        // coordinator is live, tells it
        if ($coordinators === []) {
            return 0;
        }

        $running = $this->gates->running();
        $told = 0;

        $lanes = AgentSession::query()
            ->whereIn('role', [Role::Build->value, Role::Ci->value])
            ->where('status', '!=', AgentSessionStatus::Gone->value)
            ->orderBy('id')
            ->get();

        foreach ($lanes as $lane) {
            if ($lane->role === Role::Ci && ! isset($running[$lane->id])) {
                continue;
            }

            $since = $this->lastActed($lane);

            if ($now->diffInMinutes($since, true) <= self::WINDOW_MINUTES) {
                continue;
            }

            $told += DB::transaction(function () use ($lane, $since, $now, $coordinators): int {
                // Conditional on the row, like every presence write: two checks at once tell once
                $claimed = AgentSession::query()
                    ->whereKey($lane->getKey())
                    ->where(fn ($query) => $query->whereNull('quiet_noticed_at')->orWhere('quiet_noticed_at', '<', $since))
                    ->update(['quiet_noticed_at' => $now]);

                if ($claimed !== 1) {
                    return 0;
                }

                $this->events->record(
                    FleetEventType::LaneQuiet,
                    null,
                    sprintf('Session #%d has authored nothing for %d minutes.', $lane->id, (int) $now->diffInMinutes($since, true)),
                    ['session_id' => $lane->id, 'quiet_since' => $since->toIso8601String()],
                    addressees: array_values($coordinators)
                );

                return 1;
            });
        }

        return $told;
    }

    /**
     * When a session last acted substantively, or started if it never has.
     *
     * @param  AgentSession  $lane  The session.
     * @return CarbonImmutable The moment.
     */
    private function lastActed(AgentSession $lane): CarbonImmutable
    {
        $latest = DB::table('robot_council_events')
            ->where('agent_session_id', $lane->getKey())
            ->where('user_id', $lane->user_id)
            ->whereIn('type', array_map(static fn (FleetEventType $type): string => $type->value, self::SUBSTANTIVE))
            ->max('created_at');

        $started = $lane->getAttributes()['created_at'] ?? null;

        return CarbonImmutable::parse(\is_string($latest) ? $latest : (\is_string($started) ? $started : Carbon::now()->toDateTimeString()), 'UTC');
    }
}
