<?php

declare(strict_types=1);

/**
 * How long ago a session was last heard from, in words.
 *
 * The column read `187s ago` at three minutes and `172800s ago` after a weekend, while the queue's
 * `Age` beside it read `2 days ago`. This makes the two agree.
 *
 * @command  vendor/bin/pest --compact tests/LastSeenReadabilityTest.php
 */

use Livewire\Livewire;
use RobotCouncil\Livewire\Agents;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Support\FleetPresence;
use RobotCouncil\Support\PresenceClock;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);

    $installation = $this->approveInstallation($this->developer);

    [$this->session, $this->token] = $this->startAgentSession($installation);

    $this->actingAs($this->developer, 'web');
});

it('grows through the units rather than counting seconds forever', function (int $secondsAgo, string $expected): void {
    // **Frozen, because this test reads a difference between two clocks it takes at two moments.**
    // Nothing here is about elapsed time: the write below subtracts a fixed number of seconds, and
    // the render compares it against `PresenceClock::now()` again. On a machine slow enough for a
    // second to pass between them, `5` renders as `6 seconds ago` and every boundary row -- 5, 59,
    // 3600 -- crosses into the next unit. Observed on the `prefer-lowest` ubuntu cell of #216,
    // where this row failed alone while the suite was otherwise green; the test dates from #213
    // and the defect is its own, not that branch's.
    $this->freezeTime();

    // Written on the presence clock, which is the clock `last_seen_at` is compared against (#51).
    AgentSession::query()->whereKey($this->session->id)->update([
        'last_seen_at' => PresenceClock::now()->subSeconds($secondsAgo),
    ]);

    Livewire::test(Agents::class)
        ->assertOk()
        ->assertSee($expected);
})->with([
    'seconds stay seconds' => [5, '5 seconds ago'],
    'just under a minute' => [59, '59 seconds ago'],
    'a minute becomes minutes' => [60, '1 minute ago'],
    'minutes round down' => [187, '3 minutes ago'],
    'an hour becomes hours' => [3600, '1 hour ago'],
    'a weekend becomes days' => [172800, '2 days ago'],
]);

it('no longer renders a bare second count', function (): void {
    AgentSession::query()->whereKey($this->session->id)->update([
        'last_seen_at' => PresenceClock::now()->subSeconds(187),
    ]);

    $panel = Livewire::test(Agents::class);

    $panel->assertOk()->assertSee('3 minutes ago');

    // The shape the column had before. Asserted absent rather than only asserting the new shape
    // present, because both could be true if the view rendered two columns.
    expect((string) $panel->html())->not->toContain('187s ago');
});

it('keeps the machine-readable value the API and MCP tools read', function (): void {
    AgentSession::query()->whereKey($this->session->id)->update([
        'last_seen_at' => PresenceClock::now()->subSeconds(187),
    ]);

    // The store's contract is unchanged: it still returns an integer, and the words are the
    // panel's own decoration. Anything reading `seconds_since_contact` is unaffected.
    $described = $this->service(FleetPresence::class)->sessions(50)['sessions'];

    expect($described[0]['seconds_since_contact'])->toBeInt()
        ->toBeGreaterThanOrEqual(187)
        ->and($described[0])->not->toHaveKey('last_seen');
});

it('reads the age off the presence clock, not the application clock', function (): void {
    // The two clocks agree in this suite, so a test that only checked the rendered words would pass
    // whichever was used. What can be checked is that the words are derived from the integer the
    // store measured -- change the integer and the words follow, with no timestamp re-parsed.
    AgentSession::query()->whereKey($this->session->id)->update([
        'last_seen_at' => PresenceClock::now()->subSeconds(7200),
    ]);

    Livewire::test(Agents::class)->assertOk()->assertSee('2 hours ago');

    expect(file_get_contents(__DIR__.'/../src/Livewire/Agents.php'))
        ->toContain('CarbonInterval::seconds($seconds)')

        // A `Carbon::now()` in the formatting would put the displayed age on the application's
        // clock, which is the mistake this shape exists to make unavailable
        ->not->toContain('Carbon::parse($session');
});
