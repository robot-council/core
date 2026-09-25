<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RobotCouncil\Access\CurrentDeveloper;
use RobotCouncil\Support\LaneBoard;
use RobotCouncil\Support\PollInterval;

/**
 * The dashboard's overview.
 *
 * **It mounts no panel.** Each of the four is a page of its own -- the decision on #187, reversed
 * once the combined view's cost outgrew what it bought: keeping four panels on one page needed a
 * selection, a URL-synced property, a toggle action and a sidebar that went stale, and a route
 * mounts one panel with none of it.
 *
 * What is left here is the question a developer opens the console to ask -- is anything happening
 * at all -- answered by `Livewire\FleetTotals` in three counting queries, and a way into each
 * section for a viewport too narrow to show the sidebar.
 */
#[Layout('robot-council::layouts.dashboard')]
final class Dashboard extends Component
{
    /**
     * The interval this page refreshes on, in seconds.
     *
     * Locked: `mount()` runs once and every later request goes through `hydrate()`, so a public
     * property without this is writable by whatever posts to `/livewire/update` -- the snapshot's
     * checksum covers the snapshot rather than the `updates` map.
     *
     * **Not to protect against a zero.** Livewire's `extractDurationFrom()` ends
     * `return durationInMilliSeconds || defaultDuration`, so `wire:poll.0s` takes its own
     * two-second default rather than looping as fast as the browser can. To protect against a small
     * one: every page here costs queries per render, so one second where a host configured five
     * multiplies the fleet's query load by five.
     */
    #[Locked]
    public int $pollSeconds = PollInterval::DEFAULT;

    /**
     * Read the configured interval once, when the component mounts.
     *
     * @param  Repository  $config  The application's configuration.
     */
    public function mount(Repository $config): void
    {
        $this->pollSeconds = PollInterval::fromConfig($config);
    }

    /**
     * Render the overview.
     *
     * @param  CurrentDeveloper  $developer  Who is signed in on the package's guard.
     * @return View The totals, and the way into each section.
     */
    public function render(CurrentDeveloper $developer, LaneBoard $lanes): View
    {
        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.dashboard';

        return view($template, [
            // Whether to offer the administration section. Resolved here rather than with `@can`,
            // which asks the framework gate to resolve the principal and gets the HOST'S DEFAULT
            // guard -- so on a host that defaults to another one a real admin would not be offered
            // their own page. This decides what is *linked*; `Livewire\Administration` refuses in
            // `mount()` regardless, which is what makes the route safe on its own.
            'isAdmin' => $developer->isAdmin(),

            // The lane board's summary, counted by the same reader the board renders from
            'laneCounts' => $lanes->counts(),
        ]);
    }
}
