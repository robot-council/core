<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

/**
 * The states a task moves through.
 *
 * `done`, `failed`, and `cancelled` are terminal: nothing leaves them, which is why a former
 * claimant's `start` on a cancelled task is a conflict rather than a permission problem.
 */
enum TaskStatus: string
{
    /**
     * Waiting for somebody to claim it.
     */
    case Pending = 'pending';

    /**
     * Held by one agent session, which has not started work.
     */
    case Claimed = 'claimed';

    /**
     * Being worked by the session that holds it.
     */
    case InProgress = 'in_progress';

    /**
     * Held, and waiting on something outside the session's control.
     */
    case Blocked = 'blocked';

    /**
     * Finished successfully. Terminal.
     */
    case Done = 'done';

    /**
     * Finished unsuccessfully. Terminal.
     */
    case Failed = 'failed';

    /**
     * Called off by a coordinator. Terminal.
     */
    case Cancelled = 'cancelled';

    /**
     * Whether nothing leaves this status.
     *
     * @return bool True for the three statuses no transition starts from.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Failed, self::Cancelled => true,
            self::Pending, self::Claimed, self::InProgress, self::Blocked => false,
        };
    }

    /**
     * Whether a session holds a task in this status.
     *
     * @return bool True for the statuses that carry a claimant.
     */
    public function isHeld(): bool
    {
        return match ($this) {
            self::Claimed, self::InProgress, self::Blocked => true,
            self::Pending, self::Done, self::Failed, self::Cancelled => false,
        };
    }

    /**
     * The statuses nothing leaves.
     *
     * Derived from `isTerminal()` rather than listed again, so adding a status forces the decision
     * in the `match` above and cannot quietly default to prunable here.
     *
     * @return list<self> The terminal statuses.
     */
    public static function terminal(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $status): bool => $status->isTerminal()));
    }

    /**
     * The statuses a session holds a task in.
     *
     * @return list<self> The held statuses.
     */
    public static function held(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $status): bool => $status->isHeld()));
    }

    /**
     * Reduce a list of statuses to the values the column holds.
     *
     * @param  list<self>  $statuses  The statuses to convert.
     * @return list<string> Their stored values.
     */
    public static function values(array $statuses): array
    {
        return array_map(static fn (self $status): string => $status->value, $statuses);
    }
}
