<?php

declare(strict_types=1);

/**
 * The Agents and Locks pages as two pages, and the links that keep them one diagnosis path (#308).
 *
 * They were one panel on `dashboard/presence` until #308 split them, because the two lists were
 * independently paged and filtered and each had to be scrolled past to reach the other. What the
 * shared panel bought -- from a stuck process to what it blocks, and back -- is kept as a link each
 * way, and this is what says the links arrive where they claim to.
 *
 * @command  vendor/bin/pest --compact tests/AgentsAndLocksPagesTest.php
 */

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use RobotCouncil\Livewire\Agents;
use RobotCouncil\Livewire\Locks as LocksPage;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Support\Locks;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    // Two developers, one session each, each holding one lock. Two of everything, so a narrowing
    // that did nothing shows the other row and a narrowing that pointed at the wrong one shows it
    // instead of this one.
    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $other = $this->enrollDeveloper(77, login: 'otherdev');

    [$this->session] = $this->startAgentSession($this->approveInstallation($this->developer, machineLabel: 'box-first'));
    [$second] = $this->startAgentSession($this->approveInstallation($other, machineLabel: 'box-second'));

    $this->service(Locks::class)->acquire($this->session, 'lock-first', 60, false);
    $this->service(Locks::class)->acquire($second, 'lock-second', 60, false);

    $this->actingAs($this->developer, 'web');
});

/**
 * The one session running on a machine, read back rather than kept on the test case.
 *
 * `TestCase` declares one typed `$session`, which holds the first developer's; the second is looked
 * up by its machine label so the analyzer sees an `AgentSession` rather than `mixed`.
 *
 * @param  string  $machineLabel  The installation's machine label.
 * @return AgentSession The session.
 */
function sessionOn(string $machineLabel): AgentSession
{
    return AgentSession::query()
        ->whereHas('installation', static fn (Builder $query) => $query->where('machine_label', $machineLabel))
        ->sole();
}

/**
 * The machine cell a session renders on the Agents page, as exact markup.
 *
 * A machine label appears nowhere else on the page, but it does appear in Livewire's serialized
 * snapshot whenever a property holds it -- so this asserts the element rather than the word.
 *
 * @param  string  $label  The machine label.
 * @return string The markup.
 */
function machineCell(string $label): string
{
    return '<div>'.$label.'</div>';
}

/**
 * The name cell a lock renders on the Locks page, as exact markup.
 *
 * @param  string  $name  The lock's name.
 * @return string The markup.
 */
function lockCell(string $name): string
{
    return '<td role="cell" data-label="Lock" class="font-medium">'.$name.'</td>';
}

/**
 * Every `href` on a page that points at one route, whatever its query string.
 *
 * @param  string  $html  The rendered page.
 * @param  string  $route  The route's name.
 * @return list<string> The hrefs, decoded, in document order.
 */
function hrefsTo(string $html, string $route): array
{
    preg_match_all('/href="('.preg_quote(route($route), '/').'(?:\?[^"]*)?)"/', $html, $found);

    return array_map(static fn (string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5), $found[1]);
}

/**
 * The opening tag of the anchor whose `href` is exactly one URL, with no query string.
 *
 * Exact, because the rows on each page link to the other page WITH a query string, and those are
 * not the sidebar entry being asserted on.
 *
 * @param  string  $html  The rendered page.
 * @param  string  $url  The href to find.
 * @return string The opening tag, or the empty string when the anchor is absent.
 */
function openingTagLinkingTo(string $html, string $url): string
{
    return preg_match('/<a\\s[^>]*href="'.preg_quote($url, '/').'"[^>]*>/', $html, $found) === 1 ? $found[0] : '';
}

it('renders the agents on the Agents page and the locks on the Locks page, and neither on the other', function (): void {
    $agents = (string) $this->get(route('robot-council.agents'))->assertOk()->getContent();
    $locks = (string) $this->get(route('robot-council.locks'))->assertOk()->getContent();

    // Column headings rather than words. "Agents" and "Locks" are both in the sidebar on both pages,
    // so a bare string would discriminate nothing.
    expect($agents)->toContain('<th role="columnheader">Last seen</th>')
        ->toContain(machineCell('box-first'))
        ->not->toContain('<th role="columnheader">Fence</th>')
        ->not->toContain(lockCell('lock-first'))
        ->and($locks)->toContain('<th role="columnheader">Fence</th>')
        ->toContain(lockCell('lock-first'))
        ->not->toContain('<th role="columnheader">Last seen</th>')
        ->not->toContain(machineCell('box-first'));
});

