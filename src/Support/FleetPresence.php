<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Lock;

/**
 * Who is alive in the fleet, and what they are holding.
 *
 * Two reads that answer the same question from opposite ends: an agent session is a process that
 * might be stuck, and a lock is the thing a stuck process is blocking.
 *
 * **Both are for a signed-in developer and neither takes a session**, which is the shape the
 * decision on robot-council/core#73 fixed for an unfiltered read -- a named method rather than a
 * flag, so that no agent-facing call can reach one by passing a boolean. There is no agent-facing
 * equivalent to drift from: the machine API has never listed other sessions or other holders.
 *
 * The caller is responsible for having established that the reader is an allowlisted developer.
 * Nothing here checks it, because nothing here can.
 */
final class FleetPresence
{
    /**
     * The most sessions or locks one read returns.
     */
    public const int MAX_PAGE = 200;

    /**
     * @param  AgentLogins  $logins  Resolves the GitHub account behind a session.
     */
    public function __construct(private readonly AgentLogins $logins) {}

    /**
     * One page of agent sessions, newest first.
     *
     * **Ordered by `id`, not by `last_seen_at`, and that is the whole reason this method has a
     * cursor at all.** Contact time is rewritten on every request a session makes, so a keyset
     * built on it walks an ordering that moves underneath the reader: a session on page two that
     * heartbeats moves ahead of the cursor, and the next page skips it. A reader would see a
     * shorter fleet than exists and nothing would say so -- the exact failure this issue is about,
     * reintroduced by the fix. `id` never moves, and "newest session first" is a coherent presence
     * order with the contact age on each row.
     *
     * **`gone` sessions are listed by default**, because #24 keeps the row and #75 decided it
     * should be shown: a task sitting held is explained by the process that ended, and a reader
     * hunting that would not find it behind a filter. `Scope::Live` narrows to the ones still
     * running. This is the opposite default from `locks()` below, and the reason is in `Scope`.
     *
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @param  Scope  $scope  Live sessions, or every one the table holds.
     * @param  int|null  $after  The id of the last session the reader has seen.
     * @return array{sessions: list<array<string, mixed>>, cursor: int|null, more: bool, live: int, gone: int}
     */
    public function sessions(int $limit, Scope $scope = Scope::All, ?int $after = null): array
    {
        // Eager-loaded rather than read per row. `Model::preventLazyLoading()` raises on a query
        // that hydrated more than one row, so a host running strict mode would take a
        // `LazyLoadingViolationException` off the first page with two sessions on it -- and the
        // harness and machine label live on the installation rather than on the session.
        $size = max(1, min($limit, self::MAX_PAGE));

        // One more than the page, so whether a next page exists is known rather than guessed.
        // Deciding it from `count() === $size` is a page behind: on a set that is an exact multiple
        // of the page size it offers a next page that turns out to be empty.
        //
        // Fetching one MORE than one extra changes nothing anyone can observe -- `$more` and the
        // page are both taken from `$size` -- so that direction has no input that can kill it. The
        // other direction, fetching no extra, is what the exact-multiple test pins.
        // @pest-mutate-ignore: IncrementInteger
        $sessions = AgentSession::query()
            ->with('installation')
            ->when($scope === Scope::Live, fn (Builder $query) => $query->where('status', '!=', AgentSessionStatus::Gone->value))
            ->when($after !== null, fn (Builder $query) => $query->where('id', '<', $after))
            ->orderByDesc('id')
            ->limit($size + 1)
            ->get();

        $more = $sessions->count() > $size;

        $sessions = $sessions->take($size);

        $logins = $this->logins->forSessions($sessions->pluck('id')->all());

        // The presence clock, not the application's: this is compared against
        // `last_seen_at`, which is written on the same clock (#51).
        $now = PresenceClock::now();

        $live = AgentSession::query()->where('status', '!=', AgentSessionStatus::Gone->value)->count();

        return [
            'cursor' => $sessions->last()?->id,
            'more' => $more,

            // Counted rather than inferred from the page. A truncated list and a complete one look
            // identical, and the number that would tell them apart is the one not printed.
            'live' => $live,
            'gone' => AgentSession::query()->count() - $live,
            'sessions' => array_values($sessions->map(fn (AgentSession $session): array => [
                'id' => $session->id,
                'github_login' => $logins[$session->id] ?? null,
                // Not nullsafe: `installation_id` is a non-nullable foreign key and the relation is
                // eager-loaded above, so a null here would mean a row the schema forbids
                'harness' => $session->installation->harness,
                'machine_label' => $session->installation->machine_label,

                // Null for a session started without one, and rendered as such rather than filled in.
                // It reaches the page as an agent-supplied string, which is why `ProjectId` bounds its
                // charset at the edge and why the view escapes it like every other one.
                'project_id' => $session->project_id,

                // Read from the row rather than recomputed from `last_seen_at`. #24 made the row the
                // decision, and a view that derived the status itself would disagree with the sweep
                // for as long as the sweep had not run -- showing `stale` to a developer while every
                // conditional update in the package still treated the session as active.
                'status' => $session->status->value,
                // Cast, because Carbon 3's `diffInSeconds()` returns a float and `last_seen_at` is a
                // `dateTime` column with no microseconds while `now()` has them -- so the difference is
                // fractional on every read and rendered straight it reads `30.482913s ago`
                'seconds_since_contact' => (int) max(0, $now->diffInSeconds($session->last_seen_at, false) * -1),
            ])->all()),
        ];
    }

