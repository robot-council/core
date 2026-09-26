<?php

declare(strict_types=1);

/**
 * The dashboard's vocabulary and its answers (#402): every term of art a page shows is explained on
 * that page, every action says in words what it did beside the control that caused it, and every
 * empty state leads with the word that sums it up.
 *
 * @command  vendor/bin/pest --compact tests/DashboardVocabularyTest.php
 */

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use RobotCouncil\Access\Role;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Livewire\SeatSettings;
use RobotCouncil\Livewire\TaskBoard;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RobotCouncil\Models\Seat;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\DeveloperSettings;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\Glossary;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\RoleRequests;
use RobotCouncil\Support\Seats;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 4243], admins: [4242]);

    $this->admin = $this->enrollDeveloper(4242, login: 'octoadmin');
    $this->developer = $this->enrollDeveloper(4243, login: 'octodev');
});

/**
 * The terms each page explains, which is the inventory #402 asked for.
 *
 * **Pinned rather than derived.** A list read back from the views could only say that each page
 * explains whatever it explains; this says which words each page shows, so dropping one from a
 * page's glossary fails here.
 *
 * @return array<string, array{string, array<string, string>, list<string>}> Route, its parameters, and its terms.
 */
function vocabularyPages(): array
{
    return [
        'overview' => ['robot-council.dashboard', [], ['fleet', 'agent', 'session', 'live_agents', 'task', 'open_tasks', 'queue', 'lock', 'held_locks', 'lane', 'change_feed']],
        'lanes' => ['robot-council.lanes', [], ['lane', 'harness', 'working', 'idle', 'parked', 'blocked', 'not_observed', 'gate', 'watcher', 'tickets_held', 'task', 'ticket', 'hand_back', 'subagent', 'taken_up', 'validating', 'known_since', 'open_issues', 'waiting_on_developer']],
        'agents' => ['robot-council.agents', [], ['agent', 'session', 'live_scope', 'harness', 'machine_label', 'working_in', 'role', 'coordinator', 'active', 'stale', 'gone', 'lock']],
        'locks' => ['robot-council.locks', [], ['lock', 'held_scope', 'held_by_lock', 'fence', 'lease', 'session']],
        'queue' => ['robot-council.queue', [], ['task', 'ticket', 'pending', 'claimed', 'in_progress', 'blocked_task', 'done', 'failed', 'cancelled', 'priority', 'held_by_task', 'lane', 'coordinator']],
        'feed' => ['robot-council.feed', [], ['change_feed', 'entry_type', 'narration', 'directive', 'placement_instruction', 'coordinator', 'session', 'task', 'lock', 'lane']],
        'administration' => ['robot-council.administration', [], ['installation', 'harness', 'machine_label', 'usable', 'revoked', 'expired', 'session', 'active', 'stale', 'gone', 'role', 'coordinator', 'ephemeral', 'asked_for_role', 'make_role', 'revoke_session', 'revoke_installation']],
        'seats' => ['robot-council.seats', [], ['seat', 'harness', 'machine_label', 'park', 'exempt', 'tickets_at_once', 'placement', 'waive', 'assignment_hours', 'days_off', 'gate', 'hand_back', 'coordinator']],
    ];
}

