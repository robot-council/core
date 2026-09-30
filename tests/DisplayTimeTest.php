<?php

declare(strict_types=1);

/**
 * Times as the dashboard writes them, and in whose zone (#487).
 *
 * The baseline is the fleet's, so it is named in the fleet zone whoever is looking; every other
 * time a page shows is in the viewer's own zone, from their assignment hours, and named. Each
 * page-level assertion pairs a viewer with a zone and one without, so a page that ignored the
 * viewer would fail one of the two.
 *
 * @command  vendor/bin/pest --compact tests/DisplayTimeTest.php
 */

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Models\Seat;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Backlog;
use RobotCouncil\Support\DeveloperSettings;
use RobotCouncil\Support\DisplayTime;
use RobotCouncil\Support\Glossary;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\Seats;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 4343], admins: [4242]);

    // The fleet on Central time; one developer in Tokyo by their hours, one with no hours at all
    config()->set('robot-council.dashboard.timezone', 'America/Chicago');

    $this->tokyo = $this->enrollDeveloper(4242, login: 'in-tokyo');
    $this->unset = $this->enrollDeveloper(4343, login: 'no-hours');
    $this->service(DeveloperSettings::class)->setHours(HostKey::from($this->tokyo->getAuthIdentifier()), 'Asia/Tokyo', '09:00', '17:00', false);

    $this->installation = $this->approveInstallation($this->tokyo);
});

it('writes a clock time the way the maintainer asked', function (string $at, string $words): void {
    expect(DisplayTime::clock(CarbonImmutable::parse('2026-09-30 '.$at)))->toBe($words);
})->with([
    'a whole hour in the morning' => ['08:00', '8 a.m.'],
    'minutes in the afternoon' => ['14:05', '2:05 p.m.'],
    'noon' => ['12:00', 'noon'],
    'midnight' => ['00:00', 'midnight'],
    'just after midnight' => ['00:30', '12:30 a.m.'],
    'just after noon' => ['12:30', '12:30 p.m.'],
    'a whole hour in the evening' => ['17:00', '5 p.m.'],
    'the last minute of the day' => ['23:59', '11:59 p.m.'],
]);

it('names a zone in words, and UTC as itself', function (): void {
    expect(DisplayTime::zoneName('America/Chicago'))->toBe('Central Time')
        ->and(DisplayTime::zoneName('Asia/Tokyo'))->toStartWith('Japan ')
        ->and(DisplayTime::zoneName('UTC'))->toBe('UTC');
})->skip(! class_exists(IntlTimeZone::class), 'Needs ext-intl, which names zones in words.');

it('names the baseline in the fleet zone on the lane board and in the glossary, whoever is looking', function (): void {
    // A meter needs a reading on the board
    Carbon::setTestNow('2026-09-24 12:00:00');
    $lane = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'a')->owner;
    $this->service(Backlog::class)->report($lane, 'robot-council/core', 10);

    foreach ([$this->tokyo, $this->unset] as $viewer) {
        $html = Livewire::actingAs($viewer)->test(Lanes::class)->html();

        expect($html)->toContain("Each count is compared with the same repository's count at 8 a.m. Central Time today.")
            ->and($html)->toContain('no 8 a.m. Central Time count yet')
            ->and($html)->not->toContain('08:00');
    }

    $this->actingAs($this->tokyo);

    expect(Glossary::entries(['open_issues'])[0]['means'])->toContain('compares with its count at 8 a.m. Central Time today')
        ->and(Glossary::entries(['open_issues'])[0]['means'])->not->toContain(':baseline');
});

it("shows the lane board's stamps in the viewer's own zone, named, with the fleet zone as the fallback", function (): void {
    // 15:05 UTC is 12:05 a.m. the next day in Tokyo and 10:05 a.m. in Chicago
    Carbon::setTestNow('2026-09-24 15:05:00');
    $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'a');

    // Parking the seat is a change the board stamps
    $key = HostKey::from($this->tokyo->getAuthIdentifier());
    $seat = collect($this->service(Seats::class)->forDeveloper($key))->firstWhere('work_location', 'a');
    $this->service(Seats::class)->park($key, $seat instanceof Seat ? $seat->id : 0);

    $tokyo = Livewire::actingAs($this->tokyo)->test(Lanes::class)->html();
    $fleet = Livewire::actingAs($this->unset)->test(Lanes::class)->html();

    $japan = DisplayTime::zoneName('Asia/Tokyo');

    expect($tokyo)->toContain('<time datetime="2026-09-24T15:05:00Z">Sep 25, 2026, 12:05 a.m. '.$japan.'</time>')
        ->and($tokyo)->toContain('read <time datetime="2026-09-24T15:05:00Z">12:05 a.m. '.$japan.'</time>')
        ->and($fleet)->toContain('<time datetime="2026-09-24T15:05:00Z">Sep 24, 2026, 10:05 a.m. Central Time</time>')
        ->and($fleet)->toContain('read <time datetime="2026-09-24T15:05:00Z">10:05 a.m. Central Time</time>');
})->skip(! class_exists(IntlTimeZone::class), 'Needs ext-intl, which names zones in words.');

it("shows a session's times on the administration page in the viewer's own zone, keeping the instant in datetime", function (): void {
    Carbon::setTestNow('2026-09-24 17:00:00');
    $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'a');

    // Midnight and noon, the two times written as words
    Carbon::setTestNow('2026-09-24 17:10:00');
    $tokyo = Livewire::actingAs($this->tokyo)->test(Administration::class)->html();

    expect($tokyo)->toContain('Sep 25, 2026, 2 a.m. '.DisplayTime::zoneName('Asia/Tokyo').' (10 minutes ago)');

    $this->setAccessLists(developers: [4242, 4343], admins: [4242, 4343]);
    $fleet = Livewire::actingAs($this->unset)->test(Administration::class)->html();

    expect($fleet)->toContain('Sep 24, 2026, noon Central Time (10 minutes ago)')
        ->and($fleet)->toContain('datetime="2026-09-24T17:00:00+00:00"');
})->skip(! class_exists(IntlTimeZone::class), 'Needs ext-intl, which names zones in words.');

it('falls back to the zone identifier where ext-intl cannot name it, and never to a GMT offset', function (): void {
    // A zone ICU names only by offset reads as its identifier; the control is one it names
    $named = DisplayTime::zoneName('America/Chicago');
    $offsetOnly = DisplayTime::zoneName('Etc/GMT+5');

    expect($named)->not->toBe('America/Chicago')
        ->and($offsetOnly)->toBe('Etc/GMT+5');
})->skip(! class_exists(IntlTimeZone::class), 'Needs ext-intl, which names zones in words.');

it('writes the instant for datetime as ISO 8601 in UTC', function (): void {
    expect(DisplayTime::iso(CarbonImmutable::parse('2026-09-24 10:05:00', 'America/Chicago')))->toBe('2026-09-24T15:05:00Z');
});
