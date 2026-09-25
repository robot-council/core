<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

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
     * Record that a session has asked to be a role, or withdraw what it asked for.
     *
     * **It changes nothing the session may do.** The abilities on its token are untouched, and they
     * stay untouched until an administrator decides. Asking again replaces what was pending, which
     * is what a client retrying after a restart does anyway.
     *
     * **Asking for the role it already holds withdraws whatever is pending** (the decision on
     * `robot-council/core#369`). An earlier version answered it as nothing to do and left the other
     * request in the administrator's queue, where it could still be approved -- promoting a session
     * that had been told nothing was pending. Being promoted after changing its mind is the worse
     * failure, so the request is cleared and the feed records a withdrawal, which is not a denial:
     * nobody refused anything.
     *
     * **The return describes the row once this returns, not the request that was made**, so a
     * caller reporting it cannot tell a session that a request is gone while one still waits.
     *
     * @param  AgentSession  $session  The session asking.
     * @param  Role  $role  What it wants to be.
     * @return Role|null The role now pending for an administrator, or null when nothing is: the
     *                   session holds the role it asked for, or it is not live.
     */
    public function request(AgentSession $session, Role $role): ?Role
    {
        return DB::transaction(function () use ($session, $role): ?Role {
            $current = $this->locked($session);

            // A session that has gone has nothing an administrator can decide, whatever its row
            // still holds -- `settle()` and `deny()` both refuse it -- so nothing is pending for it.
            if (! $current instanceof AgentSession || $current->hasGone()) {
                return null;
            }

            // Asking to be what it already is is not a request. Answering it as one would put a
            // row in an administrator's queue whose approval changes nothing -- and it is how a
            // session takes back a request it no longer wants.
            if ($current->role === $role) {
                return $this->withdraw($current);
            }

            // **Already pending is not a new request, and saying so here is what keeps the three
            // engines agreeing.** `Builder::update()` returns rows CHANGED on MySQL and rows
            // MATCHED on SQLite and Postgres, and `requested_at` is a `dateTime` bound at second
            // precision -- so a session re-asking for what is already pending, inside the same
            // second, writes byte-identical values and reports 0 on MySQL and 1 on the other two.
            // Without this the endpoint would answer `pending: false` on one engine while a request
            // WAS pending, which is the state the field exists to let a client tell apart.
            if ($current->requested_role === $role) {
                return $role;
            }

            $changed = AgentSession::query()
                ->whereKey($current->getKey())
                ->where('status', '!=', AgentSessionStatus::Gone->value)
                ->update([
                    'requested_role' => $role->value,
                    'requested_at' => PresenceClock::now(),
                ]);

            if ($changed !== 1) {
                return null;
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

            return $role;
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

            // **This comparison is the whole compare-and-swap, and it is enough because of the
            // lock.** `locked()` holds the row for the rest of the transaction, so nothing can
            // change `requested_role` between reading it here and writing it in `settle()`. An
            // earlier version also repeated the check as a `where` on the update; no test could
            // reach it -- the row is held either way -- so it was two permanent mutation survivors
            // and a second place to get the rule right, rather than a second line of defence.
            if (! $current instanceof AgentSession || $current->requested_role !== $expected) {
                return null;
            }

            return $this->settle($current, $expected, $actor, 'approved') ? $expected : null;
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

            // Unreachable on one connection: the row is held by `locked()` and `requested_role`
            // was read non-null under that lock, so nothing can clear it in between. Kept because
            // a second connection can on an engine that does not serialize writers, and because
            // every other write here decides the same way.
            // @pest-mutate-ignore
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
     * **It takes no `$expected`, unlike `approve()`, and that asymmetry is deliberate.** The gap
     * that made approval a compare-and-swap was that the control carried only a session id while
     * the role came from the row -- so what the administrator consented to and what they got could
     * differ. Here the role rides the control and the confirmation is gated on the role being sent,
     * so the two cannot come apart. What a stale click can still do is overwrite a change another
     * administrator made in between; that is last-writer-wins between two people who are both
     * entitled to decide, which is what an imposition means.
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
     * Take back a live session's pending request, leaving its role as it is.
     *
     * @param  AgentSession  $session  The session, already locked and live.
     * @return Role|null Null once nothing is pending, which is every path but the unreachable one.
     */
    private function withdraw(AgentSession $session): ?Role
    {
        $withdrawn = $session->requested_role;

        // Check whether anything is pending at all. Nothing to take back writes nothing and
        // records nothing, so asking for the current role stays free of feed noise.
        if (! $withdrawn instanceof Role) {
            return null;
        }

        // Clear the request only while the row still holds the one that was read, so the update
        // cannot take back a different request than the one this decided about.
        $changed = AgentSession::query()
            ->whereKey($session->getKey())
            ->where('requested_role', $withdrawn->value)
            ->where('status', '!=', AgentSessionStatus::Gone->value)
            ->update(['requested_role' => null, 'requested_at' => null]);

        // Unreachable on one connection, as in `deny()`: the row is held by `locked()` and was read
        // live with this request pending, so nothing can change either in between. A write that did
        // not happen leaves the request where it was, and the return says so.
        // @pest-mutate-ignore
        if ($changed !== 1) {
            return $withdrawn;
        }

        // Record the withdrawal after the row, as its own type: a denial names an administrator
        // who refused, and here nobody did -- the session changed its mind.
        $this->record(
            $session,
            FleetEventType::SessionRoleWithdrawn,
            sprintf('withdrew its request for %s and stays %s', $withdrawn->value, $session->role->value),
            ['withdrawn' => $withdrawn->value, 'stays' => $session->role->value],
            null
        );

        return null;
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
    private function settle(AgentSession $session, Role $role, ?string $actor, string $how): bool
    {
        $from = $session->role;

        // **Nothing changed means nothing happened**, and an event saying otherwise is noise in the
        // one feed an authorization change has to be legible in -- the guard
        // `Support\Installations::setAbility()` carried for the same reason, before
        // `robot-council/core#231` retired it.
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
        // this reason before `robot-council/core#231` retired it -- a token write before the event
        // would be the 6th row ahead of the 5th, and a session write after it would be the 2nd
        // behind the 5th, which deadlocks against `Support\Locks::acquire()`.
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

        // The narrowing is for the analyzer rather than for the runtime: `first()` already answers
        // null for a row that is not there, so `true ? $current : null` is the same expression and
        // no input can tell the two apart. `Support\AgentSessions::locked()` carries the same shape.
        // @pest-mutate-ignore: InstanceOfToTrue
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
