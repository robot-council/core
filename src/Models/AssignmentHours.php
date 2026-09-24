<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The hours one developer's seats take new placements in.
 *
 * One row per developer, keyed on the host user; no row means none are set, and a developer who
 * has said nothing is not gated. The window is local to `timezone` and stored as typed, which the
 * migration explains. `Support\AssignmentWindow` is what answers whether a moment is inside it.
 *
 * Nothing mass-assigns this model. `Support\DeveloperSettings` writes it with a literal array.
 *
 * @property string $user_id
 * @property string $timezone
 * @property string $starts_at
 * @property string $ends_at
 * @property bool $skip_weekends
 */
#[Table(name: 'robot_council_assignment_hours', key: 'user_id', keyType: 'string', incrementing: false)]
final class AssignmentHours extends Model
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
            'skip_weekends' => 'boolean',
        ];
    }
}
