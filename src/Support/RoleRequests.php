<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;

/**
 * Asking to be a different role, and an administrator deciding.
 *
 * **The rule, and it has no exemptions: a session may REQUEST a role change, an administrator may
 * IMPOSE one, and neither a session nor any automatic rule may effect one alone.** Enrollment has
 * an approver -- a device code, a browser, a developer reading what was asked for. `POST
 * api/sessions` has none; it is an unattended call from a bridge. A role requested there and
 * granted on the spot would be a role *asserted*, and any checkout could take `coordinator:direct`
 * by asking for it. The approval is the entire gate, and it is what lets installations stay keyed
 * on `(developer, harness, machine)`: privilege has to ride something the client cannot assert, and
 * here that is a human rather than a credential.
 *
 * **No direction is exempt, including a narrowing.** A draft derived self-service demotion from
 * comparing the presets, so that it could not rot as the presets moved; it was dropped as one rule
 * too many. A session that wants to stop coordinating can end -- the role dies with the process.
 *
 * **Every write here is conditional on the ROW, and the changed count is the decision**, which is
 * the pattern `SessionPresence` uses throughout. Two administrators clicking approve on one request
 * must produce one role change and one event, not two, and a session that went `gone` between the
 * panel rendering and the click must not be approved at all. `Builder::update()` returns rows
 * CHANGED on MySQL, so every predicate here names a value that is genuinely different from what it
 * writes.
 */
final class RoleRequests
{
    /**
     * @param  FleetEvents  $events  The change feed.
     */
    public function __construct(private readonly FleetEvents $events) {}

    /**
     * Record that a session has asked to be a role.
     *
     * **It changes nothing the session may do.** The abilities on its token are untouched, and they
     * stay untouched until an administrator decides. Asking again replaces what was pending, which
     * is what a client retrying after a restart does anyway.
     *
     * @param  AgentSession  $session  The session asking.
     * @param  Role  $role  What it wants to be.
     * @return bool True when a request is now pending, false when the session is not live or
     *              already holds that role.
     */
    public function request(AgentSession $session, Role $role): bool
    {
        return DB::transaction(function () use ($session, $role): bool {
            $current = $this->locked($session);

            // Asking to be what it already is is not a request. Answering it as one would put a
            // row in an administrator's queue whose approval changes nothing.
            if (! $current instanceof AgentSession || $current->role === $role) {
                return false;
            }

            // **Already pending is not a new request, and saying so here is what keeps the three
            // engines agreeing.** `Builder::update()` returns rows CHANGED on MySQL and rows
            // MATCHED on SQLite and Postgres, and `requested_at` is a `dateTime` bound at second
            // precision -- so a session re-asking for what is already pending, inside the same
            // second, writes byte-identical values and reports 0 on MySQL and 1 on the other two.
            // Without this the endpoint would answer `pending: false` on one engine while a request
            // WAS pending, which is the state the field exists to let a client tell apart.
            if ($current->requested_role === $role) {
                return true;
            }

            $changed = AgentSession::query()
                ->whereKey($current->getKey())
                ->where('status', '!=', AgentSessionStatus::Gone->value)
                ->update([
                    'requested_role' => $role->value,
                    'requested_at' => PresenceClock::now(),
                ]);

            if ($changed !== 1) {
                return false;
            }

            // Recorded after the row, so the feed cannot describe a request that failed to write.
            // The body says what was asked for and from what, because a reader of the feed has no
            // other way to know which way the change would go.
            $this->record(
                $current,
                FleetEventType::SessionRoleRequested,
                sprintf('asked to change from %s to %s', $current->role->value, $role->value),
                ['from' => $current->role->value, 'to' => $role->value],
                null
            );

            return true;
        });
    }

