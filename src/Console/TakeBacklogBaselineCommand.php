<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RobotCouncil\Support\Backlog;

/**
 * Take today's backlog baselines once local time passes 08:00 (#339).
 *
 * Scheduled every five minutes and idempotent: a repository with a baseline for today is skipped,
 * so a run before 08:00 takes none and a run after takes each one once. `Support\Backlog` records
 * why this is safer than one run scheduled at 08:00.
 */
#[Description('Take the start-of-day backlog baseline for each repository, once local time passes 08:00')]
#[Signature('robot-council:backlog-baseline')]
final class TakeBacklogBaselineCommand extends Command
{
    /**
     * Take the baselines.
     *
     * @param  Backlog  $backlog  The backlog store.
     * @return int The command's exit code.
     */
    public function handle(Backlog $backlog): int
    {
        $this->components->info(sprintf('Took %d baseline(s).', $backlog->takeBaselines(Carbon::now())));

        return self::SUCCESS;
    }
}
