<?php

declare(strict_types=1);

namespace RobotCouncil\Access;

/**
 * What one agent session is for, and therefore what it may do.
 *
 * **The role is the unit, not the ability.** Before this, every session an installation started
 * held that installation's `granted_abilities` verbatim, so a machine running seven checkouts from
 * one harness gave all seven identical authority and there was no way to give one of them something
 * the others lacked. The preset moves that decision onto the session, where the process it
 * describes actually is.
 *
 * **A role's preset is the whole answer.** `Support\AgentSessions` mints a token from it and from
 * nothing else, so an installation's stored abilities no longer widen or narrow what a session
 * holds. What they still decide is `permittedBy()` below -- whether this machine may run a session
 * in a given role at all -- which is eligibility rather than a ceiling.
 *
 * **`sessions:start` is in no preset, and that is not an omission.** It is the installation
 * credential's own ability, the one thing that credential can do; a session token carrying it could
 * start further sessions, which is the escalation the split between the two credentials exists to
 * prevent.
 */
enum Role: string
{
    /**
     * An agent doing the work: it creates and claims tasks, takes locks, and narrates.
     *
     * The floor every session falls back to, and the role a session takes when nothing has asked
     * for one.
     */
    case Build = 'build';

    /**
     * An automated run rather than a developer's agent.
     *
     * **Identical to `build` today, deliberately.** The distinction is introduced before anything
     * depends on it, so that the change which gives continuous integration a different ability is
     * a change to one arm of `abilities()` rather than a change that also has to introduce the
     * concept. `tests/SessionRoleTest.php` asserts the two are equal, which is what makes a
     * divergence a deliberate edit rather than a silent one.
     */
    case Ci = 'ci';

    /**
     * An agent that may also direct the fleet: release, reassign, or cancel any task, and post
     * directives.
     */
    case Coordinator = 'coordinator';

    /**
     * The abilities a session in this role holds.
     *
     * **`Ci` repeats `Build`'s list rather than sharing it, and `Coordinator` composes from it.**
     * The asymmetry is the point. Making the two identical by construction would leave the
     * acceptance criterion -- that a divergence between them is loud -- satisfied by an assertion
     * no edit could ever fail, which is a description of nothing. Written out, `RolePresetTest`
     * goes red the moment one arm moves. `Coordinator` has the opposite requirement: it is defined
     * as the build preset plus one ability, so composing it is what stops it falling behind when
     * the build preset gains something. `tests/SessionRoleTest.php` holds both assertions.
     *
     * @return list<Ability> The preset, in a fixed order.
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Build => [
                Ability::TasksCreate,
                Ability::TasksClaim,
                Ability::LocksAcquire,
                Ability::EventsPost,
            ],
            self::Ci => [
                Ability::TasksCreate,
                Ability::TasksClaim,
                Ability::LocksAcquire,
                Ability::EventsPost,
            ],
            self::Coordinator => [...self::Build->abilities(), Ability::CoordinatorDirect],
        };
    }

    /**
     * The preset as a token carries it.
     *
     * @return list<string> The ability names to mint a session token with.
     */
    public function tokenAbilities(): array
    {
        return Ability::values($this->abilities());
    }

    /**
     * Whether a session in this role holds one ability.
     *
     * @param  Ability  $ability  The ability to look for.
     * @return bool True when the preset carries it.
     */
    public function holds(Ability $ability): bool
    {
        return \in_array($ability, $this->abilities(), true);
    }

    /**
     * Whether an installation holding these abilities may run a session in this role.
     *
     * **Eligibility, not a ceiling.** An ability an enrollment can ask for is one the server is
     * willing to give any agent that enrolls, so it decides nothing about which role a machine may
     * run; the abilities that gate a role are the ones only an admin can hand out, which today is
     * `coordinator:direct` alone. Written as the general rule rather than as that one name, so a
     * fourth role carrying a second administered ability is covered without an edit here.
     *
     * The list is whatever `Models\Installation::abilities()` returned, which has already dropped
     * anything outside the fixed list.
     *
     * @param  list<string>  $installationAbilities  What the installation holds.
     * @return bool True when the machine may run this role.
     */
    public function permittedBy(array $installationAbilities): bool
    {
        $requestable = Ability::requestable();

        foreach ($this->abilities() as $ability) {
            if (\in_array($ability, $requestable, true)) {
                continue;
            }

            if (! \in_array($ability->value, $installationAbilities, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The role a session starts in when nothing has asked for one.
     *
     * **Derived from the installation rather than fixed at `build`, and that is a compatibility
     * decision with a shelf life.** Every session that exists today takes its abilities from its
     * installation, so a machine an admin made a coordinator produces coordinator sessions; fixing
     * this at `build` would take `coordinator:direct` away from that machine the next time its
     * agent started, with nothing in the fleet saying why. Requesting a role is a later slice
     * (`robot-council/core#222`), and this derivation is what stands in until one exists -- it is
     * the same rule the backfill migration applies to rows written before the column, stated
     * forward.
     *
     * @param  list<string>  $installationAbilities  What the installation holds.
     * @return self The role to start in.
     */
    public static function defaultFor(array $installationAbilities): self
    {
        return self::Coordinator->permittedBy($installationAbilities) ? self::Coordinator : self::Build;
    }

    /**
     * This role, or the floor when the installation may no longer run it.
     *
     * The one place a role is narrowed, so an admin taking `coordinator:direct` off a machine
     * demotes its live coordinator sessions rather than leaving them holding an ability the
     * machine no longer has. It never widens: a role the installation is eligible for is returned
     * unchanged, and every other answer is `build`.
     *
     * @param  list<string>  $installationAbilities  What the installation holds.
     * @return self This role, or `build`.
     */
    public function narrowedBy(array $installationAbilities): self
    {
        return $this->permittedBy($installationAbilities) ? $this : self::Build;
    }
}