    /**
     * Approve a session's pending request, when it is still the one that was shown.
     *
     * **`$expected` is the whole gate, and reading the role off the row instead was a privilege
     * escalation.** The panel renders one control per session carrying only its id, and the
     * coordinator warning is gated on the role it rendered -- so an earlier version that settled
     * whatever the row held at click time could be walked: ask for `ci`, wait for the page to
     * render an Approve button with no warning on it, ask for `coordinator`, and the next click
     * grants `coordinator:direct` from an administrator who consented to `ci`. Requests replace
     * rather than queue, the poll interval is at least five seconds and `wire:poll` pauses on a
     * hidden tab, so the window is wide and costs the asker nothing to wait for.
     *
     * So this is a compare-and-swap: the update names the role the administrator was looking at,
     * and a request that changed underneath is refused rather than approved into something else.
     * That is the same discipline every other write here follows -- conditional on the ROW, with
     * the changed count as the decision -- applied to the value being decided rather than only to
     * the status.
     *
     * @param  AgentSession  $session  The session to approve.
     * @param  Role  $expected  The role the administrator was shown and is consenting to.
     * @param  string|null  $actor  The administrator deciding.
     * @return Role|null The role it now holds, or null when nothing was pending or the request had
     *                   changed since it was rendered.
     */
    public function approve(AgentSession $session, Role $expected, ?string $actor = null): ?Role
    {
        return DB::transaction(function () use ($session, $expected, $actor): ?Role {
            $current = $this->locked($session);

            // Read under the lock, so the comparison cannot be raced by a request arriving between
            // the read and the write.
            if (! $current instanceof AgentSession || $current->requested_role !== $expected) {
                return null;
            }

            return $this->settle($current, $expected, $actor, 'approved', $expected) ? $expected : null;
        });
    }

    /**
     * Refuse what a session asked for, leaving it as it is.
     *
     * @param  AgentSession  $session  The session to refuse.
     * @param  string|null  $actor  The administrator deciding.
     * @return bool True when a pending request was cleared.
     */
    public function deny(AgentSession $session, ?string $actor = null): bool
    {
        return DB::transaction(function () use ($session, $actor): bool {
            $current = $this->locked($session);

            if (! $current instanceof AgentSession || $current->requested_role === null) {
                return false;
            }

            $refused = $current->requested_role;

            $changed = AgentSession::query()
                ->whereKey($current->getKey())
                ->whereNotNull('requested_role')

                // The same gate `settle()` takes, rather than the asymmetry an earlier version
                // left: a session that ended between the panel rendering and the click has nothing
                // an administrator can decide about, in either direction.
                ->where('status', '!=', AgentSessionStatus::Gone->value)
                ->update(['requested_role' => null, 'requested_at' => null]);

            if ($changed !== 1) {
                return false;
            }

            // A denial is recorded against the request rather than as a role change, because no
            // role changed. A feed that said nothing here would leave a session's operator unable
            // to tell a refusal from a request nobody has looked at yet.
            $this->record(
                $current,
                FleetEventType::SessionRoleRequested,
                sprintf('was refused %s and stays %s', $refused->value, $current->role->value),
                ['refused' => $refused->value, 'stays' => $current->role->value],
                $actor
            );

            return true;
        });
    }

    /**
     * Put a session in a role with no request outstanding.
     *
     * **This is what makes an emergency demotion possible, and the administrator's own action is
     * the approval.** It also clears anything pending: an administrator who imposes a role has
     * answered the question the request was asking, whichever way it was asked.
     *
     * @param  AgentSession  $session  The session to change.
     * @param  Role  $role  What it becomes.
     * @param  string|null  $actor  The administrator deciding.
     * @return bool True when the role changed.
     */
    public function impose(AgentSession $session, Role $role, ?string $actor = null): bool
    {
        return DB::transaction(function () use ($session, $role, $actor): bool {
            $current = $this->locked($session);

            if (! $current instanceof AgentSession) {
                return false;
            }

            return $this->settle($current, $role, $actor, 'imposed');
        });
    }