    /**
     * Every named lock, by name.
     *
     * A lapsed lease is returned rather than hidden, with `held` saying which it is. A row whose
     * lease has run out but which still names a holder is precisely the state a developer is
     * looking for, and hiding it would make the page agree with nothing.
     *
     * **A released lock keeps its row forever, by design**, for the fence it carries -- so this
     * table fills with names nobody holds, in alphabetical order, and a live or lapsed lock whose
     * name sorts past the page was invisible with nothing saying so. `Scope::Live` is the rows that
     * still name a holder, which is held and lapsed together: a lease that has run out while the
     * row still names somebody is precisely what a developer is hunting, so it must not be filtered
     * out with the free ones.
     *
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @param  Scope  $scope  Locks that still name a holder, or every row the table holds.
     * @param  string|null  $after  The name of the last lock the reader has seen.
     * @return array{locks: list<array<string, mixed>>, cursor: string|null, more: bool, held: int, free: int}
     */
    public function locks(int $limit, Scope $scope = Scope::Live, ?string $after = null): array
    {
        $size = max(1, min($limit, self::MAX_PAGE));

        // Keyed on `name`, which is the one column here that is unique, immutable and already the
        // order. The migration gives it `utf8mb4_bin` on MySQL precisely so that it compares as
        // bytes the way Postgres and SQLite already do, which is what makes `name > ?` mean the
        // same thing on every engine rather than depending on a collation.
        // One extra for the same reason as `sessions()` above, annotated for the same reason.
        // @pest-mutate-ignore: IncrementInteger
        $locks = Lock::query()
            ->when($scope === Scope::Live, fn (Builder $query) => $query->whereNotNull('holder_id'))
            ->when($after !== null, fn (Builder $query) => $query->where('name', '>', $after))
            ->orderBy('name')
            ->limit($size + 1)
            ->get();

        $more = $locks->count() > $size;

        $locks = $locks->take($size);

        $logins = $this->logins->forSessions([
            ...$locks->pluck('holder_id')->all(),
            ...$locks->pluck('previous_holder_id')->all(),
        ]);

        $now = Carbon::now();

        $held = Lock::query()->whereNotNull('holder_id')->count();

        return [
            'cursor' => $locks->last()?->name,
            'more' => $more,
            'held' => $held,
            'free' => Lock::query()->count() - $held,
            'locks' => array_values($locks->map(fn (Lock $lock): array => [
                // The row's own key. A `wire:key` built from `fence` and a loop index is neither stable
                // nor unique: two locks routinely share a fence, so one row's key can be taken over by
                // another between polls, and a re-acquire changes the key of a row that did not move.
                'id' => $lock->id,
                'name' => $lock->name,
                'fence' => $lock->fence,
                'held' => $lock->isHeldAt($now),
                'holder' => $lock->holder_id === null ? null : [
                    'session_id' => $lock->holder_id,
                    'github_login' => $logins[$lock->holder_id] ?? null,
                ],

                // Who had it before, which is what tells a reader whether a lock is being handed round
                // or has simply been sitting with one holder
                'previous_holder' => $lock->previous_holder_id === null ? null : [
                    'session_id' => $lock->previous_holder_id,
                    'github_login' => $logins[$lock->previous_holder_id] ?? null,
                ],
                'expires_at' => $lock->expires_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
