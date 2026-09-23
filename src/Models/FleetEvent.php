<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in the fleet's change feed.
 *
 * The name avoids `Event`, which would collide with Laravel's own facade and with the `event()`
 * helper wherever both are in scope, and reads wrong in a package whose events are rows rather than
 * dispatched objects.
 *
 * An event is a fact about a moment, so it is written once and never edited: the table carries
 * `created_at` and no `updated_at`, and `posted_with_coordinator` records what was true when the
 * event was written rather than what is true when it is read.
 *
 * @property int $id
 * @property int|null $agent_session_id
 * @property string|null $user_id
 * @property string|null $actor_user_id
 * @property FleetEventType $type
 * @property string|null $body
 * @property array<string, mixed>|null $meta
 * @property bool $posted_with_coordinator
 * @property Carbon|null $created_at
 * @property-read AgentSession|null $session
 */
#[Fillable([
    'agent_session_id',
    'user_id',
    'actor_user_id',
    'type',
    'body',
    'meta',
    'posted_with_coordinator',
])]
#[Table(name: 'robot_council_events')]
final class FleetEvent extends Model
{
    /**
     * The longest body an event may carry.
     *
     * Policy rather than capacity: the column is `text`, which holds far more. A body reaches every
     * agent the visibility rule admits, and an agent's context window is the real budget.
     */
    public const int MAX_BODY = 4000;

    /**
     * Nothing updates an event, so there is no column to touch.
     */
    public const ?string UPDATED_AT = null;

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
            'agent_session_id' => 'integer',
            'type' => FleetEventType::class,
            'meta' => 'array',
            'posted_with_coordinator' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The agent session that wrote this event, when a session did.
     *
     * @return BelongsTo<AgentSession, $this> The posting session, or nothing for a service event.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class, 'agent_session_id');
    }
}
