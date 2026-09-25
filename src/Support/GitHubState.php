<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use Throwable;

/**
 * What GitHub has told the fleet, and what that means for the lanes (#318).
 *
 * **Every delivery is applied in one transaction with its own id, and the id is the idempotency
 * key.** GitHub redelivers, so the `X-GitHub-Delivery` id is inserted first against a primary key;
 * a replay finds it taken and changes nothing, which is what stops a lane being freed twice.
 *
 * **A delivery older than what is stored changes nothing.** GitHub does not promise order, so an
 * issue's row is replaced only by a report at least as recent as the one it holds, and a lifecycle
 * write fires only from a report that became the current state -- a `closed` arriving after a later
 * `reopened` frees nobody.
 *
 * **Everything taken from a payload is bounded here**, because a delivery is signed by whoever holds
 * the secret and is otherwise input like any other. A value GitHub would never send -- a title past
 * 256 characters, a repository outside `WorkIdentity::REPOSITORY` -- refuses the whole delivery
 * rather than storing half of it.
 *
 * Lock order: the delivery and item rows are this path's own, taken before `robot_council_tasks`
 * and the feed sentinel, which is the order every other task write already follows after its own
 * rows.
 */
final class GitHubState
{
    /**
     * How long a delivery id is kept. GitHub offers redelivery for a few days; a week covers it.
     */
    public const int DELIVERY_RETENTION_DAYS = 7;

    /**
     * How many expired delivery ids one delivery prunes, so no request pays for a backlog.
     */
    public const int PRUNE_BATCH = 500;

    /**
     * The most labels an item may carry here. GitHub allows far fewer on a real issue.
     */
    public const int MAX_LABELS = 100;

    /**
     * The most mentioned paths kept for one item.
     */
    public const int MAX_PATHS = 50;

    /**
     * @param  Tasks  $tasks  The task store, for freeing a lane.
     * @param  GateRuns  $gates  The gate runs, ended when their pull request leaves the open set.
     * @param  OwedItems  $owed  What the fleet waits on developers for, settled by their tickets (#335).
     * @param  LaneConditions  $conditions  Told when a merge leaves sessions behind (#319).
     */
    public function __construct(
        private readonly Tasks $tasks,
        private readonly GateRuns $gates,
        private readonly OwedItems $owed,
        private readonly LaneConditions $conditions
    ) {}

    /**
     * Apply one delivery.
     *
     * @param  string  $deliveryId  The `X-GitHub-Delivery` header.
     * @param  string  $event  The `X-GitHub-Event` header.
     * @param  array<array-key, mixed>  $payload  The decoded body.
     * @return string `applied`, `duplicate` for an id already seen, or `ignored` for an event the
     *                fleet does not use.
     *
     * @throws InvalidArgumentException When the delivery carries something GitHub would not send.
     */
    public function receive(string $deliveryId, string $event, array $payload): string
    {
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/D', $deliveryId) !== 1) {
            throw new InvalidArgumentException('A delivery id is up to 64 letters, digits and dashes.');
        }

        if (preg_match('/^[a-z_]{1,64}$/D', $event) !== 1) {
            throw new InvalidArgumentException('An event name is up to 64 lower-case letters and underscores.');
        }

        $outcome = DB::transaction(function () use ($deliveryId, $event, $payload): string {
            $fresh = DB::table('robot_council_github_deliveries')->insertOrIgnore([
                'delivery_id' => $deliveryId,
                'event' => $event,
                'received_at' => PresenceClock::now(),
            ]);

            if ($fresh !== 1) {
                return 'duplicate';
            }

            return match ($event) {
                'issues' => $this->issue($payload),
                'pull_request' => $this->pullRequest($payload),
                'issue_dependencies' => $this->dependency($payload),
                'create', 'delete' => $this->branch($event, $payload),
                default => 'ignored',
            };
        });

        $this->prune();

