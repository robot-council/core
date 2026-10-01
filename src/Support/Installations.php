<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;

/**
 * Creating, revoking, and re-scoping installations.
 *
 * An installation credential can do one thing: start and renew agent sessions. The abilities a
 * developer approved ride on the session tokens instead, so a stolen installation credential
 * cannot itself create a task, take a lock, or post to the feed.
 */
final class Installations
{
    /**
     * The name Sanctum records against an installation's credential.
     */
    public const string CREDENTIAL_NAME = 'robot-council installation';

    /**
     * @param  Credentials  $credentials  The configured lifetimes.
     * @param  FleetEvents  $events  The fleet's change feed.
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly FleetEvents $events
    ) {}

    /**
     * The live installations that approving this identity would supersede.
     *
     * Read by the verification page so an approver is told what their approval ends **before** they
     * make it. That notice is not decoration: `harness` and `machine_label` are supplied by whoever
     * asked for the code and nothing checks them, and the CLI falls back to `unknown-machine` when
     * a hostname reduces to nothing -- so two of one developer's machines can collide on the
     * identity and a silent supersede would take out a working installation (#106).
     *
     * The identity is scoped to the approving developer, which is what `user_id` holds, so no
     * collision can cross developers.
     *
     * @param  string  $userId  The approving developer's host key.
     * @param  string  $harness  The harness the requester claimed.
     * @param  string  $machineLabel  The machine label the requester claimed.
     * @return Collection<int, Installation> The installations an approval would revoke.
     */
    public function liveFor(string $userId, string $harness, string $machineLabel): Collection
    {
        return $this->liveQuery($userId, $harness, $machineLabel)->get();
    }

    /**
     * Create the installation an approved device code stands for, and its credential.
     *
     * Both happen in one transaction, so nothing can leave an installation with no way to reach it
     * or a credential belonging to no installation.
     *
     * @param  DeviceCode  $code  The approved and already-claimed request.
     * @return IssuedCredential<Installation> The installation and its plaintext credential.
     */
    public function createFrom(DeviceCode $code): IssuedCredential
    {
        // Re-checked rather than trusted, although `DeviceCodes::issue()` has already bounded these.
        // `createFrom()` takes a model, and a caller can hand it one it built itself rather than one
        // this package wrote -- so the copy forward is its own entry point into every column the
        // create below writes. **All SIX caller-derived ones**: the decider's key lands in `user_id`
        // AND `approved_by`, both `varchar(64)`, the IP in a `varchar(45)`, and the abilities in a
        // `json` column. Checking a subset would leave exactly the "one call, three outcomes" this
        // guard exists to close.
        //
        // **The abilities were the subset, and the count in this comment said five.** #170 bounded
        // `DeviceCodes::approve()`, which writes that column on the device code -- and this reads
        // the model's in-memory attribute rather than re-reading the row, so setting it after a
        // narrowed approval simply overwrote the narrowed value. `coordinator:direct` is what makes
        // it matter: the device-code flow can never request it, and `Models\Installation::abilities()`
        // filters against the GRANTABLE list, which holds it, so it survived every later check and
        // the installation carried an ability no developer approved on the verification page.
        MachineIdentity::ensure($code->harness, $code->machine_label);

        // Through `HostKey`, which is where the 64-character bound on a host user key lives, rather
        // than a length test written again here. The narrowed value is kept rather than discarded,
        // because the supersede below matches on it and `decided_by` is nullable on the column.
        $approver = HostKey::from($code->decided_by);

        if ($code->requested_ip !== null && mb_strlen($code->requested_ip) > DeviceCodes::MAX_REQUESTED_IP) {
            throw new InvalidArgumentException(sprintf(
                'A requested IP is limited to %d characters, and this one is %d.',
                DeviceCodes::MAX_REQUESTED_IP,
                mb_strlen($code->requested_ip)
            ));
        }

        return DB::transaction(function () use ($code, $approver): IssuedCredential {
            // **Superseded before the replacement is created, and the rows are held while it
            // happens** (#106). A developer who re-enrolls because they believe a credential was
            // exposed has not invalidated it otherwise: the old credential is worth
            // `installation_max_age_days` from the day it was issued, so the act that felt like a
            // remedy was not one.
            //
            // `lockForUpdate()` rather than a plain read, because two approvals for the same
            // identity arriving together would otherwise both see the same live row, both decide to
            // revoke it, and both create a replacement -- leaving exactly the two live
            // installations this exists to prevent. `robot_council_installations` is first in the
            // package's lock order, so taking it here inverts nothing.
            //
            // Every live row rather than the newest, because a fleet that predates this change can
            // already carry several for one identity, and leaving all but one is the same defect
            // with a smaller number.
            //
            // **The developer's whole live set for the harness is held first, as `rename()` holds
            // it** (#550). Holding only the rows that already carry the label held nothing when none
            // did, so an approval and a rename to that label had no row in common to wait on and
            // both committed a live installation with one identity. A rename's row is in this set
            // whatever it is being renamed to, so the two now queue on it. The supersede read below
            // is a separate statement for the reason `heldLiveSet()` gives: it sees a label, or an
            // installation, that committed while the hold was waiting.
            $this->heldLiveSet($approver, $code->harness);

            $superseded = $this->liveQuery($approver, $code->harness, $code->machine_label)
                ->lockForUpdate()
                ->get();

            foreach ($superseded as $previous) {
                $this->revoke($previous, $approver);
            }

            $installation = Installation::query()->create([
                'user_id' => $code->decided_by,
                'harness' => $code->harness,
                'machine_label' => $code->machine_label,
                'approved_by' => $code->decided_by,
                'requested_ip' => $code->requested_ip,
                'expires_at' => $this->credentials->installationExpiry(),
            ]);

            $token = $installation->createToken(
                self::CREDENTIAL_NAME,
                [Ability::SessionsStart->value],
                $installation->expires_at
            );

            return new IssuedCredential($installation, $token->plainTextToken, [Ability::SessionsStart->value]);
        });
    }

