<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RobotCouncil\Console\Concerns\ManagesAbilities;
use RobotCouncil\Support\Installations;

/**
 * Removes one ability from an installation, and demotes any live session it no longer qualifies
 * to run.
 *
 * Since `robot-council/core#221` only `coordinator:direct` reaches a running session, because it
 * is the only ability a role turns on. Revoking one of the four an enrollment may request changes
 * the stored list and nothing else; the count this prints is live tokens re-minted, which is zero
 * in that case.
 *
 * Rewriting the live tokens is what makes this take effect now rather than within the hour a
 * session token lives.
 */
#[Description('Revoke one ability from a robot-council installation')]
#[Signature("robot-council:revoke-ability {installation : The installation's ID} {ability : The ability to revoke}")]
final class RevokeAbilityCommand extends Command
{
    use ManagesAbilities;

    /**
     * Remove the ability.
     *
     * @param  Installations  $installations  The installation store.
     * @return int The command's exit code.
     */
    public function handle(Installations $installations): int
    {
        return $this->applyAbility($installations, granted: false);
    }
}
