<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\Locks;

/**
 * Deletes lock rows nobody holds, past the configured retention.
 *
 * Only a free lock is ever removed, and "free" is the same condition an acquisition takes one
 * from: no holder, or a lease that has lapsed. A lock somebody is holding is left alone whatever
 * its age, because it is the live answer to who is guarding what.
 *
 * A retention of zero keeps everything, and the command says so rather than reporting that it
 * deleted nothing, because those are different states and only one of them wants looking at.
 */
#[Description('Delete free robot-council locks past the configured retention')]
#[Signature('robot-council:prune-locks')]
final class PruneLocksCommand extends Command
{
    /**
     * Delete the free locks past retention.
     *
     * @param  Locks  $locks  The lock store.
     * @param  Credentials  $credentials  The configured retention.
     * @return int The command's exit code.
     */
    public function handle(Locks $locks, Credentials $credentials): int
    {
        $days = $credentials->lockRetentionDays();

        if ($days === 0) {
            $this->components->info('Lock retention is set to keep everything, so nothing was deleted.');

            return self::SUCCESS;
        }

        $before = Carbon::now()->subDays($days);

        $deleted = $locks->prune($before);

        $this->components->info(sprintf(
            'Deleted %d free lock(s) untouched for more than %d day(s), before %s.',
            $deleted,
            $days,
            $before->toDateTimeString()
        ));

        return self::SUCCESS;
    }
}
