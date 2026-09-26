<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use RobotCouncil\Access\Role;
use RobotCouncil\Support\PresenceTimestamp;

/**
 * One running agent process. It is the principal a session token authenticates as, rather than the
 * developer who owns it, so claims, locks, and presence belong to the process and a leaked token
 * cannot act as the human.
 *
 * It is authenticatable because Sanctum resolves a token's owner through an authentication
 * provider, which needs one. It deliberately does not extend `Illuminate\Foundation\Auth\User`,
 * the framework's base user: a host application whose `auth.providers.users.model` names that class
 * would then find an agent session to be an instance of its own user model, and Sanctum's
 * `Guard::hasValidProvider()` -- an `instanceof` against exactly that model -- would admit a session
 * token on the host's own `auth:sanctum` routes. Sharing no ancestor with the host's user model is
 * what makes that impossible rather than unlikely.
 *
 * It owns no credentials of its own: there is no password column, and nothing signs in as a
 * session. The password-reset and email-verification behavior the framework's base user carries
 * would be meaningless here, so it is absent.
 *
 * @property int $id
 * @property int $installation_id
 * @property string $user_id
 * @property AgentSessionStatus $status
 * @property Role $role
 * @property Carbon $last_seen_at
 * @property Carbon|null $created_at
 * @property string|null $repository
 * @property string|null $work_location
 * @property string|null $os_family
 * @property string|null $arch
 * @property int $declared_capacity
 * @property Role|null $requested_role
 * @property Carbon|null $requested_at
 * @property int $feed_cursor
 * @property bool $ephemeral
 * @property-read Installation $installation
 *
 * @phpstan-use HasApiTokens<PersonalAccessToken>
 */
#[Fillable([
    'installation_id',
    'user_id',
    'status',
    'role',
    'last_seen_at',
    'repository',
    'work_location',
    'os_family',
    'arch',
    'declared_capacity',
    'requested_role',
    'requested_at',
    'ephemeral',
])]
#[Table(name: 'robot_council_agent_sessions')]
final class AgentSession extends Model implements AuthenticatableContract
{
    use Authenticatable;

    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    /**
     * What a model built in memory holds before it is saved (#409).
     *
     * The column defaults to one as well, but a host constructing a session itself would otherwise
     * hold `null` until a reload, and `Support\Capacity` reads this as an integer. Public, since a
     * subclass may widen a parent's visibility and the package keeps nothing protected.
     *
     * @var array<string, mixed>
     */
    public $attributes = ['declared_capacity' => 1, 'ephemeral' => false];

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
            'installation_id' => 'integer',
            'feed_cursor' => 'integer',
            'declared_capacity' => 'integer',
            'ephemeral' => 'boolean',
            'status' => AgentSessionStatus::class,
            // Cast like `status`, and carrying the same exposure: Laravel resolves an enum cast
            // through `from()`, so a row holding a name the enum no longer has raises a
            // `ValueError` rather than reading as unknown. The column is written only by this
            // package and its migration, and a renamed case takes a data migration with it, which
            // is the rule `2026_09_23_000002_rename_session_enrolled_events.php` was paid for.
            'role' => Role::class,
            // Nullable, and cast like `role` -- so a pending request is read back as a `Role`
            // rather than as a string every caller has to resolve. Null means nothing is pending.
            'requested_role' => Role::class,
            'requested_at' => 'datetime',
            // Not `datetime`: that hydrates in the application's timezone, and the presence
            // sweep re-binds the value it read into the `where` that commits a status. See
            // `Support\PresenceTimestamp` for what that costs on a host that is not on UTC (#51).
            'last_seen_at' => PresenceTimestamp::class,
        ];
    }

    /**
     * The installation that started this session.
     *
     * @return BelongsTo<Installation, $this> The owning installation.
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    /**
     * The tasks this session claimed, in any status.
     *
     * Exists for `Support\SessionPresence::prune()`, which will not delete a session while one of
     * these is still in a held status. `robot_council_tasks.claimed_by` is `nullOnDelete`, so
     * deleting the row would strip a task of its claimant while its status still says it is held,
     * leaving a row no release path can reach.
     *
     * **Every task it ever claimed, not only the ones it holds**, because `claimed_by` is not
     * cleared when a task finishes. The caller narrows by status; a relation that pretended to
     * mean "held" would be wrong the moment somebody read it for anything else.
     *
     * @return HasMany<Task, $this> The tasks claimed by this session.
     */
    public function claimedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'claimed_by');
    }

    /**
     * The locks naming this session as holder.
     *
     * The same shape as `claimedTasks()` and for the same reason: `robot_council_locks.holder_id`
     * is `nullOnDelete`, so deleting the session would free a held lock without the feed event a
     * release writes. A row whose lease has lapsed is already free, so the caller narrows by
     * expiry rather than this relation doing it.
     *
     * @return HasMany<Lock, $this> The locks this session is named on.
     */
    public function heldLocks(): HasMany
    {
        return $this->hasMany(Lock::class, 'holder_id');
    }

    /**
     * The sessions the fleet is told about: every one that was not started ephemeral (#424).
     *
     * **Every list of sessions or lanes reads through this, and nothing that acts on a session
     * does.** An ephemeral session is a process `robot-council api` starts around one read, so a
     * list that included it would show a seat nobody sits in, and a loop of reads would fill it
     * (`robot-council/cli#298`). Authentication, claims, locks, releases and the sweep all still
     * reach it through `query()`, because an ephemeral session that does take something has to give
     * it back.
     *
     * @return Builder<static> The sessions that are not ephemeral.
     */
    public static function announced(): Builder
    {
        return self::query()->where('ephemeral', false);
    }

    /**
     * Determine whether the session was started ephemeral (#424).
     *
     * Read off the instance rather than the row, which is safe here where it is not for `status`:
     * the flag is written once, at start, and nothing in the package changes it afterwards.
     * Compared rather than returned, so an instance hydrated from a partial select -- which holds no
     * value for the column -- reads as the ordinary session it most likely is.
     *
     * @return bool True when the fleet is not to be told the session exists.
     */
    public function isEphemeral(): bool
    {
        return $this->ephemeral === true;
    }

    /**
     * Determine whether the session has ended.
     *
     * @return bool True once the process is gone, which is final.
     */
    public function hasGone(): bool
    {
        return $this->status === AgentSessionStatus::Gone;
    }

    /**
     * Determine whether nothing has been heard from the process for a while.
     *
     * @return bool True while the session is stale, which one request undoes.
     */
    public function hasGoneQuiet(): bool
    {
        return $this->status === AgentSessionStatus::Stale;
    }
}
