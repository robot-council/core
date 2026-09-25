<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RobotCouncil\Support\FleetPresence as Presence;
use RobotCouncil\Support\PollInterval;
use RobotCouncil\Support\Scope;

/**
 * Who is alive in the fleet, and what they are holding.
 *
 * Sessions and locks on one panel, because they answer the same question from opposite ends: a
 * session is a process that might be stuck, and a lock is what a stuck process is blocking.
 *
 * Both the harness and the machine label are charset-limited at the edge, so neither can carry a
 * `<` -- but that is a second line rather than a first, for the reason #70 records about URLs. What
 * makes this page safe is that nothing here is rendered unescaped.
 */
#[Layout('robot-council::layouts.dashboard')]
#[Title('Presence')]
final class FleetPresence extends Component
{
    /**
     * How many sessions the panel lists.
     */
    public const int SESSIONS = 50;

    /**
     * How many locks the panel lists.
     */
    public const int LOCKS = 50;

    /**
     * The interval this panel refreshes on, in seconds.
     */
    #[Locked]
    public int $pollSeconds = PollInterval::DEFAULT;

    /**
     * Which sessions are shown: `live`, or every row including the ones that have gone.
     *
     * Not `#[Locked]`: it is the reader's own filter and they set it. A value this does not
     * recognize falls back to `live` rather than throwing, because it arrives from a link.
     */
    #[Url(as: 'sessions', keep: false)]
    public ?string $sessionScope = null;

    /**
     * Which locks are shown: those that still name a holder, or every row.
     */
    #[Url(as: 'locks', keep: false)]
    public ?string $lockScope = null;

    /**
     * The id of the last session on the page before this one, or null at the head.
     *
     * Locked, like the task board's: it is a position in an ordering the server computed, and
     * `showNextSessions()` below is how the rendered button moves it. A client may still call that
     * action with any id it likes, which reaches nothing it could not already page to -- #73 gives
     * a signed-in developer the whole fleet.
     */
    #[Locked]
    public ?int $afterSession = null;

    /**
     * The name of the last lock on the page before this one, or null at the head.
     */
    #[Locked]
    public ?string $afterLock = null;

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
     * Show the sessions after the last one on this page.
     *
     * @param  int  $after  The id the last read handed back.
     */
    public function showNextSessions(int $after): void
    {
        $this->afterSession = max(0, $after);
    }

    /**
     * Go back to the newest sessions.
     */
    public function showFirstSessions(): void
    {
        $this->afterSession = null;
    }

    /**
     * Show the locks after the last one on this page.
     *
     * @param  string  $after  The name the last read handed back.
     */
    public function showNextLocks(string $after): void
    {
        $this->afterLock = $after;
    }

    /**
     * Go back to the first locks.
     */
    public function showFirstLocks(): void
    {
        $this->afterLock = null;
    }

    /**
     * Widen or narrow which sessions are listed, and start again from the head.
     *
     * The cursor is dropped, because a position in one filtered ordering means nothing in another.
     *
     * @param  string  $scope  The scope to read with.
     */
    public function showSessions(string $scope): void
    {
        $this->sessionScope = Scope::orDefault($scope, Scope::All)->value;

        $this->showFirstSessions();
    }

    /**
     * Widen or narrow which locks are listed, and start again from the head.
     *
     * @param  string  $scope  The scope to read with.
     */
    public function showLocks(string $scope): void
    {
        $this->lockScope = Scope::orDefault($scope, Scope::Live)->value;

        $this->showFirstLocks();
    }

