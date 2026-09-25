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
     *
     * **`joined`, not `enrolled`, and not `started`.** An installation enrolls -- a device code, a
     * developer at a browser, an approval. Sharing that verb made a reader work out that the two
     * enrollments were unrelated events with different subjects, actors and approval paths (#217).
     *
     * `started` was the first replacement and lasted one afternoon. `robot-council/cli#125` decided
     * that joining the fleet is a deliberate act rather than something a harness does by launching
     * its stdio servers, so what this event records is an agent choosing to join -- not a process
     * beginning. The verb follows the act. Changed while the rename was still unreleased, which is
     * the only reason it cost one migration rather than two.
     *
     * Rows written before the rename are rewritten by
     * `2026_09_23_000002_rename_session_enrolled_events`, because this enum is a cast and
     * `from()` raises on a value it no longer has.
     */
    case SessionJoined = 'session.joined';

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
     *
     * **Nothing writes this any more, and it stays because rows already hold it.**
     * `robot-council/core#231` retired the controls that recorded it -- the two console commands and
     * the administration panel's per-ability buttons -- after `robot-council/core#222` made a
     * session's abilities come from its role, at which point the column those controls wrote decided
     * nothing about any session.
     *
     * **Removing the case is what would break a fleet, not keeping it.**
     * `robot_council_events.type` is a `string(64)` holding this backing value and
     * `Models\FleetEvent` casts it with `'type' => FleetEventType::class`; Laravel's enum cast
     * resolves through `from()`, which raises `ValueError` on a value the enum no longer has. So a
     * historical row becomes unreadable the moment the feed pages over it -- on the dashboard, and
     * in every agent's feed read. Measured on the deployment 2026-09-24: three such rows.
     *
     * **A migration deleting them was written and rejected.** `retention.events_days` may be set to
     * zero, which `config/robot-council.php` documents as keeping the table forever for "a host
     * running its own archiving", and `Console\PruneEventsCommand` honors it. On such a host the
     * feed is a record rather than a rolling window, and a package upgrade must not delete from it.
     * The enum is the feed's vocabulary, and a vocabulary has to cover what was said as well as what
     * is still being said.
     */
    case InstallationAbilityGranted = 'installation.ability_granted';

    /**
     * An admin took an ability away from an installation.
     *
     * Retired alongside `InstallationAbilityGranted` and kept for the same reason; that case records
     * it.
     */
    case InstallationAbilityRevoked = 'installation.ability_revoked';

    /**
     * A session asked to be a different role. Nothing about what it may do has changed.
     *
     * Recorded rather than left in the panel alone, because the request and the decision are two
     * acts by two parties and a feed that showed only the second could not say how long the first
     * had been waiting.
     */
    case SessionRoleRequested = 'session.role_requested';

    /**
     * A session's role changed: approved from a request, or imposed by an administrator.
     *
     * **One type for both, with the decision in the payload.** Two types would make a reader
     * hunting "what is this session allowed to do, and who decided" join two streams, and the
     * difference between an approval and an imposition is a field rather than a kind of event.
     */
    case SessionRoleChanged = 'session.role_changed';

    /**
     * A session took back a role request it had made, by asking for the role it already holds.
     *
     * **Its own type, not a denial.** A denial is an administrator refusing; this is the session
     * changing its mind, and an administrator reading why a request left the queue needs to tell
     * the two apart (`robot-council/core#369`). Nothing about what the session may do changed.
     */
    case SessionRoleWithdrawn = 'session.role_withdrawn';

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
     * A build lane has authored nothing substantive for longer than its window (#332).
     *
     * Addressed to the coordinator and restricted, so no other session receives it: it is the
     * coordinator's to-do, not the fleet's news. Recorded by the service with no session and no
     * user, so the addressees are its only readers.
     */
    case LaneQuiet = 'lane.quiet';

    /**
     * A lane condition the coordinator should act on (#319): a lane free with no stated hold, a
     * placement not taken up, a working lane whose session stopped answering, a ready pull request
     * no gate picked up, or a merge that left sessions behind. `meta.condition` names which.
     *
     * Restricted and addressed to the coordinators, like `lane.quiet`, for the same reason.
     */
    case LaneCondition = 'lane.condition';

    /**
     * A coordinator's own words to the lane it placed work on (#331).
     *
     * **Restricted, addressed to the lane, and excluded from the coordinator broadcast.** The
     * placement's `task.reassigned` and its package-composed directive reach every agent; what the
     * coordinator typed reaches only the lane and the coordinator's own developer, because it may
     * name a task another developer's agent may not read. It still carries `coordinator_direct`
     * truthfully, so the lane can tell a coordinator's instruction from anybody's -- which is why
     * `Support\FleetFeed` must not serve it through the branch that broadcasts coordinator posts.
     */
    case PlacementInstruction = 'placement.instruction';

    /**
     * Whether an event of this type is only visible to some readers.
     *
     * **Marking an `installation.*` type restricted is now safe, and it was not before #115.**
     * `Support\FleetFeed` serves a restricted event to the developer named in the event's
     * `user_id`, which used to hold the acting ADMIN for an administrative event -- so restricting
     * one would have served it to the admin and hidden it from the owner whose agent was affected.
     * #115 proposed a tripwire here against exactly that, and the column split removed the need for
     * one **for the `installation.*` types**, which is all it verified.
     *
     * **It is not safe for every type, and the tripwire question stays open for two shapes.** A
     * sweep records what the service observed with no session and no subject, so
     * `Support\Tasks`'s `task.released` and `Support\Locks`'s `lock.released` carry a **null**
     * `user_id` -- and `NULL = 'x'` is unknown in SQL, so restricting either would hide it from
     * everyone including the developer whose work was released. And a transition records the
     * ACTING session: `task.assigned` from a reassignment names the reassigner while the assignee
     * is only in `meta`, as `lock.taken_over` and `lock.force_released` name the taker while the
     * dispossessed holder is only in `meta.taken_from`. Restricting one of those is the same
     * inversion #115 removed, arriving through a different door.
     *
     * So: restricting an `installation.*` type is safe. Restricting anything else needs the
     * event's `user_id` checked first.
     *
     * **`lane.quiet` is restricted, and safe to be, for the reason the paragraph above asks.** It
     * carries a null `user_id` and is always addressed, so the addressees -- the coordinators -- are
     * its only readers, which is exactly what #332 asks for (#323's decision).
     *
     * `lane.condition` (#319) is the same shape -- no user, always addressed -- and restricted for
     * the same reason.
     *
     * `placement.instruction` (#331) names the coordinator's developer in `user_id` and is always
     * addressed to the lane, so those two are its readers -- see `staysAddressed()`.
     *
     * @return bool True for narration, which #29 restricts, and for `lane.quiet`, `lane.condition`
     *              and `placement.instruction`.
     */
    public function isRestricted(): bool
    {
        return in_array($this, [self::Narration, self::LaneQuiet, self::LaneCondition, self::PlacementInstruction], true);
    }

    /**
     * Whether posting with `coordinator:direct` fails to widen this type's audience.
     *
     * Coordinator narration reaches every reader (#29). A placement instruction is posted with the
     * flag set, truthfully, and must still reach only its addressees and its own developer (#331).
     *
     * @return bool True for a type the coordinator flag does not broadcast.
     */
    public function staysAddressed(): bool
    {
        return $this === self::PlacementInstruction;
    }

    /**
     * The restricted types the coordinator flag does not broadcast, as stored.
     *
     * @return list<string> Their values.
     */
    public static function addressedOnlyValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->staysAddressed())
        ));
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
