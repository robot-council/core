<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\PlacementRule;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;

/**
 * Which invariants a coordinator's placement breaks, and what it should be warned about (#320).
 *
 * **Refusals block; warnings only say why.** #314 decided the hard invariants refuse and the soft
 * ones warn, and that only the developer who owns a seat can waive -- so this class decides nothing
 * about waivers and writes nothing. `Support\Tasks` calls it inside the placement's transaction,
 * after the lane's session row is locked, and throws on what it returns.
 *
 * **The ticket rules apply only to a task that names an issue.** A task filed without one is not a
 * ticket placement, and refusing it for having no ticket would refuse every task the fleet filed
 * before #316. A task that names one the fleet has never heard of *is* refused: "resolves" is part
 * of the rule, and a fleet whose webhook is not configured yet waives it or imports its tickets.
 */
final class PlacementRules
{
    /**
     * Title words that need a human whatever the ticket's labels say.
     *
     * @var list<string>
     */
    public const array HUMAN_VERBS = ['delete', 'remove', 'retire', 'release', 'tag', 'publish', 'install', 'upgrade', 'rotate', 'spend'];

    /**
     * @param  Seats  $seats  The seat store, for whether the lane is parked or exempt.
     * @param  DeveloperSettings  $settings  The hours store.
     */
    public function __construct(
        private readonly Seats $seats,
        private readonly DeveloperSettings $settings
    ) {}

    /**
     * The rules a placement breaks.
     *
     * @param  Task  $task  The task being placed, read under its lock.
     * @param  AgentSession  $lane  The session it is placed on, read under its lock.
     * @param  bool  $handBack  Whether it returns a gate's pull request, which assignment hours do not gate.
     * @param  DateTimeInterface  $at  The moment of the placement.
     * @return list<PlacementRule> The broken rules, in declaration order.
     */
    public function refusals(Task $task, AgentSession $lane, bool $handBack, DateTimeInterface $at): array
    {
        $broken = [];
        $item = $this->item($task);

        if (\is_string($task->issue)) {
            if (! $item instanceof GitHubItem || ! $item->isOpen()) {
                $broken[] = PlacementRule::TicketOpen;
            }

            $repository = strstr($task->issue, '#', true);

            // Compared without case, as GitHub names repositories
            if (! \is_string($lane->repository) || ! \is_string($repository) || strcasecmp($lane->repository, $repository) !== 0) {
                $broken[] = PlacementRule::LaneInRepository;
            }
        }

        $holdsOther = Task::query()
            ->where('claimed_by', $lane->getKey())
            ->whereKeyNot($task->getKey())
            ->whereIn('status', TaskStatus::values(TaskStatus::held()))
            ->exists();

        if ($holdsOther) {
            $broken[] = PlacementRule::LaneFree;
        }

        $seat = $this->seats->of($lane);

        if ($seat?->isParked() === true) {
            $broken[] = PlacementRule::LaneNotParked;
        }

        if ($item instanceof GitHubItem && $this->blocked($item)) {
            $broken[] = PlacementRule::TicketUnblocked;
        }

        // New placements only: a hand-back fix, and work already held that is being moved, are not
        // gated, as #314 records. An exempt seat is not either -- that is its developer's setting.
        $isNew = $task->status === TaskStatus::Pending;

        if ($isNew && ! $handBack && $seat?->hours_exempt !== true && ! $this->settings->takesNewWorkAt($lane->user_id, $at)) {
            $broken[] = PlacementRule::AssignmentHours;
        }

        return $broken;
    }

    /**
     * What a coordinator should be told about a placement, without blocking it.
     *
     * @param  Task  $task  The task being placed.
     * @return list<string> The warnings.
     */
    public function warnings(Task $task): array
    {
        $warnings = [];
        $title = mb_strtolower($task->title);

        foreach (self::HUMAN_VERBS as $verb) {
            if (preg_match('/\b'.$verb.'\b/u', $title) === 1) {
                $warnings[] = sprintf('The title says "%s", which needs a human whatever the labels say.', $verb);
            }
        }

        $item = $this->item($task);

        if ($item instanceof GitHubItem && $item->isOpen() && $item->checkboxes > 0 && $item->checkboxes_ticked === $item->checkboxes) {
            $warnings[] = 'Every acceptance criterion is ticked and the ticket is still open. Read it before placing it.';
        }

        return $warnings;
    }

    /**
     * The stored issue a task names.
     *
     * @param  Task  $task  The task.
     * @return GitHubItem|null The issue, or null when the task names none or the fleet has no record.
     */
    private function item(Task $task): ?GitHubItem
    {
        if (! \is_string($task->issue)) {
            return null;
        }

        [$repository, $number] = explode('#', $task->issue, 2);

        return GitHubItem::query()
            ->whereRaw('lower(repository) = ?', [mb_strtolower($repository)])
            ->where('number', (int) $number)
            ->where('is_pull_request', false)
            ->first();
    }

    /**
     * Whether an issue has a `blocked_by` edge whose blocker is open, or unknown.
     *
     * An unknown blocker counts as open: the edge says something blocks the ticket, and not knowing
     * whether it has finished is not evidence that it has.
     *
     * @param  GitHubItem  $item  The issue.
     * @return bool True when something still blocks it.
     */
    private function blocked(GitHubItem $item): bool
    {
        $blockers = DB::table('robot_council_github_blockers')
            ->where('repository', $item->repository)
            ->where('number', $item->number)
            ->get(['blocker_repository', 'blocker_number']);

        foreach ($blockers as $blocker) {
            $state = GitHubItem::query()
                ->where('repository', $blocker->blocker_repository)
                ->where('number', $blocker->blocker_number)
                ->value('state');

            if ($state !== 'closed') {
                return true;
            }
        }

        return false;
    }
}
