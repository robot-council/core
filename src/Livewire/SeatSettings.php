<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RobotCouncil\Access\CurrentDeveloper;
use RobotCouncil\Models\AssignmentHours;
use RobotCouncil\Models\PlacementRule;
use RobotCouncil\Models\Seat;
use RobotCouncil\Support\Capacity;
use RobotCouncil\Support\DeveloperSettings;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\PlacementWaivers;
use RobotCouncil\Support\Seats;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A developer's own seats, their capacity (#409), assignment hours and days off (#322).
 *
 * **The developer is read off the package's web guard on every entry point, and never from the
 * request.** Nothing a client sends names whose settings change: an action carries a seat id or a
 * date, and the stores refuse a seat that belongs to somebody else. So a developer edits only their
 * own settings however the snapshot is shaped, and a session -- which authenticates by token and is
 * never a developer on this guard -- cannot reach any of it, which is #314's rule that the
 * coordinator reads these and never writes them.
 *
 * **Parking and lifting are refused by the store, not hidden by the view.** A Livewire action is an
 * ordinary POST to `/livewire/update`, so a button this page declines to draw is markup a client
 * can simply not need; the refusal is the conditional update in `Support\Seats`.
 */
#[Layout('robot-council::layouts.dashboard')]
#[Title('My seats and hours')]
final class SeatSettings extends Component
{
    /**
     * The zone the window is in, as typed.
     */
    public string $timezone = '';

    /**
     * The start of the window, `HH:MM`.
     */
    public string $startsAt = '09:00';

    /**
     * The end of the window, `HH:MM`.
     */
    public string $endsAt = '17:00';

    /**
     * Whether weekends take no new placements.
     */
    public bool $skipWeekends = true;

    /**
     * A day off being added, `YYYY-MM-DD`.
     */
    public string $holiday = '';

    /**
     * Each seat's capacity as the form holds it, by seat id (#409).
     *
     * Client-writable, like every other field here, so `setCapacity()` reads it as untrusted and the
     * store refuses a seat that is not this developer's.
     *
     * @var array<int|string, mixed>
     */
    public array $capacities = [];

    /**
     * The seat whose ticket limit the last save was about, or null.
     *
     * Locked, like `said`: the page shows its confirmation or its error next to that seat's field,
     * and only the server says which seat that is.
     */
    #[Locked]
    public ?int $capacitySeat = null;

    /**
     * Why the last ticket-limit save was refused, in words, or null when it was saved.
     */
    #[Locked]
    public ?string $capacityError = null;

    /**
     * What the last action did, or why it did nothing, in words (#402).
     *
     * Locked, so only the server sets it: a client could otherwise put any sentence it liked in
     * the page's own account of what happened, which is harmless on its own page and still not the
     * page's to say.
     */
    #[Locked]
    public ?string $said = null;

    /**
     * Where the page shows `said`: `seat-` and a seat id, `hours`, or `days-off`.
     *
     * Each action's words appear beside the control that caused them rather than in one alert at
     * the top, so a reader hears the answer where they are (`.claude/rules/accessibility.md`).
     */
    #[Locked]
    public ?string $saidAt = null;

    /**
     * Whether the last action was refused or changed nothing, which the page shows as an error.
     */
    #[Locked]
    public bool $refused = false;

    /**
     * Refuse anyone the guard does not resolve, and fill the form from what is stored.
     */
    public function mount(): void
    {
        $hours = $this->service(DeveloperSettings::class)->hours($this->developer());

        if ($hours !== null) {
            $this->timezone = $hours->timezone;
            $this->startsAt = $hours->starts_at;
            $this->endsAt = $hours->ends_at;
            $this->skipWeekends = $hours->skip_weekends;
        }
    }

