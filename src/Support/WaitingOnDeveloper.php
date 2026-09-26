<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\HoldParty;
use RobotCouncil\Models\LaneHold;

/**
 * What the fleet is waiting on one developer for (#411): their open owed items, and the lanes held
 * with them as the party.
 *
 * **The same records the lane board shows, filtered to one developer.** Owed items come from
 * `OwedItems::open()`, which already groups them by developer and drops an item naming a developer
 * the fleet no longer knows; this takes that developer's section. `General` items are nobody's in
 * particular and stay on the lane board. Holds are `robot_council_lane_holds` rows whose party is a
 * developer, on lanes still live and listed.
 *
 * **Matched by GitHub login, compared without case**, which is how an owed item and a hold name a
 * developer and how `OwedItems` groups them. That is for display only, as every login is here: this
 * page decides nothing, and its gate is the dashboard's allowlist, not the login.
 *
 * @phpstan-type OwedItem array{id: int, ticket: string, question: string, why: string, recorded_at: Carbon}
 * @phpstan-type HeldLane array{session_id: int, label: string|null, machine: string, reason: string, held_at: Carbon}
 */
final class WaitingOnDeveloper
{
    /**
     * @param  OwedItems  $owed  The owed-items store.
     */
    public function __construct(private readonly OwedItems $owed) {}

    /**
     * Everything the fleet is waiting on this developer for.
     *
     * @param  string  $login  The developer's GitHub login.
     * @return array{owed: list<OwedItem>, holds: list<HeldLane>} Their items, oldest first, and the
     *                                                            lanes held on them, longest first.
     */
    public function for(string $login): array
    {
        $owed = [];

        foreach ($this->owed->open() as $section) {
            if ($section['developer'] !== null && strcasecmp($section['developer'], $login) === 0) {
                $owed = $section['items'];
            }
        }

        $holds = [];

        $rows = LaneHold::query()
            ->where('party_kind', HoldParty::Developer->value)
            ->whereRaw('lower(party) = ?', [mb_strtolower($login)])
            ->orderBy('held_at')
            ->get();

        // The lanes, in one read: live and listed only, since a hold on a lane that has gone or
        // never appears on the board is nothing anybody can act on
        $sessions = AgentSession::announced()
            ->whereIn('id', $rows->pluck('agent_session_id')->all())
            ->where('status', '!=', AgentSessionStatus::Gone->value)
            ->with('installation')
            ->get()
            ->keyBy('id');

        foreach ($rows as $hold) {
            $session = $sessions->get($hold->agent_session_id);

            // Checked again in PHP, because a column compared without case on one engine is not on
            // another; the login is what this page is about
            if (! $session instanceof AgentSession || strcasecmp($hold->party, $login) !== 0) {
                continue;
            }

            $holds[] = [
                'session_id' => $session->id,
                'label' => SessionLabels::of($session->repository, $session->installation->machine_label, $session->work_location),
                'machine' => $session->installation->machine_label,
                'reason' => $hold->reason->reads(),
                'held_at' => $hold->held_at,
            ];
        }

        return ['owed' => $owed, 'holds' => $holds];
    }
}
