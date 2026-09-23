<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

/**
 * The kinds of thing the fleet's change feed records.
 *
 * Narration is what an agent says about its own work, and it is the only kind whose visibility
 * depends on who is reading (#29). Everything else describes a change to fleet state, or is a
 * directive, and reaches every reader.
 *
 * A type is stored as its string value, so a later release can add one without renumbering
 * anything, and a reader that does not recognize one can still page past it.
 */
enum FleetEventType: string
{
    /**
     * An agent talking about its own work. Visible to its own developer, and to everyone when the
     * session that posted it held the coordinator's ability at the time.
     */
    case Narration = 'narration';

    /**
     * An instruction to the fleet from a session holding `coordinator:direct`.
     */
    case Directive = 'directive';

    /**
     * A new agent session came into existence.
     */
    case SessionEnrolled = 'session.enrolled';

    /**
     * A session stopped answering for long enough to be marked stale. It still holds whatever it
     * claimed, so this is a warning rather than a release.
     */
    case SessionStale = 'session.stale';

    /**
     * A stale session made a request, so it is active again.
     */
    case SessionResumed = 'session.resumed';

    /**
     * A session ended: the process said so, an admin revoked it, or it stopped answering for long
     * enough. Whatever it held is released.
     */
    case SessionGone = 'session.gone';

    /**
     * An admin revoked an installation, and with it every session it had started.
     */
    case InstallationRevoked = 'installation.revoked';

    /**
     * An admin gave an installation an ability it did not have.
     */
    case InstallationAbilityGranted = 'installation.ability_granted';

    /**
     * An admin took an ability away from an installation.
     */
    case InstallationAbilityRevoked = 'installation.ability_revoked';

    /**
     * A task was created and is waiting for somebody to claim it.
     */
    case TaskCreated = 'task.created';

    /**
     * A session took a pending task.
     */
    case TaskClaimed = 'task.claimed';

    /**
     * A session began work on a task it holds.
     */
    case TaskStarted = 'task.started';

    /**
     * A task is waiting on something outside its session's control.
     */
    case TaskBlocked = 'task.blocked';

    /**
     * A task finished successfully.
     */
    case TaskCompleted = 'task.completed';

    /**
     * A task finished unsuccessfully.
     */
    case TaskFailed = 'task.failed';

    /**
     * A task went back to the queue, by its claimant, a coordinator, or the presence sweep.
     */
    case TaskReleased = 'task.released';

    /**
     * A coordinator moved a held task to another session.
     */
    case TaskReassigned = 'task.reassigned';

    /**
     * A coordinator called a task off for good.
     */
    case TaskCancelled = 'task.cancelled';

    /**
     * A session took a free lock.
     */
    case LockAcquired = 'lock.acquired';

    /**
     * A session extended a lease it already held.
     */
    case LockRenewed = 'lock.renewed';

    /**
     * A session gave up a lock it held.
     */
    case LockReleased = 'lock.released';

    /**
     * A session took a lock whose lease had lapsed, from whoever held it.
     */
    case LockTakenOver = 'lock.taken_over';

    /**
     * A coordinator took a lock away from the session holding it.
     */
    case LockForceReleased = 'lock.force_released';

    /**
     * Whether an event of this type is only visible to some readers.
     *
     * **Marking an `installation.*` type restricted is now safe, and it was not before #115.**
     * `Support\FleetFeed` serves a restricted event to the developer named in the event's
     * `user_id`, which used to hold the acting ADMIN for an administrative event -- so restricting
     * one would have served it to the admin and hidden it from the owner whose agent was affected.
     * #115 proposed a tripwire here against exactly that, and the column split removed the need
     * for one: `user_id` is the developer the event is about for every type, so the rule points at
     * the right person whatever is restricted. The tripwire is recorded as not built, with its
     * reason, rather than left as an unmet line on a closed ticket.
     *
     * @return bool True for narration, which #29 restricts, and false for everything else.
     */
    public function isRestricted(): bool
    {
        return $this === self::Narration;
    }

    /**
     * The types whose visibility depends on who is reading.
     *
     * The feed's query is built from this rather than from a hardcoded comparison, so adding a
     * restricted type is a matter of declaring it restricted. The other way round fails open: the
     * new type would be served to every reader, and the one place a reviewer looks to confirm the
     * boundary would be the place that does not enforce it.
     *
     * @return list<string> The restricted types, as stored.
     */
    public static function restrictedValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->isRestricted())
        ));
    }
}
