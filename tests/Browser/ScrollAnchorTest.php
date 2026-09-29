<?php

declare(strict_types=1);

/**
 * The served script keeps the row being read in place when a poll adds rows above it (#472),
 * measured the way #444 found the fault: in headless Chromium at 390x700, scrolled 700px down, with a
 * real Livewire round trip standing in for the poll.
 *
 * **Each claim is paired with the reading that would expose a blind check.** A row that stays put
 * proves nothing unless something was actually added above it, so every "stays" assertion is made
 * beside one that the page gained a row and that `scrollY` moved to compensate.
 *
 * @command  vendor/bin/pest --testsuite=Browser tests/Browser/ScrollAnchorTest.php
 */

use Pest\Browser\Api\PendingAwaitablePage;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

pest()->group('browser');

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $this->lane = $this->service(AgentSessions::class)->start($this->approveInstallation($this->developer), 'robot-council/core', 'a')->owner;
});

/**
 * Open a page at #444's size, scrolled 700px down, and prove it scrolled.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $route  The page's route name.
 * @return PendingAwaitablePage The page.
 */
function anchoredPage(TestCase $case, string $route): PendingAwaitablePage
{
    $case->actingAs($case->developer, 'web');

    $page = visit(route($route));
    $page->resize(390, 700);
    $page->script('() => window.scrollTo(0, 700)');

    expect($page->script('() => window.scrollY'))->toBe(700);

    return $page;
}

/**
 * The first rendered keyed row at or below the top of the viewport, and the page's state around it.
 *
 * @return array{key: string, top: float, scrollY: float, rows: int}
 */
function readingPosition(PendingAwaitablePage $page): array
{
    $found = $page->script(<<<'JS'
        () => {
            const rows = document.querySelectorAll('main [wire\\:key]');
            for (const el of rows) {
                const rect = el.getBoundingClientRect();
                if (rect.width === 0 && rect.height === 0) continue;
                if (rect.top >= 0) return { key: el.getAttribute('wire:key'), top: rect.top, scrollY: window.scrollY, rows: rows.length };
            }
            return 'no keyed row in view';
        }
    JS);

    if (! is_array($found)) {
        throw new RuntimeException('The reading position could not be taken: '.json_encode($found));
    }

    /** @var array{key: string, top: float, scrollY: float, rows: int} $found */
    return $found;
}

/**
 * Where a given keyed row is now.
 */
function topOf(PendingAwaitablePage $page, string $key): ?float
{
    $top = $page->script(sprintf('() => { const el = document.querySelector(`[wire\\\\:key="%s"]`); return el ? el.getBoundingClientRect().top : null; }', $key));

    return is_int($top) || is_float($top) ? (float) $top : null;
}

/**
 * Re-render every component on the page through Livewire, as a poll does, and wait for it.
 */
function poll(PendingAwaitablePage $page): void
{
    $done = $page->script('async () => { await Promise.all(window.Livewire.all().map(c => c.$wire.$refresh())); return "polled"; }');

    expect($done)->toBe('polled');
}

/**
 * Record a narration from the lane, which the feed shows newest first.
 */
function narrate(AgentSession $lane, string $body): void
{
    app(FleetEvents::class)->record(FleetEventType::Narration, $lane, $body);
}

it('keeps the row being read in place when a poll adds an event above it on the change feed', function (): void {
    foreach (range(1, 40) as $n) {
        narrate($this->lane, sprintf('Earlier narration number %d, long enough to take a line or two on a phone.', $n));
    }

    $page = anchoredPage($this, 'robot-council.feed');
    $before = readingPosition($page);

    narrate($this->lane, 'A new narration, arriving above the reader.');
    poll($page);

    $after = readingPosition($page);

    // Something really was added above, and the page scrolled to make up for it
    expect($page->script('() => document.body.innerText.includes("A new narration, arriving above the reader.")'))->toBeTrue()
        ->and($after['scrollY'])->toBeGreaterThan($before['scrollY'])
        // The row being read is where it was, within a pixel
        ->and(abs((topOf($page, $before['key']) ?? INF) - $before['top']))->toBeLessThanOrEqual(1.0);
});

it('keeps the row being read in place when a poll adds a task above it on the queue', function (): void {
    $tasks = $this->service(Tasks::class);

    foreach (range(1, 25) as $n) {
        $tasks->create($this->lane, ['title' => sprintf('Queued task number %d', $n), 'priority' => 1], false);
    }

    $page = anchoredPage($this, 'robot-council.queue');
    $before = readingPosition($page);

    // The most urgent, so the queue lists it first
    $tasks->create($this->lane, ['title' => 'An urgent task, arriving above the reader', 'priority' => 9], false);
    poll($page);

    $after = readingPosition($page);

    expect($page->script('() => document.body.innerText.includes("An urgent task, arriving above the reader")'))->toBeTrue()
        ->and($after['scrollY'])->toBeGreaterThan($before['scrollY'])
        ->and(abs((topOf($page, $before['key']) ?? INF) - $before['top']))->toBeLessThanOrEqual(1.0);
});

it('moves nothing when a poll changes nothing above the reader', function (): void {
    foreach (range(1, 40) as $n) {
        narrate($this->lane, sprintf('Narration number %d, long enough to take a line or two on a phone.', $n));
    }

    $page = anchoredPage($this, 'robot-council.feed');
    $before = readingPosition($page);

    poll($page);

    $after = readingPosition($page);

    expect($after['key'])->toBe($before['key'])
        ->and($after['scrollY'])->toBe($before['scrollY'])
        ->and(abs($after['top'] - $before['top']))->toBeLessThanOrEqual(1.0);
});

it('falls back to the page as the morph leaves it when the row being read is gone', function (): void {
    foreach (range(1, 40) as $n) {
        narrate($this->lane, sprintf('Narration number %d, long enough to take a line or two on a phone.', $n));
    }

    $page = anchoredPage($this, 'robot-council.feed');
    $before = readingPosition($page);

    // The anchored event itself disappears, so there is nothing to keep in place
    expect(FleetEvent::query()->whereKey((int) str_replace('event-', '', $before['key']))->delete())->toBe(1);

    poll($page);

    // No error, the row is gone, and the script did not scroll
    expect(topOf($page, $before['key']))->toBeNull()
        ->and($page->script('() => window.scrollY'))->toBe($before['scrollY'])
        ->and($page->script('() => typeof window.Livewire'))->toBe('object');
});
