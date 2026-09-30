<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Access\AccessList;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Models\GithubIdentity;

/**
 * GitHub accounts looked up by ID or by login, and the logins remembered for the Access page (#484).
 *
 * **Display and an administrator's confirmation, never an access decision.** `Access\Allowlist`
 * reads no column this class writes: an allowlisted ID admits whatever this table says about it,
 * and a stale or wrong login here changes what a page shows and nothing else. Adding by login is
 * resolved here to an ID the administrator then confirms, and the ID is what is stored.
 *
 * **Reading is free; asking is not.** `logins()` reads only the table, so rendering the page makes
 * no request. Requests happen when an administrator looks an account up, and in the scheduled
 * `refresh()`, which asks about an ID only once its last answer has aged: a day for a login, an
 * hour for an ID GitHub could not name.
 *
 * **Every request borrows an installation of the App on an account the package already fetches
 * counts for**: a lane board repository's owner, or one the fetch history names, so a quiet fleet
 * with no live lane can still look accounts up. A profile needs no permission, so the token is the
 * count's own. Kept separate from the Access page so that #410's avatars can reuse the lookup.
 */
final class GitHubAccounts
{
    /**
     * How long a login GitHub gave is trusted before it is asked about again, in hours.
     */
    public const int FRESH_FOR_HOURS = 24;

    /**
     * How long an ID GitHub could not name waits before it is asked about again, in minutes.
     */
    public const int RETRY_AFTER_MINUTES = 60;

    /**
     * How many IDs one scheduled run asks about at most.
     */
    public const int MAX_PER_RUN = 50;

    /**
     * The installation this instance's requests borrow, once found.
     */
    private ?int $installation = null;

    /**
     * @param  GitHubAppKey  $key  Whether an App is configured at all.
     * @param  GitHubApp  $github  The requests.
     * @param  LaneBoard  $board  The repositories with a meter now.
     * @param  Allowlist  $allowlist  The environment's entries, whose IDs the refresh covers too.
     */
    public function __construct(
        private readonly GitHubAppKey $key,
        private readonly GitHubApp $github,
        private readonly LaneBoard $board,
        private readonly Allowlist $allowlist
    ) {}

    /**
     * The account with a numeric user ID, asked of GitHub now, and remembered.
     *
     * @param  int  $githubId  The ID.
     * @return GitHubAccount|null The account, or null when GitHub says none has that ID.
     *
     * @throws GitHubRefusal When GitHub could not be asked or did not answer with a profile.
     */
    public function byId(int $githubId): ?GitHubAccount
    {
        $account = $this->github->accountById($githubId, $this->token());

        $this->remember($githubId, $account);

        return $account;
    }

    /**
     * The account with a login, asked of GitHub now, and remembered under its ID.
     *
     * @param  string  $login  The login, which must match `LaneHolds::LOGIN`.
     * @return GitHubAccount|null The account, or null when GitHub says none has that login.
     *
     * @throws GitHubRefusal When GitHub could not be asked or did not answer with a profile.
     */
    public function byLogin(string $login): ?GitHubAccount
    {
        $account = $this->github->accountByLogin($login, $this->token());

        if ($account instanceof GitHubAccount) {
            $this->remember($account->id, $account);
        }

        return $account;
    }

    /**
     * The logins remembered for some IDs, read from the table alone.
     *
     * @param  list<int>  $githubIds  The IDs.
     * @return array<int, string> Logins by ID, for the IDs GitHub named.
     */
    public function logins(array $githubIds): array
    {
        if ($githubIds === []) {
            return [];
        }

        $logins = [];

        foreach (DB::table('robot_council_github_accounts')->whereIn('github_id', array_values(array_unique($githubIds)))->whereNotNull('login')->get(['github_id', 'login']) as $row) {
            // Checked again on the way out, since a host could write the table directly
            if (is_numeric($row->github_id) && \is_string($row->login) && preg_match(LaneHolds::LOGIN, $row->login) === 1) {
                $logins[(int) $row->github_id] = $row->login;
            }
        }

        return $logins;
    }

    /**
     * Ask again about every allowlisted ID that has not signed in and whose last answer has aged.
     *
     * **A failed request keeps the login already remembered** and only moves `checked_at`, so an
     * unreachable GitHub leaves the page as it was rather than blanking it. A failure every later
     * request would share -- no App, no installation, unreachable, rate limited -- ends the run.
     *
     * @return array{read: int, failed: int} How many IDs were answered, and how many were not.
     */
    public function refresh(): array
    {
        $tally = ['read' => 0, 'failed' => 0];

        if (! $this->key->configured()) {
            return $tally;
        }

        foreach ($this->due() as $githubId) {
            try {
                $this->byId($githubId);
                $tally['read']++;
            } catch (GitHubRefusal $gitHubRefusal) {
                $this->checked($githubId);
                $tally['failed']++;

                if (\in_array($gitHubRefusal->outcome, [BacklogFetchOutcome::Unreachable, BacklogFetchOutcome::RateLimited, BacklogFetchOutcome::KeyUnusable, BacklogFetchOutcome::NoInstallation], true)) {
                    break;
                }
            }
        }

        return $tally;
    }

