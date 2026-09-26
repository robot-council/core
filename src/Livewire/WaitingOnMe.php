<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RobotCouncil\Access\CurrentDeveloper;
use RobotCouncil\Support\HostUsers;
use RobotCouncil\Support\PollInterval;
use RobotCouncil\Support\WaitingOnDeveloper;

/**
 * What the fleet is waiting on the signed-in developer for (#411), and nothing else.
 *
 * The lane board's "Waiting on a developer" card is a coordinator's view of everyone's items; this
 * page filters it to one developer, and adds the lanes held with them as the party, which the board
 * shows only on each lane. Read-only, behind the same allowlist gate as the lane board, and polled
 * the same way, so an item settled elsewhere leaves on the next refresh.
 *
 * **Whose page it is comes from the package's own guard**, through `Access\CurrentDeveloper`, never
 * from anything a client sends: a developer cannot ask for another developer's list.
 */
#[Layout('robot-council::layouts.dashboard')]
#[Title('Waiting on me')]
final class WaitingOnMe extends Component
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
     */
    public function mount(Repository $config): void
    {
        $this->pollSeconds = PollInterval::orConfig(null, $config);
    }

    /**
     * Render the page.
     *
     * @param  WaitingOnDeveloper  $waiting  The reader.
     * @param  HostUsers  $users  Resolves the signed-in developer's GitHub login.
     * @param  CurrentDeveloper  $developer  Who is signed in, on the package's own guard.
     * @return View The page.
     */
    public function render(WaitingOnDeveloper $waiting, HostUsers $users, CurrentDeveloper $developer): View
    {
        // Pinned, as every panel pins it: whether the analyzer resolves a package view depends on
        // whether it could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.waiting-on-me';

        // Nobody signed in cannot reach this page past the gate; a developer with no recorded login
        // is waited on for nothing the fleet can name
        $login = $users->githubLoginForKey($developer->user()?->getAuthIdentifier());

        return view($template, [
            'login' => $login,
            'waiting' => $login === null ? ['owed' => [], 'holds' => []] : $waiting->for($login),
        ]);
    }
}
