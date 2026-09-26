<?php

declare(strict_types=1);

/**
 * The dashboard's automated accessibility gate (#403): every surface, loaded in a real headless
 * Chromium with a seeded fleet, in both themes, scanned by axe-core.
 *
 * **This is the mechanical half, and never the whole.** axe reliably decides about a third of WCAG
 * -- contrast, names, roles, states -- and nothing it reports can say whether a word is plain, a
 * focus order sensible, or a change announced. `.claude/rules/accessibility.md` keeps the manual pass
 * for that, and a clean run here is necessary rather than sufficient.
 *
 * **What fails the gate:** any axe violation of serious or critical impact among the WCAG A and AA
 * rules, on any surface, in either theme. **What is only reported:** the AAA rules, written to
 * `build/axe-aaa.json` and summarized on stderr, because AAA is the target rather than the floor and
 * is not wholly machine-decidable. Beside axe, two checks it cannot make: no status is carried by
 * color alone, and every control meets its target size.
 *
 * **Browser tests run apart, as a test suite of their own.** `phpunit.xml.dist` keeps
 * `tests/Browser` out of the default suite rather than only excluding a group, because the plugin
 * starts Playwright while it collects tests -- as soon as it loads this file, before any group
 * filter applies -- so a run that merely loads it needs Playwright installed. The `browser` job in
 * `.github/workflows/ci.yml` runs it, and locally it is `composer test:browser` after
 * `npx playwright install chromium`.
 *
 * @command  vendor/bin/pest --testsuite=Browser
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Pest\Browser\Api\PendingAwaitablePage;
use RobotCouncil\Access\AccessList;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Models\Lock;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\AllowlistEntries;
use RobotCouncil\Support\Backlog;
use RobotCouncil\Support\DeveloperSettings;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\GateRuns;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\LaneHolds;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\Outcome;
use RobotCouncil\Support\OwedItems;
use RobotCouncil\Support\RoleRequests;
use RobotCouncil\Support\Seats;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

pest()->group('browser');

/**
 * The WCAG A and AA rule tags, which are the gate. axe's own default run adds its best-practice
 * rules, which are advice rather than conformance, so the tags are named rather than left to it.
 * The axe the plugin bundles (4.10.3) files WCAG 2.2's one AA rule, `target-size`, under `wcag22aa`
 * and has no `wcag22a` rule, so that tag is not listed.
 */
const AXE_GATE = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/**
 * The AAA rule tags, which are reported and never fail the run. axe 4.10.3 files every AAA rule it
 * has under `wcag2aaa`.
 */
const AXE_AAA = ['wcag2aaa'];

/**
 * Where the AAA findings are written.
 */
function axeAaaReport(): string
{
    return dirname(__DIR__, 2).'/build/axe-aaa.json';
}

/**
 * Every surface: the route that renders it, what it needs in the URL, and a string only that
 * surface draws from the seeded fleet.
 *
 * **The string is how a scan knows it scanned the right page.** The browser reports no status
 * code, and the plugin's server answers an exception with an error page, so a surface that failed
 * to render, or bounced to sign-in, would otherwise be scanned in its place -- and a bare error page
 * passes every check here. `guest` visits signed out; `code` passes the seeded enrollment code.
 *
 * @return array<string, array{string, string, string}> The route, the parameter, and the string.
 */
function accessibilitySurfaces(): array
{
    return [
        'overview' => ['robot-council.dashboard', '', 'Live agents'],
        'lanes' => ['robot-council.lanes', '', 'Run the screen-reader pass'],
        'queue' => ['robot-council.queue', '', 'Everyone stop and sync'],
        'agents' => ['robot-council.agents', '', 'coordinator-mac'],
        'locks' => ['robot-council.locks', '', 'branch:vocabulary'],
        'feed' => ['robot-council.feed', '', 'Running the gate before opening the pull request.'],
        'administration' => ['robot-council.administration', '', 'gate-runner'],
        'access' => ['robot-council.access', '', 'from configuration'],
        'seats' => ['robot-council.seats', '', 'robot-council-core-a'],
        'waiting' => ['robot-council.waiting', '', 'Run the screen-reader pass'],
        'enrollment' => ['robot-council.enroll.show', 'code', 'What the machine says about itself'],
        'signed out' => ['robot-council.signed-out', 'guest', 'Signed out'],
        'sign-in expired' => ['robot-council.auth.callback', 'guest', 'That sign-in attempt expired'],
    ];
}

