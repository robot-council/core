<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RobotCouncil\Support\LaneConditions;

/**
 * Raise the lane conditions of #319 to the coordinators, and clear those that stopped holding.
 *
 * Scheduled every five minutes; `Support\LaneConditions` raises each occurrence once.
 */
#[Description('Raise lane conditions to the coordinators: free lanes, placements not taken up, ready pull requests no gate picked up')]
#[Signature('robot-council:lane-conditions')]
final class CheckLaneConditionsCommand extends Command
{
    /**
     * Run the check.
     *
     * @param  LaneConditions  $conditions  The check.
     * @return int The command's exit code.
     */
    public function handle(LaneConditions $conditions): int
    {
        $this->components->info(sprintf('Raised %d lane condition(s).', $conditions->check(Carbon::now())));

        return self::SUCCESS;
    }
}
