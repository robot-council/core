<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RobotCouncil\Support\PresenceTimestamp;

/**
 * One developer's seat: a machine's harness, working in one repository at one work location.
 *
 * **It outlives every session that sits in it**, which is why it is not a session. #314 settled that
 * only the developer who parked a seat lifts it and that elapsed time never does; a parked session
 * would lift itself the next time its process restarted. The migration records the key and why
 * `work_location` is `''` rather than null for a seat that names none.
 *
 * Nothing mass-assigns this model. `Support\Seats` writes it with literal arrays through
 * conditional updates, so there is no `#[Fillable]` to keep honest.
 *
 * @property int $id
 * @property int $installation_id
 * @property string $user_id
 * @property string $repository
 * @property string $work_location
 * @property string|null $parked_by
 * @property Carbon|null $parked_at
 * @property bool $hours_exempt
 * @property-read Installation $installation
 */
#[Table(name: 'robot_council_seats')]
final class Seat extends Model
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
            'installation_id' => 'integer',
            // Written on `Support\PresenceClock`, like every other instant the package records,
            // so a host off UTC does not read it back shifted by its offset (#149)
            'parked_at' => PresenceTimestamp::class,
            'hours_exempt' => 'boolean',
        ];
    }

    /**
     * The installation this seat belongs to.
     *
     * @return BelongsTo<Installation, $this> The owning installation.
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    /**
     * Whether a developer has said this seat takes no work.
     *
     * @return bool True while it is parked.
     */
    public function isParked(): bool
    {
        return $this->parked_by !== null;
    }
}