it('keeps only its own state in its URL', function (ReflectionClass $page, array $expected): void {
    // **The structural half of "a deep link carries only its own state."** Livewire writes into the
    // query string exactly the properties that carry `#[Url]`, so this list is the whole of what a
    // copied link can hold. Before #308 the one panel carried both scopes, `sessions` and `locks`,
    // whether or not the reader cared about both.
    $declared = [];

    foreach ($page->getProperties() as $property) {
        foreach ($property->getAttributes(Url::class) as $attribute) {
            $declared[] = $attribute->newInstance()->as;
        }
    }

    sort($declared);

    expect($declared)->toBe($expected);
})->with([
    'agents' => [new ReflectionClass(Agents::class), ['scope', 'session']],
    'locks' => [new ReflectionClass(LocksPage::class), ['holder', 'scope']],
]);

it('links each page to the other without carrying its own filter across', function (): void {
    // The behavioral half. A reader who narrowed the agents to live ones and followed a row to its
    // locks must arrive at the Locks page's own default, not at a `scope=live` that page would read
    // as its own and apply to a different question.
    $agents = (string) $this->get(route('robot-council.agents', ['scope' => 'live']))->assertOk()->getContent();
    $locks = (string) $this->get(route('robot-council.locks', ['scope' => 'all']))->assertOk()->getContent();

    expect(hrefsTo($agents, 'robot-council.locks'))->toContain(route('robot-council.locks', ['holder' => $this->session->id]))
        ->each->not->toContain('scope=')
        ->and(hrefsTo($locks, 'robot-council.agents'))->toContain(route('robot-council.agents', ['session' => $this->session->id]))
        ->each->not->toContain('scope=');
});

it('leads from a session to the locks it holds, and only those', function (): void {
    $agents = (string) $this->get(route('robot-council.agents'))->assertOk()->getContent();

    // Read off the page rather than built here, so the test follows the link the reader follows
    $link = route('robot-council.locks', ['holder' => sessionOn('box-second')->id]);

    expect(hrefsTo($agents, 'robot-council.locks'))->toContain($link);

    // The control: unnarrowed, both locks are on the Locks page, so the absence below is the
    // narrowing and not a fixture that never held the lock
    $this->get(route('robot-council.locks'))->assertSeeHtml(lockCell('lock-first'))->assertSeeHtml(lockCell('lock-second'));

    $this->get($link)
        ->assertOk()
        ->assertSeeHtml(lockCell('lock-second'))
        ->assertDontSeeHtml(lockCell('lock-first'))
        ->assertSeeHtml('<span>Showing locks held by session #'.sessionOn('box-second')->id.'.</span>');
});

it('leads from a lock to the session holding it, and only that one', function (): void {
    $locks = (string) $this->get(route('robot-council.locks'))->assertOk()->getContent();

    $link = route('robot-council.agents', ['session' => $this->session->id]);

    // The holder's login is the link text, so the link is to THIS lock's holder rather than merely
    // to some session
    expect($locks)->toContain('<a href="'.e($link).'" class="link">octodev</a>');

    $this->get(route('robot-council.agents'))->assertSeeHtml(machineCell('box-first'))->assertSeeHtml(machineCell('box-second'));

    $this->get($link)
        ->assertOk()
        ->assertSeeHtml(machineCell('box-first'))
        ->assertDontSeeHtml(machineCell('box-second'))
        ->assertSeeHtml('<span>Showing one session, #'.$this->session->id.'.</span>');
});

it('still counts the whole fleet while narrowed to one row', function (): void {
    // A count that shrank with the narrowing would tell a reader following a link that the fleet
    // is one session and one lock
    Livewire::withQueryParams(['session' => $this->session->id])->test(Agents::class)
        ->assertSeeHtml('All (2)')
        ->assertDontSeeHtml(machineCell('box-second'));

    Livewire::withQueryParams(['holder' => $this->session->id])->test(LocksPage::class)
        ->assertSeeHtml('Held (2)')
        ->assertDontSeeHtml(lockCell('lock-second'));
});

it('goes back to the whole list from a narrowed one', function (): void {
    Livewire::withQueryParams(['session' => $this->session->id])->test(Agents::class)
        ->call('showEverySession')
        ->assertSet('session', null)
        ->assertSeeHtml(machineCell('box-second'))
        ->assertDontSeeHtml('Showing one session');

    Livewire::withQueryParams(['holder' => $this->session->id])->test(LocksPage::class)
        ->call('showEveryHolder')
        ->assertSet('holder', null)
        ->assertSeeHtml(lockCell('lock-second'))
        ->assertDontSeeHtml('Showing locks held by');
});

