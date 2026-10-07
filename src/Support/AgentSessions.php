<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\HoldParty;
use RobotCouncil\Models\Installation;
use RobotCouncil\Models\LaneHold;

/**
 * Starting and renewing the session one agent process runs under.
 *
 * A session token is short-lived on purpose: the helper renews it without a restart and without a
 * human, so the window a leaked one is useful in is minutes rather than the installation's month.
 *
 * Ending a session is `SessionPresence`'s, because ending it and finding it gone are the same
 * transition and have to be written in one place: each is conditional on the row not already being
 * `gone`, which is what makes `Events\SessionGone` fire once for a session however it ended.
 */
final class AgentSessions
{
    /**
     * The name Sanctum records against a session token.
     */
    public const string TOKEN_NAME = 'robot-council agent session';

    /**
     * @param  Credentials  $credentials  The configured lifetimes.
     * @param  FleetEvents  $events  The change feed.
     * @param  SessionPresence  $presence  Where contact is recorded.
     * @param  FeedCursors  $cursors  Where each session's feed position is kept.
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly FleetEvents $events,
        private readonly SessionPresence $presence,
        private readonly FeedCursors $cursors
    ) {}

    /**
     * Start a session for one agent process, and issue its first token.
     *
     * **The legacy `project_id` label is not taken here.** `robot-council/core#285` dropped the
     * column, and a client that still sends one is translated where the request is read, by
     * `Http\Controllers\SessionStartController`. So this store speaks only the two fields the fleet
     * reads, and a host calling it directly gets no split it did not ask for.
     *
     * @param  Installation  $installation  The installation the process is running under.
     * @param  string|null  $repository  The repository the process is working in, as `owner/name`.
     * @param  string|null  $workLocation  Which checkout of it, as a conventional label.
     * @param  string|null  $osFamily  The OS family the bridge runs on, as `PHP_OS_FAMILY` (#351).
     * @param  string|null  $arch  The architecture it runs on.
     * @param  int|null  $capacity  How many tickets it declares it will hold at once (#409), or null
     *                              where it declared nothing and takes its seat's setting (#564).
     *                              Clamped to `Capacity::DEFAULT`..`Capacity::MAX` rather than
     *                              refused, since it is an ordinal; its seat applies on every read,
     *                              not here.
     * @param  bool  $ephemeral  Whether the fleet is not to be told the session exists (#424): it
     *                           records no `session.joined`, and nothing that lists sessions or
     *                           lanes shows it. Everything else it does is an ordinary session's.
     * @return IssuedCredential<AgentSession> The session and its plaintext token.
     */
    public function start(
        Installation $installation,
        ?string $repository = null,
        ?string $workLocation = null,
        ?string $osFamily = null,
        ?string $arch = null,
        ?int $capacity = null,
        bool $ephemeral = false
    ): IssuedCredential {
        // Bounded here as well as at the endpoint, because this is a public method a host may call
        // directly and the values reach other developers' agents through the enrollment event
        WorkIdentity::ensure($repository, $workLocation);
        Platform::ensure($osFamily, $arch);
        $capacity = $capacity === null ? null : Capacity::clamp($capacity);

        return DB::transaction(function () use ($installation, $repository, $workLocation, $osFamily, $arch, $capacity, $ephemeral): IssuedCredential {
            $current = $this->locked($installation);

            // **Every session starts as `build`, and that is the decision rather than a default
            // nobody chose.** `robot-council/core#221` derived the role from the installation's
            // abilities as a compatibility measure, which left the defect the epic was filed about
            // standing: one machine runs seven checkouts from one installation, and a derived role
            // gives `coordinator` to all seven. `robot-council/core#222` removed the derivation, so
            // the one checkout that should direct asks and an administrator decides -- measured at
            // roughly eight approvals a day on the deployed fleet, which is the cost that decision
            // was taken with its eyes open about.
            $role = Role::Build;

            $abilities = $role->tokenAbilities();

            $session = AgentSession::query()->create([
                'installation_id' => $installation->getKey(),

                // Copied from the installation rather than taken from the request, so a process
                // cannot start a session belonging to another developer
                'user_id' => $current->user_id,
                'status' => AgentSessionStatus::Active,
                'role' => $role,
                'last_seen_at' => PresenceClock::now(),
                'repository' => $repository,
                'work_location' => $workLocation,
                'os_family' => $osFamily,
                'arch' => $arch,

                // What it declared, not what is in effect, and null where it declared nothing: the
                // seat is read on every use, so a developer raising it reaches this session without
                // a restart (`Capacity`, #564)
                'declared_capacity' => $capacity,
                'ephemeral' => $ephemeral,
            ]);

            // **An ephemeral session announces nothing, so it has no event to start from** (#424).
            // Its cursor is the feed's head read under the same sentinel lock `record()` takes, so
            // the guarantee below holds unchanged: every id at or below it had committed, and
            // everything written after this start is above it. A reader starting there sees each
            // later event once and nothing from before -- the same position a normal session is
            // given, minus the join it would not have seen anyway.
            //
            // Taken after the insert, which is the lock order `record()` keeps: this transaction
            // holds the installation and the session row, then the sentinel.
            if ($ephemeral) {
                $head = $this->events->head();

                $this->cursors->seed($session, $head);

                return new IssuedCredential(
                    $session,
                    $this->issueToken($session, $abilities),
                    $abilities,
                    $head,
                );
            }

            // In the same transaction as the session it describes, so a failure here leaves
            // neither the session nor a feed entry claiming one exists
            $enrolled = $this->events->record(
                FleetEventType::SessionJoined,
                $session,
                sprintf('%s on %s started a session.', $current->harness, $current->machine_label),
                // Both fields ride the event, so a reader of the feed can group by repository
                // without parsing a label. Charset-limited by `Support\WorkIdentity` for the reason
                // the body is: every session in the fleet reads this, and event content is untrusted
                // input to something with shell access.
                [
                    'installation_id' => $current->id,
                    'repository' => $repository,
                    'work_location' => $workLocation,

                    // What a coordinator placing platform-bound work reads (#351), bounded by
                    // `Support\Platform` for the same reason
                    'os_family' => $osFamily,
                    'arch' => $arch,

                    // What it declared (#409), or null where it takes its seat's setting (#564). The
                    // number in effect is on `GET lanes`.
                    'declared_capacity' => $capacity,
                ]
            );

            // The enrollment event's own id is the cursor this session starts from, and it needs no
            // separate read of the feed's head. `FleetEvents::record()` holds the sentinel row lock
            // while it inserts, and that lock is transaction-scoped -- so every id below this one
            // belonged to a writer that held the lock before us and therefore committed before us.
            // A `MAX(id)` taken outside that lock could observe 6 committed while 5 was still in
            // flight, and a reader paging `id > cursor` would pass 6 and never see 5 again.
            //
            // Kept on the row as well as returned, so a process that loses it can ask for it back
            // (#86). Written after the event because the event needs the session's id, which does
            // not invert the lock order: this transaction has held an exclusive lock on the row
            // since it inserted it, so the update acquires nothing the sentinel was taken ahead of.
            $this->cursors->seed($session, $enrolled->id);

            return new IssuedCredential(
                $session,
                $this->issueToken($session, $abilities),
                $abilities,
                $enrolled->id,
            );
        });
    }

