<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\Tasks;

/**
 * Deletes finished tasks past the configured retention.
 *
 * Only a terminal task is ever removed. A task nobody has finished is work the fleet still owes
 * somebody, and age is the opposite of a reason to delete it.
 *
 * A retention of zero keeps everything, and the command says so rather than reporting that it
 * deleted nothing, because "pruned 0 tasks" and "pruning is switched off" are different states.
 */
#[Description('Delete finished robot-council tasks past the configured retention')]
#[Signature('robot-council:prune-tasks')]
final class PruneTasksCommand extends Command
{
    /**
     * Delete the finished tasks past retention.
     *
     * @param  Tasks  $tasks  The task store.
     * @param  Credentials  $credentials  The configured retention.
     * @return int The command's exit code.
     */
    public function handle(Tasks $tasks, Credentials $credentials): int
    {
        $days = $credentials->taskRetentionDays();

        if ($days === 0) {
            $this->components->info('Task retention is set to keep everything, so nothing was deleted.');

            return self::SUCCESS;
        }

        $before = Carbon::now()->subDays($days);

        $deleted = $tasks->prune($before);

        $this->components->info(sprintf(
            'Deleted %d finished task(s) that ended more than %d day(s) ago, before %s.',
            $deleted,
            $days,
            $before->toDateTimeString()
        ));

        return self::SUCCESS;
    }
}
