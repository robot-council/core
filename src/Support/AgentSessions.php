<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;

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
     * @param  Installation  $installation  The installation the process is running under.
     * @param  string|null  $projectId  The repository or workspace the process named, if any.
     * @return IssuedCredential<AgentSession> The session and its plaintext token.
     */
    public function start(Installation $installation, ?string $projectId): IssuedCredential
    {
        // Bounded here as well as at the endpoint, because this is a public method a host may call
        // directly and the value reaches other developers' agents through the enrollment event
        ProjectId::ensure($projectId);

        return DB::transaction(function () use ($installation, $projectId): IssuedCredential {
            $current = $this->locked($installation);

            $abilities = $current->abilities();

            $session = AgentSession::query()->create([
                'installation_id' => $installation->getKey(),

                // Copied from the installation rather than taken from the request, so a process
                // cannot start a session belonging to another developer
                'user_id' => $current->user_id,
                'status' => AgentSessionStatus::Active,
                'last_seen_at' => PresenceClock::now(),
                'project_id' => $projectId,
            ]);

            // In the same transaction as the session it describes, so a failure here leaves
            // neither the session nor a feed entry claiming one exists
            $enrolled = $this->events->record(
                FleetEventType::SessionStarted,
                $session,
                sprintf('%s on %s started a session.', $current->harness, $current->machine_label),
                ['installation_id' => $current->id, 'project_id' => $projectId]
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
            $current = $this->locked($installation);

            $abilities = $current->abilities();

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
     * Issue one session token.
     *
     * The abilities are read from the installation on every issue rather than copied at enrollment,
     * so one an admin revoked is gone from the next token even though the row was written weeks ago.
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
