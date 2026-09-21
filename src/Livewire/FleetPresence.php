<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RobotCouncil\Support\FleetPresence as Presence;
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
    public int $pollSeconds = Dashboard::DEFAULT_POLL_SECONDS;

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
     * Take the polling interval from the page that mounts this component.
     *
     * @param  int  $pollSeconds  The interval the dashboard resolved.
     */
    public function mount(int $pollSeconds = Dashboard::DEFAULT_POLL_SECONDS): void
    {
        $this->pollSeconds = $pollSeconds;
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

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.fleet-presence';

        return view($template, [
            'sessions' => $sessions,
            'locks' => [...$locks, 'locks' => array_map($this->withLapse(...), $locks['locks'])],
            'sessionScope' => Scope::orDefault($this->sessionScope, Scope::All),
            'lockScope' => Scope::orDefault($this->lockScope, Scope::Live),
            'scopes' => Scope::cases(),
        ]);
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