    /**
     * Write a role onto a live session, re-mint its tokens, and record it.
     *
     * @param  AgentSession  $session  The session, already locked.
     * @param  Role  $role  The role it takes.
     * @param  string|null  $actor  The administrator deciding.
     * @param  string  $how  `approved` or `imposed`, which the event carries.
     * @return bool True when the row changed.
     */
    private function settle(AgentSession $session, Role $role, ?string $actor, string $how, ?Role $expected = null): bool
    {
        $from = $session->role;

        // **Nothing changed means nothing happened**, and an event saying otherwise is noise in the
        // one feed an authorization change has to be legible in -- the guard
        // `Support\Installations::setAbility()` carries for the same reason.
        //
        // It is also what makes the three engines agree. `Builder::update()` returns rows CHANGED
        // on MySQL and rows MATCHED on SQLite and Postgres, so writing a role a row already holds
        // reports 0 on one and 1 on the others: without this, an imposition of the current role
        // would write an event on two engines and not on the third.
        if ($from === $role) {
            return false;
        }

        // **Conditional on the session not having gone**, which is the window between an
        // administrator seeing the panel and clicking: a session that ended in between must not be
        // given a role, because its tokens are already deleted and nothing would carry it.
        $changed = AgentSession::query()
            ->whereKey($session->getKey())
            ->where('status', '!=', AgentSessionStatus::Gone->value)

            // **The pending role is part of the predicate on the approval path**, so a request that
            // changed between the render and the click loses rather than being approved into
            // something the administrator never saw. `impose()` passes null, because an imposition
            // answers the question whatever was pending -- including nothing.
            ->when($expected instanceof Role, fn (Builder $query): Builder => $query->where('requested_role', $expected?->value))
            ->update([
                'role' => $role->value,
                'requested_role' => null,
                'requested_at' => null,
            ]);

        if ($changed !== 1) {
            return false;
        }

        // **The session row, then the feed sentinel, then the tokens.** That is the package's lock
        // order, and `Support\Installations::setAbility()` was split across `record()` for exactly
        // this reason -- a token write before the event would be the 6th row ahead of the 5th, and
        // a session write after it would be the 2nd behind the 5th, which deadlocks against
        // `Support\Locks::acquire()`.
        $this->record(
            $session,
            FleetEventType::SessionRoleChanged,
            sprintf('%s from %s to %s', $how, $from->value, $role->value),
            ['from' => $from->value, 'to' => $role->value, 'how' => $how],
            $actor
        );

        // Re-minted rather than left to the next renewal, which is up to an hour away: an approval
        // that took an hour to reach the process would be indistinguishable from one nobody made,
        // and a demotion that took an hour would be a demotion that did not happen.
        foreach ($session->tokens()->get() as $token) {
            $token->forceFill(['abilities' => $role->tokenAbilities()])->save();
        }

        return true;
    }

    /**
     * Re-read the session inside the transaction, holding its row.
     *
     * The instance a caller arrives with was loaded before any of this ran -- by a guard, or by the
     * panel's own render a poll ago -- so its role and its pending request are both as old as that
     * read. Every decision here is made on the row.
     *
     * @param  AgentSession  $session  The session a caller named.
     * @return AgentSession|null The row as it stands now, or null when it has gone entirely.
     */
    private function locked(AgentSession $session): ?AgentSession
    {
        $current = AgentSession::query()->whereKey($session->getKey())->lockForUpdate()->first();

        return $current instanceof AgentSession ? $current : null;
    }

    /**
     * Record one role event against a session.
     *
     * @param  AgentSession  $session  The session the event is about.
     * @param  FleetEventType  $type  Which event.
     * @param  string  $happened  What happened, as a predicate.
     * @param  array<string, mixed>  $meta  The decision's own fields.
     * @param  string|null  $actor  The administrator, when one decided.
     */
    private function record(
        AgentSession $session,
        FleetEventType $type,
        string $happened,
        array $meta,
        ?string $actor
    ): void {
        $this->events->record(
            $type,
            $session,
            sprintf('session %d %s.', $session->id, $happened),
            ['installation_id' => $session->installation_id, ...$meta],

            // Both, and they can be different people: the event is ABOUT the session's owner and
            // was DONE by whoever decided, which is the split #115 added to the feed.
            actor: $actor,
            subject: $session->user_id
        );
    }
}
