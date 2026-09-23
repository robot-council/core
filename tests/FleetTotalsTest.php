<?php

declare(strict_types=1);

/**
 * The three totals above the panels: what they count, that they count rather than measure a page,
 * and that they cannot disagree with the panel beneath them.
 *
 * @command  vendor/bin/pest --compact tests/FleetTotalsTest.php
 */

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RobotCouncil\Access\Ability;
use RobotCouncil\Livewire\Dashboard;
use RobotCouncil\Livewire\FleetPresence as PresencePanel;
use RobotCouncil\Livewire\FleetTotals;
use RobotCouncil\Livewire\TaskBoard;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\FleetPresence;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\TaskList;
use RobotCouncil\Support\Tasks;

/**
 * The number rendered inside one tile.
 *
 * Read from within the tile that carries the label rather than searched for across the row: three
 * tiles sit side by side and a document-wide match is satisfied by whichever of them happens to
 * hold the number.
 *
 * @param  string  $html  The rendered row.
 * @param  string  $label  The tile's title.
 * @return int|null The total, or null when the tile or its value is absent.
 */
function tileTotal(string $html, string $label): ?int
{
    $matched = preg_match(
        '/'.preg_quote($label, '/').'<\\/div>\\s*<div class="stat-value[^"]*">\\s*(\\d+)/',
        $html,
        $found
    );

    return $matched === 1 ? (int) $found[1] : null;
}

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

it('shows a zero rather than an empty tile', function (): void {
    $this->actingAs($this->developer, 'web');

    // An empty fleet is the state a host sees on the day they install this, so it is the one the
    // row has to render honestly rather than blankly.
    Livewire::test(FleetTotals::class)
        ->assertOk()
        ->assertSee('Live agents')
        ->assertSee('Open tasks')
        ->assertSee('Held locks')
        ->assertSeeHtml('>0<');
});

it('counts the fleet rather than the page', function (): void {
    // Over every panel bound: `FleetPresence::SESSIONS` and `::LOCKS` are 50, `TaskBoard::PER_PAGE`
    // is 25. A total taken as `count()` over a page would report the bound, and a capped total is
    // indistinguishable from a real one -- which is the failure this row exists to prevent.
    // Sessions exceeds locks, because each lock is acquired by one of the sessions below -- asking
    // for more locks than sessions silently produces fewer than requested.
    $sessions = PresencePanel::SESSIONS + 2;
    $locks = PresencePanel::LOCKS + 1;
    $tasks = TaskBoard::PER_PAGE + 1;

    // **The three counts are deliberately different from one another.** With all three equal, an
    // assertion that the sessions tile reads 51 is satisfied by the locks tile reading 51 -- which
    // is exactly what happened to the first draft of this test: a mutation that made the session
    // total measure a page left it green, because another tile still carried the number.
    expect([$sessions, $locks, $tasks])->toHaveSameSize(array_unique([$sessions, $locks, $tasks]));

    $first = null;

    for ($i = 0; $i < $sessions; $i++) {
        $installation = $this->approveInstallation($this->developer, [
            Ability::TasksCreate->value,
            Ability::LocksAcquire->value,
        ], 'box-'.$i);

        [$session, $token] = $this->startAgentSession($installation);

        $first ??= $session;
        $this->session = $session;

        // One lock each on the first `$locks` sessions, so the lock total clears its own bound
        // without any session passing `robot-council.locks.max_per_session`
        if ($i < $locks) {
            $this->service(Locks::class)->acquire($session, 'lock-'.$i, 60, false);
        }
    }

    for ($i = 0; $i < $tasks; $i++) {
        $this->service(Tasks::class)->create($first, ['title' => 'Task '.$i], withCoordinator: false);
    }

    $this->actingAs($this->developer, 'web');

    $row = Livewire::test(FleetTotals::class);

    $row->assertOk();

    $html = (string) $row->html();

    // Each number bound to its own tile, not merely present somewhere on the row
    foreach ([['Live agents', $sessions], ['Open tasks', $tasks], ['Held locks', $locks]] as [$label, $total]) {
        expect(tileTotal($html, $label))->toBe($total);
    }
});

