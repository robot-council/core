<?php

declare(strict_types=1);

/**
 * Each session row on the administration page names its id and when it joined and was last seen
 * (#419), so two sessions in one checkout -- a restarted agent beside the one it replaced -- can be
 * told apart before one is revoked.
 *
 * @command  vendor/bin/pest --compact tests/AdminSessionIdentityTest.php
 */

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Support\AgentSessions;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242], admins: [4242]);

    $this->admin = $this->enrollDeveloper(4242, login: 'octoadmin');
});

/**
 * Each session row's text, keyed by the session id it names.
 *
 * @return array<int, string>
 */
function adminSessionRows(string $html): array
{
    preg_match_all('/<li wire:key="admin-session-(\d+)"[^>]*>(.*?)<\/li>/s', $html, $rows, PREG_SET_ORDER);

    $text = [];

    foreach ($rows as $row) {
        $text[(int) $row[1]] = trim((string) preg_replace('/\s+/', ' ', strip_tags($row[2])));
    }

    return $text;
}

it('tells apart two live sessions in the same checkout with the same role', function (): void {
    $installation = $this->approveInstallation($this->admin, 'josh-office');
    $sessions = app(AgentSessions::class);

    $this->travelTo(CarbonImmutable::parse('2026-09-26 08:00:00'));
    $old = $sessions->start($installation, 'robot-council/coordinator', 'primary')->owner;

    // The restart an administrator has to sort out: a second session in the same place, an hour on
    $this->travelTo(CarbonImmutable::parse('2026-09-26 09:00:00'));
    $new = $sessions->start($installation, 'robot-council/coordinator', 'primary')->owner;

    // The old one went quiet twenty minutes ago; the new one was heard from just now
    AgentSession::query()->whereKey($old->id)->update(['last_seen_at' => '2026-09-26 08:40:00']);
    $this->travelTo(CarbonImmutable::parse('2026-09-26 09:00:30'));

    $html = Livewire::actingAs($this->admin)->test(Administration::class)->html();
    $rows = adminSessionRows($html);

    expect($old->role)->toBe($new->role)
        ->and(array_keys($rows))->toBe([$old->id, $new->id])
        // The same repository, place and role in both, and yet two different rows
        ->and($rows[$old->id])->toContain('robot-council/coordinator', 'primary')
        ->and($rows[$new->id])->toContain('robot-council/coordinator', 'primary')
        ->and($rows[$old->id])->not->toBe($rows[$new->id])
        // Each names its own id, as agents do
        ->and($rows[$old->id])->toContain('#'.$old->id)
        ->and($rows[$new->id])->toContain('#'.$new->id)
        // And when it joined and was last seen
        ->and($rows[$old->id])->toContain('joined 2026-09-26 08:00 UTC (1 hour ago), last seen 2026-09-26 08:40 UTC (20 minutes ago)')
        ->and($rows[$new->id])->toContain('joined 2026-09-26 09:00 UTC (30 seconds ago), last seen 2026-09-26 09:00 UTC (30 seconds ago)')
        // The exact time is in the markup, for software rather than for hover
        ->and($html)->toContain('<time datetime="2026-09-26T08:00:00+00:00">')
        ->toContain('<time datetime="2026-09-26T08:40:00+00:00">');
});

it("shows the same instants on a host whose clock is not UTC, in the dashboard's zone", function (): void {
    // `created_at` is written on the application's clock and `last_seen_at` on the presence
    // clock, which is UTC (#51); both must still name the right instant
    $zone = date_default_timezone_get();
    config(['app.timezone' => 'Asia/Kolkata', 'robot-council.dashboard.timezone' => 'America/Chicago']);
    date_default_timezone_set('Asia/Kolkata');

    try {
        $this->travelTo(CarbonImmutable::parse('2026-09-26 08:00:00', 'UTC'));
        $session = app(AgentSessions::class)->start($this->approveInstallation($this->admin, 'josh-office'), 'robot-council/core', 'robot-council-core-a')->owner;
        $this->travelTo(CarbonImmutable::parse('2026-09-26 08:10:00', 'UTC'));

        $row = adminSessionRows(Livewire::actingAs($this->admin)->test(Administration::class)->html())[$session->id] ?? '';

        // 08:00 UTC is 03:00 in Chicago on that date
        expect($row)->toContain('joined 2026-09-26 03:00 CDT (10 minutes ago), last seen 2026-09-26 03:00 CDT (10 minutes ago)');
    } finally {
        date_default_timezone_set($zone);
    }
});

// Showing these adds no query: the id and both times are on the session rows the page already
// eager-loads, and `AdministrationQueryCostTest` holds the page at seven queries however many
// sessions each installation has.
