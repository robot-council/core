<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\PlacementRule;
use RobotCouncil\Models\PlacementWaiver;
use RobotCouncil\Models\Seat;

/**
 * Waivers of placement refusals, granted by a seat's own developer (#320).
 *
 * **Only the developer who owns the seat grants one, and nothing an agent session reaches writes
 * here.** #314 decided a coordinator cannot waive, since the settings it would be waiving constrain
 * it; every method that grants takes the developer as a host user key, which the dashboard reads
 * off the package's web guard. The placement consumes a waiver inside its own transaction.
 */
final class PlacementWaivers
{
    /**
     * Grant a waiver of one rule for the next placement on a seat.
     *
     * Granting a rule already waived and unused changes nothing: there is only ever one outstanding
     * waiver per seat and rule, since each lets through exactly one placement.
     *
     * @param  string  $developer  The developer asking, as a host user key.
     * @param  int  $seatId  The seat.
     * @param  PlacementRule  $rule  The rule to waive.
     * @return Outcome Applied, NotFound, or Forbidden for another developer's seat.
     */
    public function grant(string $developer, int $seatId, PlacementRule $rule): Outcome
    {
        $developer = HostKey::from($developer);

        // The seat row locked around the check and the insert, so two grants at once -- two open
        // tabs -- cannot both find nothing outstanding and both insert: a second waiver would let a
        // second placement through on what the developer meant as one
        return DB::transaction(function () use ($developer, $seatId, $rule): Outcome {
            $seat = Seat::query()->whereKey($seatId)->lockForUpdate()->first();

            if (! $seat instanceof Seat) {
                return Outcome::NotFound;
            }

            if ($seat->user_id !== $developer) {
                return Outcome::Forbidden;
            }

            if ($this->outstanding($seatId, $rule) instanceof PlacementWaiver) {
                return Outcome::Applied;
            }

            PlacementWaiver::query()->insert([
                'seat_id' => $seatId,
                'rule' => $rule->value,
                'granted_by' => $developer,
                'granted_at' => Carbon::now(),
            ]);

            return Outcome::Applied;
        });
    }

    /**
     * Withdraw an unused waiver.
     *
     * @param  string  $developer  The developer asking, as a host user key.
     * @param  int  $seatId  The seat.
     * @param  PlacementRule  $rule  The rule.
     * @return bool True when there was one to withdraw.
     */
    public function withdraw(string $developer, int $seatId, PlacementRule $rule): bool
    {
        return PlacementWaiver::query()
            ->where('seat_id', $seatId)
            ->where('rule', $rule->value)
            ->where('granted_by', HostKey::from($developer))
            ->whereNull('consumed_at')
            ->delete() > 0;
    }

    /**
     * The unused waivers on a seat.
     *
     * @param  int  $seatId  The seat.
     * @return list<PlacementRule> The rules waived for its next placement.
     */
    public function waivedOn(int $seatId): array
    {
        return array_values(PlacementWaiver::query()
            ->where('seat_id', $seatId)
            ->whereNull('consumed_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (PlacementWaiver $waiver): PlacementRule => $waiver->rule)
            ->unique()
            ->all());
    }

    /**
     * Spend the waivers a placement relied on.
     *
     * Called inside the placement's transaction. Each is a conditional update on an unused waiver,
     * so two placements racing for one waiver cannot both spend it: the loser changes no row and
     * the placement refuses.
     *
     * @param  int  $seatId  The seat.
     * @param  list<PlacementRule>  $rules  The rules the placement broke.
     * @param  int  $taskId  The task it places.
     * @return list<PlacementRule> The rules no waiver covered, which still refuse.
     */
    public function spend(int $seatId, array $rules, int $taskId): array
    {
        $uncovered = [];

        foreach ($rules as $rule) {
            $waiver = $this->outstanding($seatId, $rule);

            $spent = $waiver instanceof PlacementWaiver && PlacementWaiver::query()
                ->whereKey($waiver->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => Carbon::now(), 'consumed_by_task' => $taskId]) === 1;

            if (! $spent) {
                $uncovered[] = $rule;
            }
        }

        return $uncovered;
    }

    /**
     * The oldest unused waiver of a rule on a seat.
     *
     * @param  int  $seatId  The seat.
     * @param  PlacementRule  $rule  The rule.
     * @return PlacementWaiver|null The waiver.
     */
    private function outstanding(int $seatId, PlacementRule $rule): ?PlacementWaiver
    {
        return PlacementWaiver::query()
            ->where('seat_id', $seatId)
            ->where('rule', $rule->value)
            ->whereNull('consumed_at')
            ->orderBy('id')
            ->first();
    }
}
