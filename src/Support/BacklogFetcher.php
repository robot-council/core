<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Facades\Log;

/**
 * Fetch each board repository's open-issue count through the GitHub App, and store what it answers
 * (#383).
 *
 * **Failure is a missing reading, never a wrong one.** A repository GitHub refused, did not answer
 * for, or answered for with no usable count gets no reading, so its meter reads unreadable once its
 * last reading ages out, exactly as #339 built it to. What happened is kept in
 * `Support\BacklogFetches` for doctor, and logged at `warning` with the repository and the status
 * and nothing else.
 *
 * **With no App configured this makes no request at all**, so a host that never registers one keeps
 * the meters exactly as they were: session reports alone.
 */
final class BacklogFetcher
{
    /**
     * The most repositories one run asks about.
     *
     * GitHub's search allows 30 authenticated requests a minute, and a run makes one per repository
     * in about that long. A board wider than this is reached in turn, oldest attempt first.
     */
    public const int MAX_PER_RUN = 25;

    /**
     * @param  GitHubAppKey  $key  Whether an App is configured.
     * @param  GitHubApp  $github  The requests.
     * @param  Backlog  $backlog  Where a count is stored.
     * @param  BacklogFetches  $fetches  Where each attempt's outcome is stored.
     * @param  LaneBoard  $board  Which repositories to ask about.
     */
    public function __construct(
        private readonly GitHubAppKey $key,
        private readonly GitHubApp $github,
        private readonly Backlog $backlog,
        private readonly BacklogFetches $fetches,
        private readonly LaneBoard $board
    ) {}

    /**
     * Fetch the counts.
     *
     * @return array{read: int, failed: int} How many repositories got a reading, and how many did not.
     */
    public function run(): array
    {
        $tally = ['read' => 0, 'failed' => 0];

        // Nothing configured: no request of any kind, not even the board read
        if (! $this->key->configured()) {
            return $tally;
        }

        $repositories = \array_slice($this->fetches->oldestFirst($this->board->repositories()), 0, self::MAX_PER_RUN);

        if ($repositories === []) {
            return $tally;
        }

        // One request for every installation, so each owner's is known before any count is asked for
        try {
            $installations = $this->github->installations();
        } catch (GitHubRefusal $gitHubRefusal) {
            // Nothing can be counted without it, so every repository is recorded with the same cause
            foreach ($repositories as $repository) {
                $this->fetches->record($repository, $gitHubRefusal->outcome, $gitHubRefusal->status);
            }

            self::warn(null, $gitHubRefusal);

            return ['read' => 0, 'failed' => \count($repositories)];
        }

        foreach ($repositories as $repository) {
            $installation = $installations[mb_strtolower(explode('/', $repository, 2)[0])] ?? null;

            // No installation on its owner: no count request, and the meter reads unreadable
            if ($installation === null) {
                $this->fetches->record($repository, BacklogFetchOutcome::NoInstallation, null);
                self::warn($repository, new GitHubRefusal(BacklogFetchOutcome::NoInstallation));
                $tally['failed']++;

                continue;
            }

            try {
                $count = $this->github->openIssues($repository, $this->github->token($installation));
            } catch (GitHubRefusal $refusal) {
                // A token GitHub no longer accepts is dropped, so the next run mints a fresh one
                // rather than presenting the rejected one until it would have expired
                if ($refusal->status === 401) {
                    $this->github->forgetToken($installation);
                }

                $this->fetches->record($repository, $refusal->outcome, $refusal->status);
                self::warn($repository, $refusal);
                $tally['failed']++;

                // A rate limit stops the run rather than spending the rest of it being refused; the
                // repositories not reached are the first tried next time
                if ($refusal->outcome === BacklogFetchOutcome::RateLimited) {
                    break;
                }

                continue;
            }

            $this->backlog->record($repository, $count);
            $this->fetches->record($repository, BacklogFetchOutcome::Read, 200);
            $tally['read']++;
        }

        return $tally;
    }

    /**
     * Log a failure with the repository and its status, and nothing else.
     *
     * @param  string|null  $repository  The repository, or null when listing the App's
     *                                   installations failed.
     * @param  GitHubRefusal  $refusal  The failure.
     */
    private static function warn(?string $repository, GitHubRefusal $refusal): void
    {
        Log::warning(
            $repository === null
                ? "robot-council could not list the GitHub App's installations, so no backlog count was read."
                : 'robot-council could not read a backlog count from GitHub.',
            [
                'repository' => $repository,
                'outcome' => $refusal->outcome->value,
                'status' => $refusal->status,
            ]
        );
    }
}
