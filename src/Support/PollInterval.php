<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * How often a dashboard page asks the server for fresh state.
 *
 * **One place, because every page needs the validated value.** It lived on `Livewire\Dashboard`
 * while that component was the only one a route mounted and the panels took the number as a
 * parameter from it. Once each panel has a route of its own, each one has to validate the host's
 * configuration itself -- and a second copy of `is_int && >= 1 && <= MAX` is how two pages come to
 * poll at different rates on the same host.
 *
 * A host may put anything in a published config file. A non-integer renders a `wire:poll` attribute
 * the browser silently ignores, leaving a page that never refreshes and says nothing about it; a
 * value below the floor multiplies every panel's per-render cost by the ratio. Neither is refused,
 * because a service provider that throws takes down the `config:clear` that would fix it -- the
 * documented default is used instead.
 *
 * A class of its own for the reason `HostKey`, `ProjectId` and `AggregateCount` are: one narrow
 * helper per value, rather than the rule restated by each caller.
 */
final class PollInterval
{
    /**
     * The interval used when a host has configured something unusable.
     */
    public const int DEFAULT = 5;

    /**
     * The longest interval a host may configure.
     */
    public const int MAX = 3600;

    /**
     * The configured interval, or the default when it is unusable.
     *
     * @param  Repository  $config  The application's configuration repository.
     * @return int Seconds between refreshes, from 1 to `MAX`.
     */
    public static function fromConfig(Repository $config): int
    {
        $configured = $config->get('robot-council.dashboard.poll_seconds', self::DEFAULT);

        return self::usable($configured) ? $configured : self::DEFAULT;
    }

    /**
     * Whether a configured value is one a page can poll on.
     *
     * @param  mixed  $configured  Whatever the host put in the config file.
     * @return bool True when it is an integer inside the bounds.
     *
     * @phpstan-assert-if-true int $configured
     */
    private static function usable(mixed $configured): bool
    {
        return \is_int($configured) && $configured >= 1 && $configured <= self::MAX;
    }
}
