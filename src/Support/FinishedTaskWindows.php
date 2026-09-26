<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use RobotCouncil\Models\TaskStatus;

/**
 * How long a finished task stays on the dashboard's unfiltered queue (#420).
 *
 * **A display window, not a retention.** `robot-council:prune-tasks` deletes a finished task at
 * `retention.tasks_days` (#127); this only decides what the board shows by default, and filtering
 * the board to a status shows every task in it whatever its age. One window per terminal status,
 * which is the decision on #420: `done` and `cancelled` are finished business and leave after a
 * day, while a `failed` task usually still needs someone and stays for a week, long enough to be
 * seen across a weekend.
 *
 * Age is measured from `updated_at`, which is when the task finished, as #127 established: nothing
 * transitions out of a terminal status. A task exactly at the cutoff is still shown; only one older
 * than its window is hidden.
 *
 * **A value a host published that is not a whole number of hours from 0 up is not refused**, for the
 * reason `PollInterval` gives: this is read on every render, and the documented default is used
 * instead. Zero means never hide, as the board behaved before #420.
 */
final readonly class FinishedTaskWindows
{
    /**
     * The window for each terminal status, in hours, when the host has configured nothing usable.
     *
     * @var array<string, int>
     */
    public const array DEFAULT_HOURS = [
        'done' => 24,
        'cancelled' => 24,
        'failed' => 168,
    ];

    /**
     * The longest window a host may configure: ten years, so that no arithmetic on it overflows.
     * Any window past `retention.tasks_days` already means the prune removes the task first.
     */
    public const int MAX_HOURS = 87_600;

    /**
     * @param  Repository  $config  The application's configuration repository.
     */
    public function __construct(private Repository $config) {}

    /**
     * Each terminal status's window, in hours.
     *
     * @return array<string, int> Hours by status value; zero never hides.
     */
    public function hours(): array
    {
        $hours = [];

        foreach (TaskStatus::terminal() as $status) {
            $configured = $this->config->get('robot-council.dashboard.hide_finished_after_hours.'.$status->value);

            $hours[$status->value] = \is_int($configured) && $configured >= 0 && $configured <= self::MAX_HOURS
                ? $configured
                : (self::DEFAULT_HOURS[$status->value] ?? 0);
        }

        return $hours;
    }

    /**
     * The moment before which a finished task is hidden, for each status that hides at all.
     *
     * @param  DateTimeInterface  $now  The time the board is read at.
     * @return array<string, CarbonImmutable> The cutoff by status value; a status whose window is zero is absent.
     */
    public function cutoffs(DateTimeInterface $now): array
    {
        $cutoffs = [];

        foreach ($this->hours() as $status => $hours) {
            if ($hours > 0) {
                $cutoffs[$status] = CarbonImmutable::instance($now)->subHours($hours);
            }
        }

        return $cutoffs;
    }
}
