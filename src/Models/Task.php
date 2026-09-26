<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One unit of work agents hand each other.
 *
 * Nothing here decides a transition. Every state change is a conditional update written by
 * `Support\Tasks`, so this model is what a task *is* rather than what may happen to it: the rules
 * live in `TaskTransition`, and the write that enforces them is the same statement that performs
 * them.
 *
 * @property int $id
 * @property int|null $parent_task_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property int $priority
 * @property int $queue_rank
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $result
 * @property int|null $claimed_by
 * @property Carbon|null $claimed_at
 * @property int|null $created_by
 * @property string $user_id
 * @property bool $created_with_coordinator
 * @property string|null $project_id
 * @property string|null $issue
 * @property string|null $branch
 * @property string|null $sub_label
 * @property Placement|null $placed_by
 * @property Carbon|null $github_finished_at
 * @property Carbon|null $result_added_at
 * @property bool $hand_back
 * @property Carbon|null $created_at
 * @property-read AgentSession|null $claimant
 * @property-read AgentSession|null $creator
 */
#[Fillable([
    'parent_task_id',
    'title',
    'description',
    'status',
    'priority',
    'payload',
    'result',
    'claimed_by',
    'claimed_at',
    'created_by',
    'user_id',
    'created_with_coordinator',
    'project_id',

    // Named at creation. `branch`, `sub_label`, `placed_by` and `hand_back` are deliberately absent:
    // each is a fact about how the task came to be held, so only a conditional update naming the
    // holder writes them
    'issue',
])]
#[Table(name: 'robot_council_tasks')]
final class Task extends Model
{
    /**
     * The longest title a task may carry.
     */
    public const int MAX_TITLE = 255;

    /**
     * The longest description a task may carry.
     */
    public const int MAX_DESCRIPTION = 4000;

    /**
     * The highest priority a task may be given. Higher is more urgent.
     */
    public const int MAX_PRIORITY = 9;

    /**
     * Urgency, and the ascending key the queue is actually ordered by.
     *
     * Setting `priority` sets both columns, in one assignment, so nothing can give them disagreeing
     * values through the model -- including `Support\Tasks::create()`, which a host may call
     * directly. The queue orders by `queue_rank` ascending because `order by priority desc, id asc`
     * matches no single-direction b-tree in either scan direction, and Laravel's `Blueprint::index()`
     * cannot declare a direction; ascending, an index can be walked instead of sorted. No API serves
     * this column: a client sends and reads `priority`, where higher is still more urgent, and
     * `TaskList::describe()` names the fields it returns one by one.
     *
     * Public rather than protected, because Pest's `strict()` preset forbids protected methods in
     * the package's namespaces. Laravel finds an attribute mutator by method name and does not care
     * about its visibility.
     *
     * **Clamped here, not only at the edge.** Both API paths validate `between:0,9`, but
     * `Support\Tasks::create()` spreads what it is given and a host may call it directly -- and an
     * unclamped `priority` of 10 writes a `queue_rank` of -1, which sorts ahead of every legitimate
     * task forever. That is the same queue-jump the `unsignedTinyInteger` on `priority` exists to
     * stop, arriving through the column that has no such backstop in the direction that matters:
     * `priority` is unsigned on MySQL and signed `smallint` on Postgres, so one out-of-range write
     * is a permanent queue jump on two engines and a 500 or a silent disagreement on the third.
     * Clamping rather than throwing, because that is what `TaskList` and `FleetFeed` already do with
     * a caller's bounds.
     *
     * Taking `mixed` rather than `int` for the reason CLAUDE.md gives for `Access\Tokens` and
     * `Console\Argument`: a narrower parameter turns a host's bad value into an uncaught `TypeError`
     * from inside vendor code.
     *
     * `never` for the read side because there is no accessor: reading `priority` returns the column
     * as the `integer` cast gives it.
     *
     * @return Attribute<never, mixed> The urgency being set, which writes `priority` and `queue_rank`.
     */
    public function priority(): Attribute
    {
        return Attribute::set(function (mixed $value): array {
            $priority = max(0, min(self::MAX_PRIORITY, is_numeric($value) ? (int) $value : 0));

            return [
                'priority' => $priority,
                'queue_rank' => self::MAX_PRIORITY - $priority,
            ];
        });
    }

    /**
     * The attribute casts.
     *
     * Public rather than protected, because Pest's `strict()` preset forbids protected methods in
     * the package's namespaces, and PHP allows a subclass to widen a parent's visibility.
     *
     * @return array<string, string> The casts Eloquent applies to this model's attributes.
     */
    public function casts(): array
    {
        return [
            'parent_task_id' => 'integer',
            'status' => TaskStatus::class,
            'priority' => 'integer',
            'queue_rank' => 'integer',
            'payload' => 'array',
            'result' => 'array',
            'claimed_by' => 'integer',
            'claimed_at' => 'datetime',
            'created_by' => 'integer',
            'created_with_coordinator' => 'boolean',
            'placed_by' => Placement::class,
            'hand_back' => 'boolean',
            'github_finished_at' => 'datetime',
            'result_added_at' => 'datetime',
        ];
    }

    /**
     * The session holding this task, while one does.
     *
     * @return BelongsTo<AgentSession, $this> The claimant.
     */
    public function claimant(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class, 'claimed_by');
    }

    /**
     * The session that created this task.
     *
     * @return BelongsTo<AgentSession, $this> The creator, which may have been deleted.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class, 'created_by');
    }

    /**
     * The tasks filed under this one.
     *
     * Exists for `Support\Tasks::prune()`, which will not delete a task that still has one.
     * `parent_task_id` is `nullOnDelete`, so deleting a parent rewrites a row nobody chose to
     * touch, and that row can be a task the fleet is still working on.
     *
     * @return HasMany<self, $this> The children, which a deletion leaves rather than taking.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    /**
     * Whether a session may claim this task, under #16.
     *
     * The same condition the claim's conditional update carries, in the form a diagnosis can read.
     * Both are written from `user_id` and `created_with_coordinator` on this row, so a creating
     * session that has since been deleted, or a coordinator's ability revoked since, changes
     * neither answer.
     *
     * @param  AgentSession  $session  The session attempting the claim.
     * @return bool True when the task is the session's developer's, or was created by a coordinator.
     */
    public function isClaimableBy(AgentSession $session): bool
    {
        return $this->user_id === $session->user_id || $this->created_with_coordinator;
    }
}
