<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;

/**
 * Which pull request each gate is validating (#336).
 *
 * **Only a gate reports one, about itself.** A gate is a session in the `ci` role, and what it reports
 * is its own work, so the write names the session making it and nothing else; a build lane or a
 * coordinator is refused. A run ends when the gate clears it, or when GitHub reports the pull request
 * closed or merged (`Support\GitHubState`), so a gate that died mid-run does not leave a pull request
 * shown `running` once it has left the open set.
 */
final class GateRuns
{
    /**
     * Record that a gate has started validating a pull request, replacing whatever it ran before.
     *
     * @param  AgentSession  $gate  The gate session reporting it.
     * @param  string  $reference  The pull request, `owner/name#N`.
     * @return Outcome Applied, or Forbidden for a session that is not a gate.
     *
     * @throws InvalidArgumentException When the reference is not repository-qualified.
     */
    public function start(AgentSession $gate, string $reference): Outcome
    {
        IssueReference::ensure($reference);

        if ($gate->role !== Role::Ci) {
            return Outcome::Forbidden;
        }

        [$repository, $number] = explode('#', $reference, 2);

        DB::transaction(function () use ($gate, $repository, $number): void {
            DB::table('robot_council_gate_runs')->where('agent_session_id', $gate->getKey())->delete();
            DB::table('robot_council_gate_runs')->insert([
                'agent_session_id' => $gate->getKey(),
                'repository' => $repository,
                'number' => (int) $number,
                'started_at' => Carbon::now(),
            ]);
        });

        return Outcome::Applied;
    }

    /**
     * Clear a gate's run.
     *
     * @param  AgentSession  $gate  The gate.
     * @return bool True when it was running one.
     */
    public function clear(AgentSession $gate): bool
    {
        return DB::table('robot_council_gate_runs')->where('agent_session_id', $gate->getKey())->delete() > 0;
    }

    /**
     * Clear every run of a pull request that has left the open set.
     *
     * Compared without case, as GitHub names repositories: a gate may have reported the spelling
     * its checkout has.
     *
     * @param  string  $repository  The pull request's repository.
     * @param  int  $number  Its number.
     * @return int How many runs it ended.
     */
    public function endPullRequest(string $repository, int $number): int
    {
        return DB::table('robot_council_gate_runs')
            ->whereRaw('lower(repository) = ?', [mb_strtolower($repository)])
            ->where('number', $number)
            ->delete();
    }

    /**
     * Every run, by gate session.
     *
     * @param  list<int>  $gates  The sessions to read, or every one when empty.
     * @return array<int, string> The pull request each is running, `owner/name#N`, by session id.
     */
    public function running(array $gates = []): array
    {
        $runs = [];

        $rows = DB::table('robot_council_gate_runs')
            ->when($gates !== [], static fn ($query) => $query->whereIn('agent_session_id', $gates))
            ->get(['agent_session_id', 'repository', 'number']);

        foreach ($rows as $row) {
            if (is_numeric($row->agent_session_id) && \is_string($row->repository) && is_numeric($row->number)) {
                $runs[(int) $row->agent_session_id] = $row->repository.'#'.$row->number;
            }
        }

        return $runs;
    }
}