    /**
     * Store the window as the form holds it.
     */
    public function saveHours(): void
    {
        $saved = $this->attempt('hours', 'Not saved:', fn () => $this->service(DeveloperSettings::class)->setHours(
            $this->developer(),
            trim($this->timezone),
            $this->startsAt,
            $this->endsAt,
            $this->skipWeekends
        ));

        if ($saved) {
            $this->say('hours', sprintf(
                'Saved: your seats take new work from %s until %s, %s time, %s.',
                $this->startsAt,
                $this->endsAt,
                trim($this->timezone),
                $this->skipWeekends ? 'on weekdays only' : 'every day of the week'
            ));
        }
    }

    /**
     * Remove the window, so nothing gates this developer's seats.
     */
    public function clearHours(): void
    {
        $settings = $this->service(DeveloperSettings::class);

        if (! $settings->hours($this->developer()) instanceof AssignmentHours) {
            $this->say('hours', 'No hours to remove: none were set, so your seats already take new work at any time.');

            return;
        }

        $settings->clearHours($this->developer());

        $this->say('hours', 'Removed: your seats take new work at any time. Your days off apply again once you set hours.');
    }

    /**
     * Add the day the form holds.
     */
    public function addHoliday(): void
    {
        $developer = $this->developer();

        $day = trim($this->holiday);
        $added = null;

        $this->attempt('days-off', 'Not added:', function () use ($developer, $day, &$added): void {
            $added = $this->service(DeveloperSettings::class)->addHoliday($developer, $day);
            $this->holiday = '';
        });

        // Only a real date reaches either branch: the store refuses anything else before it writes
        if ($added === true) {
            $this->say('days-off', sprintf('Added: %s is a day off.', $day));
        } elseif ($added === false) {
            $this->say('days-off', sprintf('Already listed: %s was already a day off, so nothing changed.', $day));
        }
    }

    /**
     * Remove a day off.
     *
     * @param  string  $day  The date, as the page rendered it.
     */
    public function removeHoliday(string $day): void
    {
        // The day is repeated back only once the store matched it, since until then it is whatever
        // a client chose to send
        if ($this->service(DeveloperSettings::class)->removeHoliday($this->developer(), $day)) {
            $this->say('days-off', sprintf('Removed: %s is no longer a day off.', $day));

            return;
        }

        $this->say('days-off', 'Not listed: that date was not a day off, so nothing changed.', refused: true);
    }

    /**
     * Say a seat takes no work.
     *
     * @param  int  $seatId  The seat.
     */
    public function park(int $seatId): void
    {
        $this->report($seatId, $this->service(Seats::class)->park($this->developer(), $seatId), 'Parked: %s takes no new work until you lift it.');
    }

    /**
     * Let a seat this developer parked take work again.
     *
     * @param  int  $seatId  The seat.
     */
    public function lift(int $seatId): void
    {
        $this->report($seatId, $this->service(Seats::class)->lift($this->developer(), $seatId), 'Lifted: %s can take new work again.');
    }

    /**
     * Exempt a seat from this developer's assignment hours.
     *
     * @param  int  $seatId  The seat.
     */
    public function exempt(int $seatId): void
    {
        $this->report($seatId, $this->service(Seats::class)->exempt($this->developer(), $seatId, true), 'Exempted: %s takes new work at any time, whatever your hours say.');
    }

    /**
     * Hold a seat to this developer's assignment hours again.
     *
     * @param  int  $seatId  The seat.
     */
    public function unexempt(int $seatId): void
    {
        $this->report($seatId, $this->service(Seats::class)->exempt($this->developer(), $seatId, false), 'Hours apply: %s takes new work only inside your assignment hours.');
    }

