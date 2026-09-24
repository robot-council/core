<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Collection;
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
     * How many sessions one read takes.
     *
     * Small on purpose. This runs on a route an agent may call up to its per-session rate limit, so
     * a page sized by habit would hydrate a thousand models to answer one boolean, on a fleet that
     * has nothing like that many live sessions. A thousand is what `lazyById()` defaults to;
     * `chunkById()` has no default at all and takes the count as a required argument, so the number
     * is stated here or nowhere.
     *
     * **It is also how much of a page an admitted holder saves**, now that the walk stops on the
     * page it finds one: a smaller chunk returns sooner and pages more often, which is a trade
     * between the two costs the method's own table gives rather than a free improvement either way.
     *
     * No test can pin the number, only that paging works across it: 49 and 51 read the same rows in
     * a different number of round trips, and a test asserting the query count against a fixture of
     * some fixed size would be asserting this constant against itself.
     * `it('pages past the chunk size')` covers the boundary instead.
     *
     * **The four query-count tests are not that test, and the reason is one line of each fixture.**
     * Every one of them seeds `CHUNK + 1` matching sessions rather than 51, so the fixture moves
     * with the constant and the counts they assert -- 2, 4, 4 and 1 -- hold at 49 and 51 alike.
     * What they pin is the number of round trips per page, which is a behavior; the page size is
     * still not one.
     */
    // The value is a round-trip size and nothing reads it back, so 49 and 51 are the same program.
    // The boundary itself is covered; the number is not a behavior.
    // @pest-mutate-ignore: IncrementInteger, DecrementInteger
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
     * **Three gates, and they are NARROWER than `Http\Middleware\EnsureAgentSession`'s, not the
     * same three.** An earlier draft of this sentence claimed exact parity, which is the sentence a
     * future author would restore parity against. What it takes is:
     *
     * 1. the session's status is `active`;
     * 2. its installation is still usable -- neither revoked nor expired;
     * 3. its developer is still on the access list.
     *
     * The middleware's status gate is `! $session->hasGone()`, which admits `stale` as well, and it
     * additionally checks the principal's kind through `Access\Tokens` -- a question about the
     * caller that has no set-level form. Both differences point the same way: this answers `false`
     * where the middleware would let the request through, never the reverse. **A new middleware gate
     * still has to be mirrored here**; one relaxed there does not.
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
     * **The walk decides as it goes, so one admitted holder ends the read** (#246). Each page
     * resolves the identities behind its own holders and stops there when one of them is still on
     * the access list, rather than collecting every match and asking once at the end. Over `M`
     * matching sessions, `M > 0`, reading `P = floor(M / CHUNK) + 1` pages -- the `M = 0` case is
     * the last row of the measured table below, where the old shape's `$holders === []` guard
     * skipped the identity read and both cost `P`:
     *
     * | | before | now |
     * | --- | --- | --- |
     * | nothing admitted | `P + 1` | `P + ceil(M / CHUNK)` |
     * | something admitted, first on page `k` | `P + 1` | `2k` |
     *
     * Measured with `tests/Pest.php`'s `queriesIssuedBy()` on SQLite against `CHUNK + 1` matching
     * sessions, where `P` is 2. `tests/FleetCanDirectTest.php` asserts every figure in the right
     * column:
     *
     * | | before | now |
     * | --- | --- | --- |
     * | the admitted holder is on the first page | 3 | **2** |
     * | the admitted holder is on the last page | 3 | 4 |
     * | no holder is admitted | 3 | 4 |
     * | no session matches the role at all | 1 | 1 |
     *
     * **The figure this replaced was reasoned rather than measured, and it was wrong.** The
     * superseded sentence gave `ceil(matches / CHUNK) + 2`, which is 4 for the fixture above where
     * the walk actually issued 3. That is the whole reason the numbers here are asserted by tests:
     * a count in prose has nothing checking it, and the direction it drifted -- overstating what
     * the old shape cost -- is the direction that would have made this change look better than it
     * is.
     *
     * **So the `true` answer got cheaper and the exhaustive `false` answer got one read per page
     * dearer**, which is the trade this change weighed rather than a cost it overlooked. The `false`
     * walk is an index range over rows that genuinely match (`robot-council/core#223`), so its extra
     * reads are bounded by the matches rather than by the fleet; the `true` walk is the one whose
     * length a holder of the ability can arrange, and it no longer grows with how many other
     * ADMITTED sessions hold the same role. That is the reasoning `Support\FleetFeed::EXAMINE_CAP`
     * records.
     *
     * **What it does not fix, said plainly**: a crowd of matching rows whose developers have all
     * been off-boarded still sits ahead of the admitted one and pushes it onto a later page, so
     * `k` is not bounded. Reaching that state takes an administrator off-boarding developers whose
     * coordinator sessions are still `active`, which is not something the holder of an ability can
     * arrange on its own -- a session cannot be started for a developer who is not admitted. It is
     * residue rather than a hole, and it is why the `false` row above is the one to watch.
     *
     * **It is a live reading, and a host that turned the presence sweep off has no live readings.**
     * `config/robot-council.php` documents disabling `schedule.sweep_sessions` as supported, and on
     * such a host no session is ever marked `stale` or `gone` -- so a coordinator that died months
     * ago still reads `active` and this answers `true` forever. That is a property of the sweep
     * rather than of this question, and `robot-council:doctor` is where an operator would see it.
     *
     * @param  Ability  $ability  The ability to look for.
     * @return bool True when at least one live session could exercise it right now.
     */
    public function anyLiveSessionHolds(Ability $ability): bool
    {
        // `->value` rather than the cases themselves. `Query\Builder::cleanBindings()` maps every
        // binding through `enum_value()`, so passing `Role` instances would bind identically today
        // -- which makes both unwrap mutants here equivalent, and is exactly why the explicit form
        // is preferred: it does not depend on that staying true.
        // @pest-mutate-ignore: UnwrapArrayMap, UnwrapArrayValues
        $roles = array_values(array_map(
            static fn (Role $role): string => $role->value,
            array_filter(Role::cases(), static fn (Role $role): bool => $role->holds($ability))
        ));

        // No role carries it, so no session can.
        //
        // **This saves a round trip; it does not prevent a failure, and an earlier comment here
        // claimed it did.** `Query\Grammars\Grammar::whereIn()` returns the literal `0 = 1` for an
        // empty list on every grammar, so removing this guard answers `false` all the same -- which
        // is why the mutant on it survives and cannot be killed.
        // @pest-mutate-ignore: RemoveEarlyReturn
        if ($roles === []) {
            return false;
        }

        $admitted = false;

        /**
         * Decide one page, and say whether the walk should go on.
         *
         * **Returning `false` is what stops it, and it stops it BEFORE the next page is read.**
         * `Concerns\BuildsQueries::orderedChunkById()` returns as soon as the callback answers
         * `false`, so what is saved is a round trip rather than a hydration.
         *
         * @param  Collection<int, AgentSession>  $sessions  One page of matching sessions.
         * @return bool Whether to read the next page.
         */
        $examine = function (Collection $sessions) use (&$admitted): bool {
            $admitted = $this->anyAdmittedHolder($sessions);

            return ! $admitted;
        };

        AgentSession::query()
            // Two columns rather than the row, because this runs on a route an agent may call up
            // to its per-session rate limit.
            //
            // **It is not what stops the `role` cast raising, and an earlier comment here said it
            // was.** Eloquent hydrates through `setRawAttributes()` and casts in `getAttribute()`,
            // so a cast runs only when the attribute is READ -- and nothing here reads anything
            // but `user_id`. A row holding a role outside the enum is therefore harmless with or
            // without this narrowing, which `it('reads the roles the enum defines')` covers either
            // way.
            //
            // **`id` is load-bearing, and moving to `chunkById()` made its absence LOUDER rather
            // than quieter.** Both walkers read the key off the last hydrated row to page, and both
            // throw when it is missing -- `The chunkById operation was aborted because the [id]
            // column is not present`. But `lazyById()` only reaches that throw once a page comes
            // back FULL, so under it a fleet with fewer than `CHUNK` matching sessions never saw
            // the defect; `orderedChunkById()` checks after every page whose callback did not stop
            // the walk, short pages included. Measured by dropping `id` from this list and running
            // `tests/FleetCanDirectTest.php` as it stands: the old walker failed 4 and this one
            // fails 5, and the two it adds each hold a SINGLE matching session -- a fleet size at
            // which the old walker could not have caught it however long the defect sat there.
            //
            // The one shape that still cannot see it is a walk that stops on its first page, which
            // is exactly the `true`-on-page-one answer this method was changed to give. So the
            // tests that reach the throw are the ones that keep walking: `it('pages past the chunk
            // size')` and the two costing the `false` and last-page answers.
            ->select(['id', 'user_id'])
            ->whereIn('role', $roles)
            ->where('status', AgentSessionStatus::Active->value)

            // A subquery rather than a join or a second walk: `Installation::usable()` is the
            // set-level half of `isUsable()` and the two are kept agreeing by a test, so asking it
            // here cannot drift from what the middleware asks per request.
            ->whereIn('installation_id', Installation::usable()->select('id'))
            ->orderBy('id')

            // The return value is deliberately unread. It reports whether the walk ran to the end,
            // which is `! $admitted` said a worse way: it would make this method's answer rest on
            // a contract the framework documents for the CALLBACK rather than for the walker.
            ->chunkById(self::CHUNK, $examine);

        return $admitted;
    }

    /**
     * Whether one page of sessions holds a developer the access list still admits.
     *
     * **One read per page, not one per session.** The host keys are deduplicated first, so a page
     * of fifty sessions belonging to one developer resolves one identity -- which is the shape a
     * fleet running many agents under one account actually has.
     *
     * False here means every holder on this page is off the access list, so the role is held and
     * unusable -- which reads the same to a caller as nobody holding it, and is the same thing
     * operationally.
     *
     * @param  Collection<int, AgentSession>  $sessions  One page of matching sessions.
     * @return bool True when at least one of them belongs to an admitted developer.
     */
    private function anyAdmittedHolder(Collection $sessions): bool
    {
        $holders = [];

        foreach ($sessions as $session) {
            // **Keyed by the host key to deduplicate, and holding it as the value to read back.**
            // A PHP array key coerces a canonical numeric string to an int, so `array_keys()` would
            // hand `42` where the row said `"42"`. `Support\HostKey::tryFrom()` casts an int back
            // and the round trip is lossless today, but nothing states that this depends on it.
            $holders[$session->user_id] = $session->user_id;
        }

        // **`array_values()` is load-bearing for the analyzer, not for the answer.**
        // `HostUsers::githubIdsForKeys()` reads values and ignores keys, so dropping it changes no
        // answer -- but its parameter is `list<mixed>` and `$holders` is keyed, so `composer
        // analyse` refuses it. Measured: `expects list<mixed>`, one error. The mutant therefore
        // cannot be killed without failing the other gate.
        // @pest-mutate-ignore: UnwrapArrayValues
        return array_any(
            $this->hostUsers->githubIdsForKeys(array_values($holders)),
            fn (int $githubId): bool => $this->allowlist->admits($githubId)
        );
    }
}
