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
 * `sessionTokenExpiry()` write `expires_at` on a token that **Sanctum** compares against its own
 * `now()`, which is the application's. Writing those in UTC while Sanctum reads them in the host's
 * zone would introduce exactly the mismatch this class exists to remove, in a place that decides
 * whether a credential still works.
 *
 * So what belongs here is the set of values that are only ever compared against each other --
 * `last_seen_at` and the cutoffs measured against it, and since #149 a lock's `acquired_at`,
 * `expires_at` and its two Eloquent timestamps, which nothing outside this package reads.
 *
 * A device code's `expires_at` joined them in #160, for the same reason: `Support\DeviceCodes`
 * both writes and compares it, so there was never a second side to keep in step.
 *
 * **What is left outside this clock is not nothing, and this is a scope rather than a census.** Of
 * the values whose drift `robot-council:doctor` reports, only a **token's** expiry remains, and
 * only because Sanctum reads it. Plenty else still calls `Carbon::now()` -- three retention
 * cutoffs, a device code's decision timestamps, a job's retry window -- and each is self-consistent,
 * written and compared on one clock, so nothing lapses. `Support\Doctor`'s docblock names them.
 *
 * **Upgrading a host that is not on UTC shifts existing rows by its offset, once.** Rows written
 * before carry wall-clock time and are read as UTC afterwards, so for one sweep they read as
 * *newer* than they are by the offset -- a session stays active slightly too long rather than being
 * marked gone too early. That is the safe direction **for presence**, and it is why presence needed
 * no migration.
 *
 * **It is not the safe direction everywhere, and the paragraph above must not be read as covering
 * the rest.** A lock lease reinterpreted the same way reads as already lapsed west of UTC, and an
 * outstanding device code survives its ceiling east of it. `Models\Lock` and `Models\DeviceCode`
 * each carry the note for their own column, including how long the window lasts, which is not the
 * same bound in the two cases.
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
