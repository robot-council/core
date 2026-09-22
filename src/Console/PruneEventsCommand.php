<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\FleetEvents;

/**
 * Deletes fleet events past the configured retention.
 *
 * The service provider schedules it daily, and it is safe to run by hand at any time: it deletes
 * by age and takes no lock, so it cannot interfere with a writer or with a reader paging the feed.
 *
 * **A retention of zero keeps everything**, which is the setting for a host archiving on its own
 * terms. The command says so and exits rather than deleting nothing silently, because "pruned 0
 * events" and "pruning is switched off" are different states and only one of them wants looking at.
 */
#[Description('Delete robot-council fleet events past the configured retention')]
#[Signature('robot-council:prune-events')]
final class PruneEventsCommand extends Command
{
    /**
     * Delete the events past retention.
     *
     * @param  FleetEvents  $events  The change feed.
     * @param  Credentials  $credentials  The configured retention.
     * @return int The command's exit code.
     */
    public function handle(FleetEvents $events, Credentials $credentials): int
    {
        $days = $credentials->eventRetentionDays();

        if ($days === 0) {
            $this->components->info('Event retention is set to keep everything, so nothing was deleted.');

            return self::SUCCESS;
        }

        $before = Carbon::now()->subDays($days);

        $deleted = $events->prune($before);

        $this->components->info(sprintf(
            'Deleted %d event(s) older than %d day(s), before %s.',
            $deleted,
            $days,
            $before->toDateTimeString()
        ));

        return self::SUCCESS;
    }
}