/**
 * A fleet with something in every panel, so each page is scanned populated rather than empty.
 *
 * Every state a page draws differently is here: a working lane with a branch and a hand-back, a
 * blocked one, a parked one, a stale one and a gate validating a pull request; a lock held, one
 * lapsed and one released; tasks in several statuses, one of them a coordinator's; a narration, a
 * directive and a role request; an owed item; a meter reading; hours and a day off.
 *
 * @return array{developer: User, code: string} Who to sign in, and a live enrollment code.
 */
function seedAccessibilityFleet(TestCase $case): array
{
    $case->migrateUsersTableWithPackageColumns();
    $case->setAccessLists(developers: [4242, 4243], admins: [4242]);

    $developer = $case->enrollDeveloper(4242, login: 'octodev');
    $colleague = $case->enrollDeveloper(4243, login: 'colleague');

    $sessions = $case->service(AgentSessions::class);
    $tasks = $case->service(Tasks::class);

    // A coordinator, three build lanes, a gate, and a colleague's lane
    [$coordinator] = $case->startCoordinatorSession($case->approveInstallation($developer, 'coordinator-mac'));
    $working = $sessions->start($case->approveInstallation($developer, 'office-mac'), 'robot-council/core', 'robot-council-core-a')->owner;
    $blocked = $sessions->start($case->approveInstallation($developer, 'home-windows'), 'robot-council/core', 'robot-council-core-b')->owner;
    $colleagueLane = $sessions->start($case->approveInstallation($colleague, 'colleague-laptop'), 'robot-council/core', 'robot-council-core-c')->owner;
    $stale = $sessions->start($case->approveInstallation($colleague, 'colleague-desktop'), 'robot-council/cli', 'robot-council-cli-a')->owner;
    $gate = $sessions->start($case->approveInstallation($developer, 'gate-runner'), 'robot-council/core', 'robot-council-core-ci')->owner;

    // Every seeding write is checked: a refused one would leave a panel empty and its scan vacuous
    expect($case->service(RoleRequests::class)->impose($gate, Role::Ci, 'test-administrator'))->toBeTrue()
        ->and($case->service(RoleRequests::class)->request($blocked, Role::Coordinator))->toBeTrue();

    $gate->refresh();

    // Work: one task held and started on a branch, flagged as a hand-back; others in other states
    $held = $tasks->create($working, ['title' => 'Explain the vocabulary', 'priority' => 7, 'issue' => 'robot-council/core#402'], false);
    $tasks->transition($held->id, TaskTransition::Claim, $working, asCoordinator: false);
    $tasks->transition($held->id, TaskTransition::Start, $working, asCoordinator: false, branch: 'vocabulary');
    Task::query()->whereKey($held->id)->update(['hand_back' => true]);

    $tasks->create($coordinator, ['title' => 'Everyone stop and sync', 'priority' => 9], true);
    $tasks->create($working, ['title' => 'Measure the gate', 'priority' => 3], false);

    $done = $tasks->create($working, ['title' => 'Port the rule', 'priority' => 1], false);
    $tasks->transition($done->id, TaskTransition::Claim, $working, asCoordinator: false);
    $tasks->transition($done->id, TaskTransition::Complete, $working, asCoordinator: false, result: ['pull_request' => 448]);

    // Lanes in the other states
    expect($case->service(LaneHolds::class)->hold($coordinator, $blocked->id, 'robot-council/core#403', HoldReason::TicketLands))->toBe(Outcome::Applied)
        // A lane held on the signed-in developer, so the waiting page (#411) is scanned with one
        ->and($case->service(LaneHolds::class)->hold($coordinator, $colleagueLane->id, 'octodev', HoldReason::Decision))->toBe(Outcome::Applied);
    $parkedSeat = $case->service(Seats::class)->forDeveloper(HostKey::from($colleague->getAuthIdentifier()))[0];
    expect($case->service(Seats::class)->park(HostKey::from($colleague->getAuthIdentifier()), $parkedSeat->id))->toBe(Outcome::Applied);
    AgentSession::query()->whereKey($stale->id)->update(['status' => AgentSessionStatus::Stale->value]);
    expect($case->service(GateRuns::class)->start($gate, 'robot-council/core#453'))->toBe(Outcome::Applied);

    // Locks: one held, one lapsed, one released
    $locks = $case->service(Locks::class);
    $locks->acquire($working, 'branch:vocabulary', 300, false);
    $locks->acquire($blocked, 'deploy:production', 300, false);
    Lock::query()->where('name', 'deploy:production')->update(['expires_at' => Carbon::now()->subMinutes(5)]);
    $locks->acquire($working, 'db:migrations', 300, false);
    $locks->release($working, 'db:migrations', false);

    // The feed, the owed item and the meter
    $events = $case->service(FleetEvents::class);
    $events->record(FleetEventType::Narration, $working, 'Running the gate before opening the pull request.');
    $events->record(FleetEventType::Directive, $coordinator, 'Sync with main before pushing.', withCoordinator: true);
    $case->service(OwedItems::class)->record($coordinator, 'octodev', 'robot-council/core#450', 'Run the screen-reader pass', 'needs a person at VoiceOver');
    $case->service(Backlog::class)->record('robot-council/core', 42);

    // Hours and a day off, so the seats page draws its filled form
    $settings = $case->service(DeveloperSettings::class);
    $settings->setHours(HostKey::from($developer->getAuthIdentifier()), 'America/Chicago', '08:00', '17:00', true);
    $settings->addHoliday(HostKey::from($developer->getAuthIdentifier()), '2026-12-25');

    // A table entry on the access page, so it is scanned with a remove control and not only with
    // the configuration entries (#407)
    $case->service(AllowlistEntries::class)->add(AccessList::Developer, 5150, 'added-here');

    $code = app(DeviceCodes::class)->issue(['fleet:read', 'tasks:create'], 'claude-code', 'workbench', hash('sha256', 'accessibility-verifier'), null);

    return ['developer' => $developer, 'code' => $code->record->user_code];
}

