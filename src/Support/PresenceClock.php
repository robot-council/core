<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;

/**
 * The one clock the presence thresholds are measured on.
 *
 * **Wall-clock time is not monotonic, and the presence sweep measures elapsed time.** A host that
 * sets `app.timezone` to a zone with daylight saving gets `Carbon::now()` in that zone, and
 * `Connection::prepareBindings()` writes it to the database naive -- formatted `Y-m-d H:i:s` with
 * the zone discarded. So at the spring-forward transition every `last_seen_at` is instantly an hour
 * behind `now()`, and the next sweep marks **the entire fleet gone** at a thirty-minute threshold:
 * every token deleted, every claim and lock released, while every process is still running. Falling
 * back does the mirror image and marks nothing stale for an hour, because every contact time reads
 * as being in the future (#51).
 *
 * Laravel's default `app.timezone` is `UTC`, so a default host never sees it. It is one line of
 * host configuration away, and its failure mode is fleet-wide.
 *
 * **Scoped to presence deliberately, and the scope is the interesting part.** Reading this clock
 * everywhere would be worse, not better: `Credentials::installationExpiry()` and
 * `sessionExpiry()` write `expires_at` on a token that **Sanctum** compares against its own
 * `now()`, which is the application's. Writing those in UTC while Sanctum reads them in the host's
 * zone would introduce exactly the mismatch this class exists to remove, in a place that decides
 * whether a credential still works.
 *
 * So what belongs here is the set of values that are only ever compared against each other --
 * `last_seen_at` and the cutoffs measured against it, and since #149 a lock's `acquired_at`,
 * `expires_at` and its two Eloquent timestamps, which nothing outside this package reads.
 *
 * **A device code's `expires_at` has the same shape and has not moved yet.**
 * `Credentials::deviceCodeExpiry()` writes it and `Support\DeviceCodes` is the only thing that
 * compares it, so there is no Sanctum coupling to stop it; the reason it is still here is that
 * nobody has done it, not that it cannot be done. Tracked separately.
 *
 * **Upgrading a host that is not on UTC shifts existing rows by its offset, once.** Rows written
 * before carry wall-clock time and are read as UTC afterwards, so for one sweep they read as
 * *newer* than they are by the offset -- a session stays active slightly too long rather than being
 * marked gone too early. That is the safe direction, and it is why this needs no migration.
 */
final class PresenceClock
{
    /**
     * The zone the presence clock runs in.
     *
     * UTC because it does not shift, not because it is the host's. Nothing here is displayed to a
     * person: `Support\FleetPresence` reports an elapsed duration rather than an instant, for the
     * clock-skew reason the API already states thresholds as durations.
     */
    public const string ZONE = 'UTC';

    /**
     * Now, on the presence clock.
     *
     * @return Carbon The current time in a zone that does not shift.
     */
    public static function now(): Carbon
    {
        return Carbon::now(self::ZONE);
    }
}
