<?php

declare(strict_types=1);

/**
 * The console's sections, nested under the page they belong to.
 *
 * The sidebar listed six destinations flat, as though `Queue` and `Enroll a machine` were the same
 * kind of thing. Four of the six are sections of one console and the fifth is the console itself.
 *
 * **Every assertion here reads the parsed tree rather than the markup**, because a string check
 * cannot tell nesting from adjacency: `</li><li>` and `<ul><li>` both put one anchor after another
 * in the source, and only one of them is what this ticket is about.
 *
 * The helpers narrow to `DOMElement` rather than passing `DOMNode` around, because `DOMXPath::query()`
 * is declared to return `DOMNodeList<DOMNameSpaceNode|DOMNode>|false` and a `DOMNameSpaceNode` has no
 * `textContent` at all -- so the narrowing is what makes the reads safe rather than only quiet.
 *
 * @command  vendor/bin/pest --compact tests/NestedSidebarTest.php
 */

use Illuminate\Foundation\Auth\User;
use RobotCouncil\Tests\TestCase;

/**
 * Parse a rendered page.
 *
 * @param  string|false  $html  What the response returned.
 * @return DOMXPath A query object over the document.
 */
function sidebarXPath(string|false $html): DOMXPath
{
    if (! is_string($html) || $html === '') {
        throw new RuntimeException('The page rendered nothing, so there is no sidebar to read.');
    }

    $document = new DOMDocument;

    // The dashboard is HTML5 and `loadHTML` warns about anything it does not know; the page is the
    // subject here rather than the parser's opinion of it.
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

/**
 * Every element an expression matches, in document order.
 *
 * @param  DOMXPath  $xpath  The query object.
 * @param  string  $expression  The XPath expression.
 * @param  DOMElement|null  $context  The node to search beneath, or null for the document.
 * @return list<DOMElement> The matching elements.
 */
function elementsMatching(DOMXPath $xpath, string $expression, ?DOMElement $context = null): array
{
    $found = $context instanceof DOMElement
        ? $xpath->query($expression, $context)
        : $xpath->query($expression);

    // False is a malformed expression, which is a broken test rather than an empty sidebar, and the
    // two would otherwise be the same empty array.
    if ($found === false) {
        throw new RuntimeException(sprintf('The expression [%s] is not valid XPath.', $expression));
    }

    $elements = [];

    foreach ($found as $node) {
        if ($node instanceof DOMElement) {
            $elements[] = $node;
        }
    }

    return $elements;
}

/**
 * The one element an expression matches, refusing none and refusing several.
 *
 * @param  DOMXPath  $xpath  The query object.
 * @param  string  $expression  The XPath expression.
 * @param  DOMElement|null  $context  The node to search beneath, or null for the document.
 * @return DOMElement The single match.
 */
function elementMatching(DOMXPath $xpath, string $expression, ?DOMElement $context = null): DOMElement
{
    $elements = elementsMatching($xpath, $expression, $context);

    if (count($elements) !== 1) {
        throw new RuntimeException(sprintf(
            'Expected one element for [%s], found %d.', $expression, count($elements)
        ));
    }

    return $elements[0];
}

/**
 * The `li` elements directly beneath the sidebar's top-level menu.
 *
 * @param  DOMXPath  $xpath  The query object.
 * @return list<DOMElement> The top-level entries.
 */
function topLevelEntries(DOMXPath $xpath): array
{
    // **The parentheses are load-bearing.** `//ul[...][1]` filters per PARENT -- each parent's
    // first matching `ul` -- rather than taking the first such `ul` in the document, and
    // `contains(@class, "menu")` also matches `menu-sm`, `menu-xs` and `submenu`. Measured on PHP
    // 8.4.23: give the nested list a size modifier and the unparenthesized form returns FOUR
    // entries, folding two sections up into the top level and reading as "the nesting broke" when
    // it did not.
    //
    // A purely structural `//nav[...]/ul/li` was the other candidate and is worse: it returns
    // three when a second list exists later in the nav, because it has no way to prefer the first.
    return elementsMatching(
        $xpath,
        '(//nav[@aria-labelledby="robot-council-menu-heading"]//ul[contains(@class, "menu")])[1]/li'
    );
}

/**
 * The anchor text of every `li` directly beneath an entry's own nested list.
 *
 * @param  DOMXPath  $xpath  The query object.
 * @param  DOMElement  $entry  The entry whose children to read.
 * @return list<string> The labels, in document order.
 */
function childLabels(DOMXPath $xpath, DOMElement $entry): array
{
    $labels = [];

    foreach (elementsMatching($xpath, './ul/li/a', $entry) as $anchor) {
        $labels[] = trim($anchor->textContent);
    }

    return $labels;
}

/**
 * Sign in a developer who can see the console.
 *
 * @param  TestCase  $test  The test case.
 * @param  bool  $admin  Whether the developer also holds admin rights.
 * @return User The signed-in developer.
 */
function signInForSidebar(TestCase $test, bool $admin = true): User
{
    $test->migrateUsersTableWithPackageColumns();

    $test->setAccessLists(developers: [4242], admins: $admin ? [4242] : []);

    $developer = $test->enrollDeveloper(4242);

    $test->actingAs($developer, 'web');

    return $developer;
}

it('puts the sections inside the dashboard entry and leaves enrollment beside it', function (): void {
    signInForSidebar($this);

    $xpath = sidebarXPath($this->get(route('robot-council.dashboard'))->assertOk()->getContent());

    $entries = topLevelEntries($xpath);

    // Two top-level entries, not six: the console, and the one destination that is not part of it
    expect($entries)->toHaveCount(2);

    [$console, $enroll] = $entries;

    // The console's own link, then its sections beneath it
    expect(trim(elementMatching($xpath, './a', $console)->textContent))->toBe('Dashboard')
        ->and(childLabels($xpath, $console))->toBe(['Agents', 'Locks', 'Lanes', 'Queue', 'Change feed', 'My seats and hours', 'Waiting on me', 'Administration', 'Access']);

    // And enrollment is a sibling with no children of its own, which is the half that fails if the
    // whole list were simply wrapped one level deeper
    expect(trim(elementMatching($xpath, './a', $enroll)->textContent))->toBe('Enroll a machine')
        ->and(childLabels($xpath, $enroll))->toBeEmpty();
});

it('names the console after what it is rather than after its position in a list', function (): void {
    signInForSidebar($this);

    $xpath = sidebarXPath($this->get(route('robot-council.dashboard'))->assertOk()->getContent());

    [$console] = topLevelEntries($xpath);

    // `Overview` described where the entry sat when the sections were its siblings. They are its
    // children now, so the entry names the page.
    //
    // Read through the tree like everything else here, rather than with a document-wide string
    // match. The overview renders a second `ul.menu` of the same four links inside `<main>` for
    // narrow viewports, so `toContain('>Dashboard</a>')` would be answerable by markup that is not
    // the sidebar at all.
    expect(trim(elementMatching($xpath, './a', $console)->textContent))->toBe('Dashboard');

    // And the old label is gone from the whole document, which is the one claim that is genuinely
    // about the page rather than about one entry.
    expect((string) $this->get(route('robot-council.dashboard'))->getContent())
        ->not->toContain('>Overview</a>');
});

it('keeps the administration section out of the list for a developer who is not an admin', function (): void {
    signInForSidebar($this, admin: false);

    $xpath = sidebarXPath($this->get(route('robot-council.dashboard'))->assertOk()->getContent());

    [$console] = topLevelEntries($xpath);

    // The other four are still nested, so this is the admin entry being withheld rather than the
    // whole group failing to render.
    expect(childLabels($xpath, $console))->toBe(['Agents', 'Locks', 'Lanes', 'Queue', 'Change feed', 'My seats and hours', 'Waiting on me']);
});

it('marks a nested section as current without marking the page that holds it', function (): void {
    signInForSidebar($this);

    $xpath = sidebarXPath($this->get(route('robot-council.queue'))->assertOk()->getContent());

    [$console] = topLevelEntries($xpath);

    $parent = elementMatching($xpath, './a', $console);
    $queue = elementMatching($xpath, './ul/li/a[contains(text(), "Queue")]', $console);

    // The child is current. The parent must NOT be, which is the half that fails if the marker is
    // applied to an ancestor because one of its sections is being shown.
    //
    // `hasAttribute` rather than comparing `getAttribute` to the empty string: the two agree here,
    // but Rector rewrites the comparison to `toBeEmpty()`, which also passes for `'0'` -- a weaker
    // assertion than the one that was written. Absence is what the markup means anyway.
    expect($queue->getAttribute('aria-current'))->toBe('page')
        ->and($queue->getAttribute('class'))->toContain('menu-active')
        ->and($parent->hasAttribute('aria-current'))->toBeFalse()
        ->and($parent->getAttribute('class'))->not->toContain('menu-active');
});

it('keeps the whole tree on a page that is not part of the console', function (): void {
    signInForSidebar($this);

    $xpath = sidebarXPath($this->get(route('robot-council.enroll.show'))->assertOk()->getContent());

    [$console] = topLevelEntries($xpath);

    // The sections were fragments once, and vanished everywhere but the dashboard. They are routes
    // now, so the tree has the same shape on every page.
    expect(childLabels($xpath, $console))->toBe(['Agents', 'Locks', 'Lanes', 'Queue', 'Change feed', 'My seats and hours', 'Waiting on me', 'Administration', 'Access']);
});
