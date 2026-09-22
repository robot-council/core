<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;

/**
 * The package's bounded numbers, read from configuration on every call so a host that changes one
 * does not have to restart anything: how long each credential lives, how long a session may go
 * without contact, and how many attempts a minute each rate limit allows.
 *
 * Every value is bounded here rather than trusted from configuration, because a zero or negative
 * lifetime would issue a credential that is already expired, and an unbounded device-code lifetime
 * would leave an approvable code waiting for a phishing victim for as long as the typo allowed.
 */
final class Credentials
{
    /**
     * The longest a device code may live, whatever configuration asks for.
     */
    public const int MAX_DEVICE_CODE_TTL_SECONDS = 600;

    /**
     * @param  Repository  $config  The host application's configuration repository.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * How long an installation credential lives before it has to be approved again.
     *
     * @return int The maximum age in days, at least one.
     */
    public function installationMaxAgeDays(): int
    {
        return $this->bounded('credentials.installation_max_age_days', 30);
    }

    /**
     * How long a session token lives before the helper has to renew it.
     *
     * @return int The lifetime in minutes, at least one.
     */
    public function sessionTtlMinutes(): int
    {
        return $this->bounded('credentials.session_ttl_minutes', 60);
    }

    /**
     * How long a device code may be approved and exchanged.
     *
     * @return int The lifetime in seconds, at least one and never above the ten-minute ceiling.
     */
    public function deviceCodeTtlSeconds(): int
    {
        return min(
            $this->bounded('credentials.device_code_ttl_seconds', self::MAX_DEVICE_CODE_TTL_SECONDS),
            self::MAX_DEVICE_CODE_TTL_SECONDS
        );
    }

    /**
     * How long the enrollment helper is told to wait between polls of the token endpoint.
     *
     * @return int The interval in seconds, at least one.
     */
    public function deviceCodeIntervalSeconds(): int
    {
        return $this->bounded('credentials.device_code_interval_seconds', 5);
    }

    /**
     * When an installation approved now would expire.
     *
     * @return Carbon The installation's expiry.
     */
    public function installationExpiry(): Carbon
    {
        return Carbon::now()->addDays($this->installationMaxAgeDays());
    }

    /**
     * When a session token issued now would expire.
     *
     * @return Carbon The token's expiry.
     */
    public function sessionTokenExpiry(): Carbon
    {
        return Carbon::now()->addMinutes($this->sessionTtlMinutes());
    }

    /**
     * When a device code requested now would expire.
     *
     * @return Carbon The code's expiry.
     */
    public function deviceCodeExpiry(): Carbon
    {
        return Carbon::now()->addSeconds($this->deviceCodeTtlSeconds());
    }

    /**
     * How long a session may go without contact before the sweep marks it stale.
     *
     * @return int The threshold in minutes, at least one.
     */
    public function staleAfterMinutes(): int
    {
        return $this->bounded('presence.stale_after_minutes', 5);
    }

    /**
     * How long a session may go without contact before the sweep marks it gone.
     *
     * Always at least a minute past the stale threshold, never merely equal to it. Equal is not
     * enough: the sweep runs its gone pass first, so two identical cutoffs mean every session goes
     * straight to gone and the warning state -- the one an operator reads before anything is
     * released -- exists in the enum and never in the feed.
     *
     * @return int The threshold in minutes, past the stale threshold.
     */
    public function goneAfterMinutes(): int
    {
        return max($this->bounded('presence.gone_after_minutes', 30), $this->staleAfterMinutes() + 1);
    }

    /**
     * How many sessions one sweep may move in each of its passes.
     *
     * A fleet that went silent at once is otherwise one unbounded batch, and every session in it
     * takes the feed's single writer lock in turn while every agent's narration queues behind it.
     *
     * @return int The ceiling, at least one.
     */
    public function maxPerSweep(): int
    {
        return $this->bounded('presence.max_per_sweep', 500);
    }

    /**
     * The contact time at or before which a session is stale.
     *
     * @return Carbon The cutoff.
     */
    public function staleCutoff(): Carbon
    {
        return Carbon::now()->subMinutes($this->staleAfterMinutes());
    }

