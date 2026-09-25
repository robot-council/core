<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RobotCouncil\Support\BacklogFetcher;
use Throwable;

/**
 * Fetch each lane board repository's open-issue count through the GitHub App (#383).
 *
 * Scheduled every five minutes, in the background and never overlapping itself. It exits zero
 * whatever happened: a repository it could not read reads unreadable on the board and is named by
 * `robot-council:doctor`, and a failing exit code would only add a second, less specific report of
 * the same thing to the scheduler's output.
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
        try {
            $tally = $fetcher->run();
        } catch (Throwable $throwable) {
            // Anything the fetcher did not catch itself -- the board read, say, on a database that
            // is down. The class alone, since a message can carry a URL; and still zero, because
            // a display that could not refresh is not a scheduler failure
            Log::warning('robot-council could not fetch backlog counts.', ['exception' => $throwable::class]);

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Read %d count(s); %d could not be read.', $tally['read'], $tally['failed']));

        return self::SUCCESS;
    }
}