/**
 * Visit one surface in one theme, and prove it is that surface before anything scans it.
 *
 * @param  array<string, mixed>  $options  Browser context options, such as `forcedColors`.
 */
function visitSurface(TestCase $case, string $route, string $parameter, string $expect, string $theme, array $options = []): PendingAwaitablePage
{
    $fleet = seedAccessibilityFleet($case);

    if ($parameter !== 'guest') {
        $case->actingAs($fleet['developer'], 'web');
    }

    $url = route($route, $parameter === 'code' ? ['user_code' => $fleet['code']] : []);
    $page = visit($url, $options);
    $page = $theme === 'dark' ? $page->inDarkMode() : $page->inLightMode();

    // Still on the page asked for, with its main landmark and the words only it draws
    $where = $page->script('() => ({ path: location.pathname, main: document.querySelectorAll("main").length })');

    expect($where)->toBe(['path' => (string) parse_url($url, PHP_URL_PATH), 'main' => 1]);

    $page->assertSee($expect);

    return $page;
}

/**
 * The violations axe finds on a page, for the rule tags given.
 *
 * **Run through `script()` rather than the plugin's `assertNoAccessibilityIssues()`**, which turns an
 * absent or failed axe run into an empty list and so a pass. Here an axe that did not run throws.
 *
 * @param  list<string>  $tags  The axe rule tags to run.
 * @return list<array{id: string, impact: string, help: string, nodes: list<string>}> What it found.
 */
function axeViolations(PendingAwaitablePage $page, array $tags): array
{
    $found = $page->script(sprintf(<<<'JS'
        async () => {
            if (typeof window.axe !== 'object') return 'axe is not on the page';
            const result = await window.axe.run(document, { runOnly: { type: 'tag', values: %s } });
            return result.violations.map(v => ({
                id: v.id,
                impact: v.impact || 'unknown',
                help: v.help,
                nodes: v.nodes.slice(0, 5).map(n => n.target.join(' ')),
            }));
        }
    JS, json_encode($tags)));

    if (! is_array($found)) {
        throw new RuntimeException('axe did not run on the page: '.json_encode($found));
    }

    /** @var list<array{id: string, impact: string, help: string, nodes: list<string>}> $found */
    return $found;
}

/**
 * The violations that fail the gate: serious or critical.
 *
 * @param  list<array{id: string, impact: string, help: string, nodes: list<string>}>  $violations
 * @return list<string> One line per violation.
 */