    /**
     * Render the panel.
     *
     * @param  Presence  $presence  The presence store.
     * @return View The panel.
     */
    public function render(Presence $presence): View
    {
        $sessions = $presence->sessions(self::SESSIONS, Scope::orDefault($this->sessionScope, Scope::All), $this->afterSession);
        $locks = $presence->locks(self::LOCKS, Scope::orDefault($this->lockScope, Scope::Live), $this->afterLock);

        // Rewritten in place rather than spread-and-overridden. `[...$locks, 'locks' => ...]` is
        // correct PHP -- a later explicit key wins over a spread one -- but PHPStan 2.2.15 reads
        // the two as duplicate keys and refuses the file, where 2.2.14 did not. No `composer.lock`
        // is committed, so that patch arrived on CI without a commit here and turned `main` red on
        // a file nobody had touched. This form says the same thing and has no key to duplicate.
        $locks['locks'] = array_map($this->withLapse(...), $locks['locks']);

        // Rewritten in place for the same reason as the line above.
        $sessions['sessions'] = array_map($this->withLastSeen(...), $sessions['sessions']);

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.fleet-presence';

        return view($template, [
            'sessions' => $sessions,
            'locks' => $locks,
            'sessionScope' => Scope::orDefault($this->sessionScope, Scope::All),
            'lockScope' => Scope::orDefault($this->lockScope, Scope::Live),
            'scopes' => Scope::cases(),
        ]);
    }

    /**
     * One session, with how long ago it was last heard from in words.
     *
     * **Built from the SECONDS the store measured, never from a timestamp re-parsed here.**
     * `Support\FleetPresence` takes that difference on `Support\PresenceClock`, which is the clock
     * `last_seen_at` is written on (#51) -- so re-deriving it from `Carbon::now()` would put the
     * displayed age on the application's clock and disagree with every cutoff the sweep applies.
     * `CarbonInterval` takes the integer and involves no "now" at all, which is what makes that
     * impossible rather than merely avoided.
     *
     * The store keeps returning `seconds_since_contact` unchanged: it is the machine-readable value
     * and the API and MCP tools read it. This adds the words the panel shows beside it.
     *
     * `parts: 1` so the column reads like the queue's `Age` -- one unit that grows from seconds to
     * minutes to hours rather than a second count that runs to five figures after a weekend.
     *
     * @param  array<string, mixed>  $session  The session as the store described it.
     * @return array<string, mixed> The session, with a `last_seen` in words.
     */
    private function withLastSeen(array $session): array
    {
        $seconds = $session['seconds_since_contact'] ?? null;

        // The store types this as an int and nothing else writes it, so a non-int means the array
        // did not come from there. Saying so beats rendering "0 seconds ago" for an unknown age.
        $session['last_seen'] = \is_int($seconds)
            ? CarbonInterval::seconds($seconds)->cascade()->forHumans(parts: 1).' ago'
            : 'at an unrecorded time';

        return $session;
    }

    /**
     * One lock, with how its lease stands rather than only when it ends.
     *
     * A lease that has run out while the row still names a holder is the state a developer is
     * looking for, so it is said in words rather than left to be worked out from a timestamp.
     *
     * @param  array<string, mixed>  $lock  The lock as the store described it.
     * @return array<string, mixed> The lock, with a `lease` and whether it has `lapsed`.
     */
    private function withLapse(array $lock): array
    {
        $expires = $lock['expires_at'] ?? null;

        // Every release path in `Support\Locks` nulls `holder_id` **and** `expires_at`, so a
        // cleanly released lock arrives here with no expiry at all. Reading that as "never held"
        // was wrong on both counts: the row is kept precisely because it *was* held, for the fence
        // it carries, and a released lock is the ordinary case rather than the alarming one.
        if (! \is_string($expires)) {
            $lock['lease'] = 'free';
            $lock['lapsed'] = false;

            return $lock;
        }

        // Absolute, because `diffForHumans()` on its own renders "1 minute from now", which reads
        // badly beside a verb and made an assertion for "expires in" unable to ever match
        $distance = Carbon::parse($expires)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE);

        $lock['lease'] = $lock['held'] === true ? 'expires in '.$distance : 'lapsed '.$distance.' ago';
        $lock['lapsed'] = $lock['held'] !== true;

        return $lock;
    }
}
