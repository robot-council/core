<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RobotCouncil\Support\AssignmentWindow;
use RobotCouncil\Support\LaneBoard;
use RobotCouncil\Support\PollInterval;

/**
 * The lane board: which lanes exist, what each is on, and what is queued (#317).
 *
 * Read-only, and every cell comes from `Support\LaneBoard`, which derives it from measured state.
 * The page renders; it decides nothing. It sits behind the same allowlist gate as every other panel,
 * and it is for the developer reading the dashboard -- #314 decided the coordinator's own to-do
 * items reach it as fleet events, not as this page.
 */
#[Layout('robot-council::layouts.dashboard')]
final class Lanes extends Component
{
    /**
     * The interval this page refreshes on, in seconds.
     */
    #[Locked]
    public int $pollSeconds = PollInterval::DEFAULT;

    /**
     * Take the polling interval.
     *
     * @param  Repository  $config  The application's configuration repository.
     * @param  int|null  $pollSeconds  The interval a parent passed, or null to read the host's.
     */
    public function mount(Repository $config, ?int $pollSeconds = null): void
    {
        $this->pollSeconds = PollInterval::orConfig($pollSeconds, $config);
    }

    /**
     * Render the board.
     *
     * @param  LaneBoard  $board  The board reader.
     * @param  Repository  $config  The application's configuration repository.
     * @return View The page.
     */
    public function render(LaneBoard $board, Repository $config): View
    {
        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.lanes';

        $timezone = $config->get('robot-council.dashboard.timezone');

        return view($template, [
            'board' => $board->read(),
            // A zone the host mistyped falls back rather than failing the page
            'timezone' => AssignmentWindow::isTimezone($timezone) && \is_string($timezone) ? $timezone : 'UTC',
        ]);
    }
}
