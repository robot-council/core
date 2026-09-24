<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Models\HoldParty;
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Models\LaneHold;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;

/**
 * A coordinator's record of why a lane is idle on purpose (#334).
 *
 * **A hold names a party and a reason from a closed set, and nothing else.** #314 found the board
 * drifting on free-text notes, so `On what` for a held lane is always `<party> — <what>`: the party a
 * developer known to the fleet or a repository-qualified ticket, the `<what>` a `Models\HoldReason`.
 * A free-text note, a bare `#N`, and a developer the fleet has never seen are all refused, here as
 * well as at the edge, because this is a public method on a class a host can call.
 *
 * **A lane holding work cannot be held.** The board renders any lane with a ticket as `Working`, so a
 * hold on one would be a statement nobody could see. The lane's session row is locked first -- the
 * package's lock order -- which serializes this against a claim or a placement of the same lane, and
 * those clear the hold in their own transaction (`Support\Tasks`).
 */
final class LaneHolds
{
    /**
     * A GitHub login: letters, digits and single hyphens, not leading or trailing, 39 at most.
     */
    public const string LOGIN = '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/D';

    /**
     * Record why a lane is idle, replacing any hold it had.
     *
     * @param  AgentSession  $coordinator  The coordinator recording it.
     * @param  int  $laneId  The lane's session.
     * @param  string  $party  A GitHub login, or `owner/name#N`.
     * @param  HoldReason  $reason  What the lane waits on the party for.
     * @return Outcome Applied; NotFound for no such lane; Conflict for a lane that has gone or holds work.
     *
     * @throws InvalidArgumentException When the party is not one the fleet can name, or the reason
     *                                  belongs to the other kind of party.
     */
    public function hold(AgentSession $coordinator, int $laneId, string $party, HoldReason $reason): Outcome
    {
        [$kind, $named] = $this->party($party);

        if ($reason->party() !== $kind) {
            throw new InvalidArgumentException(sprintf('`%s` is a reason for a %s, and this names a %s.', $reason->value, $reason->party()->value, $kind->value));
        }

        return DB::transaction(function () use ($coordinator, $laneId, $kind, $named, $reason): Outcome {
            $lane = AgentSession::query()->whereKey($laneId)->lockForUpdate()->first();

            if (! $lane instanceof AgentSession) {
                return Outcome::NotFound;
            }

            if ($lane->hasGone() || $this->holdsWork($laneId)) {
                return Outcome::Conflict;
            }

            LaneHold::query()->whereKey($laneId)->delete();
            LaneHold::query()->insert([
                'agent_session_id' => $laneId,
                'party_kind' => $kind->value,
                'party' => $named,
                'reason' => $reason->value,
                'held_by' => $coordinator->getKey(),
                'held_at' => Carbon::now(),
            ]);

            return Outcome::Applied;
        });
    }

    /**
     * Lift a lane's hold.
     *
     * @param  int  $laneId  The lane's session.
     * @return bool True when there was one.
     */
    public function clear(int $laneId): bool
    {
        return LaneHold::query()->whereKey($laneId)->delete() === 1;
    }

    /**
     * A lane's hold.
     *
     * @param  int  $laneId  The lane's session.
     * @return LaneHold|null The hold, or null.
     */
    public function of(int $laneId): ?LaneHold
    {
        return LaneHold::query()->find($laneId);
    }

    /**
     * Whether a lane holds a task.
     *
     * @param  int  $laneId  The lane's session.
     * @return bool True while it holds one.
     */
    private function holdsWork(int $laneId): bool
    {
        return Task::query()
            ->where('claimed_by', $laneId)
            ->whereIn('status', TaskStatus::values(TaskStatus::held()))
            ->exists();
    }

    /**
     * What a party names, refused when it names nothing the fleet can resolve.
     *
     * @param  string  $party  What the coordinator wrote.
     * @return array{HoldParty, string} The kind, and the party as stored -- a login as GitHub spells it.
     *
     * @throws InvalidArgumentException When it is neither.
     */
    private function party(string $party): array
    {
        if (preg_match(IssueReference::PATTERN, $party) === 1 || preg_match('/^#?[0-9]+$/D', $party) === 1) {
            // `IssueReference` refuses a bare `#N` with the message saying why
            IssueReference::ensure($party);

            return [HoldParty::Ticket, $party];
        }

        if (preg_match(self::LOGIN, $party) !== 1) {
            throw new InvalidArgumentException('A hold names a developer by GitHub login, or a ticket as owner/name#N. A note is not a party.');
        }

        // GitHub logins compare without case, so `Octodev` finds `octodev`, and the login is stored
        // as the fleet knows it
        $known = GithubIdentity::query()
            ->whereRaw('lower(github_login) = ?', [mb_strtolower($party)])
            ->value('github_login');

        if (! \is_string($known)) {
            throw new InvalidArgumentException(sprintf('No developer in this fleet signs in as `%s`.', $party));
        }

        return [HoldParty::Developer, $known];
    }
}