    /**
     * Replace a session's token with a fresh one, and refuse the old one from then on.
     *
     * @param  Installation  $installation  The installation the session belongs to.
     * @param  AgentSession  $session  The session to renew.
     * @return IssuedCredential<AgentSession> The session and its new plaintext token.
     */
    public function renew(Installation $installation, AgentSession $session): IssuedCredential
    {
        return DB::transaction(function () use ($installation, $session): IssuedCredential {
            // **Taken for the lock, not for the abilities, and it is still required.** It is the
            // first row in the package's lock order, so a renewal that reached the session row and
            // the token rows without holding it would invert the order `Installations::revoke()`
            // takes the same three in. It is also what serializes a renewal against an admin
            // demoting this session a moment earlier through `Support\RoleRequests`.
            $this->locked($installation);

            // Read from the ROW, inside the transaction, for the reason `locked()` records about
            // the installation: the instance this request arrived with was hydrated by the guard
            // before any of this ran. An administrator demoting this session through
            // `Support\RoleRequests` writes its `role` and re-mints its tokens in one transaction,
            // so a renewal that minted from the instance in hand would hand back the role the
            // administrator has just taken away, for another hour.
            $abilities = $this->roleOf($session)->tokenAbilities();

            // Contact before tokens, and through the presence store rather than beside it. Two
            // reasons, and both were bugs. The order is the package's lock order -- the session row
            // before `personal_access_tokens` -- and taking them the other way round here while
            // `SessionPresence` takes them this way is a deadlock between a renewal and the sweep
            // ending the same session, which is exactly the moment both run. And a renewal is
            // contact: a stale session whose bridge renews has to come back, which a bare write to
            // `last_seen_at` would not do -- it would leave a stale row with a fresh contact time,
            // which no sweep pass can reach again.
            $this->presence->sighted($session);

            $session->tokens()->delete();

            // The position the session has ACKNOWLEDGED, read back from the row -- not a fresh
            // one. A renewal must not move where the session reads: handing back the feed's head
            // would skip everything it had not read, and handing back its starting position would
            // replay everything since. Restating what it already holds is what lets a restarted
            // process recover instead of guessing (#86).
            return new IssuedCredential(
                $session,
                $this->issueToken($session, $abilities),
                $abilities,
                $this->cursors->of($session),
            );
        });
    }

