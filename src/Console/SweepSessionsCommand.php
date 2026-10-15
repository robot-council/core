<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RobotCouncil\Support\SessionPresence;

/**
 * Marks the agent sessions that have stopped answering, and runs whatever releases what they held.
 *
 * The service provider schedules it every minute, and it is safe to run by hand at any time and
 * safe to run twice at once: every transition it writes is conditional on the state the sweep read,
 * so a session that made contact in between stays active, and a session two sweeps both find silent
 * is ended by exactly one of them.
 */
#[Description('Mark robot-council agent sessions stale and gone when they stop answering')]
#[Signature('robot-council:sweep-sessions')]
final class SweepSessionsCommand extends Command
{
    /**
     * Sweep the sessions.
     *
     * @param  SessionPresence  $presence  The presence store.
     * @return int The command's exit code.
     */
    public function handle(SessionPresence $presence): int
    {
        $swept = $presence->sweep();

        $this->components->info(sprintf(
            'Marked %d session(s) stale and %d gone; announced %d still stale.',
            $swept['stale'],
            $swept['gone'],
            $swept['announced']
        ));

        return self::SUCCESS;
    }
}
