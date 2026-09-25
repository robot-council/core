<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

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
 *
 * **Nothing one repository does ends the run, and nothing ends the command.** Anything thrown while
 * fetching one -- a cache store that is down, a database write that fails -- is recorded as `error`
 * and logged with its class alone, because an exception's message can carry a URL. Two things end a
 * run early, both to stop asking a GitHub that is not answering: a rate limit, and a second
 * consecutive request that got no answer at all.
 */
final class BacklogFetcher
{
    /**
     * The most repositories one run asks about.
     *
     * GitHub's search allows 30 authenticated requests a minute, and a run makes at most one search
     * per repository, so a run of this many stays under that however quickly it goes. A board wider
     * than this is reached in turn, oldest attempt first.
     */
    public const int MAX_PER_RUN = 25;

    /**
     * How many requests in a row may go unanswered before the run stops.
     *
     * Each can take the client's whole timeout, so a GitHub that is down would otherwise hold a run
     * for minutes; the next run tries again.
     */
    public const int MAX_CONSECUTIVE_UNREACHABLE = 2;

    /**
     * Each owner's installation this run, by lower-cased login: its id, null for none, or the
     * refusal that looking it up met. A transient failure is not kept, so the next repository asks
     * again.
     *
     * @var array<string, int|GitHubRefusal|null>
     */
    private array $installations = [];

    /**
     * Each installation whose token could not be minted this run, with why. Kept so a suspended
     * installation is asked once a run rather than once for each of its repositories.
     *
     * @var array<int, GitHubRefusal>
     */
    private array $mintRefusals = [];

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

        // What each repository's previous attempt was, so an unchanged state is not logged again
        $previous = $this->fetches->latest($repositories);

        $this->installations = [];
        $this->mintRefusals = [];
        $unanswered = 0;

        foreach ($repositories as $repository) {
            try {
                $refusal = $this->fetch($repository);

                // Read and stored
                if (! $refusal instanceof GitHubRefusal) {
                    $tally['read']++;
                    $unanswered = 0;

                    continue;
                }

                $tally['failed']++;
                $this->fetches->record($repository, $refusal->outcome, $refusal->status);

                // A missing installation is a standing state, which doctor reports; it is logged when
                // it begins rather than every five minutes while it lasts
                if ($refusal->outcome !== BacklogFetchOutcome::NoInstallation || ($previous[$repository]['outcome'] ?? null) !== BacklogFetchOutcome::NoInstallation) {
                    self::warn($repository, $refusal);
                }
            } catch (Throwable $throwable) {
                $tally['failed']++;
                $unanswered = 0;
                $this->failed($repository, $throwable);

                continue;
            }

            // A rate limit stops the run rather than spending the rest of it being refused; the
            // repositories not reached are the first tried next time
            if ($refusal->outcome === BacklogFetchOutcome::RateLimited) {
                break;
            }

            // So does a GitHub that has not answered twice running
            $unanswered = $refusal->outcome === BacklogFetchOutcome::Unreachable ? $unanswered + 1 : 0;

            if ($unanswered >= self::MAX_CONSECUTIVE_UNREACHABLE) {
                break;
            }
        }

        return $tally;
    }

    /**
     * Fetch one repository's count, and store it when there is one.
     *
     * @param  string  $repository  `owner/name`.
     * @return GitHubRefusal|null Why there is no count, or null when one was stored.
     */
    private function fetch(string $repository): ?GitHubRefusal
    {
        $owner = explode('/', $repository, 2)[0];
        $key = mb_strtolower($owner);

        // The owner's installation, looked up once a run
        if (! \array_key_exists($key, $this->installations)) {
            try {
                $this->installations[$key] = $this->github->installation($owner);
            } catch (GitHubRefusal $gitHubRefusal) {
                if (! $gitHubRefusal->outcome->transient()) {
                    $this->installations[$key] = $gitHubRefusal;
                }

                return $gitHubRefusal;
            }
        }

        $installation = $this->installations[$key];

        if ($installation instanceof GitHubRefusal) {
            return $installation;
        }

        // No installation on its owner: no count request, and the meter reads unreadable
        if ($installation === null) {
            return new GitHubRefusal(BacklogFetchOutcome::NoInstallation);
        }

        // Its token, unless minting one already failed this run
        if (isset($this->mintRefusals[$installation])) {
            return $this->mintRefusals[$installation];
        }

        try {
            $token = $this->github->token($installation);
        } catch (GitHubRefusal $gitHubRefusal) {
            if (! $gitHubRefusal->outcome->transient()) {
                $this->mintRefusals[$installation] = $gitHubRefusal;
            }

            return $gitHubRefusal;
        }

        try {
            $count = $this->github->openIssues($repository, $token);
        } catch (GitHubRefusal $gitHubRefusal) {
            // A token GitHub no longer accepts is dropped, so the next run mints a fresh one
            // rather than presenting the rejected one until it would have expired
            if ($gitHubRefusal->status === 401) {
                $this->github->forgetToken($installation);
            }

            return $gitHubRefusal;
        }

        $this->backlog->record($repository, $count);
        $this->fetches->record($repository, BacklogFetchOutcome::Read, 200);

        return null;
    }

    /**
     * Record and log something thrown while fetching one repository.
     *
     * @param  string  $repository  The repository.
     * @param  Throwable  $throwable  What was thrown.
     */
    private function failed(string $repository, Throwable $throwable): void
    {
        // The class and never the message, which can carry a URL or a query
        Log::warning('robot-council could not read a backlog count: something failed while fetching it.', [
            'repository' => $repository,
            'exception' => $throwable::class,
        ]);

        // Recorded when the database allows; the failure may have been the database
        try {
            $this->fetches->record($repository, BacklogFetchOutcome::Error, null);
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Log a failure with the repository and its status, and nothing else.
     *
     * @param  string  $repository  The repository.
     * @param  GitHubRefusal  $refusal  The failure.
     */
    private static function warn(string $repository, GitHubRefusal $refusal): void
    {
        Log::warning('robot-council could not read a backlog count from GitHub.', [
            'repository' => $repository,
            'outcome' => $refusal->outcome->value,
            'status' => $refusal->status,
        ]);
    }
}