function blockingViolations(array $violations): array
{
    return array_values(array_map(
        static fn (array $violation): string => sprintf('%s [%s] %s: %s', $violation['id'], $violation['impact'], $violation['help'], implode(', ', $violation['nodes'])),
        array_filter($violations, static fn (array $violation): bool => in_array($violation['impact'], ['serious', 'critical'], true)),
    ));
}

/**
 * The selector for every element whose color carries a meaning: badges, status dots, alerts, and
 * the semantic text, background, border and progress colors.
 */
const SEMANTIC_COLOR = '/(^|\s)(badge|badge-[a-z]+|status|status-[a-z]+|alert-[a-z]+|progress-[a-z]+|(text|bg|border)-(error|success|warning|info))(\s|$)/';

/**
 * Run a check in the page that returns a list, or throw when it did not run.
 *
 * @return list<string>
 */
function pageList(PendingAwaitablePage $page, string $check, string $script): array
{
    $found = $page->script($script);

    if (! is_array($found)) {
        throw new RuntimeException(sprintf('The %s check did not run on the page: %s', $check, json_encode($found)));
    }

    /** @var list<string> $found */
    return $found;
}

/**
 * Every element whose color carries a meaning and that shows no word to carry it too.
 *
 * A semantic color reaches no assistive technology and no reader who cannot tell the hues apart,
 * so each must carry its meaning as VISIBLE text as well: two letters at least, which a dot, an icon
 * or an empty box does not have, and not counting `sr-only` text, which a sighted reader never sees.
 *
 * @return list<string> The start of each such element's markup.
 */
function colorOnlyElements(PendingAwaitablePage $page): array
{
    return pageList($page, 'color', sprintf(<<<'JS'
        () => {
            const semantic = %s;
            const visibleText = el => [...el.childNodes].map(n => n.nodeType === 3 ? n.textContent : (n.nodeType === 1 && !n.classList.contains('sr-only') ? visibleText(n) : '')).join('');
            return [...document.querySelectorAll('body *')]
                .filter(el => typeof el.className === 'string' && semantic.test(el.className) && el.getClientRects().length > 0)
                .filter(el => !/[A-Za-z]{2,}/.test(visibleText(el)))
                .map(el => el.outerHTML.slice(0, 120));
        }
    JS, SEMANTIC_COLOR));
}

/**
 * Every element with a semantic color whose word a forced-colors user cannot see: hidden,
 * transparent, or drawn in its own background's color once the system's colors replace the theme's.
 *
 * @return list<string>
 */
function wordsLostToForcedColors(PendingAwaitablePage $page): array
{
    return pageList($page, 'forced-colors', sprintf(<<<'JS'
        () => {
            const semantic = %s;
            return [...document.querySelectorAll('body *')]
                .filter(el => typeof el.className === 'string' && semantic.test(el.className) && el.getClientRects().length > 0)
                .filter(el => { const cs = getComputedStyle(el); return cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0 || cs.color === 'rgba(0, 0, 0, 0)' || cs.color === cs.backgroundColor; })
                .map(el => el.outerHTML.slice(0, 120));
        }
    JS, SEMANTIC_COLOR));
}

/**
 * Every control smaller than its target: 44px for a consequential one (`btn-target`), 24px for the
 * rest (SC 2.5.5 and SC 2.5.8).
 *
 * Measured as rendered. **Only a link inside a sentence is exempt**, under SC 2.5.8's inline
 * exception: a link whose enclosing block holds text of its own beside it. A link alone in a table
 * cell is a target like any other. A `label` is measured only where it is itself the control, as the
 * drawer's toggle is; otherwise the control it names is measured.
 *
 * @return list<string> Each undersized control, with its size.
 */
function undersizedControls(PendingAwaitablePage $page): array
{
    return pageList($page, 'target-size', <<<'JS'
        () => {
            const out = [];
            const describe = el => (el.getAttribute('aria-label') || el.textContent || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 40);
            const inSentence = el => {
                if (el.tagName !== 'A') return false;
                const block = el.parentElement.closest('p, li, dd, td, span, div');
                return block !== null && block.textContent.replace(el.textContent, '').trim().length > 0;
            };
            for (const el of document.querySelectorAll('button, a[href], summary, input:not([type=hidden]), select, textarea, label.btn, [role=button]')) {
                const rect = el.getBoundingClientRect();
                if (rect.width === 0 && rect.height === 0) continue;
                const side = el.closest('.drawer-side');
                if (side && getComputedStyle(side).visibility === 'hidden') continue;
                if (el.matches('.drawer-toggle') || inSentence(el)) continue;
                const floor = el.matches('.btn-target') ? 44 : 24;
                if (rect.width < floor - 0.5 || rect.height < floor - 0.5) out.push(`${describe(el)} ${Math.round(rect.width)}x${Math.round(rect.height)} < ${floor}`);
            }
            return out;
        }
    JS);
}

