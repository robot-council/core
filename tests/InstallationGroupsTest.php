<?php

declare(strict_types=1);

/**
 * The administration page groups installations by developer, then machine, then harness (#518).
 *
 * Developers by login without regard to case and the unknown one last, machines by label, harnesses
 * by name. The grouping is over one page as read, so the cursor stays on `id` and a group that
 * began on an earlier page is marked continued rather than moved. Asserted on what
 * `InstallationList` returns and again on the rendered page, since a view that looped something
 * else would pass the first.
 *
 * @command  vendor/bin/pest --compact tests/InstallationGroupsTest.php
 */

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\InstallationList;
use RobotCouncil\Support\Scope;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 701, 702, 703], admins: [4242]);

    $this->admin = $this->enrollDeveloper(4242, login: 'octoadmin');

    // `Zara` sorts before `bob` byte for byte, since every capital precedes every small letter
    $this->zara = $this->enrollDeveloper(701, login: 'Zara');
    $this->bob = $this->enrollDeveloper(702, login: 'bob');

    // A developer whose GitHub identity is gone, so the page has no login to show
    $this->ghost = $this->enrollDeveloper(703, login: 'ghost');
    GithubIdentity::query()->where('user_id', $this->ghost->getKey())->delete();
});

/**
 * An approved installation with a harness.
 *
 * @param  TestCase  $case  The test case.
 * @param  User  $user  Whose.
 * @param  string  $machine  Its machine label.
 * @param  string  $harness  Its harness.
 * @return Installation The installation.
 */
function groupedInstallation(TestCase $case, User $user, string $machine, string $harness): Installation
{
    $installation = $case->approveInstallation($user, machineLabel: $machine);
    Installation::query()->whereKey($installation->id)->update(['harness' => $harness]);

    return $installation;
}

/**
 * A page's groups as developer => machine => harnesses, with "+" marking a continued group.
 *
 * @param  array{groups: list<array{developer: string|null, continued: bool, machines: list<array{machine: string, continued: bool, installations: list<array<string, mixed>>}>}>, ...}  $page  The page.
 * @return list<string> One line per installation, in the page's order.
 */
function groupLines(array $page): array
{
    $lines = [];

    foreach ($page['groups'] as $group) {
        foreach ($group['machines'] as $machine) {
            foreach ($machine['installations'] as $installation) {
                $lines[] = sprintf(
                    '%s%s / %s%s / %s',
                    $group['developer'] ?? '(unknown)',
                    $group['continued'] ? '+' : '',
                    $machine['machine'],
                    $machine['continued'] ? '+' : '',
                    is_string($installation['harness'] ?? null) ? $installation['harness'] : '?'
                );
            }
        }
    }

    return $lines;
}

/**
 * Seed the fleet the grouping tests read: two developers, one with two machines and two harnesses
 * on one of them, and one installation whose developer is unknown.
 *
 * @param  TestCase  $case  The test case.
 * @param  User  $zara  The developer with two machines.
 * @param  User  $bob  The developer with one.
 * @param  User  $ghost  The developer the fleet no longer knows.
 */
function seedGroupedFleet(TestCase $case, User $zara, User $bob, User $ghost): void
{
    // Created out of order, so the order the page shows is the grouping's rather than the ids'
    // and `codex` is newer than `claude-code` on the same machine, so newest-first would list it first
    groupedInstallation($case, $ghost, 'ghost-box', 'claude-code');
    groupedInstallation($case, $zara, 'zeta-box', 'claude-code');
    groupedInstallation($case, $bob, 'bob-box', 'claude-code');
    groupedInstallation($case, $zara, 'alpha-box', 'claude-code');
    groupedInstallation($case, $zara, 'zeta-box', 'codex');
}

it('groups by developer, then machine, then harness, with an unknown developer last', function (): void {
    seedGroupedFleet($this, $this->zara, $this->bob, $this->ghost);

    expect(groupLines($this->service(InstallationList::class)->everything(50)))->toBe([
        'bob / bob-box / claude-code',
        'Zara / alpha-box / claude-code',
        'Zara / zeta-box / claude-code',
        'Zara / zeta-box / codex',
        '(unknown) / ghost-box / claude-code',
    ]);
});