    /**
     * Cap how many tickets a session in this seat may hold at once, from what the form holds (#409).
     *
     * **Refused here when out of range, though the store clamps.** A developer who typed 50 should
     * be told the bound rather than find 16 stored, and a blank or a fraction is not a number of
     * tickets at all.
     *
     * @param  int  $seatId  The seat.
     */
    public function setCapacity(int $seatId): void
    {
        $developer = $this->developer();
        $typed = $this->capacities[$seatId] ?? null;
        $capacity = \is_int($typed) ? $typed : (\is_string($typed) && preg_match('/^[0-9]{1,3}$/D', trim($typed)) === 1 ? (int) trim($typed) : null);

        // Reported beside the field it is about, so a reader of that seat hears the answer where they
        // are (`.claude/rules/accessibility.md`); the field has its own message, so any other clears
        $this->said = null;
        $this->capacitySeat = $seatId;

        if ($capacity === null || $capacity < Capacity::DEFAULT || $capacity > Capacity::MAX) {
            $this->capacityError = sprintf('Not saved: enter a whole number from %d to %d.', Capacity::DEFAULT, Capacity::MAX);

            return;
        }

        $this->capacityError = match ($this->service(Seats::class)->cap($developer, $seatId, $capacity)) {
            Outcome::Applied => null,
            Outcome::NotFound => 'Not found: that seat no longer exists. Reload the page to see your seats as they are now.',
            Outcome::Conflict, Outcome::Forbidden => "Not allowed: only a seat's own developer can change how many tickets it takes at once.",

            // A task's alone (#433); `cap()` never answers it
            Outcome::Added => null,
        };
    }

    /**
     * Waive one placement refusal for the next placement on a seat (#320).
     *
     * The rule arrives as a string from rendered markup, so it goes through the enum rather than
     * being trusted: a Livewire action is an ordinary POST a client can shape however it likes.
     *
     * @param  int  $seatId  The seat.
     * @param  string  $rule  The rule, as the page rendered it.
     */
    public function waive(int $seatId, string $rule): void
    {
        $resolved = $this->rule($rule);

        $this->report($seatId, $this->service(PlacementWaivers::class)->grant($this->developer(), $seatId, $resolved), 'Waived once: the next placement on %s goes ahead even when %s.', $resolved->reads());
    }

    /**
     * Withdraw a waiver no placement has used yet.
     *
     * @param  int  $seatId  The seat.
     * @param  string  $rule  The rule, as the page rendered it.
     */
    public function withdrawWaiver(int $seatId, string $rule): void
    {
        $resolved = $this->rule($rule);

        if ($this->service(PlacementWaivers::class)->withdraw($this->developer(), $seatId, $resolved)) {
            $this->say('seat-'.$seatId, sprintf('Withdrawn: placements on %s are refused again when %s.', $this->seatName($seatId), $resolved->reads()));

            return;
        }

        $this->say('seat-'.$seatId, 'Nothing withdrawn: that waiver was already used or withdrawn. The list shows what is waived now.', refused: true);
    }

    /**
     * Render the page.
     *
     * @param  Seats  $seats  The seat store.
     * @param  DeveloperSettings  $settings  The hours and days-off store.
     * @return View The page.
     */
    public function render(Seats $seats, DeveloperSettings $settings): View
    {
        $developer = $this->developer();

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.seat-settings';

        $mine = $seats->forDeveloper($developer);
        $waivers = $this->service(PlacementWaivers::class);

        // Each seat's field starts at what is stored, and a seat that appeared since the last render
        // gets one; a value the developer is part-way through typing is left alone
        foreach ($mine as $seat) {
            $this->capacities[$seat->id] ??= $seat->max_capacity;
        }

        return view($template, [
            'seats' => $mine,
            'rules' => PlacementRule::cases(),
            'waived' => array_combine(
                array_map(static fn (Seat $seat): int => $seat->id, $mine),
                array_map(static fn (Seat $seat): array => $waivers->waivedOn($seat->id), $mine)
            ),
            'hours' => $settings->hours($developer),
            'holidays' => $settings->holidays($developer),
        ]);
    }

    /**
     * A placement rule from a rendered control, or a 422.
     *
     * @param  string  $rule  The rule's value.
     * @return PlacementRule The rule.
     *
     * @throws UnprocessableEntityHttpException When it is not one.
     */
    private function rule(string $rule): PlacementRule
    {
        return PlacementRule::tryFrom($rule) ?? throw new UnprocessableEntityHttpException(sprintf('Rules are: %s.', implode(', ', PlacementRule::values())));
    }