    /**
     * Move a session to another work location or repository without leaving (#535).
     *
     * **Everything the session holds stays with it.** Its id, its role, its tasks, its locks and
     * its feed position are all keyed by the session, never by where it works, so this changes two
     * columns and nothing else. Every reader -- `sessions_list`, the lane board, the dashboard --
     * reads them off the row, so the new place shows on the next read. So does the seat: a seat is
     * matched by place on every read (`Seats::of()`), which means a session moved into a parked
     * seat is parked from then on, and its seat's cap is the one that applies.
     *
     * **Only the session itself can be moved, and the store has no other session to name.** It
     * takes the session the request authenticated as and nothing that identifies a target, so
     * neither edge can reach another session's row through it.
     *
     * **Refused with the reason `start()` gives, because it is the same check.** A host calling
     * this directly reaches `WorkIdentity::ensure()` as a join does, and the values go into the
     * feed every session reads.
     *
     * **The write is conditional on the row**, held from the read to the event, and a session that
     * has gone is refused rather than moved: its lane is over. A move to where it already is
     * changes nothing and records nothing, for the reason `RoleRequests::settle()` gives -- MySQL
     * counts a write of the same values as no row changed, where SQLite and Postgres count one.
     *
     * **A hold that names the repository it is leaving goes with the move.** A coordinator holds a
     * lane on `nothing_startable` naming the lane's own repository (`LaneHolds::hold()`), and a held
     * lane is never reported free, so a lane that moved to another repository would sit there
     * marked idle about the one it left, unseen by the signal that would have told its coordinator
     * it is free. A hold naming a developer, a ticket or a pull request is about that party, not
     * about where the lane works, and stays. Cleared as `Tasks::transition()` clears one: after the
     * session row and before the feed sentinel, and with no event of its own.
     *
     * An ephemeral session (#424) is moved without an event, since the fleet was never told it
     * exists.
     *
     * @param  AgentSession  $session  The session moving, as the request authenticated it.
     * @param  array<array-key, mixed>  $place  The fields to change, `repository` and
     *                                          `work_location`; a key that is absent is left as it
     *                                          is. Taken as any array and narrowed here, for the
     *                                          reason `WorkIdentity::ensure()` takes `mixed`.
     * @return Outcome `Applied` when the session is where it was asked to be, `NotFound` when its
     *                 row is gone, and `Conflict` when it has ended.
     *
     * @throws InvalidArgumentException When a value is outside what `WorkIdentity` admits or is
     *                                  null, or `$place` names neither field or any other key.
     */
    public function move(AgentSession $session, array $place): Outcome
    {
        $named = array_intersect_key($place, ['repository' => true, 'work_location' => true]);

        if ($named === []) {
            throw new InvalidArgumentException('A move names a repository, a work location, or both.');
        }

        // A misspelled key beside a real one would otherwise be dropped without a word
        if (\count($named) !== \count($place)) {
            throw new InvalidArgumentException('A move changes only a repository and a work location.');
        }

        WorkIdentity::ensure($named['repository'] ?? null, $named['work_location'] ?? null);

        // Null passes `ensure()`, which allows it at join, so it is refused here: a store that
        // cleared a field would do what neither the endpoint nor the tool lets a session do
        $changes = array_filter($named, \is_string(...));

        if (\count($changes) !== \count($named)) {
            throw new InvalidArgumentException('A move sets a repository or a work location, and does not clear one.');
        }

        return DB::transaction(function () use ($session, $changes): Outcome {
            $current = AgentSession::query()->whereKey($session->getKey())->lockForUpdate()->first();

            if (! $current instanceof AgentSession) {
                return Outcome::NotFound;
            }

            if ($current->hasGone()) {
                return Outcome::Conflict;
            }

            $from = ['repository' => $current->repository, 'work_location' => $current->work_location];
            $to = [
                'repository' => $changes['repository'] ?? $from['repository'],
                'work_location' => $changes['work_location'] ?? $from['work_location'],
            ];

            if ($to === $from) {
                return Outcome::Applied;
            }

            $changed = AgentSession::query()
                ->whereKey($current->getKey())
                ->where('status', '!=', AgentSessionStatus::Gone->value)
                ->update($to);

            // The row is held, so a gone status cannot arrive between the read above and this
            // write on one connection; the count is still the decision, as everywhere in the package
            if ($changed !== 1) {
                return Outcome::Conflict;
            }

            if ($to['repository'] !== $from['repository']) {
                LaneHold::query()
                    ->whereKey($current->getKey())
                    ->where('party_kind', HoldParty::Repository->value)
                    ->delete();
            }

            if (! $current->isEphemeral()) {
                // After the row, so the feed cannot describe a move that failed to write. Both
                // values ride the event for the reason `start()` puts them on `session.joined`: a
                // reader of the feed groups by them without parsing a sentence.
                $this->events->record(
                    FleetEventType::SessionMoved,
                    $current,
                    sprintf('session %d moved from %s to %s.', $current->id, self::place($from), self::place($to)),
                    [
                        'installation_id' => $current->installation_id,
                        'from_repository' => $from['repository'],
                        'from_work_location' => $from['work_location'],
                        'to_repository' => $to['repository'],
                        'to_work_location' => $to['work_location'],
                    ]
                );
            }

            return Outcome::Applied;
        });
    }

