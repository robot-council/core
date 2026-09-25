<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A seat developer's waiver of one placement refusal, for one placement (#320).
 *
 * Nothing mass-assigns this model. `Support\PlacementWaivers` writes it with literal arrays.
 *
 * @property int $id
 * @property int $seat_id
 * @property PlacementRule $rule
 * @property string $granted_by
 * @property Carbon $granted_at
 * @property Carbon|null $consumed_at
 * @property int|null $consumed_by_task
 */
#[Table(name: 'robot_council_placement_waivers', timestamps: false)]
final class PlacementWaiver extends Model
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
            'seat_id' => 'integer',
            'rule' => PlacementRule::class,
            'granted_at' => 'datetime',
            'consumed_at' => 'datetime',
            'consumed_by_task' => 'integer',
        ];
    }
}
