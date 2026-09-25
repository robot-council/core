<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RobotCouncil\Support\FleetPresence;
use RobotCouncil\Support\PollInterval;
use RobotCouncil\Support\TaskList;

/**
 * The fleet's three totals, above the panels.
 *
 * **Each is a counting query, never a `count()` over a page.** The panels below are bounded by
 * their stores' `MAX_PAGE`, so a total measured from one would report the bound rather than the
 * truth on any fleet large enough for the number to matter -- and a capped total is
 * indistinguishable from a real one, which is the failure this row exists to prevent rather than
 * introduce.
 *
 * **It polls itself rather than riding the index.** A parent refresh does not re-execute a child
 * component -- Livewire spoofs an already-rendered child into a placeholder -- so a `wire:poll` on
 * the dashboard would leave this row stale while the panels beneath it updated.
 *
 * It reads through the same stores the panels do, with the **same predicates** -- asserted rather
 * than assumed. That is a claim about the definitions rather than about a moment: the tile and the
 * panel issue separate, untransacted reads, and this row renders first, so on a fleet under write
 * load the two can transiently differ by a row. What they cannot do is disagree about what `live`
 * or `held` MEANS.
 *
 * Its only gate is the route, exactly as the other panels': `EnsureAllowlistedDeveloper` on the
 * dashboard, and the same middleware registered as persistent so it survives onto
 * `/livewire/update`. These are totals rather than rows, so nothing here is developer-specific --
 * but the counts still describe the whole fleet, which is what the allowlist gate protects.
 */
final class FleetTotals extends Component
{
    /**
     * The interval this row refreshes on, in seconds.
     *
     * Locked for the reason `Livewire\Dashboard` records: a public property without it is writable
     * by whatever posts to `/livewire/update`, because the snapshot's checksum covers the snapshot
     * rather than the `updates` map.
     *
     * **What that buys is not protection from a zero.** Livewire's `extractDurationFrom()` ends
     * `return durationInMilliSeconds || defaultDuration`, so `wire:poll.0s` falls back to its own
     * two-second default rather than looping as fast as the browser can. What it buys is protection
     * from a SMALL one: this row costs three aggregates a render and the panels beside it cost more,
     * so a client setting one second where a host configured five multiplies the whole fleet's
     * query load by five.
     */
    #[Locked]
    public int $pollSeconds = PollInterval::DEFAULT;

    /**
     * Take the interval the index was given.
     *
     * @param  Repository  $config  The application's configuration repository.
     * @param  int|null  $pollSeconds  The interval a parent passed, or null to read the host's.
     */
    public function mount(Repository $config, ?int $pollSeconds = null): void
    {
        // Null when a route mounted this directly rather than the overview passing it down,
        // which is every visit now that each panel has a page of its own.
        $this->pollSeconds = PollInterval::orConfig($pollSeconds, $config);
    }

    /**
     * Render the row.
     *
     * @param  FleetPresence  $presence  The store the Agents and Locks pages read.
     * @param  TaskList  $tasks  The store the task board reads.
     * @return View The three tiles.
     */
    public function render(FleetPresence $presence, TaskList $tasks): View
    {
        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.fleet-totals';

        return view($template, [
            'liveSessions' => $presence->liveSessions(),

            // Everything not terminal, which is what `TaskList::openTasks()` derives from
            // `TaskStatus::terminal()` rather than listing
            'openTasks' => $tasks->openTasks(),
            'heldLocks' => $presence->heldLocks(),
        ]);
    }
}