    /**
     * A place as the move event's sentence names it.
     *
     * @param  array{repository: string|null, work_location: string|null}  $place  The two fields.
     * @return string `owner/name at a`, or whichever half there is, or `nowhere`.
     */
    private static function place(array $place): string
    {
        return match (true) {
            $place['repository'] !== null && $place['work_location'] !== null => sprintf('%s at %s', $place['repository'], $place['work_location']),
            $place['repository'] !== null => $place['repository'],
            $place['work_location'] !== null => 'location '.$place['work_location'],
            default => 'nowhere',
        };
    }

    /**
     * Re-read the installation inside the transaction, holding its row.
     *
     * The instance a request arrives with was loaded by the guard before any of this ran, so its
     * abilities are whatever they were then. Without the lock, an admin revoking an ability can
     * commit between that load and this insert: the revoking command's own loop sees no session to
     * rewrite, the new token is minted from the stale attributes, and the operator is told the
     * revocation touched every live token while one carrying the revoked ability has just been
     * issued for the next hour.
     *
     * @param  Installation  $installation  The installation the request authenticated as.
     * @return Installation The row as it stands now, held until the transaction ends.
     */
    private function locked(Installation $installation): Installation
    {
        $current = Installation::query()->whereKey($installation->getKey())->lockForUpdate()->first();

        return $current instanceof Installation ? $current : $installation;
    }

    /**
     * Re-read a session's role inside the transaction, holding nothing further.
     *
     * The same shape as `locked()` and for the same reason -- the instance a request arrives with
     * is as stale as the guard that hydrated it -- without the row lock, which the installation
     * above already serializes every writer of this column against.
     *
     * Falls back to the instance rather than throwing when the row has gone, because a renewal for
     * a pruned session is an existing edge this method must not change the outcome of.
     *
     * @param  AgentSession  $session  The session being renewed.
     * @return Role The role as the row stands now.
     */
    private function roleOf(AgentSession $session): Role
    {
        $role = AgentSession::query()->whereKey($session->getKey())->value('role');

        return $role instanceof Role ? $role : $session->role;
    }

    /**
     * Issue one session token.
     *
     * The abilities are read from the session's ROLE on every issue rather than copied at
     * enrollment, so a demotion an admin made is gone from the next token even though the row was
     * written weeks ago.
     *
     * **That is narrower than it used to be, and the narrowing is worth stating.** Before
     * `robot-council/core#221` this read the installation's `granted_abilities` on every renewal,
     * so ANY writer of that column -- the console, the panel, host code, a seeder, a restore --
     * reached every live session within one token lifetime. Now only a write to the session's own
     * `role` does, and `Support\RoleRequests::settle()` is its only writer outside `start()`.
     * A host stripping `granted_abilities` with a raw query no longer reaches a running session at
     * all. `Support\Installations::revoke()` is what still ends one unconditionally.
     *
     * @param  AgentSession  $session  The session the token authenticates as.
     * @param  list<string>  $abilities  The abilities to mint it with.
     * @return string The plaintext token.
     */
    private function issueToken(AgentSession $session, array $abilities): string
    {
        return $session->createToken(
            self::TOKEN_NAME,
            $abilities,
            $this->credentials->sessionTokenExpiry()
        )->plainTextToken;
    }
}
