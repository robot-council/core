<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use RobotCouncil\Models\AgentSession;

/**
 * Whether each lane's watcher is alive, read from its own heartbeat (#337).
 *
 * **Apart from session contact, on purpose.** Any request refreshes `last_seen_at`; only the
 * watcher's heartbeat refreshes `watcher_seen_at`, so an agent calling tools with no watcher behind
 * it reads as exactly that. #314 set the four readings:
 *
 * - `absent` -- the watcher never reported;
 * - `alive` -- it reported within `presence.watcher_stale_after_seconds`;
 * - `stale` -- it reported, then stopped, shown with how long ago;
 * - `unknown` -- the last reading is older than fifteen minutes, which says too little to call it
 *   either way, and the board asks to re-read.
 */
final class Watchers
{
    /**
     * A reading older than this says nothing reliable either way, as #314 records.
     */
    public const int UNKNOWN_AFTER_SECONDS = 900;

    /**
     * @param  Repository  $config  The application's configuration.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * Record the watcher's heartbeat for its session.
     *
     * One conditional update naming the session, so it writes only its own row.
     *
     * @param  AgentSession  $session  The session the watcher belongs to.
     */
    public function beat(AgentSession $session): void
    {
        AgentSession::query()->whereKey($session->getKey())->update(['watcher_seen_at' => PresenceClock::now()]);
    }

    /**
     * What a watcher's last heartbeat says.
     *
     * @param  mixed  $seenAt  The session's `watcher_seen_at`, as the row holds it.
     * @param  DateTimeInterface  $at  The moment to read it at.
     * @return array{state: string, age_seconds: int|null} The reading.
     */
    public function reading(mixed $seenAt, DateTimeInterface $at): array
    {
        $seen = match (true) {
            $seenAt instanceof DateTimeInterface => CarbonImmutable::instance($seenAt),
            \is_string($seenAt) && $seenAt !== '' => CarbonImmutable::parse($seenAt, PresenceClock::ZONE),
            default => null,
        };

        if (! $seen instanceof CarbonImmutable) {
            return ['state' => 'absent', 'age_seconds' => null];
        }

        $age = max(0, (int) $seen->diffInSeconds(CarbonImmutable::instance($at), false));

        return [
            'state' => match (true) {
                $age > self::UNKNOWN_AFTER_SECONDS => 'unknown',
                $age > $this->staleAfterSeconds() => 'stale',
                default => 'alive',
            },
            'age_seconds' => $age,
        ];
    }

    /**
     * How long a heartbeat counts as alive.
     *
     * @return int Seconds, at least one.
     */
    private function staleAfterSeconds(): int
    {
        $seconds = $this->config->get('robot-council.presence.watcher_stale_after_seconds', 90);

        return max(1, \is_int($seconds) ? $seconds : 90);
    }
}
