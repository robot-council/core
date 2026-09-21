<?php

declare(strict_types=1);

namespace RobotCouncil\Console\Concerns;

use RobotCouncil\Access\Ability;
use RobotCouncil\Console\Argument;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\Installations;

/**
 * Granting and revoking one ability on an installation.
 *
 * Held apart from `ManagesInstallations` rather than merged into it, because Larastan checks every
 * `argument()` name against the command's own signature: a command with no `ability` argument that
 * carried this code would be reported for naming one.
 */
trait ManagesAbilities
{
    use ManagesInstallations;

    /**
     * Add or remove one ability, on the installation and on its live session tokens.
     *
     * @param  Installations  $installations  The installation store.
     * @param  bool  $granted  True to add the ability, false to remove it.
     * @return int The command's exit code.
     */
    private function applyAbility(Installations $installations, bool $granted): int
    {
        $ability = $this->abilityArgument();

        if (! $ability instanceof Ability) {
            return self::FAILURE;
        }

        $installation = $this->installationArgument();

        if (! $installation instanceof Installation) {
            return self::FAILURE;
        }

        $rewritten = $installations->setAbility($installation, $ability, $granted);

        $this->components->info(sprintf(
            '%s `%s` %s installation %d. %d live session token(s) rewritten.',
            $granted ? 'Granted' : 'Revoked',
            $ability->value,
            $granted ? 'to' : 'from',
            $installation->id,
            $rewritten
        ));

        return self::SUCCESS;
    }

    /**
     * Read the ability argument, refusing anything outside the fixed list.
     *
     * `*` is rejected here like any other unknown name, because Sanctum reads it as every ability;
     * so is `sessions:start`, which belongs to an installation credential rather than a session.
     *
     * @return Ability|null The ability, or null once the refusal has been reported.
     */
    private function abilityArgument(): ?Ability
    {
        // Through the enum's own gate, which the dashboard's admin panel also calls. Two copies of
        // this condition is how `*` gets admitted on one path years after being refused on the
        // other, and `*` is the one value that must never be stored.
        $ability = Ability::grantableFrom(Argument::text($this->argument('ability')));

        if (! $ability instanceof Ability) {
            $this->components->error(sprintf(
                'Grantable abilities are: %s.',
                implode(', ', Ability::values(Ability::grantable()))
            ));

            return null;
        }

        return $ability;
    }
}
