<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A contact time that is stored, read back, and re-bound on the presence clock.
 *
 * **Writing on a fixed zone is only half of it.** The column is a naive `datetime`: the driver
 * stores `Y-m-d H:i:s` with the zone discarded, so `Support\PresenceClock` writing UTC digits makes
 * the SQL comparison correct -- `where last_seen_at <= ?` is digits against digits, both UTC. What
 * it does not fix is the read: Eloquent's `datetime` cast hydrates through `Model::asDateTime()`,
 * which labels the parsed value with the **application's** timezone. On a host at UTC+5:30 the
 * instant that comes back is five and a half hours from the one that went in (#51).
 *
 * Two things break on that, and the second is the dangerous one:
 *
 * - `Support\FleetPresence` reports `seconds_since_contact` as the difference between the presence
 *   clock and this value, so every session's age is wrong by the host's offset.
 * - **`Support\SessionPresence` re-binds the contact time its read saw** into the `where` of the
 *   conditional update that commits a status. A value mislabelled on the way out is bound back
 *   mislabelled, so the update matches no row -- and a sweep that matches nothing does not fail,
 *   it reports that nothing needed marking. The fleet would simply stop going stale, quietly, on
 *   any host that is not on UTC.
 *
 * So the column is made genuinely timezone-aware here rather than left to the application's
 * default, which is the second of the two remedies #51 allows.
 *
 * @implements CastsAttributes<Carbon, Carbon|string>
 */
final class PresenceTimestamp implements CastsAttributes
{
    /**
     * The format the column holds, which is the driver's naive datetime.
     */
    public const string FORMAT = 'Y-m-d H:i:s';

    /**
     * Read the stored digits back as the instant they were written at.
     *
     * @param  Model  $model  The model being hydrated.
     * @param  string  $key  The attribute's name.
     * @param  mixed  $value  The stored value.
     * @param  array<string, mixed>  $attributes  The model's raw attributes.
     * @return Carbon|null The instant, on the presence clock.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->setTimezone(PresenceClock::ZONE);
        }

        // Parsed **in** the presence clock's zone rather than parsed and then converted: the stored
        // digits carry no zone, and converting would relabel an instant that was never in the
        // application's zone to begin with.
        return Carbon::parse(\is_scalar($value) ? (string) $value : '', PresenceClock::ZONE);
    }

    /**
     * Store an instant as digits on the presence clock.
     *
     * @param  Model  $model  The model being saved.
     * @param  string  $key  The attribute's name.
     * @param  mixed  $value  The value being set.
     * @param  array<string, mixed>  $attributes  The model's raw attributes.
     * @return string|null The digits to store.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Converted rather than parsed-in-zone, because what arrives here is an instant that
        // already knows its own zone -- usually `PresenceClock::now()`, occasionally a test's
        // frozen clock in whatever zone the application is set to. Both are the same moment.
        $instant = $value instanceof Carbon
            ? $value->copy()
            : Carbon::parse(\is_scalar($value) ? (string) $value : 'now');

        return $instant->setTimezone(PresenceClock::ZONE)->format(self::FORMAT);
    }
}
