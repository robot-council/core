<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\HoldParty;
use RobotCouncil\Models\HoldReason;
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
 * **A login can be renamed, and that cuts both ways here** (#411's review), as it already did on
 * the lane board. An item or a hold stores the login it was given, so a developer who renames their
 * GitHub account stops seeing what was recorded under the old name; and if another allowlisted
 * person later signs in under that old name, they see it. Both follow from items being keyed by
 * login at write time, which this page does not change.
 *
 * @phpstan-type OwedItem array{id: int, ticket: string, question: string, why: string, recorded_at: Carbon}
 * @phpstan-type HeldLane array{session_id: int, label: string|null, machine: string, waiting: string, held_at: Carbon}
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
                'waiting' => self::waiting($hold->reason),
                'held_at' => $hold->held_at,
            ];
        }

        return ['owed' => $owed, 'holds' => $holds];
    }

    /**
     * What a lane waits on the developer for, addressed to them.
     *
     * `HoldReason::reads()` is written for a third party beside a name (`octodev -- an action only
     * they can take`); this page speaks to the developer, so it says the same thing in the second
     * person rather than reusing the board's words where they read wrong.
     *
     * @param  HoldReason  $reason  The hold's reason, which a developer party always has.
     * @return string The clause, as `waiting on you ...`.
     */
    private static function waiting(HoldReason $reason): string
    {
        return match ($reason) {
            HoldReason::ClearingSeat => 'waiting on you to clear this seat so it can take tickets',
            HoldReason::Decision => 'waiting on you for a decision',
            HoldReason::Action => 'waiting on you for an action only you can take',
            // A ticket is never a developer party's reason, and such holds are not read above
            HoldReason::TicketLands, HoldReason::TicketDecided => 'waiting on you',
        };
    }
}