beforeAll(function (): void {
    // A report, not an accumulation: an earlier run's findings for a page that has since been
    // fixed would otherwise survive in it
    @unlink(axeAaaReport());
});

it('meets the A and AA gate on every surface, in both themes', function (string $route, string $parameter, string $expect, string $theme): void {
    $page = visitSurface($this, $route, $parameter, $expect, $theme);

    $blocking = blockingViolations(axeViolations($page, AXE_GATE));

    expect($blocking)->toBeEmpty(implode("\n", $blocking));
})->with(accessibilitySurfaces())->with(['light', 'dark']);

it('reports the AAA findings on every surface without failing on them', function (string $route, string $parameter, string $expect, string $theme): void {
    $violations = axeViolations(visitSurface($this, $route, $parameter, $expect, $theme), AXE_AAA);

    $path = axeAaaReport();
    $report = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $report = is_array($report) ? $report : [];
    $report[$route.' '.$theme] = $violations;

    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));

    foreach ($violations as $violation) {
        fwrite(STDERR, sprintf("AAA %s %s: %s [%s] x%d\n", $route, $theme, $violation['id'], $violation['impact'], count($violation['nodes'])));
    }

    $written = json_decode((string) file_get_contents($path), true);

    expect($written)->toBeArray()->toHaveKey($route.' '.$theme);
})->with(accessibilitySurfaces())->with(['light', 'dark']);

it('draws every seeded state, so the scans above are of populated pages', function (): void {
    // The lane board: a working lane on its branch with a hand-back, a blocked, a parked and a
    // stale lane, a gate validating a pull request, the owed item and the meter
    visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', 'light')
        ->assertSee('vocabulary')
        ->assertSee('hand-back')
        ->assertSee('Blocked')
        ->assertSee('Parked')
        ->assertSee('not observed')
        ->assertSee('validating')
        ->assertSee('42');

    // Locks: one held, one lapsed and shown as a warning
    visitSurface($this, 'robot-council.locks', '', 'branch:vocabulary', 'light')
        ->assertSee('deploy:production')
        ->assertPresent('.badge-warning');

    // The queue: a coordinator's task and a finished one
    visitSurface($this, 'robot-council.queue', '', 'Everyone stop and sync', 'light')
        ->assertSee('Port the rule')
        ->assertPresent('.badge-outline');

    // Administration: a role request waiting
    visitSurface($this, 'robot-council.administration', '', 'gate-runner', 'light')
        ->assertSee('asked for coordinator');
});

it('fails on a planted violation, so a clean run means axe looked', function (): void {
    $page = visitSurface($this, 'robot-council.dashboard', '', 'Live agents', 'light');

    // Two plants: an image with no text alternative, and a button with no name, both critical
    $page->script(<<<'JS'
        () => {
            const main = document.querySelector('main');
            const image = document.createElement('img');
            image.src = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
            main.appendChild(image);
            main.appendChild(document.createElement('button'));
        }
    JS);

    expect(array_column(axeViolations($page, AXE_GATE), 'id'))->toContain('image-alt', 'button-name')
        ->and(blockingViolations(axeViolations($page, AXE_GATE)))->not->toBeEmpty();
});

it('fails on a planted contrast failure, so the stylesheet is what axe measured', function (): void {
    $page = visitSurface($this, 'robot-council.dashboard', '', 'Live agents', 'dark');

    // About 1.3:1 on the dark theme's page, below even the 3:1 large-text bar. Not the background's
    // own color: axe files identical colors as undecidable, not as a failure
    $page->script('() => { document.querySelector("h1").style.color = "rgb(60, 60, 60)"; }');

    expect(array_column(axeViolations($page, AXE_GATE), 'id'))->toContain('color-contrast');
});

