<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
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

        // New placements only, as #314 records, and "new" is decided from state the coordinator does
        // not assert. **Work already held moves ungated only within one developer's lanes**: hours
        // are a developer's, so work arriving from another developer's lane is new to this one --
        // otherwise placing on an in-hours lane and then moving it would place anything anywhere.
        // **A hand-back is exempt only when this lane has itself started the task before**, which
        // its own `task.started` event records; a bare `hand_back: true` from the coordinator would
        // otherwise be a waiver the coordinator granted itself. An exempt seat is not gated either
        // -- that is its developer's setting.
        $isNew = $task->status === TaskStatus::Pending || ! $this->heldByTheSameDeveloper($task, $lane);
        $returning = $handBack && $this->laneStarted($task, $lane);

        if ($isNew && ! $returning && $seat?->hours_exempt !== true && ! $this->settings->takesNewWorkAt($lane->user_id, $at)) {
            $broken[] = PlacementRule::AssignmentHours;
        }

        return $broken;
    }

    /**
     * Whether a held task is currently held by one of this lane's developer's sessions.
     *
     * @param  Task  $task  The task.
     * @param  AgentSession  $lane  The lane it is being moved to.
     * @return bool True when the current holder belongs to the same developer.
     */
    private function heldByTheSameDeveloper(Task $task, AgentSession $lane): bool
    {
        $holder = $task->claimed_by === null ? null : AgentSession::query()->find($task->claimed_by);

        return $holder instanceof AgentSession && $holder->user_id === $lane->user_id;
    }

    /**
     * Whether this lane has itself started the task before, which is what makes a placement back
     * onto it a hand-back rather than new work.
     *
     * Read from the lane's own `task.started` events, matched on the event's recorded developer as
     * well as its session id, because session ids are reused. Matched in PHP rather than through a
     * JSON path, which compares text against an integer differently on each engine. Bounded: a lane
     * that started this task starts few others before it is handed back.
     *
     * @param  Task  $task  The task.
     * @param  AgentSession  $lane  The lane.
     * @return bool True when it started the task.
     */
    private function laneStarted(Task $task, AgentSession $lane): bool
    {
        return FleetEvent::query()
            ->where('type', FleetEventType::TaskStarted->value)
            ->where('agent_session_id', $lane->getKey())
            ->where('user_id', $lane->user_id)
            ->orderByDesc('id')
            ->limit(500)
            ->get(['meta'])
            ->contains(static fn (FleetEvent $event): bool => ($event->meta['task_id'] ?? null) === $task->id);
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

        $ahead = $item instanceof GitHubItem ? $this->functionalityAhead($item) : [];

        if ($ahead !== []) {
            $warnings[] = sprintf(
                'This is documentation, and %d functionality ticket(s) are placeable in %s: %s. The service cannot know why they were passed over; their blind spots are on the shortlist.',
                \count($ahead),
                $item->repository,
                implode(', ', $ahead)
            );
        }

        $started = $item instanceof GitHubItem ? $this->branchesWithoutPullRequest($item) : [];

        if ($started !== []) {
            $warnings[] = sprintf(
                "A branch whose name carries this ticket's number exists with no open pull request -- the work may already have started: %s. Matched by name, so it may be unrelated.",
                implode(', ', $started)
            );
        }

        return $warnings;
    }

    /**
     * The functionality tickets placeable in the same repository, when this one is documentation
     * (#344, from #314's "functionality before documentation").
     *
     * Read from the shortlist, so "placeable" means exactly what the coordinator was shown. Resolved
     * here rather than injected, because the shortlist is built on these rules and each would
     * otherwise need the other to be constructed first.
     *
     * @param  GitHubItem  $item  The ticket being placed.
     * @return list<string> The functionality tickets, `owner/name#N`, up to ten.
     */
    private function functionalityAhead(GitHubItem $item): array
    {
        if (! \in_array('documentation', $item->labels, true)) {
            return [];
        }

        $ahead = [];

        foreach (app(Shortlist::class)->read()[$item->repository] ?? [] as $entry) {
            if ($entry['ticket'] !== $item->reference() && ! \in_array('documentation', $entry['labels'], true)) {
                $ahead[] = $entry['ticket'];
            }
        }

        return \array_slice($ahead, 0, 10);
    }

    /**
     * Branches in the ticket's repository whose name carries its number as a whole token, with no
     * open pull request from them (#344).
     *
     * **A heuristic, and the warning says so.** Nothing links a branch to a ticket but its name, and
     * `318-webhook`, `issue-318` and `fix/318` are all how people name them. Bounded to a few names,
     * since a warning lists them.
     *
     * @param  GitHubItem  $item  The ticket.
     * @return list<string> Up to five branch names.
     */
    private function branchesWithoutPullRequest(GitHubItem $item): array
    {
        $number = (string) $item->number;

        $candidates = DB::table('robot_council_github_branches')
            ->whereRaw('lower(repository) = ?', [mb_strtolower($item->repository)])
            ->where('name', 'like', '%'.$number.'%')
            ->orderBy('name')
            ->pluck('name')
            ->filter(static fn (mixed $name): bool => \is_string($name) && preg_match('/(?<![0-9])'.$number.'(?![0-9])/', $name) === 1);

        if ($candidates->isEmpty()) {
            return [];
        }

        $withPullRequest = GitHubItem::query()
            ->whereRaw('lower(repository) = ?', [mb_strtolower($item->repository)])
            ->where('is_pull_request', true)
            ->where('state', 'open')
            ->whereIn('head_ref', $candidates->all())
            ->pluck('head_ref')
            ->all();

        return array_values(array_slice(
            array_filter($candidates->all(), static fn (string $name): bool => ! \in_array($name, $withPullRequest, true)),
            0,
            5
        ));
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
    public function blocked(GitHubItem $item): bool
    {
        // Compared without case, as the item was found: an edge's repository comes from the issue's
        // `repository_url` and an item's from the delivery's `full_name`, and a rule that fails open
        // on a spelling difference is the wrong direction for "an unknown blocker blocks"
        $blockers = DB::table('robot_council_github_blockers')
            ->whereRaw('lower(repository) = ?', [mb_strtolower($item->repository)])
            ->where('number', $item->number)
            ->get(['blocker_repository', 'blocker_number']);

        foreach ($blockers as $blocker) {
            $state = GitHubItem::query()
                ->whereRaw('lower(repository) = ?', [mb_strtolower(\is_string($blocker->blocker_repository) ? $blocker->blocker_repository : '')])
                ->where('number', $blocker->blocker_number)
                ->value('state');

            if ($state !== 'closed') {
                return true;
            }
        }

        return false;
    }
}
