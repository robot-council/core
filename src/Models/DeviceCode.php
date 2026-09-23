<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RobotCouncil\Support\PresenceTimestamp;

/**
 * One enrollment request, from the moment a helper asks for a code until a developer decides it and
 * the helper exchanges it.
 *
 * Neither the `device_code` nor the verifier challenge is stored as sent: the table holds their
 * SHA-256 hashes, so reading the row gives an attacker nothing to exchange. `user_code` is stored
 * as sent, because a developer has to read it off one screen and type it into another.
 *
 * Everything the requester supplied -- `harness`, `machine_label`, and the abilities asked for --
 * is a claim, not a fact. The verification page says so, and the server computes what is granted.
 *
 * @property int $id
 * @property string $device_code_hash
 * @property string $challenge_hash
 * @property string $user_code
 * @property list<string> $requested_abilities
 * @property list<string>|null $granted_abilities
 * @property string $harness
 * @property string $machine_label
 * @property string|null $requested_ip
 * @property Carbon $expires_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $denied_at
 * @property Carbon|null $consumed_at
 * @property string|null $decided_by
 * @property Carbon $created_at
 *
 * **`expires_at` is on `Support\PresenceClock`** (#160), which means a host that is not on UTC
 * sees its outstanding codes reinterpreted once, at the upgrade. Rows written before it carry
 * wall-clock digits and are read as UTC afterwards.
 *
 * **East of UTC that is the unsafe direction, and the window is the host's offset rather than the
 * TTL.** A code written at `+12` stored digits twelve hours ahead of the instant it meant, so after
 * the upgrade it reads as expiring twelve hours later than it should -- and the hourly
 * `robot-council:prune-device-codes` will not remove it either, because it makes the same
 * comparison. An approvable enrollment code outliving its ten-minute ceiling is what
 * `Support\Credentials`' clamp exists to prevent, so the reinterpretation reopens exactly that for
 * one offset's worth of time. West of UTC the outstanding codes expire at once instead, which costs
 * a developer a retry.
 *
 * `Models\Lock` carries the same note for the same reason. `Support\PresenceClock`'s remark that
 * the shift is in the safe direction is about **presence**, where a row reading newer only delays a
 * sweep; it does not hold here. A host that cannot drain its outstanding codes should delete them
 * by hand at the upgrade, which is one statement against a table nothing else depends on.
 */
#[Fillable([
    'device_code_hash',
    'challenge_hash',
    'user_code',
    'requested_abilities',
    'harness',
    'machine_label',
    'requested_ip',
    'expires_at',
])]
#[Table(name: 'robot_council_device_codes')]
final class DeviceCode extends Model
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
            'requested_abilities' => 'array',
            'granted_abilities' => 'array',
            // Not `datetime`: that hydrates in the application's timezone, while
            // `Support\Credentials::deviceCodeExpiry()` writes this on `Support\PresenceClock` and
            // `Support\DeviceCodes` compares it there. `DeviceCodes::consume()` also re-reads the row
            // and asks `expires_at->isPast()` in PHP, so a value relabelled on hydration would be
            // wrong in the path that decides whether an enrollment code still works (#160).
            'expires_at' => PresenceTimestamp::class,

            // **The other three stay on the application clock, and the difference is that nothing
            // compares them.** Each is read only as null-or-not -- `hasBeenDecided()`, the
            // `consumed_at` check in `consume()` -- so no clock can make one decide wrongly. They are
            // also `timestamp` columns rather than `dateTime`, which MySQL converts from the
            // connection's time zone on write and back on read, so their stored digits are decided
            // by the connection rather than by whichever clock handed over the value. Putting a
            // fixed-clock cast on top of that is the mixed-mechanism state #149 was bitten by, for
            // no decision it could correct.
            'approved_at' => 'datetime',
            'denied_at' => 'datetime',
            'consumed_at' => 'datetime',

            // **`created_at` and `updated_at` are not cast either, and casting them is a trap
            // rather than the tidy extension it looks like** -- the one #149 was bitten by.
            // `HasAttributes::getDates()` returns both whenever a model uses timestamps, whatever
            // its casts, and `setAttribute()` tests `isDateAttribute()` in an `elseif` chain that
            // runs *before* the class-cast branch, so an assignment is first flattened by
            // `fromDateTime()` into naive digits in the Carbon's own zone and then re-parsed by
            // `PresenceTimestamp::set()` in the application's. The two cancel only where the zones
            // agree. It matters more here than on `Models\Lock`, where nothing hydrates either
            // column: `ageInSeconds()` below reads `created_at`, and the enrollment page renders
            // it.
        ];
    }

    /**
     * Determine whether a developer has already approved or denied this code.
     *
     * @return bool True once either decision has been recorded.
     */
    public function isDecided(): bool
    {
        return $this->approved_at !== null || $this->denied_at !== null;
    }

    /**
     * How long ago the helper asked for this code.
     *
     * Shown on the verification page: a code a developer did not just request is the shape a
     * phishing attempt takes, where an attacker's code waits for somebody to approve it.
     *
     * @return int The code's age in seconds, never negative.
     */
    public function ageInSeconds(): int
    {
        return max(0, (int) $this->created_at->diffInSeconds(Carbon::now()));
    }
}
