<?php

declare(strict_types=1);

/**
 * The dashboard's keyboard and structure contract (#400): one heading and one set of landmarks per
 * page, a skip link reached first, a focus order that follows the reading order, a focus indicator
 * on the navigation daisyUI strips, and identifiers marked as code.
 *
 * @command  vendor/bin/pest --compact tests/DashboardKeyboardTest.php
 */

use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242], admins: [4242]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');

    $installation = $this->approveInstallation($this->developer, 'office-mac');
    $this->session = $this->service(AgentSessions::class)->start($installation, 'robot-council/core', 'robot-council-core-a')->owner;
});

/**
 * @return array<string, string>
 */
function keyboardPages(): array
{
    return [
        'dashboard' => 'robot-council.dashboard',
        'queue' => 'robot-council.queue',
        'agents' => 'robot-council.agents',
        'locks' => 'robot-council.locks',
        'lanes' => 'robot-council.lanes',
        'feed' => 'robot-council.feed',
        'administration' => 'robot-council.administration',
        'seats' => 'robot-council.seats',
    ];
}

function keyboardDocument(string $html): DOMXPath
{
    $document = new DOMDocument;

    // HTML5 elements such as `main` and `nav` are unknown to libxml's HTML 4 parser, which warns
    // about each one; the tree it builds is still the right one
    $previous = libxml_use_internal_errors(true);
    if ($html === '') {
        throw new RuntimeException('The page rendered nothing.');
    }

    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

/**
 * The elements an expression selects, in document order.
 *
 * @return list<DOMElement>
 *
 * @throws RuntimeException When the expression is malformed, so a typo cannot read as "none found".
 */
function keyboardElements(DOMXPath $xpath, string $expression): array
{
    $nodes = $xpath->query($expression);

    if ($nodes === false) {
        throw new RuntimeException('Malformed XPath: '.$expression);
    }

    $elements = [];

    foreach ($nodes as $node) {
        if ($node instanceof DOMElement) {
            $elements[] = $node;
        }
    }

    return $elements;
}

/**
 * @param  array<string, string>  $parameters
 */
function keyboardPage(mixed $test, string $route, array $parameters = []): DOMXPath
{
    if (! $test instanceof TestCase) {
        throw new RuntimeException('Expected the test case.');
    }

    return keyboardDocument((string) $test->get(route($route, $parameters))->assertOk()->getContent());
}

it('gives every page one heading, one main, and one navigation', function (string $route): void {
    $this->actingAs($this->developer, 'web');
    $xpath = keyboardPage($this, $route);

    expect(keyboardElements($xpath, '//h1'))->toHaveCount(1)
        ->and(keyboardElements($xpath, '//main'))->toHaveCount(1)
        ->and(keyboardElements($xpath, '//main[@id="robot-council-main"][@tabindex="-1"]'))->toHaveCount(1)
        ->and(keyboardElements($xpath, '//nav'))->toHaveCount(1)
        ->and(keyboardElements($xpath, '//header'))->toHaveCount(1);

    // No level is skipped on the way down from the page's one `h1`. The sidebar's `h2`, which names
    // the navigation, comes before it in source order because the sidebar does, and is not counted
    $levels = array_map(
        static fn (DOMElement $heading): int => (int) substr($heading->nodeName, 1),
        keyboardElements($xpath, '//main//h1|//main//h2|//main//h3|//main//h4|//main//h5|//main//h6'),
    );

    $skips = [];

    foreach ($levels as $i => $level) {
        if ($i > 0 && $level > $levels[$i - 1] + 1) {
            $skips[] = 'h'.$levels[$i - 1].' -> h'.$level;
        }
    }

    expect($levels[0] ?? null)->toBe(1)
        ->and($skips)->toBeEmpty(implode(', ', $skips));
})->with(keyboardPages());

it('reaches a full-size skip link before anything else, and the sidebar before the content', function (string $route): void {
    $this->actingAs($this->developer, 'web');
    $xpath = keyboardPage($this, $route);

    // The first focusable element in the body is the skip link, pointing at the main landmark
    $focusable = keyboardElements($xpath, '//body//a[@href] | //body//button | //body//input[not(@type="hidden")] | //body//select | //body//textarea | //body//summary | //body//*[@tabindex and @tabindex != "-1"]');

    expect($focusable)->not->toBeEmpty();

    $first = $focusable[0];
    $classes = preg_split('/\s+/', $first->getAttribute('class')) ?: [];

    expect($first->nodeName)->toBe('a')
        ->and($first->getAttribute('href'))->toBe('#robot-council-main')
        ->and(trim($first->textContent))->toBe('Skip to content')
        // Parked off-screen and brought in on focus, never `sr-only`: a sighted keyboard user sees
        // it, and it keeps the 44px target size while it shows
        ->and($classes)->toContain('btn-target', 'fixed', '-top-24', 'focus:top-4')
        ->and($classes)->not->toContain('sr-only');

    // The drawer's sidebar comes before its content in source order, so Tab reaches the navigation
    // before the page it navigates, matching where it sits on screen
    $order = array_map(
        static fn (DOMElement $child): string => $child->nodeName === 'input' ? 'toggle' : (string) preg_replace('/\s.*$/', '', $child->getAttribute('class')),
        keyboardElements($xpath, '//div[contains(concat(" ", normalize-space(@class), " "), " drawer ")]/*'),
    );

    expect($order)->toBe(['toggle', 'drawer-side', 'drawer-content']);
})->with(keyboardPages());

it('marks an identifier as code wherever a page prints one', function (): void {
    $this->service(Locks::class)->acquire($this->session, 'branch:main', 300, false);

    $this->actingAs($this->developer, 'web');

    $codes = static fn (DOMXPath $xpath): array => array_map(
        static fn (DOMElement $code): string => trim($code->textContent),
        keyboardElements($xpath, '//code'),
    );

    expect($codes(keyboardPage($this, 'robot-council.locks')))->toContain('branch:main')
        ->and($codes(keyboardPage($this, 'robot-council.agents')))->toContain('office-mac', 'robot-council-core-a');
});

it('gives the enrollment and signed-out pages one heading and one main as well', function (): void {
    $code = app(DeviceCodes::class)->issue(['fleet:read'], 'claude-code', 'workbench', hash('sha256', 'keyboard-verifier'), null);

    $this->actingAs($this->developer, 'web');
    $enroll = keyboardPage($this, 'robot-council.enroll.show', ['user_code' => $code->record->user_code]);

    // Enrollment renders inside the dashboard layout, so it carries the skip link and the sidebar too
    expect(keyboardElements($enroll, '//h1'))->toHaveCount(1)
        ->and(keyboardElements($enroll, '//main'))->toHaveCount(1)
        ->and(keyboardElements($enroll, '//a[@href="#robot-council-main"]'))->toHaveCount(1);

    auth()->guard('web')->logout();
    $signedOut = keyboardPage($this, 'robot-council.signed-out');

    expect(keyboardElements($signedOut, '//h1'))->toHaveCount(1)
        ->and(keyboardElements($signedOut, '//main'))->toHaveCount(1);
});

it('marks an identifier as code on the queue and the seats page too', function (): void {
    $this->service(Tasks::class)->create($this->session, ['title' => 'Port the rule', 'project_id' => 'robot-council/core'], false);

    $this->actingAs($this->developer, 'web');

    $codes = static fn (DOMXPath $xpath): array => array_map(
        static fn (DOMElement $code): string => trim($code->textContent),
        keyboardElements($xpath, '//code'),
    );

    expect($codes(keyboardPage($this, 'robot-council.queue')))->toContain('robot-council/core')
        ->and($codes(keyboardPage($this, 'robot-council.seats')))->toContain('robot-council/core', 'robot-council-core-a', 'office-mac');
});

it('names the seat in every control a seat repeats', function (): void {
    $this->actingAs($this->developer, 'web');

    $buttons = keyboardElements(keyboardPage($this, 'robot-council.seats'), '//main//li//button');

    expect($buttons)->not->toBeEmpty();

    $unnamed = [];

    foreach ($buttons as $button) {
        $name = (string) preg_replace('/\s+/', ' ', trim($button->textContent));

        if (! str_contains($name, ' for robot-council/core / robot-council-core-a')) {
            $unnamed[] = $name;
        }
    }

    expect($unnamed)->toBeEmpty(implode(', ', $unnamed));
});
