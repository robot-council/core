<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use DateTimeInterface;
use RobotCouncil\Models\AssignmentHours;
use RobotCouncil\Models\Seat;

/**
 * Every developer's settings and every recorded seat, as a coordinator reads them.
 *
 * **One description, two ways in**: `GET {prefix}/api/developers/settings` and the
 * `developer_settings` MCP tool (#440) both return this, so a coordinator in a harness reads what
 * one calling the endpoint reads. Both sit behind `coordinator:direct`, for the reason the
 * controller records: a developer's hours and days off say when they are away.
 *
 * **Read at the moment of the call, never remembered.** Settings change on the developer's own page
 * at any time, and #440 exists because a coordinator went on reporting hours the developer had
 * already removed. So each developer and each seat carries `inside_hours` for this moment --
 * `true`, `false` or `"ungated"`, from `HoursStanding`, the same code `PlacementRules` refuses with
 * -- and `next_opens_at` when it is closed.
 *
 * Developers are named by GitHub login, as every other machine response names them. A seat's
 * `work_location` of `''` is how the table stores "none", and it is reported as null, which is what
 * a session reporting none sent in the first place.
 */
final class CoordinatorSettings
{
    /**
     * @param  DeveloperSettings  $settings  The hours and days-off store.
     * @param  Seats  $seats  The seat store.
     * @param  AgentLogins  $logins  Resolves host user keys to GitHub logins.
     */
    public function __construct(
        private readonly DeveloperSettings $settings,
        private readonly Seats $seats,
        private readonly AgentLogins $logins
    ) {}

    /**
     * Describe everything, as of a moment.
     *
     * @param  DateTimeInterface  $at  The moment `inside_hours` and `next_opens_at` answer for.
     * @return array{developers: list<array<string, mixed>>, seats: list<array<string, mixed>>} The settings.
     */
    public function describe(DateTimeInterface $at): array
    {
        $developers = $this->settings->everyone();
        $recorded = $this->seats->everyone();

        $names = $this->logins->forUsers([
            ...array_map(static fn (array $developer): string => $developer['user_id'], $developers),
            ...array_map(static fn (Seat $seat): string => $seat->user_id, $recorded),
            ...array_map(static fn (Seat $seat): ?string => $seat->parked_by, $recorded),
        ]);

        // Prefixed, never keyed by the bare host key, for the reason `DeveloperSettings::everyone()`
        // records: PHP turns a numeric string key into an integer
        $byKey = [];

        foreach ($developers as $developer) {
            $byKey['k'.$developer['user_id']] = $developer;
        }

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
                ...self::now($hours, $developer['holidays'], $at),
            ];
        }

        return [
            'developers' => $described,
            'seats' => array_map(static function (Seat $seat) use ($names, $byKey, $at): array {
                $developer = $byKey['k'.$seat->user_id] ?? null;

                return [
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
                    'max_capacity' => $seat->max_capacity,
                    // An exempt seat is ungated whatever its developer set, which is what the
                    // placement check does with it
                    ...self::now($seat->hours_exempt ? null : ($developer['hours'] ?? null), $developer['holidays'] ?? [], $at),
                ];
            }, $recorded),
        ];
    }

    /**
     * Where the hours put a moment, and when they next open if they are closed.
     *
     * @param  AssignmentHours|null  $hours  The hours in force, or null when none gate.
     * @param  list<string>  $holidays  The developer's days off.
     * @param  DateTimeInterface  $at  The moment.
     * @return array{inside_hours: bool|string, next_opens_at: string|null} The two values.
     */
    private static function now(?AssignmentHours $hours, array $holidays, DateTimeInterface $at): array
    {
        $standing = DeveloperSettings::standing($hours, $holidays, $at);

        return [
            'inside_hours' => $standing->reported(),
            'next_opens_at' => $standing === HoursStanding::Outside
                ? AssignmentWindow::nextOpening($hours, $holidays, $at)?->toIso8601String()
                : null,
        ];
    }
}
