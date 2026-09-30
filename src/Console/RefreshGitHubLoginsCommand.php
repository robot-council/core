<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RobotCouncil\Support\GitHubAccounts;
use Throwable;

/**
 * Ask GitHub again for the login of each allowlisted account that has not signed in (#484).
 *
 * Scheduled every fifteen minutes, in the background and never overlapping itself, and it asks
 * about an ID only once its last answer has aged, so most runs make no request. It exits zero
 * whatever happened, for `FetchBacklogCommand`'s reason: a login that could not be read leaves the
 * Access page showing what it showed before, and that is not a scheduler failure.
 */
#[Description('Look up the GitHub login of each allowlisted account that has not signed in yet')]
#[Signature('robot-council:refresh-github-logins')]
final class RefreshGitHubLoginsCommand extends Command
{
    /**
     * Refresh the logins.
     *
     * @param  GitHubAccounts  $accounts  The lookup.
     * @return int The command's exit code.
     */
    public function handle(GitHubAccounts $accounts): int
    {
        try {
            $tally = $accounts->refresh();
        } catch (Throwable $throwable) {
            // The class alone, since a message can carry a URL
            Log::warning('robot-council could not refresh GitHub logins.', ['exception' => $throwable::class]);

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Read %d login(s); %d could not be read.', $tally['read'], $tally['failed']));

        return self::SUCCESS;
    }
}
