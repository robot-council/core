<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RobotCouncil\Support\BacklogFetcher;

/**
 * Fetch each lane board repository's open-issue count through the GitHub App (#383).
 *
 * Scheduled every five minutes. It exits zero whatever GitHub answered: a repository it could not
 * read reads unreadable on the board and is named by `robot-council:doctor`, and a failing exit code
 * would only add a second, less specific report of the same thing to the scheduler's output.
 */
#[Description('Fetch the open-issue count of each repository on the lane board through the GitHub App')]
#[Signature('robot-council:backlog-fetch')]
final class FetchBacklogCommand extends Command
{
    /**
     * Fetch the counts.
     *
     * @param  BacklogFetcher  $fetcher  The fetch.
     * @return int The command's exit code.
     */
    public function handle(BacklogFetcher $fetcher): int
    {
        $tally = $fetcher->run();

        $this->components->info(sprintf('Read %d count(s); %d could not be read.', $tally['read'], $tally['failed']));

        return self::SUCCESS;
    }
}
