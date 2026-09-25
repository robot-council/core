<?php

declare(strict_types=1);

/**
 * Which environment variable decides whether restricted events reach Slack (#366).
 *
 * @command  vendor/bin/pest --compact tests/SlackMirrorEnvTest.php
 */

use Illuminate\Support\Env;

/**
 * The published config's `slack.mirror_restricted`, read fresh under these variables.
 *
 * Through Laravel's own environment repository, which is what `env()` reads, and cleared again
 * afterwards so no other test inherits a value.
 *
 * @param  array<string, string>  $variables  The variables to set for the read.
 * @return mixed The value the config file produced.
 */
function mirrorRestrictedUnder(array $variables): mixed
{
    $names = ['ROBOT_COUNCIL_SLACK_MIRROR_RESTRICTED', 'ROBOT_COUNCIL_SLACK_MIRROR_NARRATION'];
    $repository = Env::getRepository();

    try {
        foreach ($names as $name) {
            $repository->clear($name);
        }

        foreach ($variables as $name => $value) {
            $repository->set($name, $value);
        }

        $config = require __DIR__.'/../config/robot-council.php';

        return arrayValue(arrayValue($config)['slack'] ?? [])['mirror_restricted'] ?? null;
    } finally {
        foreach ($names as $name) {
            $repository->clear($name);
        }
    }
}

it('mirrors restricted events by default', function (): void {
    expect(mirrorRestrictedUnder([]))->toBeTrue();
});

it('reads the new name', function (): void {
    expect(mirrorRestrictedUnder(['ROBOT_COUNCIL_SLACK_MIRROR_RESTRICTED' => 'false']))->toBeFalse();
});

it('still reads the old name when the new one is unset, so a host keeps what it chose', function (): void {
    expect(mirrorRestrictedUnder(['ROBOT_COUNCIL_SLACK_MIRROR_NARRATION' => 'false']))->toBeFalse();
});

it('lets the new name win when both are set', function (): void {
    expect(mirrorRestrictedUnder([
        'ROBOT_COUNCIL_SLACK_MIRROR_RESTRICTED' => 'true',
        'ROBOT_COUNCIL_SLACK_MIRROR_NARRATION' => 'false',
    ]))->toBeTrue();
});
