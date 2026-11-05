<?php

declare(strict_types=1);

/**
 * A developer's seats under one heading per machine (#587).
 *
 * The page is a presentation change over rows it already loaded, so each test reads the rendered
 * markup for what a reader sees: which heading a seat sits under, in what order, and what a machine
 * nothing uses any more is called.
 *
 * @command  vendor/bin/pest --compact tests/SeatGroupingTest.php
 */

use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RobotCouncil\Livewire\SeatSettings;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Seats;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [5901]);

    $this->alice = $this->enrollDeveloper(5901, login: 'alice-dev');
});

/**
 * Start a session for an installation in a place, which records the seat.
 *
 * @param  Installation  $installation  The installation.
 * @param  string  $repository  The repository.
 * @param  string  $location  The work location.
 */
$seatAt = function (Installation $installation, string $repository, string $location): void {
    test()->service(AgentSessions::class)->start($installation->refresh(), $repository, $location);

    // A seat is recorded when the developer's seats are first read, so read them now: a seat that is
    // only recorded after its installation is revoked would never exist to be left behind
    test()->service(Seats::class)->forDeveloper($installation->user_id);
};

/**
 * The rendered page's text for the seats card, one string per machine section.
 *
 * @param  string  $html  The page.
 * @return list<string> Each section's markup.
 */
$sections = function (string $html): array {
    preg_match_all('/<section [^>]*data-seat-group.*?<\/section>/s', $html, $found);

    return $found[0];
};

it("lists a machine's seats together under a heading with its count, sorted by repository and then location", function () use ($seatAt, $sections): void {
    $office = $this->approveInstallation($this->alice, 'office-mac');
    $laptop = $this->approveInstallation($this->alice, 'laptop');

    $seatAt($office, 'robot-council/core', 'b');
    $seatAt($laptop, 'robot-council/cli', 'a');
    $seatAt($office, 'robot-council/cli', 'a');
    $seatAt($office, 'robot-council/core', 'a');

    $html = Livewire::actingAs($this->alice)->test(SeatSettings::class)->html();
    $groups = $sections($html);

    expect($groups)->toHaveCount(2)
        // Labels run alphabetically: laptop before office-mac
        ->and($groups[0])->toContain('<code>laptop</code>')->toContain('1 seat<')->not->toContain('1 seats')
        ->and($groups[1])->toContain('<code>office-mac</code>')->toContain('3 seats');

    // Under office-mac: cli / a, then core / a, then core / b
    expect($groups[1])->toMatch('/robot-council\/cli.*?robot-council\/core.*?>a<.*?robot-council\/core.*?>b</s')
        // The laptop's seat is not listed under the office's heading
        ->and($groups[1])->not->toContain('laptop');

    // A real heading per machine, labelled by it
    expect($html)->toContain('<h3 id="machine-group-')
        ->and(substr_count($html, '<h3 id="machine-group-'))->toBe(2)
        ->and(substr_count($html, 'aria-labelledby="machine-group-'))->toBe(2);
});

it('drops the machine from each row, since the heading names it', function () use ($seatAt, $sections): void {
    $office = $this->approveInstallation($this->alice, 'office-mac');
    $seatAt($office, 'robot-council/core', 'a');

    $groups = $sections(Livewire::actingAs($this->alice)->test(SeatSettings::class)->html());

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toContain('<code>claude-code</code>')
        ->and($groups[0])->not->toContain('<code>claude-code</code> on')
        // The label appears once, in the heading
        ->and(substr_count($groups[0], 'office-mac'))->toBe(1);
});

it('marks a machine no installation uses and sorts it after the machines in use', function () use ($seatAt, $sections): void {
    $old = $this->approveInstallation($this->alice, 'aaa-old-pc');
    $seatAt($old, 'robot-council/core', 'a');

    $office = $this->approveInstallation($this->alice, 'office-mac');
    $seatAt($office, 'robot-council/core', 'a');

    // Retired: its seat stays, and nothing live carries its label
    $old->forceFill(['revoked_at' => Carbon::now()])->save();

    $groups = $sections(Livewire::actingAs($this->alice)->test(SeatSettings::class)->html());

    expect($groups)->toHaveCount(2)
        // Alphabetically first, and still last
        ->and($groups[0])->toContain('<code>office-mac</code>')->not->toContain('data-seat-group-unused')
        ->and($groups[1])->toContain('<code>aaa-old-pc</code>')->toContain('data-seat-group-unused')->toContain('No installation uses this machine label');
});

it('does not mark a machine whose label a newer installation now carries', function () use ($seatAt, $sections): void {
    $first = $this->approveInstallation($this->alice, 'office-mac');
    $seatAt($first, 'robot-council/core', 'a');

    $first->forceFill(['revoked_at' => Carbon::now()])->save();
    $this->approveInstallation($this->alice, 'office-mac');

    $groups = $sections(Livewire::actingAs($this->alice)->test(SeatSettings::class)->html());

    // One heading for the label, in use: the old seat's own row says why it is offered
    expect($groups)->toHaveCount(1)
        ->and($groups[0])->not->toContain('data-seat-group-unused')
        ->and($groups[0])->toContain('a newer one has the same machine label');
});

it('keeps a heading when there is one machine, and leaves every control on the row', function () use ($seatAt, $sections): void {
    $office = $this->approveInstallation($this->alice, 'office-mac');
    $seatAt($office, 'robot-council/core', 'a');

    $groups = $sections(Livewire::actingAs($this->alice)->test(SeatSettings::class)->html());

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toContain('<h3 id="machine-group-')
        ->and($groups[0])->toContain('wire:click="park(')
        ->and($groups[0])->toContain('wire:click="exempt(')
        ->and($groups[0])->toContain('data-seat-controls')
        ->and($groups[0])->toContain('Set tasks at once');
});

it('says nothing about machines when the developer has no seats', function (): void {
    $html = Livewire::actingAs($this->alice)->test(SeatSettings::class)->html();

    expect($html)->not->toContain('data-seat-group')->toContain('No seats yet');
});
