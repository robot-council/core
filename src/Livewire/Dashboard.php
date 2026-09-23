<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RobotCouncil\Access\CurrentDeveloper;

/**
 * The dashboard's index.
 *
 * It renders the shell and nothing else yet. The task board, presence and locks, and the change
 * feed arrive as their own slices and mount inside this page; what this component exists to prove
 * is that the whole path works -- the route, the allowlist gate, Livewire, and the stylesheet this
 * package compiles and serves.
 *
 * The polling interval is read here rather than in the view, so a page that displays it and a
 * component that polls on it cannot disagree about what it is.
 */
#[Layout('robot-council::layouts.dashboard')]
final class Dashboard extends Component
{
    /**
     * The interval this page refreshes on, in seconds.
     *
     * Locked, because `mount()` runs once and every later request goes through `hydrate()`. A
     * public property without this is writable by whatever posts to `/livewire/update`: the
     * snapshot's checksum covers the snapshot rather than the `updates` map, so a client sets it
     * to whatever they like. **Not to protect against a zero** -- Livewire's `extractDurationFrom()`
     * ends `return durationInMilliSeconds || defaultDuration`, so `wire:poll.0s` takes its own
     * two-second default rather than looping as fast as the browser can. To protect against a small
     * one: every panel on this page costs queries per render, so one second where a host configured
     * five multiplies the fleet's query load by five. The validation below guards the host's
     * configuration; this guards the client.
     */
    #[Locked]
    public int $pollSeconds = self::DEFAULT_POLL_SECONDS;

    /**
     * The interval used when a host has configured something unusable.
     */
    public const int DEFAULT_POLL_SECONDS = 5;

    /**
     * The longest interval a host may configure.
     */
    public const int MAX_POLL_SECONDS = 3600;

    /**
     * Read the configured interval once, when the component mounts.
     *
     * @param  Repository  $config  The application's configuration.
     */
    public function mount(Repository $config): void
    {
        $configured = $config->get('robot-council.dashboard.poll_seconds', self::DEFAULT_POLL_SECONDS);

        // A host may put anything in a published config file, and this value becomes a `wire:poll`
        // interval: a zero would ask the browser to poll as fast as it can, and a non-integer would
        // render an attribute the browser silently ignores, leaving a page that never refreshes and
        // says nothing about it
        $this->pollSeconds = \is_int($configured) && $configured >= 1 && $configured <= self::MAX_POLL_SECONDS
            ? $configured
            : self::DEFAULT_POLL_SECONDS;
    }

    /**
     * Render the page.
     *
     * @param  CurrentDeveloper  $developer  Who is signed in on the package's guard.
     * @return View The dashboard index.
     */
    public function render(CurrentDeveloper $developer): View
    {
        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI. Written
        // inline it passes locally and fails there with `expects view-string|null, string given`.
        /** @var view-string $template */
        $template = 'robot-council::livewire.dashboard';

        return view($template, [
            // Decided here rather than with `@can` in the view. `@can` asks the framework gate to
            // resolve the principal, and it resolves the HOST'S DEFAULT guard -- so on a host that
            // defaults to another one the panel would be hidden from a real admin. This is what to
            // show; `Administration` authorizes every action and its own render regardless.
            'isAdmin' => $developer->isAdmin(),
        ]);
    }
}
