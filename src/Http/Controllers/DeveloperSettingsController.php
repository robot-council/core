<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RobotCouncil\Models\Seat;
use RobotCouncil\Support\AgentLogins;
use RobotCouncil\Support\DeveloperSettings;
use RobotCouncil\Support\Seats;

/**
 * Every developer's parked seats and assignment hours, for a coordinator to read.
 *
 * **Read-only, and there is no route that writes them.** #314 decided the coordinator reads these
 * to place and to render and never writes them, because they are the developers' constraints on
 * the coordinator. The only writer is the dashboard's settings page, which a session cannot reach:
 * it reads the developer off the package's web guard, and a token names a session, not a person.
 *
 * Behind `coordinator:direct` rather than open to every session, because a developer's hours and
 * days off say when they are away, and only the session placing work needs to know.
 *
 * Developers are named by GitHub login, as every other machine response names them. A seat's
 * `work_location` of `''` is how the table stores "none", and it is reported as null, which is what
 * a session reporting none sent in the first place.
 */
final class DeveloperSettingsController
{
    /**
     * List every developer's settings and every recorded seat.
     *
     * @param  DeveloperSettings  $settings  The hours and days-off store.
     * @param  Seats  $seats  The seat store.
     * @param  AgentLogins  $logins  Resolves host user keys to GitHub logins.
     * @return JsonResponse The settings.
     */
    public function __invoke(DeveloperSettings $settings, Seats $seats, AgentLogins $logins): JsonResponse
    {
        $developers = $settings->everyone();
        $recorded = $seats->everyone();

        $names = $logins->forUsers([
            ...array_map(static fn (array $developer): string => $developer['user_id'], $developers),
            ...array_map(static fn (Seat $seat): string => $seat->user_id, $recorded),
            ...array_map(static fn (Seat $seat): ?string => $seat->parked_by, $recorded),
        ]);

        $described = [];

        foreach ($developers as $developer) {
            $hours = $developer['hours'];

            $described[] = [
                'github_login' => $names[$developer['user_id']] ?? null,
                'hours' => $hours === null ? null : [
                    'timezone' => $hours->timezone,
                    'starts_at' => $hours->starts_at,
                    'ends_at' => $hours->ends_at,
                    'skip_weekends' => $hours->skip_weekends,
                ],
                'holidays' => $developer['holidays'],
            ];
        }

        return new JsonResponse([
            'developers' => $described,
            'seats' => array_map(static fn (Seat $seat): array => [
                'id' => $seat->id,
                'github_login' => $names[$seat->user_id] ?? null,
                'installation_id' => $seat->installation_id,
                // Agent-supplied at enrollment and charset-limited there, like every other place
                // these two reach another developer's agent
                'harness' => $seat->installation->harness,
                'machine_label' => $seat->installation->machine_label,
                'repository' => $seat->repository,
                'work_location' => $seat->work_location === '' ? null : $seat->work_location,
                'parked' => $seat->isParked(),
                'parked_by' => $seat->parked_by === null ? null : ($names[$seat->parked_by] ?? null),
                'parked_at' => $seat->parked_at?->toIso8601String(),
                'hours_exempt' => $seat->hours_exempt,
            ], $recorded),
        ]);
    }
}
