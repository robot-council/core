<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RobotCouncil\Support\FleetFeed;
use RobotCouncil\Support\PollInterval;

/**
 * The fleet's change feed, newest first.
 *
 * It reads through `Support\FleetFeed::latest()` rather than querying `robot_council_events`, so
 * the visibility rule stays in the one place #29 put it and cannot be reimplemented differently
 * here. `latest()` rather than `after()` because a signed-in developer sees every event unredacted
 * -- the decision on #73 -- and the method is named rather than flagged so that no agent-facing
 * call can reach it by passing a boolean.
 *
 * **Every body on this page was written by another developer's agent.** That is what #67 and #70
 * exist for, and why nothing here renders anything unescaped or puts a value in a URL.
 *
 * **This component is the caller `FleetFeed::latest()` holds responsible for authorization, and it
 * checks nothing itself.** Its only gate is the route: `EnsureAllowlistedDeveloper` on the dashboard,
 * and the same middleware registered as persistent so it survives onto `/livewire/update`. That is
 * sufficient for the page this package ships and is not sufficient in general -- the component is
 * registered globally, so a host that mounts it on a page of its own owns that page's gate, and
 * persistent middleware replays what the mounting request ran rather than adding anything. Recorded
 * rather than guarded here because a component cannot know what a host intended by rendering it.
 */
#[Layout('robot-council::layouts.dashboard')]
final class ChangeFeed extends Component
{
    /**
     * How many events the panel shows.
     */
    public const int PER_PAGE = 40;

    /**
     * The interval this panel refreshes on, in seconds.
     */
    #[Locked]
    public int $pollSeconds = PollInterval::DEFAULT;

    /**
     * The oldest event already shown, or null at the head of the feed.
     *
     * Locked: it is a position in an ordering the server computed. `showOlder()` below is how the
     * rendered button moves it, which is an action rather than a property write.
     */
    #[Locked]
    public ?int $before = null;

    /**
     * Take the polling interval, from a parent when there is one and from the host otherwise.
     *
     * A route mounts this component now, so `$pollSeconds` is null on every visit through the
     * dashboard; it stays a parameter because a host may embed the component in a page of its own.
     * `PollInterval::orConfig()` bounds both paths.
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
     * Show the events older than the last one on this page.
     *
     * @param  int  $before  The oldest event currently shown.
     */
    public function showOlder(int $before): void
    {
        $this->before = $before;
    }

    /**
     * Return to the head of the feed.
     */
    public function showLatest(): void
    {
        $this->before = null;
    }

    /**
     * Render the feed.
     *
     * @param  FleetFeed  $feed  The change feed.
     * @return View The panel.
     */
    public function render(FleetFeed $feed): View
    {
        // One more than the page, so whether an older page exists is known rather than guessed
        $events = $feed->latest(self::PER_PAGE + 1, $this->before);

        $hasOlder = \count($events) > self::PER_PAGE;

        $events = \array_slice($events, 0, self::PER_PAGE);

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.change-feed';

        return view($template, [
            // `meta` is dropped rather than passed through. The view renders none of it, and it is
            // up to 4096 bytes of agent-supplied structured data per row -- carrying it into a
            // render context leaves it one `{{ }}` away from the page with nobody having re-derived
            // whether it may be shown.
            'events' => array_map($this->forDisplay(...), $events),
            'oldest' => $this->oldestId($events),
            'hasOlder' => $hasOlder,
        ]);
    }

    /**
     * The id of the oldest event on this page, which the cursor reads from.
     *
     * @param  list<array<string, mixed>>  $events  The events being shown.
     * @return int|null The id, or null when the page is empty.
     */
    private function oldestId(array $events): ?int
    {
        $last = end($events);

        if (! \is_array($last)) {
            return null;
        }

        return \is_int($last['id'] ?? null) ? $last['id'] : null;
    }

    /**
     * One event as the page shows it: no `meta`, and an age rather than a timestamp.
     *
     * @param  array<string, mixed>  $event  The event as the store described it.
     * @return array<string, mixed> The event, projected for display.
     */
    private function forDisplay(array $event): array
    {
        /** @var array<string, mixed> $kept */
        $kept = Arr::except($event, ['meta']);

        return $this->withAge($kept);
    }

    /**
     * One event, with how long ago it happened rather than when.
     *
     * @param  array<string, mixed>  $event  The event as the store described it.
     * @return array<string, mixed> The event, with an `age`.
     */
    private function withAge(array $event): array
    {
        $at = $event['created_at'] ?? null;

        $event['age'] = \is_string($at) ? Carbon::parse($at)->diffForHumans() : null;

        return $event;
    }
}