        return $outcome;
    }

    /**
     * Store an issue or pull request from an operator's file, changing no lane.
     *
     * The backfill #318 calls for: a webhook delivers only what happens after it is configured.
     * It takes objects in the shape GitHub's REST API returns them, so the operator produces the
     * file with `gh api` and core itself still reads nothing from GitHub.
     *
     * @param  array<array-key, mixed>  $object  One issue or pull request, as the REST API returns it.
     * @return bool True when it became the stored state.
     *
     * @throws InvalidArgumentException When it is not something this can store.
     */
    public function import(array $object): bool
    {
        $repository = self::repositoryOf($object);
        $isPull = isset($object['pull_request']) || isset($object['head']);

        return DB::transaction(fn (): bool => $this->store($repository, $object, $isPull));
    }

    /**
     * An `issues` delivery.
     *
     * **A lane is freed from the state that ends up stored, not from whether this report won.**
     * GitHub does not promise order, so the `closed` delivery can arrive after a later `labeled`
     * one: it loses the newer-wins write, and the row still says `closed`. Acting only when this
     * report became the row would leave that task held for good. So a `closed` delivery frees the
     * lane whenever the stored issue is closed afterwards -- and a `closed` arriving after a later
     * `reopened` still frees nobody, because the stored issue is then open.
     *
     * A deleted or transferred issue is stored as closed, since it is no longer an open ticket here,
     * and frees no lane: its work did not finish.
     *
     * @param  array<array-key, mixed>  $payload  The delivery.
     * @return string What came of it.
     */
    private function issue(array $payload): string
    {
        $repository = self::repository($payload);
        $issue = self::object($payload, 'issue');
        $action = $payload['action'] ?? null;
        $isPull = isset($issue['pull_request']);

        if (\in_array($action, ['deleted', 'transferred'], true)) {
            $issue['state'] = 'closed';
        }

        $this->store($repository, $issue, $isPull);

        // What the fleet waits on a developer for settles when its ticket stops needing a human
        // (#335): the `hitl` label removed -- read off this delivery, since only the delivery says
        // which label went -- or the ticket closed, read off the stored state as the lane is below
        $label = \is_array($payload['label'] ?? null) ? ($payload['label']['name'] ?? null) : null;

        if (! $isPull && $action === 'unlabeled' && $label === 'hitl') {
            $this->owed->settleTicket($repository.'#'.self::number($issue), 'hitl_removed');
        }

        if ($action === 'closed' && ! $isPull && $this->stored($repository, self::number($issue))?->isOpen() === false) {
            $this->owed->settleTicket($repository.'#'.self::number($issue), 'ticket_closed');
        }

        if ($action !== 'closed' || $isPull || $this->stored($repository, self::number($issue))?->isOpen() !== false) {
            return 'applied';
        }

        $reference = $repository.'#'.self::number($issue);

        // Compared without case, because GitHub resolves `Robot-Council/core` and `robot-council/core`
        // to one repository while `WorkIdentity` keeps whatever case a task was filed with. `lower()`
        // exists on every engine the package supports; the PHP check pins the whole reference.
        $tasks = $this->heldTasks(fn ($query) => $query->whereRaw('lower(issue) = ?', [mb_strtolower($reference)]))
            ->filter(static fn (Task $task): bool => \is_string($task->issue) && strcasecmp($task->issue, $reference) === 0);

        foreach ($tasks as $task) {
            // Filtered to a string above; checked again because the analyzer cannot see into the filter
            if (\is_string($task->issue)) {
                $this->tasks->finishFromGitHub($task->id, completed: true, why: 'its issue closed on GitHub', still: ['issue' => $task->issue]);
            }
        }

        return 'applied';
    }

    /**
     * A `pull_request` delivery.
     *
     * Freed, like an issue, from the state stored after the write: a merged `closed` that arrives
     * after a later `edited` still completes the task. Only a `closed` delivery frees anyone -- a
     * push to a lane's branch is `synchronize`, and releasing the lane on it would take its work.
     *
     * @param  array<array-key, mixed>  $payload  The delivery.
     * @return string What came of it.
     */
    private function pullRequest(array $payload): string
    {
        $repository = self::repository($payload);
        $pull = self::object($payload, 'pull_request');

        $this->store($repository, $pull, true);

        if (($payload['action'] ?? null) !== 'closed') {
            return 'applied';
        }

        $stored = $this->stored($repository, self::number($pull));

        if (! $stored instanceof GitHubItem || $stored->isOpen()) {
            return 'applied';
        }

        // A gate running a pull request that has left the open set is running nothing (#336)
        $this->gates->endPullRequest($repository, $stored->number);

        // A merge leaves every live session in that repository behind (#319). Told after this
        // delivery commits, so the raise neither holds these rows nor can undo the delivery
        if ($stored->merged) {
            $this->conditions->merged($repository, $stored->number);
        }

        if ($stored->head_ref === null) {
            return 'applied';
        }

        $branch = $stored->head_ref;
        $merged = $stored->merged;

        // Matched on the branch the lane reported, exactly -- git branch names are case-sensitive,
        // and the column is not byte-compared on MySQL, so the PHP check is what decides -- within
        // the task's own repository, the half of `issue` before the `#`, compared without case as
        // GitHub compares repository names. In PHP rather than with `LIKE`, where `_` in a
        // repository name is a wildcard. A task that names no issue names no repository, so no pull
        // request can be said to be its.
        $prefix = $repository.'#';

        $tasks = $this->heldTasks(fn ($query) => $query->where('branch', $branch))
            ->filter(static fn (Task $task): bool => $task->branch === $branch
                && \is_string($task->issue)
                && strncasecmp($task->issue, $prefix, \strlen($prefix)) === 0);

        foreach ($tasks as $task) {
            $this->tasks->finishFromGitHub(
                $task->id,
                completed: $merged,
                why: $merged ? 'its pull request merged on GitHub' : 'its pull request closed on GitHub without merging',
                still: ['branch' => $branch]
            );
        }

        return 'applied';
    }

    /**
     * What is stored for an issue or pull request, after this delivery's write.
     *
     * @param  string  $repository  Its repository.
     * @param  int  $number  Its number.
     * @return GitHubItem|null The row.
     */
    private function stored(string $repository, int $number): ?GitHubItem
    {
        return GitHubItem::query()->where('repository', $repository)->where('number', $number)->first();
    }

    /**
     * A `create` or `delete` delivery, recording branches (#344).
     *
     * Only branches: a tag says nothing about work in progress. A name outside `BranchName` is not
     * recorded, since no lane could report it. Unordered, like a blocker edge (#341): a `delete`
     * delivered before its `create` leaves the branch recorded until it is deleted again.
     *
     * @param  string  $event  `create` or `delete`.
     * @param  array<array-key, mixed>  $payload  The delivery.
     * @return string What came of it.
     */
    private function branch(string $event, array $payload): string
    {
        $name = $payload['ref'] ?? null;

        if (($payload['ref_type'] ?? null) !== 'branch' || ! \is_string($name)
            || mb_strlen($name) > BranchName::MAX || preg_match(BranchName::PATTERN, $name) !== 1) {
            return 'ignored';
        }

        $key = ['repository' => self::repository($payload), 'name' => $name];

        if ($event === 'create') {
            DB::table('robot_council_github_branches')->insertOrIgnore([...$key, 'created_at' => Carbon::now()]);
        } else {
            DB::table('robot_council_github_branches')->where($key)->delete();
        }

        return 'applied';
    }

    /**
     * An `issue_dependencies` delivery.
     *
     * Each issue's repository is read off the issue object itself rather than off the delivery's
     * `repository`, because an edge may cross repositories and the delivery is sent from either side.
     *
     * @param  array<array-key, mixed>  $payload  The delivery.
     * @return string What came of it.
     */
    private function dependency(array $payload): string
    {
        $action = $payload['action'] ?? null;

        if (! \in_array($action, ['blocked_by_added', 'blocked_by_removed', 'blocking_added', 'blocking_removed'], true)) {
            return 'ignored';
        }

        $blocked = self::object($payload, 'blocked_issue');
        $blocking = self::object($payload, 'blocking_issue');

        $edge = [
            'repository' => self::repositoryOf($blocked),
            'number' => self::number($blocked),
            'blocker_repository' => self::repositoryOf($blocking),
            'blocker_number' => self::number($blocking),
        ];

        // **Unordered, and a known gap.** An edge carries no timestamp of its own, so a
        // `blocked_by_removed` delivered before the older `blocked_by_added` leaves the edge in
        // place. Tracked in #341; until then a stale edge is cleared by removing and
        // re-adding it on GitHub.
        if (str_ends_with($action, '_added')) {
            DB::table('robot_council_github_blockers')->insertOrIgnore($edge);
        } else {
            DB::table('robot_council_github_blockers')->where($edge)->delete();
        }

        return 'applied';
    }

    /**
     * Store an item, unless what is stored is newer.
     *
     * @param  string  $repository  The repository it belongs to.
     * @param  array<array-key, mixed>  $object  The issue or pull request.
     * @param  bool  $isPull  Whether it is a pull request.
     * @return bool True when this report became the stored state.
     */
    private function store(string $repository, array $object, bool $isPull): bool
    {
        $row = self::row($repository, $object, $isPull);
        $now = Carbon::now();

        // Encoded by hand: neither `insertOrIgnore` nor a query-builder `update` runs the model's casts
        $row['labels'] = json_encode($row['labels'], JSON_THROW_ON_ERROR);
        $row['mentioned_paths'] = json_encode($row['mentioned_paths'], JSON_THROW_ON_ERROR);

        $inserted = GitHubItem::query()->insertOrIgnore([...$row, 'created_at' => $now, 'updated_at' => $now]);

        if ($inserted === 1) {
            return true;
        }

        // Newer-or-equal wins. Equal, because two deliveries of one change carry one timestamp
        // and the second is harmless; strictly newer would drop a real second change made within
        // the same second GitHub stamped. **Two different changes in one second cannot be ordered
        // from the payload at all** -- GitHub stamps whole seconds -- so the one that arrives last
        // wins, and a close and a reopen in the same second can end either way. Accepted: nothing in
        // a delivery could decide it, and the next change to the issue corrects it.
        // A pull request reported in issue shape -- the issues endpoint and `issues` deliveries both
        // send one, with a `pull_request` key and no `head` -- says nothing about its branch, so it
        // leaves the stored one alone rather than erasing it. Measured by the import running the
        // issues file after the pulls file, which otherwise nulled every open pull request's branch.
        if ($isPull && ! isset($object['head'])) {
            unset($row['head_ref']);
        }

        $current = GitHubItem::query()
            ->where('repository', $repository)
            ->where('number', $row['number'])
            ->where('github_updated_at', '<=', $row['github_updated_at'])
            ->update([...$row, 'updated_at' => $now]);

        return $current === 1;
    }

    /**
     * The row an item is stored as, every field bounded.
     *
     * @param  string  $repository  The repository it belongs to.
     * @param  array<array-key, mixed>  $object  The issue or pull request.
     * @param  bool  $isPull  Whether it is a pull request.
     * @return array{repository: string, number: int, is_pull_request: bool, state: string, merged: bool, draft: bool, title: string, head_ref: string|null, labels: mixed, mentioned_paths: mixed, checkboxes: int, checkboxes_ticked: int, github_updated_at: Carbon}
     *
     * @throws InvalidArgumentException When a field is outside what GitHub sends.
     */
    private static function row(string $repository, array $object, bool $isPull): array
    {
        $state = $object['state'] ?? null;

        if ($state !== 'open' && $state !== 'closed') {
            throw new InvalidArgumentException('An item is open or closed.');
        }

        $title = $object['title'] ?? null;

        if (! \is_string($title) || mb_strlen($title) > 256) {
            throw new InvalidArgumentException('A title is text of up to 256 characters.');
        }

        $labels = [];

        foreach (\is_array($object['labels'] ?? null) ? $object['labels'] : [] as $label) {
            $name = \is_array($label) ? ($label['name'] ?? null) : $label;

            if (! \is_string($name) || mb_strlen($name) > 50) {
                throw new InvalidArgumentException('A label is a name of up to 50 characters.');
            }

            $labels[] = $name;
        }

        if (\count($labels) > self::MAX_LABELS) {
            throw new InvalidArgumentException(sprintf('An item carries at most %d labels here.', self::MAX_LABELS));
        }

        $updated = $object['updated_at'] ?? null;

        try {
            $updatedAt = \is_string($updated) ? Carbon::parse($updated)->utc() : null;
        } catch (Throwable) {
            $updatedAt = null;
        }

        if (! $updatedAt instanceof Carbon) {
            throw new InvalidArgumentException('An item carries the time GitHub last changed it.');
        }

        $body = \is_string($object['body'] ?? null) ? $object['body'] : '';

        // Paths the body mentions, for the shortlist to show as unverified (#321). Read from the
        // whole body, code spans included, since that is where a ticket names its files.
        $paths = self::mentionedPaths($body);

        // Without fenced code first: a checkbox quoted in a code block is not one GitHub renders
        $body = (string) preg_replace('/^[ \t]*(```|~~~).*?^[ \t]*\1[^\n]*$/ms', '', $body);

        // `- [ ]`, `* [x]` and `+ [X]` at the start of a line, which is what GitHub renders as a
        // task-list checkbox. Counted, and the body itself is not kept.
        $boxes = preg_match_all('/^[ \t]*[-*+][ \t]+\[[ xX]\]/m', $body);
        $ticked = preg_match_all('/^[ \t]*[-*+][ \t]+\[[xX]\]/m', $body);

        return [
            'repository' => $repository,
            'number' => self::number($object),
            'is_pull_request' => $isPull,
            'state' => $state,
            // `merged` on a pull-request object; the issues endpoint's shape carries `merged_at`
            'merged' => $isPull && (($object['merged'] ?? false) === true
                || (\is_array($object['pull_request'] ?? null) && \is_string($object['pull_request']['merged_at'] ?? null))),
            'draft' => $isPull && ($object['draft'] ?? false) === true,
            'title' => $title,
            'head_ref' => $isPull ? self::headRef($object, $repository) : null,
            'labels' => $labels,
            'checkboxes' => \is_int($boxes) ? $boxes : 0,
            'checkboxes_ticked' => \is_int($ticked) ? $ticked : 0,
            'mentioned_paths' => $paths,
            'github_updated_at' => $updatedAt,
        ];
    }

    /**
     * The file paths a body mentions: a slash-separated name ending in an extension.
     *
     * Not a path inside a URL -- a `/` or `:` before it rules that out -- and not a bare name with no
     * directory, which says too little to be worth showing. Bounded, since a body is anybody's text.
     *
     * @param  string  $body  The body.
     * @return list<string> Up to `MAX_PATHS` paths, each within `BranchName::MAX`, in first-seen order.
     */
    private static function mentionedPaths(string $body): array
    {
        preg_match_all('~(?<![\w/.:-])((?:[A-Za-z0-9_.-]+/)+[A-Za-z0-9_-][A-Za-z0-9_.-]*\.[A-Za-z0-9]{1,8})(?![\w/-])~', $body, $found);

        $paths = array_values(array_unique(array_filter($found[1], static fn (string $path): bool => mb_strlen($path) <= BranchName::MAX)));

        return \array_slice($paths, 0, self::MAX_PATHS);
    }

    /**
     * The branch a pull request comes from, when a lane's could be it.
     *
     * Null for a pull request from a fork -- its branch lives in another repository, so a lane here
     * sharing the name is a coincidence -- and for a name `BranchName` would refuse, which no lane
     * can have reported.
     *
     * @param  array<array-key, mixed>  $pull  The pull request.
     * @param  string  $repository  The repository it was opened against.
     * @return string|null The branch, or null.
     */
    private static function headRef(array $pull, string $repository): ?string
    {
        $head = \is_array($pull['head'] ?? null) ? $pull['head'] : [];
        $ref = $head['ref'] ?? null;
        $from = \is_array($head['repo'] ?? null) ? ($head['repo']['full_name'] ?? null) : null;

        if (! \is_string($ref) || $from !== $repository || mb_strlen($ref) > BranchName::MAX || preg_match(BranchName::PATTERN, $ref) !== 1) {
            return null;
        }

        return $ref;
    }

    /**
     * The tasks somebody holds, matching a condition.
     *
     * @param  callable(Builder<Task>): mixed  $where  The condition.
     * @return Collection<int, Task> The tasks.
     */
    private function heldTasks(callable $where): Collection
    {
        $query = Task::query()->whereIn('status', TaskStatus::values(TaskStatus::held()));
        $where($query);

        return $query->orderBy('id')->get()->toBase();
    }

    /**
     * Drop delivery ids older than GitHub's redelivery window, a batch at a time.
     */
    private function prune(): void
    {
        $expired = DB::table('robot_council_github_deliveries')
            ->where('received_at', '<', PresenceClock::now()->subDays(self::DELIVERY_RETENTION_DAYS))
            ->orderBy('received_at')
            ->limit(self::PRUNE_BATCH)
            ->pluck('delivery_id')
            ->all();

        if ($expired !== []) {
            DB::table('robot_council_github_deliveries')->whereIn('delivery_id', $expired)->delete();
        }
    }

    /**
     * The delivery's repository, checked.
     *
     * @param  array<array-key, mixed>  $payload  The delivery.
     * @return string `owner/name`.
     */
    private static function repository(array $payload): string
    {
        $repository = \is_array($payload['repository'] ?? null) ? ($payload['repository']['full_name'] ?? null) : null;

        return self::checkedRepository($repository);
    }

    /**
     * An item's repository, from its own `repository_url` or, for a pull request, its base.
     *
     * @param  array<array-key, mixed>  $object  The issue or pull request.
     * @return string `owner/name`.
     */
    private static function repositoryOf(array $object): string
    {
        $url = $object['repository_url'] ?? null;

        if (\is_string($url) && preg_match('#/repos/([^/]+/[^/]+)$#D', $url, $match) === 1) {
            return self::checkedRepository($match[1]);
        }

        $base = \is_array($object['base'] ?? null) && \is_array($object['base']['repo'] ?? null)
            ? ($object['base']['repo']['full_name'] ?? null)
            : null;

        return self::checkedRepository($base);
    }

    /**
     * A repository name, refused outside `WorkIdentity`'s shape.
     *
     * @param  mixed  $repository  The candidate.
     * @return string The name.
     *
     * @throws InvalidArgumentException When it is not one.
     */
    private static function checkedRepository(mixed $repository): string
    {
        if (! \is_string($repository) || mb_strlen($repository) > WorkIdentity::MAX_REPOSITORY || preg_match(WorkIdentity::REPOSITORY, $repository) !== 1) {
            throw new InvalidArgumentException('A delivery names its repository as owner/name.');
        }

        return $repository;
    }

    /**
     * A nested object from a payload, refused when it is missing.
     *
     * @param  array<array-key, mixed>  $payload  The delivery.
     * @param  string  $key  The object's key.
     * @return array<array-key, mixed> The object.
     */
    private static function object(array $payload, string $key): array
    {
        $object = $payload[$key] ?? null;

        if (! \is_array($object)) {
            throw new InvalidArgumentException(sprintf('The delivery carries no `%s`.', $key));
        }

        return $object;
    }

    /**
     * An issue or pull request's number.
     *
     * @param  array<array-key, mixed>  $object  The issue or pull request.
     * @return int The number.
     */
    private static function number(array $object): int
    {
        $number = $object['number'] ?? null;

        if (! \is_int($number) || $number < 1) {
            throw new InvalidArgumentException('An item carries a positive number.');
        }

        return $number;
    }
}
