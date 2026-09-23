<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Models\Installation;

/**
 * What this fleet can do, rather than what one session may do.
 *
 * **The two questions look alike and have different answers.** A bridge sitting on its sink is
 * waiting for somebody else's directive: posting one needs `coordinator:direct`, enrollment can
 * never request it, and a session holding none of it is the normal case. So a client that warned
 * on its own abilities would warn on almost every session, which is how a warning stops being
 * read. What decides whether anything can ever arrive is whether *somebody* on the fleet can post
 * one (#159).
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
     * call up to its per-session rate limit -- so the default would hydrate a thousand models and
     * decode a thousand JSON columns to answer one boolean, on a fleet that has nothing like that
     * many live installations.
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
     * Whether any installation on this fleet could exercise one ability.
     *
     * **Three gates, not two, and the third is the one that is easy to miss.**
     * `Http\Middleware\EnsureInstallation` refuses a credential whose installation is revoked or
     * expired *and* refuses one whose developer is no longer on the access list --
     * `Http\Middleware\EnsureAgentSession` repeats the access-list check for the session token
     * that would actually post. An answer built from the first two alone reports a fleet able to
     * deliver after an admin has off-boarded the only coordinator, which is the reassuring
     * direction and the exact false answer this whole question exists to remove. Nothing revokes
     * an installation when its developer leaves the list; the list is checked per request.
     *
     * **Read through `Installation::abilities()` rather than queried against the JSON column.**
     * That accessor drops anything the fixed list no longer holds, so a retired name left in a
     * stored row cannot answer true; and `whereJsonContains` compiles differently on each of the
     * three engines this package supports.
     *
     * **Two queries whatever the fleet's size.** The walk collects the host keys of the
     * installations holding the ability -- usually none or one -- and the identities behind them
     * are resolved in one further read, rather than one per installation.
     *
     * @param  Ability  $ability  The ability to look for.
     * @return bool True when at least one installation could exercise it today.
     */
    public function anyInstallationHolds(Ability $ability): bool
    {
        $holders = [];

        foreach (Installation::usable()->orderBy('id')->lazyById(self::CHUNK) as $installation) {
            if (\in_array($ability->value, $installation->abilities(), true)) {
                $holders[$installation->user_id] = true;
            }
        }

        if ($holders === []) {
            return false;
        }

        // False here means every holder is off the access list, so the ability is stored and
        // unusable -- which reads the same to a caller as nobody holding it, and is the same
        // thing operationally.
        return array_any(
            $this->hostUsers->githubIdsForKeys(array_keys($holders)),
            fn (int $githubId): bool => $this->allowlist->admits($githubId)
        );
    }
}
