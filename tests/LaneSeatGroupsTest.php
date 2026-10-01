<?php

declare(strict_types=1);

/**
 * The Lanes page groups a repository's seats by role, then by developer (#514).
 *
 * Roles read coordinator, build, gate, and a role with no seat is left out; developers read by
 * login without regard to case, and a seat whose developer is not known comes last. The order is
 * asserted on the groups `LaneBoard::seatGroups()` returns and again on the rendered page, since
 * the page is what a developer reads and a view that looped something else would pass the first.
 *
 * @command  vendor/bin/pest --compact tests/LaneSeatGroupsTest.php
 */

use Livewire\Livewire;
use RobotCouncil\Access\Role;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\LaneBoard;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [501, 502, 503]);

    $this->zed = $this->approveInstallation($this->enrollDeveloper(501, login: 'zed'), machineLabel: 'zed-box');
    $this->bob = $this->approveInstallation($this->enrollDeveloper(502, login: 'bob'), machineLabel: 'bob-box');

    // A developer whose GitHub identity is gone, so the board has no login to show
    $unknown = $this->enrollDeveloper(503, login: 'ghost');
    $this->unknown = $this->approveInstallation($unknown, machineLabel: 'ghost-box');
    GithubIdentity::query()->where('user_id', $unknown->getKey())->delete();
});

/**
 * A seat in `robot-council/core` with a role.
 *
 * @param  TestCase  $case  The test case.
 * @param  Installation  $installation  Whose machine.
 * @param  string  $slot  Its slot.
 * @param  Role  $role  Its role.
 * @return AgentSession The seat.
 */
function groupedSeat(TestCase $case, Installation $installation, string $slot, Role $role): AgentSession
{
    $session = $case->service(AgentSessions::class)->start($installation, 'robot-council/core', 'robot-council-core-'.$slot)->owner;

    AgentSession::query()->whereKey($session->getKey())->update(['role' => $role->value]);

    return $session;
}

/**
 * The repository's groups, as role => list of developers, each developer naming its seats' slots.
 *
 * @param  TestCase  $case  The test case.
 * @return array<string, array<string, list<string|null>>> The shape.
 */
function groupShape(TestCase $case): array
{
    $shape = [];

    foreach (LaneBoard::seatGroups($case->service(LaneBoard::class)->read()['lanes']['robot-council/core']) as $group) {
        // Recorded before its developers, so a role returned with none is seen rather than skipped
        $shape[$group['role']->value] = [];

        foreach ($group['developers'] as $developer) {
            $shape[$group['role']->value][$developer['developer'] ?? '(unknown)'] = array_map(
                static fn (array $lane): ?string => $lane['slot'],
                $developer['lanes']
            );
        }
    }

    return $shape;
}

it('groups a repository by role, then by developer, with an unknown developer last', function (): void {
    // Created out of order, so the order below is the board's rather than the insertion's
    groupedSeat($this, $this->unknown, 'u', Role::Build);
    groupedSeat($this, $this->zed, 'g', Role::Ci);
    groupedSeat($this, $this->zed, 'b', Role::Build);
    groupedSeat($this, $this->bob, 'c', Role::Coordinator);
    groupedSeat($this, $this->bob, 'a', Role::Build);
    groupedSeat($this, $this->bob, 'h', Role::Ci);

    expect(groupShape($this))->toBe([
        'coordinator' => ['bob' => ['robot-council-core-c']],
        'build' => [
            'bob' => ['robot-council-core-a'],
            'zed' => ['robot-council-core-b'],
            '(unknown)' => ['robot-council-core-u'],
        ],
        'ci' => [
            'bob' => ['robot-council-core-h'],
            'zed' => ['robot-council-core-g'],
        ],
    ]);
});

it('leaves out a role with no seats', function (): void {
    groupedSeat($this, $this->zed, 'a', Role::Build);

    expect(array_keys(groupShape($this)))->toBe(['build']);
});

it('sorts developers by login without regard to case', function (): void {
    // `Zara` sorts before `bob` byte for byte, since every capital precedes every small letter
    $zara = $this->approveInstallation($this->enrollDeveloper(504, login: 'Zara'), machineLabel: 'zara-box');
    $this->setAccessLists(developers: [501, 502, 503, 504]);

    groupedSeat($this, $this->zed, 'a', Role::Build);
    groupedSeat($this, $zara, 'b', Role::Build);
    groupedSeat($this, $this->bob, 'c', Role::Build);

    expect(array_keys(groupShape($this)['build']))->toBe(['bob', 'Zara', 'zed']);
});

it('renders the groups in order, nested, each table named, and no seat line repeating its developer', function (): void {
    groupedSeat($this, $this->unknown, 'u', Role::Build);
    groupedSeat($this, $this->zed, 'g', Role::Ci);
    groupedSeat($this, $this->zed, 'b', Role::Build);
    groupedSeat($this, $this->bob, 'c', Role::Coordinator);
    groupedSeat($this, $this->bob, 'a', Role::Build);

    $html = withoutAvatars(Livewire::test(Lanes::class)->html());

    // Every heading and caption in reading order: repository, then role, then developer
    preg_match_all('#<(h[234])[^>]*>\s*(.*?)\s*</\1>|<caption class="sr-only">(.*?)</caption>#s', $html, $matches, PREG_SET_ORDER);

    $outline = array_map(
        static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[1].' '.trim(strip_tags($m[2] ?? '')) : 'caption '.($m[3] ?? ''),
        $matches
    );

    // From the repository's own heading, past whatever the page shows above the boards
    $start = array_search('h2 robot-council/core', $outline, true);

    expect($start)->toBeInt()
        ->and(array_slice($outline, is_int($start) ? $start : 0))->toBe([
            'h2 robot-council/core',
            'h3 Coordinator seats',
            'h4 bob',
            'caption bob, coordinator seats',
            'h3 Build seats',
            'h4 bob',
            'caption bob, build seats',
            'h4 zed',
            'caption zed, build seats',
            'h4 Unknown developer',
            'caption Unknown developer, build seats',
            'h3 Gate seats',
            'h4 zed',
            'caption zed, gate seats',
            'h3 Pull requests',
            // The next card, so nothing of the repository's comes after its pull requests
            'h2 Waiting on a developer',
        ]);

    // The seat line is the label alone, then the harness alone: no login, no "gate" suffix
    expect($html)
        ->toContain('<div class="font-medium"><code>core/zed-box/g</code></div>')
        ->toContain('<div class="text-meta opacity-90"><code>claude-code</code></div>')
        ->not->toContain('&middot; zed')
        ->not->toContain('&middot; gate')
        ->not->toContain('unknown developer');
});

it('avatars each developer heading', function (): void {
    groupedSeat($this, $this->zed, 'a', Role::Build);

    $html = Livewire::test(Lanes::class)->html();

    // The avatar and then the login, inside the one heading: `Z` is the avatar's letter, hidden
    // from a screen reader, and Livewire's block markers sit between
    preg_match('#<h4[^>]*>((?:(?!</h4>).)*)</h4>#s', $html, $heading);

    expect($heading[1] ?? '')->toContain('data-avatar')
        ->and(trim(strip_tags($heading[1] ?? '')))->toBe('Zzed');
});
