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
use RobotCouncil\Models\PlacementRule;
use RobotCouncil\Models\Seat;
use RobotCouncil\Support\Capacity;
use RobotCouncil\Support\DeveloperSettings;
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
     * What the last action did not do, in words, or null when it did what was asked.
     *
     * Locked, so only the server sets it: a client could otherwise put any sentence it liked in
     * the page's alert, which is harmless on its own page and still not the page's to say.
     */
    #[Locked]
    public ?string $notice = null;

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
        $this->attempt(fn () => $this->service(DeveloperSettings::class)->setHours(
            $this->developer(),
            trim($this->timezone),
            $this->startsAt,
            $this->endsAt,
            $this->skipWeekends
        ));
    }

    /**
     * Remove the window, so nothing gates this developer's seats.
     */
    public function clearHours(): void
    {
        $this->service(DeveloperSettings::class)->clearHours($this->developer());

        $this->notice = null;
    }

    /**
     * Add the day the form holds.
     */
    public function addHoliday(): void
    {
        $developer = $this->developer();

        $this->attempt(function () use ($developer): void {
            $this->service(DeveloperSettings::class)->addHoliday($developer, trim($this->holiday));
            $this->holiday = '';
        });
    }

    /**
     * Remove a day off.
     *
     * @param  string  $day  The date, as the page rendered it.
     */
    public function removeHoliday(string $day): void
    {
        $this->service(DeveloperSettings::class)->removeHoliday($this->developer(), $day);

        $this->notice = null;
    }

    /**
     * Say a seat takes no work.
     *
     * @param  int  $seatId  The seat.
     */
    public function park(int $seatId): void
    {
        $this->report($this->service(Seats::class)->park($this->developer(), $seatId));
    }

    /**
     * Let a seat this developer parked take work again.
     *
     * @param  int  $seatId  The seat.
     */
    public function lift(int $seatId): void
    {
        $this->report($this->service(Seats::class)->lift($this->developer(), $seatId));
    }

    /**
     * Exempt a seat from this developer's assignment hours.
     *
     * @param  int  $seatId  The seat.
     */
    public function exempt(int $seatId): void
    {
        $this->report($this->service(Seats::class)->exempt($this->developer(), $seatId, true));
    }

    /**
     * Hold a seat to this developer's assignment hours again.
     *
     * @param  int  $seatId  The seat.
     */
    public function unexempt(int $seatId): void
    {
        $this->report($this->service(Seats::class)->exempt($this->developer(), $seatId, false));
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
        $typed = $this->capacities[$seatId] ?? null;
        $capacity = \is_int($typed) ? $typed : (\is_string($typed) && preg_match('/^[0-9]{1,3}$/D', trim($typed)) === 1 ? (int) trim($typed) : null);

        if ($capacity === null || $capacity < Capacity::DEFAULT || $capacity > Capacity::MAX) {
            $this->notice = sprintf('A seat takes from %d to %d tickets at once.', Capacity::DEFAULT, Capacity::MAX);

            return;
        }

        $this->report($this->service(Seats::class)->cap($this->developer(), $seatId, $capacity));
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
        $this->report($this->service(PlacementWaivers::class)->grant($this->developer(), $seatId, $this->rule($rule)));
    }

    /**
     * Withdraw a waiver no placement has used yet.
     *
     * @param  int  $seatId  The seat.
     * @param  string  $rule  The rule, as the page rendered it.
     */
    public function withdrawWaiver(int $seatId, string $rule): void
    {
        $this->service(PlacementWaivers::class)->withdraw($this->developer(), $seatId, $this->rule($rule));

        $this->notice = null;
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
     * @param  callable(): mixed  $write  The write.
     */
    private function attempt(callable $write): void
    {
        try {
            $write();
            $this->notice = null;
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->notice = $invalidArgumentException->getMessage();
        }
    }

    /**
     * Say why a seat write did not happen.
     *
     * @param  Outcome  $outcome  What came of it.
     */
    private function report(Outcome $outcome): void
    {
        $this->notice = match ($outcome) {
            Outcome::Applied => null,
            Outcome::NotFound => 'That seat no longer exists.',
            Outcome::Conflict => 'That seat was already in that state. The page has been refreshed.',
            Outcome::Forbidden => "Only the developer who parked a seat can lift it, and only a seat's own developer can change it.",
        };
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
