<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\PresenceClock;
use RobotCouncil\Support\SessionPresence;

/**
 * Deletes agent sessions that ended past the configured retention.
 *
 * Only a session that has gone is ever removed, and never one still holding a task or a lock. An
 * `active` or `stale` session is live -- a stale one is a single request from active again -- so
 * age is no reason to delete either.
 *
 * A retention of zero keeps everything, and the command says so rather than reporting that it
 * deleted nothing, because those are different states and only one of them wants looking at.
 */
#[Description('Delete robot-council agent sessions that ended past the configured retention')]
#[Signature('robot-council:prune-sessions')]
final class PruneSessionsCommand extends Command
{
    /**
     * Delete the ended sessions past retention.
     *
     * @param  SessionPresence  $presence  The presence store, which owns how a session ends.
     * @param  Credentials  $credentials  The configured retention.
     * @return int The command's exit code.
     */
    public function handle(SessionPresence $presence, Credentials $credentials): int
    {
        $days = $credentials->sessionRetentionDays();

        if ($days === 0) {
            $this->components->info('Session retention is set to keep everything, so nothing was deleted.');

            return self::SUCCESS;
        }

        $before = PresenceClock::now()->subDays($days);

        $deleted = $presence->prune($before);

        $this->components->info(sprintf(
            'Deleted %d ended session(s) last heard from more than %d day(s) ago, before %s.',
            $deleted,
            $days,
            $before->toDateTimeString()
        ));

        return self::SUCCESS;
    }
}