it('counts every status that is not terminal, and no other', function (): void {
    $installation = $this->approveInstallation($this->developer, [
        Ability::TasksCreate->value,
        Ability::TasksClaim->value,
    ]);

    [$this->session, $this->token] = $this->startAgentSession($installation);

    $store = $this->service(TaskList::class);

    // One task in each status the enum holds, driven through the model so nothing depends on the
    // transition rules. The open count is then the non-terminal cases, derived from
    // `TaskStatus::terminal()` rather than listed here -- adding a status forces the decision.
    foreach (TaskStatus::cases() as $status) {
        $task = $this->service(Tasks::class)->create($this->session, ['title' => 'A '.$status->value], withCoordinator: false);

        $task->forceFill(['status' => $status])->save();
    }

    $open = \count(TaskStatus::cases()) - \count(TaskStatus::terminal());

    expect($store->openTasks())->toBe($open)
        ->and($open)->toBe(4);
});

it('cannot disagree with the panel beneath it', function (): void {
    $installation = $this->approveInstallation($this->developer, [
        Ability::TasksCreate->value,
        Ability::LocksAcquire->value,
    ]);

    [$this->session, $this->token] = $this->startAgentSession($installation);

    $this->service(Locks::class)->acquire($this->session, 'deploy', 60, false);

    $presence = $this->service(FleetPresence::class);

    // The tile and the panel read the same store with the same predicate, which is the point of
    // putting these methods there rather than in a store of their own. Asserted rather than trusted.
    expect($presence->liveSessions())->toBe($presence->sessions(50)['live'])
        ->and($presence->heldLocks())->toBe($presence->locks(50)['held']);
});

it('polls itself rather than riding the index', function (): void {
    $this->actingAs($this->developer, 'web');

    // A parent refresh does not re-execute a child, so a `wire:poll` on the index would leave this
    // row stale while the panels below it updated.
    Livewire::test(FleetTotals::class)
        ->assertOk()
        ->assertSeeHtml('wire:poll.'.Dashboard::DEFAULT_POLL_SECONDS.'s');

    // And the index still carries none of its own. Comments are stripped first: the index carries
    // a `{{-- --}}` block explaining why it has no `wire:poll`, and a check that read it would fail
    // on the prose rather than on the markup -- which is what the first draft of this did.
    $index = (string) file_get_contents(__DIR__.'/../resources/views/livewire/dashboard.blade.php');

    $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $index);

    expect($markup)->not->toContain('wire:poll')

        // The control: the comment really does mention it, so the strip is doing work rather than
        // the file simply not containing the string
        ->and($index)->toContain('wire:poll');
});

it('will not let a client set its polling interval', function (): void {
    $this->actingAs($this->developer, 'web');

    // The snapshot's checksum covers the snapshot rather than the `updates` map, so a public
    // property without `#[Locked]` is writable by whatever posts to `/livewire/update` -- and a
    // zero would ask the browser to poll as fast as it can.
    Livewire::test(FleetTotals::class)->set('pollSeconds', 0);
})->throws(CannotUpdateLockedPropertyException::class);

it('renders on three queries, one per total', function (): void {
    $installation = $this->approveInstallation($this->developer, [
        Ability::TasksCreate->value,
        Ability::LocksAcquire->value,
    ]);

    [$this->session, $this->token] = $this->startAgentSession($installation);

    $this->actingAs($this->developer, 'web');

    // One counting query each, and nothing per row. This row is added to a poll cycle every panel
    // already pays for, so what it costs is worth pinning rather than discovering later.
    expect(queriesIssuedBy(fn () => Livewire::test(FleetTotals::class)))->toBe(3);
});

it('does not grow with the fleet', function (int $sessions): void {
    for ($i = 0; $i < $sessions; $i++) {
        $installation = $this->approveInstallation($this->developer, [Ability::LocksAcquire->value], 'box-'.$i);
        [$this->session, $this->token] = $this->startAgentSession($installation);
        $this->service(Locks::class)->acquire($this->session, 'lock-'.$i, 60, false);
    }

    $this->actingAs($this->developer, 'web');

    expect(queriesIssuedBy(fn () => Livewire::test(FleetTotals::class)))->toBe(3);
})->with([1, 5, 20]);

it('appears on the dashboard above the panels', function (): void {
    $this->actingAs($this->developer, 'web');

    $page = $this->get(route('robot-council.dashboard'))->assertOk();

    $html = (string) $page->getContent();

    expect(strpos($html, 'Live agents'))->toBeLessThan((int) strpos($html, 'id="robot-council-presence"'));
});
