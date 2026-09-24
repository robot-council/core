<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Why a coordinator is keeping one lane idle (#334).
 *
 * Nothing mass-assigns this model. `Support\LaneHolds` writes it with literal arrays.
 *
 * @property int $agent_session_id
 * @property HoldParty $party_kind
 * @property string $party
 * @property HoldReason $reason
 * @property int $held_by
 * @property Carbon $held_at
 */
#[Table(name: 'robot_council_lane_holds', key: 'agent_session_id', incrementing: false, timestamps: false)]
final class LaneHold extends Model
{
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
            'party_kind' => HoldParty::class,
            'reason' => HoldReason::class,
            'held_by' => 'integer',
            'held_at' => 'datetime',
        ];
    }

    /**
     * The hold as the board renders it: `<party> — <what>`.
     *
     * @return string The line.
     */
    public function reads(): string
    {
        return $this->party.' — '.$this->reason->reads();
    }
}