it('reaches every installation exactly once across pages, marking a group that continues', function (): void {
    seedGroupedFleet($this, $this->zara, $this->bob, $this->ghost);

    $list = $this->service(InstallationList::class);
    $pages = [];
    $seen = [];
    $after = null;

    do {
        $page = $list->everything(2, Scope::Live, $after);
        $pages[] = groupLines($page);

        foreach ($page['installations'] as $installation) {
            $seen[] = is_int($installation['id'] ?? null) ? $installation['id'] : 0;
        }

        $after = $page['cursor'];
    } while ($page['more']);

    // Newest first by id, two to a page: Zara's zeta-box codex and alpha-box claude-code, then bob
    // and Zara's zeta-box claude-code, then the unknown developer's
    expect($pages)->toBe([
        ['Zara / alpha-box / claude-code', 'Zara / zeta-box / codex'],
        ['bob / bob-box / claude-code', 'Zara+ / zeta-box+ / claude-code'],
        ['(unknown) / ghost-box / claude-code'],
    ])
        ->and($seen)->toHaveCount(5)
        ->and(array_unique($seen))->toHaveCount(5)
        ->and($seen)->toEqualCanonicalizing(Installation::query()->pluck('id')->all());
});

it('does not mark a developer continued when only another developer was on an earlier page', function (): void {
    groupedInstallation($this, $this->bob, 'bob-box', 'claude-code');
    groupedInstallation($this, $this->zara, 'zeta-box', 'claude-code');

    $list = $this->service(InstallationList::class);
    $first = $list->everything(1);

    expect(groupLines($list->everything(1, Scope::Live, $first['cursor'])))->toBe(['bob / bob-box / claude-code']);
});

it('marks a developer continued and not the machine, when the machine is new on this page', function (): void {
    groupedInstallation($this, $this->zara, 'alpha-box', 'claude-code');
    groupedInstallation($this, $this->zara, 'zeta-box', 'claude-code');

    $list = $this->service(InstallationList::class);
    $first = $list->everything(1);

    expect(groupLines($list->everything(1, Scope::Live, $first['cursor'])))->toBe(['Zara+ / alpha-box / claude-code']);
});

it('renders the headings nested, each entry under its machine, naming neither its machine nor its developer', function (): void {
    seedGroupedFleet($this, $this->zara, $this->bob, $this->ghost);

    $html = withoutAvatars(Livewire::actingAs($this->admin)->test(Administration::class)->html());

    preg_match_all('#<(h[123])[^>]*>\s*(.*?)\s*</\1>#s', $html, $headings, PREG_SET_ORDER);

    $outline = array_map(
        static fn (array $m): string => $m[1].' '.trim((string) preg_replace('/\s+/', ' ', strip_tags($m[2]))),
        $headings
    );

    expect($outline)->toBe([
        'h1 Installations',
        'h2 bob',
        'h3 bob-box',
        'h2 Zara',
        'h3 alpha-box',
        'h3 zeta-box',
        'h2 Unknown developer',
        'h3 ghost-box',
    ]);

    // Each harness entry names its harness alone
    expect($html)
        ->not->toContain('approved for')
        ->not->toContain(' on <code>')
        ->toContain('<code>codex</code>');
});

it('says "(continued)" on a page that carries on a group from the one before', function (): void {
    groupedInstallation($this, $this->zara, 'zeta-box', 'claude-code');
    $newer = groupedInstallation($this, $this->zara, 'zeta-box', 'codex');

    // The first page holds both, so nothing on it continues
    $first = Livewire::actingAs($this->admin)->test(Administration::class)->html();

    expect($first)->not->toContain('(continued)');

    // The page after the newer one lists the older alone, under its developer and machine, each
    // marked as carried on from the page before
    $next = withoutAvatars(Livewire::actingAs($this->admin)->test(Administration::class)
        ->call('showNext', $newer->id)
        ->html());

    preg_match_all('#<(h[23])[^>]*>\s*(.*?)\s*</\1>#s', $next, $headings, PREG_SET_ORDER);

    expect(array_map(static fn (array $m): string => $m[1].' '.trim((string) preg_replace('/\s+/', ' ', strip_tags($m[2]))), $headings))->toBe([
        'h2 Zara (continued)',
        'h3 zeta-box (continued)',
    ])->and($next)->toContain('<code>claude-code</code>')
        ->not->toContain('<code>codex</code>');
});
