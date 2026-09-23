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
        // create below writes. **All five of them, not the two that are obviously text**: the
        // decider's key lands in `user_id` AND `approved_by`, both `varchar(64)`, and the IP in a
        // `varchar(45)`. Checking a subset would leave exactly the "one call, three outcomes" this
        // guard exists to close.
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
                'granted_abilities' => $code->granted_abilities ?? [],
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
                $this->record($installation, FleetEventType::InstallationRevoked, 'was revoked', [], $actor);
            }

            $deleted = Tokens::deleted($installation->tokens()->delete());

            foreach ($this->sessionsOf($installation) as $session) {
                $deleted += Tokens::deleted($session->tokens()->delete());
            }

            return $deleted;
        });
    }

    /**
     * Add or remove one ability on an installation, and on the session tokens already in flight.
     *
     * Rewriting the live tokens is the point: a session token lives for an hour, so leaving them
     * alone would let a revoked ability keep working until every process happened to renew.
     *
     * @param  Installation  $installation  The installation to re-scope.
     * @param  Ability  $ability  The ability to add or remove.
     * @param  bool  $granted  True to add it, false to remove it.
     * @param  string|null  $actor  The developer making the change, when a signed-in one is.
     * @return int How many live session tokens were rewritten.
     */
    public function setAbility(Installation $installation, Ability $ability, bool $granted, ?string $actor = null): int
    {
        return DB::transaction(function () use ($installation, $ability, $granted, $actor): int {
            // Held for the length of the transaction, so a session starting concurrently waits
            // rather than minting a token from the abilities as they were a moment ago
            $installation = Installation::query()
                ->whereKey($installation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $held = $installation->abilities();

            // **One `array_values` over both branches, and the shape is deliberate.** Written as
            // one per branch, the granting side's was an equivalent mutant: `$held` is a list, so
            // the spread is a list, and `array_unique` can only drop the element just appended --
            // the highest key -- leaving `0..n-1` either way. Measured: unwrapping it left the
            // whole suite green AND `composer analyse` clean, so neither gate could tell.
            //
            // The plugin's per-line ignore marker did not suppress it either: the marker stops
            // traversal of a node's CHILDREN, and `UnwrapArrayValues` targets the annotated node
            // itself, so `leaveNode()` still ran -- measured, the survivor count did not move.
            // `adversarial-review` says to prefer killing or restructuring over a trailing marker,
            // whose line map depends on the checkout's line endings under Windows PHP, and CI runs
            // Windows. Hoisting the call leaves one, which the removing branch makes killable:
            // `array_filter` preserves keys, so dropping it writes a JSON object instead of an
            // array.
            $abilities = array_values($granted
                ? array_unique([...$held, $ability->value])
                : array_filter($held, static fn (string $current): bool => $current !== $ability->value));

            // Nothing changed means nothing happened, and an event saying otherwise is noise in
            // the one feed an authorization change has to be legible in. `array_values` on both
            // sides, because the comparison is about membership and order is an artifact of when
            // each was granted.
            if ($abilities === $held) {
                return 0;
            }

            $installation->forceFill(['granted_abilities' => $abilities])->save();

            // Recorded before the tokens are rewritten, for the lock order `revoke()` records
            $this->record(
                $installation,
                $granted ? FleetEventType::InstallationAbilityGranted : FleetEventType::InstallationAbilityRevoked,
                $granted ? 'was granted '.$ability->value : 'lost '.$ability->value,
                ['ability' => $ability->value],
                $actor
            );

            $rewritten = 0;

            foreach ($this->sessionsOf($installation) as $session) {
                foreach ($session->tokens()->get() as $token) {
                    $token->forceFill(['abilities' => $abilities])->save();

                    $rewritten++;
                }
            }

            return $rewritten;
        });
    }

    /**
     * Record one administrative change against an installation.
     *
     * The body names the installation the way the rest of the feed names a machine, so a reader
     * scanning it sees an authorization change in the same vocabulary as an enrollment. Both parts
     * are charset-limited at the edge by `MachineIdentity` and together cannot approach
     * `FleetEvent::MAX_BODY`.
     *
     * @param  Installation  $installation  The installation that changed.
     * @param  FleetEventType  $type  What changed.
     * @param  string  $happened  What happened to it, as a predicate.
     * @param  array<string, mixed>  $meta  Anything beyond the installation's own id.
     * @param  string|null  $actor  The developer responsible, when a signed-in one is.
     */
    private function record(
        Installation $installation,
        FleetEventType $type,
        string $happened,
        array $meta,
        ?string $actor
    ): void {
        $this->events->record(
            $type,

            // No session: the change was made by a developer, through the dashboard or the
            // console, rather than by a process the fleet knows about
            null,
            sprintf('%s on %s %s.', $installation->harness, $installation->machine_label, $happened),
            ['installation_id' => $installation->id, ...$meta],

            // Both, and they are different people: the event is ABOUT this installation's owner
            // and was DONE by the admin. Before #115 only the admin was recorded, in the column
            // #29's visibility rule reads.
            actor: $actor,
            subject: $installation->user_id
        );
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