    /**
     * The signed-in developer's host key, or a refusal.
     *
     * @return string The key.
     *
     * @throws AccessDeniedHttpException When the package's guard resolves nobody.
     */
    private function developer(): string
    {
        $key = $this->service(CurrentDeveloper::class)->key();

        if ($key === null) {
            throw new AccessDeniedHttpException('Sign in to change your own seats and hours.');
        }

        return $key;
    }

    /**
     * Run a write, turning a refused value into a message on the page rather than a 500.
     *
     * @param  string  $at  Where the page shows the refusal.
     * @param  string  $refusal  The word the refusal leads with, such as `Not saved:`.
     * @param  callable(): mixed  $write  The write.
     * @return bool Whether it was written.
     */
    private function attempt(string $at, string $refusal, callable $write): bool
    {
        try {
            $write();

            return true;
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->say($at, $refusal.' '.$invalidArgumentException->getMessage(), refused: true);

            return false;
        }
    }

    /**
     * Say what a seat write did, or why it did nothing.
     *
     * @param  int  $seatId  The seat.
     * @param  Outcome  $outcome  What came of it.
     * @param  string  $applied  What to say when it was applied, with `%s` for the seat's name and
     *                           any further `%s` for `$more`.
     * @param  string  ...$more  The rest of what `$applied` names.
     */
    private function report(int $seatId, Outcome $outcome, string $applied, string ...$more): void
    {
        $at = 'seat-'.$seatId;

        match ($outcome) {
            // `Added` is a task's alone (#433); no seat write answers it
            Outcome::Applied, Outcome::Added => $this->say($at, sprintf($applied, $this->seatName($seatId), ...$more)),
            Outcome::NotFound => $this->say($at, 'Not found: that seat no longer exists. Reload the page to see your seats as they are now.', refused: true),
            Outcome::Conflict => $this->say($at, sprintf('No change: %s was already in that state. The page shows where it stands now.', $this->seatName($seatId)), refused: true),
            Outcome::Forbidden => $this->say($at, "Not allowed: only the developer who parked a seat can lift it, and only a seat's own developer can change it.", refused: true),
        };
    }

    /**
     * A seat of this developer's, named as the page names it, or `that seat`.
     *
     * Read only among this developer's own seats, so a refused write about somebody else's seat
     * never learns that seat's repository.
     *
     * @param  int  $seatId  The seat.
     * @return string Its repository and working folder.
     */
    private function seatName(int $seatId): string
    {
        $seat = Seat::query()->whereKey($seatId)->where('user_id', HostKey::from($this->developer()))->first();

        if (! $seat instanceof Seat) {
            return 'that seat';
        }

        return $seat->repository.($seat->work_location !== '' ? ' / '.$seat->work_location : '');
    }

    /**
     * Record what the last action did, for the page to show beside the control that caused it.
     *
     * @param  string  $at  Where: `seat-` and a seat id, `hours`, or `days-off`.
     * @param  string  $words  What happened, leading with the word that sums it up.
     * @param  bool  $refused  Whether it was refused or changed nothing.
     */
    private function say(string $at, string $words, bool $refused = false): void
    {
        $this->said = $words;
        $this->saidAt = $at;
        $this->refused = $refused;
        $this->capacitySeat = null;
        $this->capacityError = null;
    }

    /**
     * One service, resolved out of the application.
     *
     * A Livewire component is hydrated from a snapshot rather than constructed, so nothing can be
     * injected into it. Typed through a generic so the analyzer keeps narrowing what comes back.
     *
     * @template TService of object
     *
     * @param  class-string<TService>  $abstract  What to resolve.
     * @return TService The service.
     *
     * @throws RuntimeException When the application answers with something else.
     */
    private function service(string $abstract): object
    {
        $service = app($abstract);

        if (! $service instanceof $abstract) {
            throw new RuntimeException(sprintf('robot-council resolved something other than %s.', $abstract));
        }

        return $service;
    }
}