it('says so when a link names a session that lists nothing, rather than calling the fleet empty', function (): void {
    // A session long since pruned, or one that holds nothing now. Either is a stale link, and the
    // page naming the fleet as empty would be a false statement about everything else in it.
    Livewire::withQueryParams(['session' => 999999])->test(Agents::class)
        ->assertSeeHtml('No session #999999 in this list.')
        ->assertDontSeeHtml('No agent has enrolled yet.');

    $this->service(Locks::class)->release($this->session, 'lock-first', false);

    Livewire::withQueryParams(['holder' => $this->session->id])->test(LocksPage::class)
        ->assertSeeHtml('Session #'.$this->session->id.' holds no locks in this list.')
        ->assertDontSeeHtml('Nothing is locked.');
});

it('sends the retired presence page to the agents, permanently', function (): void {
    // A bookmark is the only reader the old path has, and a 404 there would be the one visible cost
    // of the split
    $this->get('/robot-council/dashboard/presence')
        ->assertStatus(301)
        ->assertRedirect(route('robot-council.agents'));
});

it('still resolves the retired presence component, as the agents list', function (): void {
    // A host that embedded the combined panel before #308 gets the agents half rather than a
    // component-not-found error on upgrade
    Livewire::test('robot-council-fleet-presence')
        ->assertSee('box-first')
        ->assertSee('box-second')
        ->assertDontSee('lock-first');
});

it('sends the retired presence page to the agents under whatever prefix the host chose', function (): void {
    // **The case `Route::redirect()` gets wrong.** Its destination is a literal path the group
    // prefix is never applied to, so it answers a relative `dashboard/agents`, which a browser
    // resolves against the old URL to `.../dashboard/dashboard/agents`.
    $this->rebootWith('robot-council.routes.web_prefix', 'council');

    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242]);
    $this->actingAs($this->enrollDeveloper(4242, login: 'octodev'), 'web');

    $response = $this->get('/council/dashboard/presence')->assertStatus(301);

    expect($response->headers->get('Location'))->toBe(route('robot-council.agents'))
        ->and(route('robot-council.agents', absolute: false))->toBe('/council/dashboard/agents');
});

it('keeps the retired presence page behind the gate', function (): void {
    auth('web')->logout();

    $this->get('/robot-council/dashboard/presence')->assertRedirect(route('robot-council.auth.redirect'));
});

it('marks Agents and Locks as current on their own page, and only there', function (string $shown, string $other): void {
    $html = (string) $this->get(route($shown))->assertOk()->getContent();

    $current = openingTagLinkingTo($html, route($shown));
    $notCurrent = openingTagLinkingTo($html, route($other));

    // Both anchors found first, because `not->toContain` is satisfied by an empty string
    expect($current)->not->toBeEmpty()
        ->and($notCurrent)->not->toBeEmpty()
        ->and($current)->toContain('aria-current="page"')->toContain('menu-active')
        ->and($notCurrent)->not->toContain('aria-current')->not->toContain('menu-active');
})->with([
    'agents' => ['robot-council.agents', 'robot-council.locks'],
    'locks' => ['robot-council.locks', 'robot-council.agents'],
]);

it('offers no link to the retired presence page', function (): void {
    // It keeps its route name so a host template calling it still resolves, but nothing here links
    // to it. Checked on every page that carries the sidebar, since the sidebar is where it was.
    foreach (['dashboard', 'agents', 'locks', 'queue'] as $page) {
        $html = (string) $this->get(route('robot-council.'.$page))->assertOk()->getContent();

        expect($html)->not->toContain('dashboard/presence')
            ->toContain(route('robot-council.agents'));
    }
});

it('lists everything for a link whose id is not a number, rather than failing', function (string $value): void {
    // Typed as an int, so Livewire drops what does not hydrate as one -- measured: a word, a number
    // past `PHP_INT_MAX` and a number with a suffix all arrive as null. Asserted through the route
    // because that is where a hand-edited query string comes in, and a 500 there is the outcome
    // this rules out.
    $this->get(route('robot-council.agents').'?session='.$value)
        ->assertOk()
        ->assertSeeHtml(machineCell('box-first'))
        ->assertSeeHtml(machineCell('box-second'))
        ->assertDontSeeHtml('Showing one session');

    $this->get(route('robot-council.locks').'?holder='.$value)
        ->assertOk()
        ->assertSeeHtml(lockCell('lock-first'))
        ->assertSeeHtml(lockCell('lock-second'))
        ->assertDontSeeHtml('Showing locks held by');
})->with([
    'a word' => ['abc'],
    'past the largest integer' => ['99999999999999999999'],
    'a number with a suffix' => ['12abc'],
]);

it('lists nothing for an id no session can have', function (): void {
    // Zero and below are not ids this package issues. They narrow to nothing and say so, rather
    // than being widened to everything, which would leave the reader looking at a list the link
    // did not name.
    $this->get(route('robot-council.agents', ['session' => -3]))
        ->assertOk()
        ->assertSeeHtml('No session #-3 in this list.')
        ->assertDontSeeHtml(machineCell('box-first'));
});