    /**
     * Why a lookup did not answer, in words a page can show after "because".
     *
     * @param  GitHubRefusal  $refusal  What went wrong.
     * @return string The reason.
     */
    public static function reason(GitHubRefusal $refusal): string
    {
        return match ($refusal->outcome) {
            BacklogFetchOutcome::Unreachable => 'GitHub could not be reached',
            BacklogFetchOutcome::RateLimited => 'GitHub is limiting requests just now',
            BacklogFetchOutcome::KeyUnusable, BacklogFetchOutcome::NoInstallation => 'the GitHub App is not set up for looking accounts up',
            default => 'GitHub did not answer the lookup',
        };
    }

    /**
     * The allowlisted IDs to ask about this run, the longest unasked first.
     *
     * @return list<int> The IDs.
     */
    private function due(): array
    {
        $ids = [];

        foreach (AccessList::cases() as $list) {
            array_push($ids, ...$this->allowlist->configured($list));
        }

        foreach (DB::table('robot_council_allowlist_entries')->pluck('github_id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        // A signed-in account's login comes from its own sign-in, which this never overrides
        $signedIn = GithubIdentity::query()->whereIn('github_id', $ids)->pluck('github_id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();
        $ids = array_values(array_diff(array_unique($ids), $signedIn));

        if ($ids === []) {
            return [];
        }

        $now = CarbonImmutable::now();
        $last = [];

        foreach (DB::table('robot_council_github_accounts')->whereIn('github_id', $ids)->get(['github_id', 'login', 'checked_at']) as $row) {
            if (is_numeric($row->github_id) && \is_string($row->checked_at)) {
                $last[(int) $row->github_id] = [CarbonImmutable::parse($row->checked_at), $row->login !== null];
            }
        }

        $due = [];

        foreach ($ids as $id) {
            if (! isset($last[$id])) {
                // Never asked: first, ahead of every aged answer
                $due[$id] = 0;

                continue;
            }

            [$checkedAt, $named] = $last[$id];
            $agedAt = $named ? $checkedAt->addHours(self::FRESH_FOR_HOURS) : $checkedAt->addMinutes(self::RETRY_AFTER_MINUTES);

            if ($agedAt->lessThanOrEqualTo($now)) {
                $due[$id] = $checkedAt->getTimestamp();
            }
        }

        asort($due);

        return \array_slice(array_keys($due), 0, self::MAX_PER_RUN);
    }

    /**
     * An installation token, from the first owner the package fetches counts for that has the App.
     *
     * @return string The token.
     *
     * @throws GitHubRefusal When no App is configured, no owner has it installed, or GitHub refused.
     */
    private function token(): string
    {
        if (! $this->key->configured()) {
            throw new GitHubRefusal(BacklogFetchOutcome::KeyUnusable);
        }

        if ($this->installation === null) {
            foreach ($this->owners() as $owner) {
                $this->installation = $this->github->installation($owner);

                if ($this->installation !== null) {
                    break;
                }
            }
        }

        if ($this->installation === null) {
            throw new GitHubRefusal(BacklogFetchOutcome::NoInstallation);
        }

        return $this->github->token($this->installation);
    }

    /**
     * The accounts whose installation a lookup may borrow: the board's owners, then the fetch
     * history's, each once.
     *
     * @return list<string> Logins, lower-cased.
     */
    private function owners(): array
    {
        $repositories = $this->board->repositories();

        foreach (DB::table('robot_council_backlog_fetches')->orderBy('repository')->pluck('repository') as $repository) {
            if (\is_string($repository)) {
                $repositories[] = $repository;
            }
        }

        $owners = [];

        foreach ($repositories as $repository) {
            $owner = mb_strtolower(explode('/', $repository, 2)[0]);

            if (preg_match(GitHubApp::OWNER, $owner) === 1) {
                $owners[$owner] = true;
            }
        }

        return array_keys($owners);
    }

    /**
     * Remember what GitHub said about an ID.
     *
     * @param  int  $githubId  The ID.
     * @param  GitHubAccount|null  $account  The account, or null when GitHub said none has it.
     */
    private function remember(int $githubId, ?GitHubAccount $account): void
    {
        $now = CarbonImmutable::now();

        DB::table('robot_council_github_accounts')->upsert([[
            'github_id' => $githubId,
            'login' => $account?->login,
            'account_type' => $account?->type,
            'checked_at' => $now,
            'resolved_at' => $account instanceof GitHubAccount ? $now : null,
        ]], ['github_id'], ['login', 'account_type', 'checked_at', 'resolved_at']);
    }

    /**
     * Record an attempt that got no answer, keeping whatever was remembered.
     *
     * @param  int  $githubId  The ID.
     */
    private function checked(int $githubId): void
    {
        $now = CarbonImmutable::now();

        // Zero rows on MySQL can also mean "already this second", which the insert then ignores
        if (DB::table('robot_council_github_accounts')->where('github_id', $githubId)->update(['checked_at' => $now]) === 0) {
            DB::table('robot_council_github_accounts')->insertOrIgnore(['github_id' => $githubId, 'login' => null, 'account_type' => null, 'checked_at' => $now, 'resolved_at' => null]);
        }
    }
}
