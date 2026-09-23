<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Installation;

/**
 * What this fleet can do right now, rather than what one session may do.
 *
 * **The two questions look alike and have different answers.** A bridge sitting on its sink is
 * waiting for somebody else's directive: posting one needs `coordinator:direct`, a session cannot
 * ask itself into the role that carries it, and holding none of it is the normal case. So a client
 * that warned on its own abilities would warn on almost every session, which is how a warning stops
 * being read. What decides whether anything can arrive is whether *somebody* on the fleet can post
 * one (#159).
 *
 * **It counts live SESSIONS, and that changed what the answer means** (`robot-council/core#223`).
 * It used to walk installations, which was the right proxy while an installation's stored abilities
 * decided what its sessions held. `robot-council/core#222` ended that: a role is `build` at start
 * and an administrator's decision after that, so `granted_abilities` answers nothing about any
 * session and an installation-level count would have reported a fleet able to deliver on the
 * strength of a column nothing reads.
 *
 * | | before | now |
 * | --- | --- | --- |
 * | the question | could anyone ever post a directive | **is a coordinator running right now** |
 * | a coordinator enrolled but not running | `true` | `false` |
 * | flaps across a coordinator restart | no | **yes** |
 *
 * The second column is the more truthful answer to what a waiting agent needs, and
 * `robot-council/cli#113` built the diagnostic precisely because an empty sink and a fleet with
 * nothing to say are indistinguishable from the agent's side. **The cost is that the value now
 * flaps**, and a client that states it once at startup can be permanently wrong about a fleet whose
 * coordinator restarted a second later. What the client does about that is
 * `robot-council/core#223`'s recorded decision, not this class's to enforce.
 *
 * **`stale` does not count, and that is a deliberate reading rather than an oversight.** A stale
 * session is one request away from active, so counting it would answer for a coordinator that may
 * be gone; not counting it answers for one that is merely quiet. The safe direction here is the
 * one that says `false`, because a bridge told `false` keeps working and waits, while one told
 * `true` about a coordinator that never comes back waits forever.
 *
 * The access-list gate stays. `Http\Middleware\EnsureAgentSession` re-checks it on every request,
 * so a coordinator whose developer has been off-boarded cannot deliver, and an answer built without
 * that check reports a fleet able to deliver when it is not -- the reassuring direction, which is
 * the one this question exists to remove.
 *
 * **It reads only.** It is deliberately not a method on `Support\Installations`, which holds the
 * credential lifetimes and the change feed: `Support\Doctor` consumes this, and its own docblock
 * says nothing there writes. A read-only question should not put the feed's writer in scope for
 * whoever edits that class next.
 */
final class FleetAbilities
{
    /**
     * How many installations one read takes.
     *
     * Small on purpose. `lazyById()` defaults to a thousand, and this runs on a route an agent may
     * call up to its per-session rate limit -- so the default would hydrate a thousand models to
     * answer one boolean, on a fleet that has nothing like that many live sessions.
     */
    public const int CHUNK = 50;

    /**
     * @param  Allowlist  $allowlist  Who may still sign in, read rather than re-parsed.
     * @param  HostUsers  $hostUsers  The host user records, for the identity mapping.
     */
    public function __construct(
        private readonly Allowlist $allowlist,
        private readonly HostUsers $hostUsers
    ) {}

    /**
     * Whether any live session on this fleet could exercise one ability right now.
     *
     * **Three gates, and they are exactly the three `Http\Middleware\EnsureAgentSession` takes**,
     * because the question is whether a session could post a directive if it tried and that
     * middleware is what decides:
     *
     * 1. the session's status is `active`;
     * 2. its installation is still usable -- neither revoked nor expired;
     * 3. its developer is still on the access list.
     *
     * **The second was missed on the first attempt and an existing test caught it.** Revoking an
     * installation deletes its sessions' tokens and leaves the rows `active` until the sweep runs,
     * so a count built on status alone reports a fleet able to deliver through a session whose next
     * request is a 401 -- the reassuring direction, which is the one this question exists to
     * remove. Nothing marks a session when its installation is revoked or its developer leaves the
     * list; both are checked per request, so both are checked here.
     *
     * **The roles are derived from the ability rather than named**, so a fourth role that carries
     * `coordinator:direct` is counted the day it exists rather than the day somebody remembers this
     * method. A role carrying no part of the question is never queried for.
     *
     * **Two queries whatever the fleet's size.** The walk collects the host keys of the sessions
     * holding the ability -- usually none or one -- and the identities behind them are resolved in
     * one further read, rather than one per session.
     *
     * @param  Ability  $ability  The ability to look for.
     * @return bool True when at least one live session could exercise it right now.
     */
    public function anyLiveSessionHolds(Ability $ability): bool
    {
        $roles = array_values(array_map(
            static fn (Role $role): string => $role->value,
            array_filter(Role::cases(), static fn (Role $role): bool => $role->holds($ability))
        ));

        // No role carries it, so no session can. Asked before the query rather than after, because
        // `whereIn` with an empty list is `0 = 1` on some grammars and a syntax error on others.
        if ($roles === []) {
            return false;
        }

        $holders = [];

        foreach (AgentSession::query()
            // Two columns rather than the row. This runs on a route an agent may call up to its
            // per-session rate limit, and it also keeps `role` out of the hydrated model: the cast
            // would raise on a value outside the enum, which a row can hold however it got there.
            ->select(['id', 'user_id'])
            ->whereIn('role', $roles)
            ->where('status', AgentSessionStatus::Active->value)

            // A subquery rather than a join or a second walk: `Installation::usable()` is the
            // set-level half of `isUsable()` and the two are kept agreeing by a test, so asking it
            // here cannot drift from what the middleware asks per request.
            ->whereIn('installation_id', Installation::usable()->select('id'))
            ->orderBy('id')
            ->lazyById(self::CHUNK) as $session) {
            $holders[$session->user_id] = true;
        }

        if ($holders === []) {
            return false;
        }

        // False here means every holder is off the access list, so the role is held and unusable --
        // which reads the same to a caller as nobody holding it, and is the same thing
        // operationally.
        return array_any(
            $this->hostUsers->githubIdsForKeys(array_keys($holders)),
            fn (int $githubId): bool => $this->allowlist->admits($githubId)
        );
    }
}
