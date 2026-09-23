<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RobotCouncil\Access\CurrentDeveloper;
use RobotCouncil\Support\DashboardSections;

/**
 * The dashboard's index.
 *
 * The task board, presence and locks, the change feed and the administration panel all mount
 * inside it, and which of them do is the developer's choice -- the decision on #187, where the
 * console stayed one page rather than splitting into routed sections because the readings that
 * matter cross panels.
 *
 * **A section that is not selected is not mounted, rather than hidden.** That is the whole point:
 * a panel hidden with a class still runs every query it would have run, and the lever this page
 * offers is cost rather than clutter. `tests/DashboardSectionsTest.php` asserts it by counting
 * queries, because markup that is absent and markup that is invisible read the same to a test.
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
     * Which panels to mount, comma-separated, or null for every one.
     *
     * **Deliberately not `#[Locked]`.** It is the reader's own choice and they set it, exactly as
     * the panels' own scope filters are. Everything it can express is bounded by
     * `Support\DashboardSections`, which matches it against a fixed list and drops the rest -- so a
     * client posting anything at all to `/livewire/update` selects from what they were already
     * offered or selects nothing, and nothing is what the default covers.
     *
     * `keep: false` so the query string carries it only when it differs from the default, which
     * keeps a shared link short and keeps the default out of the URL entirely.
     */
    #[Url(as: 'show', keep: false)]
    public ?string $show = null;

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
     * Show a section, or put it away.
     *
     * **The last selected section cannot be put away.** A page with no panels on it is not a state
     * worth reaching by accident, and with `keep: false` an empty selection would round-trip back
     * to every section on the next load -- so the control would appear to do nothing rather than
     * appear to be refused.
     *
     * @param  string  $section  The section to toggle.
     */
    public function toggle(string $section): void
    {
        // Resolved once. `CurrentDeveloper::isAdmin()` reaches the gate, which reads
        // `robot_council_github_identities` uncached -- so asking twice is two queries a click, and
        // two chances for what is offered and what is showing to be computed from different answers.
        $isAdmin = $this->isAdmin();

        $offered = DashboardSections::offered($isAdmin);

        // **A second layer whose redundancy is an implementation detail of the branch below.** The
        // add branch filters over `$offered`, so a section nobody was offered can never enter the
        // result even without this -- there is no input for which removing it mounts a panel the
        // developer may not have, and a mutation run will report it as a survivor for that reason.
        // It stays because the day that branch is rewritten to filter over something else, this is
        // what still refuses. Killing it would need a test asserting behaviour it does not have.
        // @pest-mutate-ignore
        if (! \in_array($section, $offered, true)) {
            return;
        }

        $showing = DashboardSections::from($this->show, $isAdmin);

        $next = \in_array($section, $showing, true)
            ? array_values(array_filter($showing, static fn (string $shown): bool => $shown !== $section))
            : array_values(array_filter($offered, static fn (string $each): bool => $each === $section || \in_array($each, $showing, true)));

        if ($next === []) {
            return;
        }

        $this->show = $next === $offered ? null : DashboardSections::toQuery($next);
    }

    /**
     * Whether the signed-in developer holds the admin ability.
     *
     * Resolved through `CurrentDeveloper` rather than with `@can` or a bare `Gate::allows()`, both
     * of which resolve the HOST'S DEFAULT guard rather than `robot-council.auth.guard`.
     *
     * @return bool True when they are an admin on the package's own guard.
     */
    private function isAdmin(): bool
    {
        return app(CurrentDeveloper::class)->isAdmin();
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

        $isAdmin = $developer->isAdmin();

        return view($template, [
            // Both filtered by the same call the toggle uses, so what is offered and what is
            // mounted cannot drift apart.
            //
            // The admin decision is made here rather than with `@can` in the view. `@can` asks the
            // framework gate to resolve the principal, and it resolves the HOST'S DEFAULT guard --
            // so on a host that defaults to another one the administration panel would be hidden
            // from a real admin. This is what to *offer*; `Administration` authorizes its own
            // mount, render and every action regardless.
            'offered' => DashboardSections::offered($isAdmin),
            'showing' => DashboardSections::from($this->show, $isAdmin),
        ]);
    }
}
