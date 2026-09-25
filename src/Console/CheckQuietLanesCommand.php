<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RobotCouncil\Support\QuietLanes;

/**
 * Tell the coordinator about lanes that have authored nothing for an hour (#332).
 *
 * Scheduled every five minutes, and it repeats nothing: `Support\QuietLanes` tells the coordinators
 * once per quiet stretch.
 */
#[Description('Tell the coordinator about build lanes that have authored nothing for an hour')]
#[Signature('robot-council:quiet-lanes')]
final class CheckQuietLanesCommand extends Command
{
    /**
     * Run the check.
     *
     * @param  QuietLanes  $lanes  The check.
     * @return int The command's exit code.
     */
    public function handle(QuietLanes $lanes): int
    {
        $this->components->info(sprintf('Told the coordinators about %d quiet lane(s).', $lanes->check(Carbon::now())));

        return self::SUCCESS;
    }
}