it('reports an AAA-only failure, so an empty AAA report means the rules ran', function (): void {
    $page = visitSurface($this, 'robot-council.dashboard', '', 'Live agents', 'light');

    // About 5:1 on the light page: past the AA bar of 4.5:1, short of the AAA one of 7:1
    $page->script(<<<'JS'
        () => {
            const note = document.createElement('p');
            note.textContent = 'A note that passes AA and fails AAA.';
            note.style.cssText = 'color: rgb(110, 110, 110); font-size: 16px';
            document.querySelector('main').appendChild(note);
        }
    JS);

    expect(array_column(axeViolations($page, AXE_AAA), 'id'))->toContain('color-contrast-enhanced')
        ->and(blockingViolations(axeViolations($page, AXE_GATE)))->toBeEmpty();
});

it('carries every status in a word, never in color alone', function (string $route, string $parameter, string $expect, string $theme): void {
    $bare = colorOnlyElements(visitSurface($this, $route, $parameter, $expect, $theme));

    expect($bare)->toBe([], implode("\n", $bare));
})->with(accessibilitySurfaces())->with(['light', 'dark']);

it('finds a status carried by color alone, so the check above is not blind', function (): void {
    $page = visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', 'light');

    // An empty badge, a colored dot, and a badge whose only word is for screen readers
    $page->script(<<<'JS'
        () => {
            const main = document.querySelector('main');
            const empty = document.createElement('span');
            empty.className = 'badge badge-error';
            main.appendChild(empty);
            const dot = document.createElement('span');
            dot.className = 'text-error';
            dot.textContent = '●';
            main.appendChild(dot);
            const unseen = document.createElement('span');
            unseen.className = 'badge badge-warning';
            unseen.innerHTML = '<span class="sr-only">stale</span>';
            main.appendChild(unseen);
        }
    JS);

    expect(colorOnlyElements($page))->toHaveCount(3);
});

it('keeps every status word under forced colors, where every color is replaced', function (string $route, string $parameter, string $expect): void {
    $page = visitSurface($this, $route, $parameter, $expect, 'light', ['forcedColors' => 'active']);

    // The emulation is live, or every assertion after it passes against ordinary colors
    expect($page->script("() => window.matchMedia('(forced-colors: active)').matches"))->toBeTrue();

    $lost = wordsLostToForcedColors($page);

    expect($lost)->toBe([], implode("\n", $lost));
})->with(accessibilitySurfaces());

it('finds a status word lost under forced colors, so the check above is not blind', function (): void {
    $page = visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', 'light', ['forcedColors' => 'active']);

    // A badge whose word is hidden only when forced colors are on
    $page->script(<<<'JS'
        () => {
            const style = document.createElement('style');
            style.textContent = '@media (forced-colors: active) { .fc-probe { visibility: hidden } }';
            document.head.appendChild(style);
            const badge = document.createElement('span');
            badge.className = 'badge badge-error fc-probe';
            badge.textContent = 'failed';
            document.querySelector('main').appendChild(badge);
        }
    JS);

    expect(wordsLostToForcedColors($page))->toHaveCount(1);
});

it('holds every control to its target size, 44px for the consequential ones and 24px for the rest', function (string $route, string $parameter, string $expect, int $width): void {
    $page = visitSurface($this, $route, $parameter, $expect, 'light');

    // At a phone's width, where the sidebar is closed, and at a desktop's, where it is open
    $page->resize($width, 900);

    $small = undersizedControls($page);

    expect($small)->toBe([], implode("\n", $small));
})->with(accessibilitySurfaces())->with([390, 1280]);

it('finds an undersized control, so the check above is not blind', function (): void {
    $page = visitSurface($this, 'robot-council.seats', '', 'robot-council-core-a', 'light');

    $page->resize(390, 900);

    // A 16px button, a consequential control at 32px, and a lone link in a table cell
    $page->script(<<<'JS'
        () => {
            const main = document.querySelector('main');
            const small = document.createElement('button');
            small.textContent = 'x';
            small.style.cssText = 'width:16px;height:16px;padding:0;font-size:10px';
            main.appendChild(small);
            const target = document.createElement('button');
            target.className = 'btn btn-sm btn-target';
            target.textContent = 'Revoke';
            target.style.cssText = 'height:32px;min-height:0';
            main.appendChild(target);
            const cell = document.createElement('div');
            cell.innerHTML = '<a href="#" class="link" style="font-size:10px;line-height:1">held</a>';
            main.appendChild(cell);
        }
    JS);

    expect(undersizedControls($page))->toHaveCount(3);
});
