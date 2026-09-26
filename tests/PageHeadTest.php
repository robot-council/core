<?php

declare(strict_types=1);

/**
 * The head every page shares: its title, its robots tag, and the fleet's name (#313).
 *
 * @command  vendor/bin/pest --compact tests/PageHeadTest.php
 */

use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242], admins: [4242]);
    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
});

/**
 * Every page the package renders, and the HTML it served.
 *
 * Signed-in pages as the developer, who is also an administrator; the two pages a visitor reaches
 * without a session as nobody. All of them, not a sample: a tag missing from one page out of three
 * is the defect this exists to catch.
 *
 * @param  TestCase  $case  The test case.
 * @return array<string, string> The HTML, keyed by page.
 */
function everyPage(TestCase $case): array
{
    $pages = [];

    foreach (['dashboard', 'agents', 'locks', 'lanes', 'queue', 'feed', 'seats', 'administration', 'enroll.show'] as $route) {
        $pages[$route] = (string) $case->actingAs($case->developer, 'web')->get(route('robot-council.'.$route))->assertOk()->getContent();
    }

    auth('web')->logout();

    $pages['signed-out'] = (string) $case->get(route('robot-council.signed-out'))->assertOk()->getContent();

    $pages['sign-in-expired'] = (string) $case->withSession(['state' => 'issued'])
        ->get(route('robot-council.auth.callback', ['state' => 'other', 'code' => 'x']))
        ->assertStatus(400)
        ->getContent();

    return $pages;
}

/**
 * The `<title>` a page carries.
 *
 * @param  string  $html  The page.
 * @return string The title, decoded.
 */
function titleOf(string $html): string
{
    preg_match_all('#<title>(.*?)</title>#s', $html, $titles);

    // Exactly one: a second `<title>` is a second head
    expect($titles[1])->toHaveCount(1);

    return html_entity_decode(trim($titles[1][0]), ENT_QUOTES | ENT_HTML5);
}

it('marks every page it renders noindex, nofollow, exactly once', function (): void {
    foreach (everyPage($this) as $page => $html) {
        expect(substr_count($html, '<meta name="robots" content="noindex, nofollow">'))
            ->toBe(1, $page.' carries the robots tag '.substr_count($html, 'name="robots"').' times');
    }
});

it('gives every page its own title, with the fleet name after it and alone on the index', function (): void {
    $titles = array_map(titleOf(...), everyPage($this));

    expect($titles)->toBe([
        'dashboard' => 'Robot Council',
        'agents' => 'Agents · Robot Council',
        'locks' => 'Locks · Robot Council',
        'lanes' => 'Lanes · Robot Council',
        'queue' => 'Queue · Robot Council',
        'feed' => 'Change feed · Robot Council',
        'seats' => 'My seats and hours · Robot Council',
        'administration' => 'Administration · Robot Council',
        'enroll.show' => 'Enroll a machine · Robot Council',
        'signed-out' => 'Signed out · Robot Council',
        'sign-in-expired' => 'Sign-in expired · Robot Council',
    ])->and(array_unique($titles))->toHaveSameSize($titles);
});

it('prints the configured name in the title and the sidebar', function (): void {
    config()->set('robot-council.dashboard.name', 'Acme Fleet');

    $html = (string) $this->actingAs($this->developer, 'web')->get(route('robot-council.queue'))->assertOk()->getContent();

    expect(titleOf($html))->toBe('Queue · Acme Fleet')
        ->and($html)->toContain('<p class="font-semibold break-words">Acme Fleet</p>')
        ->not->toContain('Robot Council');
});

it('escapes a configured name carrying markup, on every page', function (): void {
    // Past what a host would sensibly set, which is the point: this proves the page, not a validator
    config()->set('robot-council.dashboard.name', '<script>alert("name")</script>');

    foreach (everyPage($this) as $page => $html) {
        expect($html)->not->toContain('<script>alert(', $page.' printed the name raw')
            ->toContain('&lt;script&gt;alert(');
    }
});

it('falls back to the default name when a host sets an empty one', function (): void {
    config()->set('robot-council.dashboard.name', '   ');

    $html = (string) $this->actingAs($this->developer, 'web')->get(route('robot-council.dashboard'))->assertOk()->getContent();

    expect(titleOf($html))->toBe('Robot Council');
});
