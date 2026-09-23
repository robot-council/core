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
 * **A role's preset is the whole answer, and nothing else contributes.** `Support\AgentSessions`
 * mints a token from it; an installation's stored abilities decide neither what a session holds nor
 * which role it may be. `robot-council/core#221` left them deciding eligibility and
 * `robot-council/core#222` removed even that: a role is `build` at start and an administrator's
 * decision after that, which is the only thing a client cannot assert.
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
}
