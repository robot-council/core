<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Carbon\CarbonInterval;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RobotCouncil\Support\FleetPresence as Presence;
use RobotCouncil\Support\PollInterval;
use RobotCouncil\Support\Scope;

/**
 * Who is alive in the fleet.
 *
 * **One of two pages that used to be one panel**, and #308 is why they were split. #215 put
 * sessions and locks together because they answer the same question from opposite ends: a session
 * is a process that might be stuck, and a lock is what a stuck process is blocking. That reasoning
 * is about diagnosis and still holds -- but the two lists were independently paged and filtered, so
 * each had to be scrolled past to reach the other, and a deep link carried both scopes. The pairing
 * survives as a link rather than a shared scroll: each row here links to the locks its session
 * holds, and each lock on `Livewire\Locks` links back to the one session holding it, which lands
 * here narrowed by `$session`.
 *
 * Both the harness and the machine label are charset-limited at the edge, so neither can carry a
 * `<` -- but that is a second line rather than a first, for the reason #70 records about URLs. What
 * makes this page safe is that nothing here is rendered unescaped, and the only value that reaches a
 * URL is a session's own id, passed to `route()` with a literal name.
 */
#[Layout('robot-council::layouts.dashboard')]
#[Title('Agents')]
final class Agents extends Component
{
    /**
     * How many sessions the page lists.
     */
    public const int SESSIONS = 50;

    /**
     * The interval this page refreshes on, in seconds.
     */
    #[Locked]
    public int $pollSeconds = PollInterval::DEFAULT;

    /**
     * Which sessions are shown: `live`, or every row including the ones that have gone.
     *
     * Not `#[Locked]`: it is the reader's own filter and they set it. A value this does not
     * recognize falls back to `all` rather than throwing, because it arrives from a link. `scope`
     * rather than the `sessions` it was called while it shared a page with the locks' filter: only
     * one filter lives here now, so the unqualified name cannot collide with anything.
     */
    #[Url(as: 'scope', keep: false)]
    public ?string $scope = null;

    /**
     * The one session to list, or null for every session in scope.
     *
     * What a lock's holder links to. Typed as an int, so the only thing it can carry into the query
     * is a number: measured on Livewire v4.4.5, a word or a number past `PHP_INT_MAX` hydrates as
     * null and the page lists everything. The assignment coerces, so `true` or `1.5` narrows to
     * session #1, which the banner names -- no scope widens either way. An id no row carries,
     * zero and negatives included, lists nothing and says so, rather than listing everything and
     * leaving the reader to wonder which row they were sent to.
     */
    #[Url(as: 'session', keep: false)]
    public ?int $session = null;

    /**
     * The id of the last session on the page before this one, or null at the head.
     *
     * Locked, like the task board's: it is a position in an ordering the server computed, and
     * `showNext()` below is how the rendered button moves it. A client may still call that action
     * with any id it likes, which reaches nothing it could not already page to -- #73 gives a
     * signed-in developer the whole fleet.
     */
    #[Locked]
    public ?int $after = null;

    /**
     * Take the polling interval, from a parent when there is one and from the host otherwise.
     *
     * A route mounts this component, so `$pollSeconds` is null on every visit through the
     * dashboard; it stays a parameter because a host may embed the component in a page of its own.
     * `PollInterval::orConfig()` bounds both paths.
     *
     * @param  Repository  $config  The application's configuration repository.
     * @param  int|null  $pollSeconds  The interval a parent passed, or null to read the host's.
     */
    public function mount(Repository $config, ?int $pollSeconds = null): void
    {
        // Null when a route mounted this directly rather than a parent passing it down, which is
        // every visit now that each panel has a page of its own.
        $this->pollSeconds = PollInterval::orConfig($pollSeconds, $config);
    }

    /**
     * Show the sessions after the last one on this page.
     *
     * @param  int  $after  The id the last read handed back.
     */
    public function showNext(int $after): void
    {
        $this->after = max(0, $after);
    }

    /**
     * Go back to the newest sessions.
     */
    public function showFirst(): void
    {
        $this->after = null;
    }

    /**
     * Widen or narrow which sessions are listed, and start again from the head.
     *
     * The cursor is dropped, because a position in one filtered ordering means nothing in another.
     *
     * @param  string  $scope  The scope to read with.
     */
    public function show(string $scope): void
    {
        $this->scope = Scope::orDefault($scope, Scope::All)->value;

        $this->showFirst();
    }

    /**
     * Stop narrowing to one session, and list every session in scope from the head.
     */
    public function showEverySession(): void
    {
        $this->session = null;

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
        $scope = Scope::orDefault($this->scope, Scope::All);

        $sessions = $presence->sessions(self::SESSIONS, $scope, $this->after, $this->session);

        // Rewritten in place rather than spread-and-overridden. `[...$sessions, 'sessions' => ...]`
        // is correct PHP -- a later explicit key wins over a spread one -- but PHPStan 2.2.15 reads
        // the two as duplicate keys and refuses the file, where 2.2.14 did not. No `composer.lock`
        // is committed, so that patch arrived on CI without a commit here and turned `main` red on
        // a file nobody had touched. This form says the same thing and has no key to duplicate.
        $sessions['sessions'] = array_map($this->withLastSeen(...), $sessions['sessions']);

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.agents';

        return view($template, [
            'sessions' => $sessions,
            'sessionScope' => $scope,
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
     * and the API and MCP tools read it. This adds the words the page shows beside it.
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
}
