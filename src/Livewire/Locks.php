<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Carbon\CarbonInterface;
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
 * What the fleet is holding.
 *
 * The other half of what was one presence panel until #308, which records why it was split and why
 * the two halves link to each other instead: a lock is what a stuck process is blocking, and the
 * holder on each row links to that one session on `Livewire\Agents`. A session row there links back
 * here, narrowed by `$holder` to the locks it holds.
 *
 * Lock names are charset-limited at the edge by `Support\Locks`, and that is a second line rather
 * than a first, for the reason #70 records about URLs. What makes this page safe is that nothing
 * here is rendered unescaped, and the only value that reaches a URL is a holder's session id,
 * passed to `route()` with a literal name.
 */
#[Layout('robot-council::layouts.dashboard')]
#[Title('Locks')]
final class Locks extends Component
{
    /**
     * How many locks the page lists.
     */
    public const int LOCKS = 50;

    /**
     * The interval this page refreshes on, in seconds.
     */
    #[Locked]
    public int $pollSeconds = PollInterval::DEFAULT;

    /**
     * Which locks are shown: those that still name a holder, or every row.
     *
     * `scope` rather than the `locks` it was called while it shared a page with the sessions'
     * filter, for the reason `Livewire\Agents::$scope` gives.
     */
    #[Url(as: 'scope', keep: false)]
    public ?string $scope = null;

    /**
     * The session whose locks to list, or null for every holder.
     *
     * What a session row on the Agents page links to. Typed as an int for the reason
     * `Livewire\Agents::$session` is.
     */
    #[Url(as: 'holder', keep: false)]
    public ?int $holder = null;

    /**
     * The name of the last lock on the page before this one, or null at the head.
     *
     * Locked for the reason `Livewire\Agents::$after` is.
     */
    #[Locked]
    public ?string $after = null;

    /**
     * Take the polling interval, from a parent when there is one and from the host otherwise.
     *
     * @param  Repository  $config  The application's configuration repository.
     * @param  int|null  $pollSeconds  The interval a parent passed, or null to read the host's.
     */
    public function mount(Repository $config, ?int $pollSeconds = null): void
    {
        // Null when a route mounted this directly, which is every visit through the dashboard
        $this->pollSeconds = PollInterval::orConfig($pollSeconds, $config);
    }

    /**
     * Show the locks after the last one on this page.
     *
     * @param  string  $after  The name the last read handed back.
     */
    public function showNext(string $after): void
    {
        $this->after = $after;
    }

    /**
     * Go back to the first locks.
     */
    public function showFirst(): void
    {
        $this->after = null;
    }

    /**
     * Widen or narrow which locks are listed, and start again from the head.
     *
     * @param  string  $scope  The scope to read with.
     */
    public function show(string $scope): void
    {
        $this->scope = Scope::orDefault($scope, Scope::Live)->value;

        $this->showFirst();
    }

    /**
     * Stop narrowing to one holder, and list every lock in scope from the head.
     */
    public function showEveryHolder(): void
    {
        $this->holder = null;

        $this->showFirst();
    }

    /**
     * Render the page.
     *
     * @param  Presence  $presence  The presence store.
     * @return View The page.
     */
    public function render(Presence $presence): View
    {
        $scope = Scope::orDefault($this->scope, Scope::Live);

        $locks = $presence->locks(self::LOCKS, $scope, $this->after, $this->holder);

        // Rewritten in place rather than spread-and-overridden, for the reason recorded on the same
        // line in `Livewire\Agents::render()`: PHPStan 2.2.15 reads the spread form as a duplicate key.
        $locks['locks'] = array_map($this->withLapse(...), $locks['locks']);

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.locks';

        return view($template, [
            'locks' => $locks,
            'lockScope' => $scope,
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