function vocabularyDocument(string $html): DOMXPath
{
    if ($html === '') {
        throw new RuntimeException('The page rendered nothing.');
    }

    $document = new DOMDocument;

    // libxml's HTML 4 parser warns about every HTML5 element; the tree it builds is still right
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

/**
 * @return list<DOMElement>
 */
function vocabularyElements(DOMXPath $xpath, string $expression): array
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

function vocabularyText(DOMElement $element): string
{
    return trim((string) preg_replace('/\s+/', ' ', $element->textContent));
}

/**
 * What the page says beside one element: the text of every `data-said` inside it.
 *
 * @return list<string>
 */
function saidInside(string $html, string $container): array
{
    return array_map(vocabularyText(...), vocabularyElements(vocabularyDocument($html), $container.'//*[@data-said]'));
}

it('explains every term of art a page shows, on that page, in a disclosure rather than a tooltip', function (string $route, array $parameters, array $terms): void {
    $this->actingAs($this->admin, 'web');

    $xpath = vocabularyDocument((string) $this->get(route($route, $parameters))->assertOk()->getContent());

    $glossaries = vocabularyElements($xpath, '//details[@data-glossary]');

    expect($glossaries)->toHaveCount(1)
        ->and(vocabularyText(vocabularyElements($xpath, '//details[@data-glossary]/summary')[0]))->toBe('What the words on this page mean');

    // Each term is the defining instance, and its explanation is the text beside it rather than an
    // attribute: a `title` tooltip is out of reach of touch and keyboard
    $defined = [];

    foreach (vocabularyElements($xpath, '//details[@data-glossary]//dfn') as $dfn) {
        $dd = vocabularyElements($xpath, '//dfn[@id="'.$dfn->getAttribute('id').'"]/parent::dt/following-sibling::dd[1]');

        expect($dd)->toHaveCount(1)
            ->and(mb_strlen(vocabularyText($dd[0])))->toBeGreaterThan(20);

        $defined[] = substr($dfn->getAttribute('id'), strlen('term-'));
    }

    expect($defined)->toBe($terms)
        ->and(vocabularyElements($xpath, '//*[@title]'))->toBeEmpty();
})->with(vocabularyPages());

it("explains the enrollment page's terms too", function (): void {
    $code = app(DeviceCodes::class)->issue(['fleet:read'], 'claude-code', 'workbench', hash('sha256', 'vocabulary-verifier'), null);

    $this->actingAs($this->developer, 'web');

    $xpath = vocabularyDocument((string) $this->get(route('robot-council.enroll.show', ['user_code' => $code->record->user_code]))->assertOk()->getContent());

    $defined = array_map(
        static fn (DOMElement $dfn): string => substr($dfn->getAttribute('id'), strlen('term-')),
        vocabularyElements($xpath, '//details[@data-glossary]//dfn'),
    );

    expect($defined)->toBe(['enrollment', 'enrollment_code', 'harness', 'machine_label', 'abilities', 'installation', 'task', 'lock', 'narration']);
});

it('refuses a term the glossary does not define, rather than printing its key', function (): void {
    expect(fn (): array => Glossary::entries(['lane', 'no_such_term']))
        ->toThrow(RuntimeException::class, 'The dashboard glossary has no entry for [no_such_term].')
        ->and(Glossary::entries(['lane'])[0])->toMatchArray(['key' => 'lane', 'term' => 'Lane']);
});

it('defines only terms some page shows, so an entry cannot outlive its word', function (): void {
    $defined = trans('robot-council::glossary');

    expect($defined)->toBeArray();

    $shown = [];

    foreach (bladeTemplatesIn(__DIR__.'/../resources/views') as $template) {
        preg_match_all("/partials\.glossary', \['terms' => \[([^\]]*)\]/", (string) file_get_contents($template), $includes);

        foreach ($includes[1] as $list) {
            preg_match_all("/'([a-z_]+)'/", $list, $keys);
            array_push($shown, ...$keys[1]);
        }
    }

    // The control: the scan finds the pages' includes at all
    expect($shown)->toContain('lane', 'seat', 'enrollment');

    expect(array_values(array_diff(array_keys(is_array($defined) ? $defined : []), $shown)))->toBeEmpty()
        ->and(array_values(array_diff(array_unique($shown), array_keys(is_array($defined) ? $defined : []))))->toBeEmpty();
});

/**
 * A developer's live session, working in one repository, and the seat that makes.
 *
 * @return array{Installation, AgentSession, Seat}
 */
function vocabularySeat(TestCase $case, User $developer): array
{
    $installation = $case->approveInstallation($developer, 'office-mac');
    $session = $case->service(AgentSessions::class)->start($installation, 'robot-council/core', 'robot-council-core-a')->owner;

    // Seats are made when a developer's seats are first read, not when a session starts
    $seat = $case->service(Seats::class)->forDeveloper(HostKey::from($developer->getAuthIdentifier()))[0];

    return [$installation, $session, $seat];
}

it('confirms each seat action in words, beside that seat', function (?string $first, string $action, array $arguments, string $words, bool $refused): void {
    [, , $seat] = vocabularySeat($this, $this->developer);

    // The state the action needs, set through the same page
    if ($first !== null) {
        Livewire::actingAs($this->developer)->test(SeatSettings::class)->call($first, $seat->id, ...$arguments);
    }

    $component = Livewire::actingAs($this->developer)->test(SeatSettings::class)
        ->call($action, $seat->id, ...$arguments)
        ->assertSet('said', $words)
        ->assertSet('saidAt', 'seat-'.$seat->id)
        ->assertSet('refused', $refused);

    // Beside the control that caused it: inside that seat's own row, and nowhere else on the page
    expect(saidInside($component->html(), '//li[.//button[contains(., "for robot-council/core / robot-council-core-a")]][1]'))->toBe([$words])
        ->and(saidInside($component->html(), ''))->toBe([$words]);
})->with([
    'park' => [null, 'park', [], 'Parked: robot-council/core / robot-council-core-a takes no new work until you lift it.', false],
    'lift' => ['park', 'lift', [], 'Lifted: robot-council/core / robot-council-core-a can take new work again.', false],
    'exempt' => [null, 'exempt', [], 'Exempted: robot-council/core / robot-council-core-a takes new work at any time, whatever your hours say.', false],
    'apply hours' => ['exempt', 'unexempt', [], 'Hours apply: robot-council/core / robot-council-core-a takes new work only inside your assignment hours.', false],
    'waive' => [null, 'waive', ['lane_not_parked'], "Waived once: the next placement on robot-council/core / robot-council-core-a goes ahead even when the lane's seat is parked by its developer.", false],
    'withdraw a waiver' => ['waive', 'withdrawWaiver', ['lane_not_parked'], "Withdrawn: placements on robot-council/core / robot-council-core-a are refused again when the lane's seat is parked by its developer.", false],
    'withdraw nothing' => [null, 'withdrawWaiver', ['lane_not_parked'], 'Nothing withdrawn: that waiver was already used or withdrawn. The list shows what is waived now.', true],
    'lift a seat that is not parked' => [null, 'lift', [], 'No change: robot-council/core / robot-council-core-a was already in that state. The page shows where it stands now.', true],
    'park a parked seat' => ['park', 'park', [], 'No change: robot-council/core / robot-council-core-a was already in that state. The page shows where it stands now.', true],
]);

it("refuses another developer's seat without naming it", function (): void {
    [, , $seat] = vocabularySeat($this, $this->admin);

    Livewire::actingAs($this->developer)->test(SeatSettings::class)
        ->call('park', $seat->id)
        ->assertSet('refused', true)
        ->assertSet('said', "Not allowed: only the developer who parked a seat can lift it, and only a seat's own developer can change it.");
});

it('confirms the hours and days off in words, beside their own form', function (): void {
    $component = Livewire::actingAs($this->developer)->test(SeatSettings::class)
        ->set('timezone', 'America/Chicago')
        ->set('startsAt', '08:00')
        ->set('endsAt', '17:00')
        ->set('skipWeekends', true)
        ->call('saveHours')
        ->assertSet('said', 'Saved: your seats take new work from 08:00 until 17:00, America/Chicago time, on weekdays only.')
        ->assertSet('saidAt', 'hours')
        ->assertSet('refused', false);

    expect(saidInside($component->html(), '//div[contains(@class, "card-body")][.//h2[.="Assignment hours"]]'))
        ->toBe(['Saved: your seats take new work from 08:00 until 17:00, America/Chicago time, on weekdays only.']);

    $component->set('timezone', 'EST')->call('saveHours')
        ->assertSet('said', 'Not saved: A timezone is an IANA zone name, such as America/Chicago.')
        ->assertSet('refused', true);

    $component->call('clearHours')
        ->assertSet('said', 'Removed: your seats take new work at any time. Your days off apply again once you set hours.')
        ->assertSet('saidAt', 'hours');

    $component->set('holiday', '2026-12-25')->call('addHoliday')
        ->assertSet('said', 'Added: 2026-12-25 is a day off.')
        ->assertSet('saidAt', 'days-off')
        ->assertSet('refused', false);

    expect(saidInside($component->html(), '//div[contains(@class, "card-body")][.//h2[.="Days off"]]'))->toBe(['Added: 2026-12-25 is a day off.']);

    $component->set('holiday', '2026-12-25')->call('addHoliday')
        ->assertSet('said', 'Already listed: 2026-12-25 was already a day off, so nothing changed.');

    $component->set('holiday', 'someday')->call('addHoliday')
        ->assertSet('said', 'Not added: A day off is a real date written as YYYY-MM-DD.')
        ->assertSet('refused', true);

    $component->call('removeHoliday', '2026-12-25')
        ->assertSet('said', 'Removed: 2026-12-25 is no longer a day off.')
        ->assertSet('refused', false);

    // What a client sent is not repeated back until the store matched it
    $component->call('removeHoliday', '<b>2026-12-26</b>')
        ->assertSet('said', 'Not listed: that date was not a day off, so nothing changed.')
        ->assertSet('refused', true);

    expect(app(DeveloperSettings::class)->holidays(HostKey::from($this->developer->getAuthIdentifier())))->toBeEmpty();
});

it('confirms each administrative action in words, beside the installation it was about', function (): void {
    [$installation, $session] = vocabularySeat($this, $this->developer);

    app(RoleRequests::class)->request($session, Role::Ci);

    $panel = Livewire::actingAs($this->admin)->test(Administration::class);

    // XPath has no name for an attribute with a colon in it, so the installation's row is found by
    // its revoke control, which only that row carries
    $row = '//li[.//button[contains(., "Revoke installation")]]';

    $panel->call('approveRole', $session->id, Role::Ci->value)
        ->assertSet('said', sprintf('Approved: session #%d is now ci.', $session->id))
        ->assertSet('saidAt', $installation->id)
        ->assertSet('refused', false);

    expect(saidInside($panel->html(), $row))->toBe([sprintf('Approved: session #%d is now ci.', $session->id)])
        ->and(saidInside($panel->html(), ''))->toBe([sprintf('Approved: session #%d is now ci.', $session->id)]);

    $panel->call('approveRole', $session->id, Role::Ci->value)
        ->assertSet('said', sprintf('Not approved: session #%d no longer asks to be ci. The list shows what it asks for now.', $session->id))
        ->assertSet('refused', true);

    $panel->call('denyRole', $session->id)
        ->assertSet('said', sprintf('Nothing to deny: session #%d has no request waiting now.', $session->id));

    app(RoleRequests::class)->request($session, Role::Coordinator);

    $panel->call('denyRole', $session->id)
        ->assertSet('said', sprintf('Denied: session #%d stays ci.', $session->id))
        ->assertSet('refused', false);

    $panel->call('imposeRole', $session->id, Role::Build->value)
        ->assertSet('said', sprintf('Changed: session #%d is now build.', $session->id));

    $panel->call('imposeRole', $session->id, Role::Build->value)
        ->assertSet('said', sprintf('No change: session #%d was already build.', $session->id))
        ->assertSet('refused', true);

    $panel->call('revokeSession', $session->id)
        ->assertSet('said', sprintf('Revoked: session #%d has ended, and its agent can no longer act.', $session->id))
        ->assertSet('saidAt', $installation->id);

    $panel->call('revokeSession', $session->id)
        ->assertSet('said', sprintf('Already gone: session #%d had already ended, so nothing changed.', $session->id))
        ->assertSet('refused', true);

    $panel->call('imposeRole', $session->id, Role::Ci->value)
        ->assertSet('said', sprintf('Gone: session #%d has ended, so its role can no longer be changed.', $session->id));

    $panel->call('revokeInstallation', $installation->id)
        ->assertSet('said', 'Revoked: claude-code on office-mac can no longer act, and neither can any session it started.')
        ->assertSet('saidAt', $installation->id)
        ->assertSet('refused', false);

    // The revoked installation has left the Usable list, so the words are shown above it instead
    expect(saidInside($panel->html(), ''))->toBe(['Revoked: claude-code on office-mac can no longer act, and neither can any session it started.'])
        ->and(saidInside($panel->html(), '//li'))->toBeEmpty();

    $panel->call('revokeSession', 999999)
        ->assertSet('said', 'Not found: session #999999 no longer exists. The list shows the ones that do.')
        ->assertSet('saidAt', null)
        ->assertSet('refused', true);

    $panel->call('revokeInstallation', 999999)
        ->assertSet('said', 'Not found: that installation no longer exists. The list shows the ones that do.');
});

it('leads every empty state with the word that sums it up, and says what to do next', function (string $route, string $words): void {
    $this->actingAs($this->admin, 'web');

    $this->get(route($route))->assertOk()->assertSeeText($words);
})->with([
    'lanes' => ['robot-council.lanes', 'No lanes: no session that can take work is connected. A lane appears here when an agent joins the fleet.'],
    'waiting' => ['robot-council.lanes', 'Nothing waiting: no agent has asked a developer for a decision or an action.'],
    'agents' => ['robot-council.agents', "No agents yet: a machine's sessions appear here once a developer approves its enrollment and an agent joins."],
    'locks' => ['robot-council.locks', 'Nothing locked: no agent holds a lock right now.'],
    'queue' => ['robot-council.queue', 'Queue empty: the fleet has not been asked to do anything yet.'],
    'feed' => ['robot-council.feed', 'No entries yet: the feed fills as agents join, take work and report.'],
    'administration' => ['robot-council.administration', 'No machines yet: a machine appears here once a developer approves its enrollment.'],
    'seats' => ['robot-council.seats', 'No seats yet: a seat appears once one of your sessions reports the repository it works in.'],
]);

it('names the filter and the way back when a filtered queue is empty', function (): void {
    Livewire::actingAs($this->admin)->test(TaskBoard::class)
        ->call('showStatus', 'blocked')
        ->assertSeeText('No blocked tasks. Choose All to see every task.');
});