    /**
     * The contact time at or before which a session has gone.
     *
     * @return Carbon The cutoff.
     */
    public function goneCutoff(): Carbon
    {
        return Carbon::now()->subMinutes($this->goneAfterMinutes());
    }

    /**
     * The longest lease a lock may be given or renewed for.
     *
     * Never longer than the hold ceiling. A host that configured a lease longer than the total
     * hold would otherwise advertise a maximum in the 422 that the 409 then refuses: a lock taken
     * at that lease could never be renewed, because the first renewal already exceeds the ceiling.
     * The lease comes down rather than the ceiling going up, so `max_hold_seconds` keeps meaning
     * what it says and the number the API advertises is one it will actually accept.
     *
     * @return int The ceiling in seconds, at least one.
     */
    public function lockMaxTtlSeconds(): int
    {
        return min($this->bounded('locks.max_ttl_seconds', 900), $this->lockMaxHoldSeconds());
    }

    /**
     * The longest one session may hold one name, measured from when it first acquired it.
     *
     * @return int The ceiling in seconds, at least one.
     */
    public function lockMaxHoldSeconds(): int
    {
        return $this->bounded('locks.max_hold_seconds', 14400);
    }

    /**
     * How many days the fleet's change feed is kept before a prune deletes it.
     *
     * Zero means forever, which is what a host archiving on its own terms wants -- so this is the
     * one reader here that admits zero, and `bounded()` cannot be used for it.
     *
     * @return int The retention in days, or zero to keep everything.
     */
    public function eventRetentionDays(): int
    {
        $configured = $this->config->get('robot-council.retention.events_days');

        if (! \is_int($configured) && (! \is_string($configured) || ! ctype_digit($configured))) {
            return 30;
        }

        return max(0, (int) $configured);
    }

    /**
     * How many days a finished task is kept before a prune deletes it.
     *
     * Longer than the feed's by default: a task is a unit of work somebody may want to look back
     * at, and there are far fewer of them than there are events. Zero means forever.
     *
     * @return int The retention in days, or zero to keep everything.
     */
    public function taskRetentionDays(): int
    {
        $configured = $this->config->get('robot-council.retention.tasks_days');

        if (! \is_int($configured) && (! \is_string($configured) || ! ctype_digit($configured))) {
            return 90;
        }

        return max(0, (int) $configured);
    }

    /**
     * How many days a lock nobody holds is kept before a prune deletes it.
     *
     * Shorter than the other two by default: a free lock row holds a name, a previous holder and a
     * number, none of which is read once the lease is over. Zero means forever.
     *
     * @return int The retention in days, or zero to keep everything.
     */
    public function lockRetentionDays(): int
    {
        $configured = $this->config->get('robot-council.retention.locks_days');

        if (! \is_int($configured) && (! \is_string($configured) || ! ctype_digit($configured))) {
            return 7;
        }

        return max(0, (int) $configured);
    }

    /**
     * How many locks one session may hold at once.
     *
     * @return int The ceiling, at least one.
     */
    public function locksPerSession(): int
    {
        return $this->bounded('locks.max_per_session', 20);
    }

    /**
     * How many attempts per minute one rate limit allows.
     *
     * @param  string  $key  The key under `robot-council.rate_limits`.
     * @param  int  $default  The value to use when configuration holds nothing usable.
     * @return int The configured limit, at least one.
     */
    public function rateLimit(string $key, int $default): int
    {
        return $this->bounded('rate_limits.'.$key, $default);
    }

    /**
     * Read one bounded whole number, falling back to the default for anything that is not a
     * positive one. Configuration arrives from the environment as a string, and an unset or
     * mistyped variable casts to zero.
     *
     * @param  string  $key  The key under `robot-council`.
     * @param  int  $default  The value to use when configuration holds nothing usable.
     * @return int The configured value, or the default.
     */
    private function bounded(string $key, int $default): int
    {
        $configured = $this->config->get('robot-council.'.$key);

        if (! \is_int($configured) && (! \is_string($configured) || ! ctype_digit($configured))) {
            return $default;
        }

        $value = (int) $configured;

        return $value >= 1 ? $value : $default;
    }
}
