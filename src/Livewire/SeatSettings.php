<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RobotCouncil\Access\CurrentDeveloper;
use RobotCouncil\Support\DeveloperSettings;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\Seats;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A developer's own seats, assignment hours and days off (#322).
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
     * What the last action did not do, in words, or null when it did what was asked.
     */
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

        return view($template, [
            'seats' => $seats->forDeveloper($developer),
            'hours' => $settings->hours($developer),
            'holidays' => $settings->holidays($developer),
        ]);
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
