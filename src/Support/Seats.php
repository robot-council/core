<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Installation;
use RobotCouncil\Models\Seat;

/**
 * Parking a developer's seats, and exempting them from assignment hours.
 *
 * **Each write is one conditional update, and its `where` is the rule.** Parking names the seat's
 * owner, so a developer can park only their own; lifting names the developer who parked it, so
 * nobody else can lift it -- not another developer, not a coordinator, and not the clock, since
 * nothing here reads one. A write that matched no row is then diagnosed from a fresh read, which
 * decides only what to report and never whether to write.
 *
 * **Nothing in this class is reachable from an agent session.** Every method takes the developer as
 * a host user key, and the only callers are the dashboard, which reads that key off the package's
 * web guard. #314 decided the coordinator reads these settings and never writes them, because they
 * are constraints on the coordinator; a machine route that wrote here would hand them to it.
 */
final class Seats
{
    /**
     * Record a seat for each place this developer's live sessions are working, and list them all.
     *
     * **Recording on read is deliberate, and it is idempotent.** A seat exists as a row only so it
     * can carry a setting; one that has never been parked or exempted behaves exactly as no row
     * does. So rows are made when the only page that can change them is opened, from what the
     * developer's own sessions report, and `insertOrIgnore` against the seat's unique key makes a
     * second visit, or two at once, write nothing new.
     *
     * A session that reported no repository has no seat: a seat is a place in a repository.
     *
     * @param  string  $developer  The developer's host user key.
     * @return list<Seat> Their seats, with installations loaded, in the lane board's order: machine,
     *                    then repository, then work location.
     */
    public function forDeveloper(string $developer): array
    {
        $developer = HostKey::from($developer);

        $places = AgentSession::query()
            ->where('user_id', $developer)
            ->where('status', '!=', AgentSessionStatus::Gone->value)
            ->whereNotNull('repository')
            ->whereIn('installation_id', Installation::usable()->where('user_id', $developer)->select('id'))
            ->get(['installation_id', 'repository', 'work_location'])
            // **De-duplicated here, byte for byte, and not with a SQL `DISTINCT`.** The session
            // table's `repository` has the engine's default collation, so on MySQL a `DISTINCT`
            // folds `UAMS-Web/x` and `uams-web/x` into one row before the seat table's binary key
            // ever sees them. Measured: with that collation in place, MySQL still recorded one seat.
            ->unique(static fn (AgentSession $session): string => json_encode([
                $session->installation_id,
                $session->repository,
                $session->work_location ?? '',
            ], JSON_THROW_ON_ERROR));

        if ($places->isNotEmpty()) {
            $now = PresenceClock::now();

            Seat::query()->insertOrIgnore($places->values()->map(static fn (AgentSession $session): array => [
                'installation_id' => $session->installation_id,
                'user_id' => $developer,
                'repository' => $session->repository,
                'work_location' => $session->work_location ?? '',
                'hours_exempt' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        return array_values(Seat::query()
            ->with('installation')
            ->where('user_id', $developer)
            ->orderBy('installation_id')
            ->orderBy('repository')
            ->orderBy('work_location')
            ->get()
            ->all());
    }

    /**
     * Every seat anybody has recorded, for the coordinator to read.
     *
     * @return list<Seat> Every seat, with installations loaded.
     */
    public function everyone(): array
    {
        return array_values(Seat::query()
            ->with('installation')
            ->orderBy('user_id')
            ->orderBy('installation_id')
            ->orderBy('repository')
            ->orderBy('work_location')
            ->get()
            ->all());
    }

    /**
     * Say a seat takes no work.
     *
     * @param  string  $developer  The developer asking, as a host user key.
     * @param  int  $seatId  The seat.
     * @return Outcome Applied, or why not: another developer's seat is Forbidden, and one already
     *                 parked is a Conflict.
     */
    public function park(string $developer, int $seatId): Outcome
    {
        $developer = HostKey::from($developer);

        $changed = Seat::query()
            ->whereKey($seatId)
            ->where('user_id', $developer)
            ->whereNull('parked_by')
            ->update(['parked_by' => $developer, 'parked_at' => PresenceClock::now(), 'updated_at' => PresenceClock::now()]);

        return $changed === 1 ? Outcome::Applied : $this->diagnose($seatId, static fn (Seat $seat): Outcome => match (true) {
            $seat->user_id !== $developer => Outcome::Forbidden,
            default => Outcome::Conflict,
        });
    }

    /**
     * Let a parked seat take work again.
     *
     * @param  string  $developer  The developer asking, as a host user key.
     * @param  int  $seatId  The seat.
     * @return Outcome Applied, or why not: a seat somebody else parked is Forbidden, and one that is
     *                 not parked is a Conflict.
     */
    public function lift(string $developer, int $seatId): Outcome
    {
        $developer = HostKey::from($developer);

        $changed = Seat::query()
            ->whereKey($seatId)
            ->where('parked_by', $developer)
            ->update(['parked_by' => null, 'parked_at' => null, 'updated_at' => PresenceClock::now()]);

        // Ownership first, so another developer is told they may not rather than that the seat is
        // free: whether it is parked is not theirs to act on either way
        return $changed === 1 ? Outcome::Applied : $this->diagnose($seatId, static fn (Seat $seat): Outcome => match (true) {
            $seat->user_id !== $developer => Outcome::Forbidden,
            $seat->parked_by === null => Outcome::Conflict,
            default => Outcome::Forbidden,
        });
    }

    /**
     * Exempt a seat from its developer's assignment hours, or stop exempting it.
     *
     * **A repeat is Applied, not a Conflict.** Asking for what the seat already says is a request
     * that is already satisfied. The update names the opposite value so that it changes the row it
     * matches on every engine -- MySQL reports rows CHANGED, so an update writing the value already
     * there reports 0 -- and the diagnosis reads the row to tell a repeat from a refusal.
     *
     * @param  string  $developer  The developer asking, as a host user key.
     * @param  int  $seatId  The seat.
     * @param  bool  $exempt  Whether the seat ignores the hours.
     * @return Outcome Applied, NotFound, or Forbidden for another developer's seat.
     */
    public function exempt(string $developer, int $seatId, bool $exempt): Outcome
    {
        $developer = HostKey::from($developer);

        $changed = Seat::query()
            ->whereKey($seatId)
            ->where('user_id', $developer)
            ->where('hours_exempt', ! $exempt)
            ->update(['hours_exempt' => $exempt, 'updated_at' => PresenceClock::now()]);

        return $changed === 1 ? Outcome::Applied : $this->diagnose($seatId, static fn (Seat $seat): Outcome => match (true) {
            $seat->user_id !== $developer => Outcome::Forbidden,
            default => Outcome::Applied,
        });
    }

    /**
     * Why a write matched no row, read fresh.
     *
     * @param  int  $seatId  The seat the write named.
     * @param  callable(Seat): Outcome  $because  What a seat that exists says about it.
     * @return Outcome NotFound, or what `$because` decides.
     */
    private function diagnose(int $seatId, callable $because): Outcome
    {
        $seat = Seat::query()->find($seatId);

        return $seat instanceof Seat ? $because($seat) : Outcome::NotFound;
    }
}