    /**
     * Revoke an installation: its credential and every session token it issued stop working on the
     * next request.
     *
     * The sessions themselves are left as they are. Nothing can reach them, because renewing one
     * needs the installation credential and every request re-reads `revoked_at`.
     *
     * @param  Installation  $installation  The installation to revoke.
     * @param  string|null  $actor  The developer revoking it, when a signed-in one is.
     * @return int How many tokens were deleted.
     */
    public function revoke(Installation $installation, ?string $actor = null): int
    {
        return DB::transaction(function () use ($installation, $actor): int {
            // The installation's own row first, then the tokens. That is the package's lock order,
            // and `AgentSessions::renew()` already held this row while reaching for the same
            // tokens -- so taking them the other way round here was a deadlock between revoking an
            // installation and one of its sessions renewing.
            //
            // **Conditional on the row, and the changed count is the decision**, which is the
            // pattern `SessionPresence` uses throughout and the reason `Events\SessionGone` fires
            // exactly once however a session ended. Unconditionally, a second revoke overwrote
            // `revoked_at` with a later time -- losing when the decision was actually made -- and
            // wrote a second event, which is a second Slack message about something that did not
            // happen. `Builder::update()` returns rows CHANGED on MySQL, which does not bite here
            // because `whereNull` guarantees the row it matches is a row that changes.
            $changed = Installation::query()
                ->whereKey($installation->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now()]);

            if ($changed === 1) {
                $installation->refresh();

                // Before the token deletes, not after. The order is `installations`, then
                // `agent_sessions`, then the feed sentinel, then `personal_access_tokens`, and
                // recording the event below the deletes would hold token rows while reaching for
                // the sentinel -- the inversion the documented order exists to prevent.
                $this->record($installation, FleetEventType::InstallationRevoked, sprintf('%s on %s was revoked.', $installation->harness, $installation->machine_label), $actor);
            }

            $deleted = Tokens::deleted($installation->tokens()->delete());

            foreach ($this->sessionsOf($installation) as $session) {
                $deleted += Tokens::deleted($session->tokens()->delete());
            }

            return $deleted;
        });
    }

    /**
     * Change a live installation's machine label in place, without the machine re-enrolling (#534).
     *
     * **Every surface reads the label off the installation row**, so changing the row is the whole
     * change: `sessions_list`, the lane board and every presence event written from now on name the
     * machine by its new label, and the sessions it has running carry on. An event already in the
     * feed keeps the label it was written with, because its body is text recorded at write time.
     *
     * **Refused, in words, for a label enrollment would refuse**, through the same
     * `MachineIdentity::ensureLabel()`, so the reason a developer reads here is the one enrollment
     * gives. **And for a label another live installation of the same developer and harness already
     * holds**, because that triple is the identity a re-enrollment supersedes on: two live rows
     * sharing it would both be revoked by the next approval for either machine, which is #106's
     * silent supersede arriving through a different door.
     *
     * **Every live installation of that developer and harness is held before the label is checked
     * against them**, through `heldLiveSet()`, which `createFrom()` takes too (#550). Holding only
     * the renamed row would let two renames to the same label each find it free and both commit,
     * and an enrollment for a label nobody held yet had no row in common with a rename to it.
     *
     * @param  int  $installationId  The installation to rename.
     * @param  string  $machineLabel  The label it should carry.
     * @param  string  $actor  The developer renaming it.
     * @param  bool  $asAdmin  Whether that developer is an administrator, who may rename anybody's.
     * @return Outcome `Applied`; `NotFound` when there is no live installation by that id;
     *                 `Forbidden` when it is somebody else's and the actor is no administrator; or
     *                 `Conflict` when it already carries that label.
     *
     * @throws InvalidArgumentException When the label is refused, with the reason.
     */
    public function rename(int $installationId, string $machineLabel, string $actor, bool $asAdmin): Outcome
    {
        MachineIdentity::ensureLabel($machineLabel);

        $actor = HostKey::from($actor);

        return DB::transaction(function () use ($installationId, $machineLabel, $actor, $asAdmin): Outcome {
            // Unlocked, only to learn which set to hold: the decision is made from the held row
            // below, so a change between the two reads is decided on what it changed to
            $found = Installation::query()->whereKey($installationId)->whereNull('revoked_at')->first(['id', 'user_id', 'harness']);

            if (! $found instanceof Installation) {
                return Outcome::NotFound;
            }

            if ($found->user_id !== $actor && ! $asAdmin) {
                return Outcome::Forbidden;
            }

            $siblings = $this->heldLiveSet($found->user_id, $found->harness);

            // Revoked between the two reads: nothing live to rename
            $installation = $siblings->firstWhere('id', $found->id);

            if (! $installation instanceof Installation) {
                return Outcome::NotFound;
            }

            $previous = $installation->machine_label;

            if ($previous === $machineLabel) {
                return Outcome::Conflict;
            }

            // Compared in PHP, against rows already held, so the answer cannot go stale before the
            // update. Byte-exact, as the supersede's own `where` is on every engine but MySQL's
            // default collation -- which #54 made binary for the key columns and not this one, so
            // there a label differing only in case would still find the other row. Refusing on a
            // case-insensitive match as well keeps the rename from creating two rows MySQL's
            // supersede would treat as one.
            $taken = $siblings->contains(
                static fn (Installation $sibling): bool => $sibling->id !== $installation->id
                    && mb_strtolower($sibling->machine_label) === mb_strtolower($machineLabel)
            );

            if ($taken) {
                throw new InvalidArgumentException(sprintf(
                    "Another of this developer's live %s installations is already called %s, and two would both be replaced by the next enrollment of either.",
                    $installation->harness,
                    $machineLabel
                ));
            }

            $installation->machine_label = $machineLabel;
            $installation->save();

            // After the installation row, which is first in the lock order; the feed sentinel
            // comes later in it
            $this->record(
                $installation,
                FleetEventType::InstallationRenamed,
                sprintf('%s on %s was renamed to %s.', $installation->harness, $previous, $machineLabel),
                $actor,
                ['old_label' => $previous, 'new_label' => $machineLabel]
            );

            return Outcome::Applied;
        });
    }

    /**
     * Record one administrative change against an installation.
     *
     * The caller writes the body, naming the installation the way the rest of the feed names a
     * machine -- `<harness> on <label>` -- so a reader scanning it sees an authorization change in
     * the same vocabulary as an enrollment. A rename has two labels to name, which is why the body
     * is no longer composed here. Every part is charset-limited at the edge by `MachineIdentity`,
     * and together they cannot approach `FleetEvent::MAX_BODY`.
     *
     * **`meta` came back with #534.** `robot-council/core#231` retired the caller that passed any,
     * and the parameter was deleted rather than left for one call site to pass empty; a rename
     * names the label it replaced and the one it took, so a reader of the feed can tell which
     * machine this was without the body's prose.
     *
     * @param  Installation  $installation  The installation that changed.
     * @param  FleetEventType  $type  What changed.
     * @param  string  $body  What happened to it, as a sentence.
     * @param  string|null  $actor  The developer responsible, when a signed-in one is.
     * @param  array<string, string>  $meta  What else the event names, beside the installation.
     */
    private function record(
        Installation $installation,
        FleetEventType $type,
        string $body,
        ?string $actor,
        array $meta = []
    ): void {
        $this->events->record(
            $type,

            // No session: the change was made by a developer, through the dashboard or the
            // console, rather than by a process the fleet knows about
            null,
            $body,
            ['installation_id' => $installation->id, ...$meta],

            // Both, and they are different people: the event is ABOUT this installation's owner
            // and was DONE by the admin. Before #115 only the admin was recorded, in the column
            // #29's visibility rule reads.
            actor: $actor,
            subject: $installation->user_id
        );
    }

    /**
     * Hold every live installation one developer has for one harness, and return them as they
     * stand once held.
     *
     * **The set every write to an identity serializes on** (#550). `rename()` and `createFrom()`
     * both take it before deciding anything about a label, and a rename's own row is always in it,
     * so an enrollment and a rename to the same label wait for each other rather than both
     * committing. No partial unique index does this instead: the operator chose holding the set on
     * #550, and MySQL has no partial index to give the same answer on every engine.
     *
     * **Read twice, and the second read is the answer.** The first statement waits for whoever
     * holds the rows and then returns them, but only the rows its own snapshot could see: on
     * Postgres an installation another writer INSERTED while this one waited is not among them,
     * because a statement under read committed keeps the snapshot it started with and re-checks only
     * the rows it had already found. So a rename that waited on an enrollment would read the set
     * without the installation that enrollment just created, find the label free, and commit a
     * second live row with it. The second statement starts after the wait, so it sees what
     * committed; it is a locking read rather than a plain one because InnoDB serves a plain read
     * from the transaction's snapshot, and a locking read from the latest committed rows. Nothing
     * can insert into a held set between the two, because every writer that inserts holds the set
     * first and waits on these rows -- short of the empty set below.
     *
     * **Id order, which is the order Postgres takes the rows in**; InnoDB takes them in the order it
     * scans the identity index. Either way both callers run the same query and take the same rows
     * in the same direction, so neither can deadlock the other. `robot_council_installations` is
     * first in the package's lock order, so holding it first inverts nothing.
     *
     * **What it cannot hold is an empty set.** Two first enrollments of one harness by one developer,
     * approved in the same moment for the same label, find nothing to wait on and both commit. A
     * rename cannot be one of them, because the renamed installation is in its own set.
     *
     * @param  string  $userId  The developer's host key.
     * @param  string  $harness  The harness.
     * @return Collection<int, Installation> The live installations, held, in id order.
     */
    private function heldLiveSet(string $userId, string $harness): Collection
    {
        $hold = static fn (): Collection => Installation::query()
            ->where('user_id', $userId)
            ->where('harness', $harness)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $hold();

        return $hold();
    }

    /**
     * The query for live installations under one identity.
     *
     * One builder for both callers, because the page's notice and the supersede itself have to
     * agree on what "live" means. If they drifted, an approver would be told one thing and the
     * approval would do another -- and the page is the only warning there is.
     *
     * **"Live" is `revoked_at` being null, and expiry is deliberately not part of it.** An expired
     * installation cannot be used, but leaving it unrevoked keeps a row that says a credential is
     * outstanding when nothing stands behind it, which is the untidiness this closes rather than
     * one it should preserve.
     *
     * @param  string  $userId  The approving developer's host key.
     * @param  string  $harness  The harness claimed.
     * @param  string  $machineLabel  The machine label claimed.
     * @return Builder<Installation> The query.
     */
    private function liveQuery(string $userId, string $harness, string $machineLabel): Builder
    {
        return Installation::query()
            ->where('user_id', $userId)
            ->where('harness', $harness)
            ->where('machine_label', $machineLabel)
            ->whereNull('revoked_at')
            ->orderBy('id');
    }

    /**
     * The sessions an installation has started that can still be holding a token.
     *
     * **Bounded deliberately, and `gone` is what bounds it.** Nothing in this package deletes a
     * session row -- `SessionPresence::goesNow()` deletes the tokens and keeps the row, and the
     * only prune that exists is for device codes -- so `$installation->sessions()` grows for the
     * life of the installation and one machine can mint a row a second within its rate limit.
     * Both callers run inside a transaction that already holds the feed's sentinel row, which
     * every writer in the fleet takes before inserting, so an unbounded loop here stops every
     * agent's narration for its duration. `SessionPresence::pass()` bounds itself for exactly
     * this reason and says so.
     *
     * Filtering to live sessions loses nothing. A session that has gone had its tokens deleted as
     * it went, so it contributes no work to either caller; and `EnsureAgentSession` refuses it on
     * `hasGone()` before it ever reads a token, so a stray row could not be used regardless.
     *
     * @param  Installation  $installation  The installation to read.
     * @return Collection<int, AgentSession> Its sessions that have not gone.
     */
    private function sessionsOf(Installation $installation): Collection
    {
        return $installation->sessions()
            ->where('status', '!=', AgentSessionStatus::Gone->value)
            ->get();
    }
}
