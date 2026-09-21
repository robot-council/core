<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use RobotCouncil\Models\AgentSession;
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
     * Every agent session, most recently seen first.
     *
     * `gone` sessions are included rather than filtered. A session that has ended is exactly what a
     * developer is looking for when a task sits held and nothing is moving, and #24 keeps the row
     * rather than deleting it for that reason.
     *
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @return list<array<string, mixed>> The sessions.
     */
    public function sessions(int $limit): array
    {
        // Eager-loaded rather than read per row. `Model::preventLazyLoading()` raises on a query
        // that hydrated more than one row, so a host running strict mode would take a
        // `LazyLoadingViolationException` off the first page with two sessions on it -- and the
        // harness and machine label live on the installation rather than on the session.
        $sessions = AgentSession::query()
            ->with('installation')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, self::MAX_PAGE)))
            ->get();

        $logins = $this->logins->forSessions($sessions->pluck('id')->all());

        $now = Carbon::now();

        return array_values($sessions->map(fn (AgentSession $session): array => [
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
        ])->all());
    }

    /**
     * Every named lock, by name.
     *
     * A lapsed lease is returned rather than hidden, with `held` saying which it is. A row whose
     * lease has run out but which still names a holder is precisely the state a developer is
     * looking for, and hiding it would make the page agree with nothing.
     *
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @return list<array<string, mixed>> The locks.
     */
    public function locks(int $limit): array
    {
        $locks = Lock::query()
            ->orderBy('name')
            ->limit(max(1, min($limit, self::MAX_PAGE)))
            ->get();

        $logins = $this->logins->forSessions([
            ...$locks->pluck('holder_id')->all(),
            ...$locks->pluck('previous_holder_id')->all(),
        ]);

        $now = Carbon::now();

        return array_values($locks->map(fn (Lock $lock): array => [
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
        ])->all());
    }
}
