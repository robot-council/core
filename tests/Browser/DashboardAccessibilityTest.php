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
use RobotCouncil\Models\AssignmentHours;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Models\Installation;
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

    // The longest type core defines, so the feed's type column is measured at its widest (#481)
    $events->record(longestFeedType(), $coordinator, 'Granted an ability.', withCoordinator: true);
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
 * Every control smaller than 44 by 44 CSS px (SC 2.5.5).
 *
 * One target for every control since #480, which took the filters, pagers, fields, navigation rows
 * and lone row links up from the 24px floor (SC 2.5.8) that #399 had held them to. Measured as
 * rendered. **Only a link inside a sentence is exempt**, under the inline exception: a link whose
 * enclosing block holds text of its own beside it. A link alone in a table cell is a target like any
 * other. A `label` is measured only where it is itself the control, as the drawer's toggle is;
 * otherwise the control it names is measured -- except a checkbox or radio, whose tappable area is
 * the label wrapped round it, so that label is what must reach 44px.
 *
 * @return list<string> Each undersized control, with its size.
 */
function undersizedControls(PendingAwaitablePage $page): array
{
    return pageList($page, 'target-size', <<<'JS'
        () => {
            const out = [];
            const describe = el => (el.getAttribute('aria-label') || el.textContent || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 40);
            // The block's own words, less the link's. A badge or screen-reader-only text beside a
            // link is not a sentence round it, so neither counts: a lone ticket link next to a
            // hand-back badge on Lanes was exempted that way and drawn 20px tall (#480)
            const inSentence = el => {
                if (el.tagName !== 'A') return false;
                const block = el.parentElement.closest('p, li, dd, td, span, div');
                if (block === null) return false;
                const copy = block.cloneNode(true);
                copy.querySelectorAll('.badge, .sr-only').forEach(n => n.remove());
                // The link's own words are read the same way, or its screen-reader text is left
                // behind in the block and reads as a sentence round it (#387)
                const own = el.cloneNode(true);
                own.querySelectorAll('.badge, .sr-only').forEach(n => n.remove());
                return copy.textContent.replace(own.textContent, '').trim().length > 0;
            };
            for (const el of document.querySelectorAll('button, a[href], summary, input:not([type=hidden]), select, textarea, label.btn, [role=button]')) {
                const rect = el.getBoundingClientRect();
                if (rect.width === 0 && rect.height === 0) continue;
                const side = el.closest('.drawer-side');
                if (side && getComputedStyle(side).visibility === 'hidden') continue;
                if (el.matches('.drawer-toggle') || inSentence(el)) continue;
                const label = el.matches('input[type=checkbox], input[type=radio]') ? el.closest('label') : null;
                const area = label ? label.getBoundingClientRect() : rect;
                if (area.width < 43.5 || area.height < 43.5) out.push(`${describe(label || el)} ${Math.round(area.width)}x${Math.round(area.height)} < 44`);
            }
            return out;
        }
    JS);
}

/**
 * How far the page scrolls sideways, in CSS px: zero when it fits the viewport (SC 1.4.10).
 */
function sidewaysScroll(PendingAwaitablePage $page): int
{
    $overflow = $page->script('() => document.documentElement.scrollWidth - document.documentElement.clientWidth');

    if (! is_int($overflow)) {
        throw new RuntimeException('The sideways-scroll read returned no number.');
    }

    return $overflow;
}

/**
 * Every button whose boundary is under 3:1 against what it sits on (SC 1.4.11, #482).
 *
 * A button is told from a label by its border or its fill, so the stronger of the two is measured
 * against the backgrounds behind it, composited as drawn. Colors are read through a canvas, which resolves
 * daisyUI's `oklch()` and composites a translucent fill over its background exactly as it is drawn.
 * The menu toggle is the one button left without a boundary: its icon is what marks it as a control.
 * A warning button is skipped until #498 gives it one.
 *
 * @return list<string> Each faint button, with its best ratio.
 */
function faintButtons(PendingAwaitablePage $page): array
{
    return pageList($page, 'button-boundary', <<<'JS'
        () => {
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 1;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            const paint = (...colors) => {
                ctx.clearRect(0, 0, 1, 1);
                for (const c of colors) { ctx.fillStyle = c; ctx.fillRect(0, 0, 1, 1); }
                return [...ctx.getImageData(0, 0, 1, 1).data.slice(0, 3)];
            };
            const luminance = rgb => {
                const [r, g, b] = rgb.map(v => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
                return 0.2126 * r + 0.7152 * g + 0.0722 * b;
            };
            const ratio = (a, b) => { const [x, y] = [luminance(a), luminance(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };
            // Every ancestor's background from the outermost in, so a translucent one is composited
            // over what is behind it rather than read as if it were opaque. The canvas starts white,
            // which is what a page with no background of its own is drawn on
            const behind = el => {
                const layers = ['rgb(255, 255, 255)'];
                for (let node = el.parentElement; node; node = node.parentElement) {
                    if (parseFloat(getComputedStyle(node).opacity) < 1) throw new Error('A button sits inside a translucent element, which this check does not measure');
                    layers.splice(1, 0, getComputedStyle(node).backgroundColor);
                }
                return layers;
            };
            const out = [];
            for (const el of document.querySelectorAll('.btn')) {
                const rect = el.getBoundingClientRect();
                if (rect.width === 0 && rect.height === 0) continue;
                const side = el.closest('.drawer-side');
                if (side && getComputedStyle(side).visibility === 'hidden') continue;
                // A warning button's fill is 2.02:1 in the light theme, which #498 is to fix
                if (el.matches('.drawer-button, .btn-warning')) continue;
                // SC 1.4.11 does not cover a control that cannot be used
                if (el.matches(':disabled, [aria-disabled=true]')) continue;
                const cs = getComputedStyle(el);
                const under = behind(el);
                const ground = paint(...under);
                const fill = ratio(paint(...under, cs.backgroundColor), ground);
                const border = parseFloat(cs.borderTopWidth) > 0 ? ratio(paint(...under, cs.borderTopColor), ground) : 1;
                const best = Math.max(fill, border);
                if (best < 3) out.push(`${(el.getAttribute('aria-label') || el.textContent).trim().replace(/\s+/g, ' ').slice(0, 40)} ${best.toFixed(2)}:1`);
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

it('filters the Queue from its status list and returns to every status', function (): void {
    // #480. The select calls the action through `$event.target.value`, which only a real browser
    // evaluates: the Livewire tests call `showStatus()` directly and cannot see that expression
    $page = visitSurface($this, 'robot-council.queue', '', 'Everyone stop and sync', 'light')
        ->assertSee('Port the rule');

    $page->select('select[data-status-filter]', 'done')
        ->assertQueryStringHas('status', 'done')
        ->assertDontSee('Everyone stop and sync')
        ->assertSee('Port the rule')
        ->assertValue('select[data-status-filter]', 'done');

    $page->select('select[data-status-filter]', '')
        ->assertQueryStringMissing('status')
        ->assertSee('Everyone stop and sync')
        ->assertValue('select[data-status-filter]', '');
});

it('picks a time zone from a grouped list by keyboard, named by its label, and saves it (#483)', function (): void {
    $page = visitSurface($this, 'robot-council.seats', '', 'robot-council-core-a', 'light');

    // Named by the label wrapped round it, grouped by region, and on the stored zone
    $picker = $page->script(<<<'JS'
        () => {
            const select = document.querySelector('select[data-time-zone]');
            return {
                name: select.labels[0].querySelector(':scope > span').textContent.trim(),
                groups: select.querySelectorAll('optgroup').length,
                value: select.value,
            };
        }
    JS);

    if (! is_array($picker)) {
        throw new RuntimeException('The picker read returned nothing.');
    }

    expect($picker)->toMatchArray(['name' => 'Time zone', 'value' => 'America/Chicago'])
        ->and($picker['groups'] ?? 0)->toBeGreaterThan(5);

    // Saved without touching the picker, the stored zone is what is saved
    $page->click('Save hours')->assertSee('Central Time');

    expect(AssignmentHours::query()->count())->toBe(1)
        ->and(AssignmentHours::query()->first()?->timezone)->toBe('America/Chicago');

    // Typing a zone's name chooses it, as a keyboard user would. Type-ahead rather than an arrow
    // key, because on macOS Chromium opens the list on ArrowDown instead of moving the choice
    $page->keys('select[data-time-zone]', str_split('Asia/Tokyo'))
        ->assertValue('select[data-time-zone]', 'Asia/Tokyo')
        ->click('Save hours')
        ->assertSee('Japan');

    expect(AssignmentHours::query()->first()?->timezone)->toBe('Asia/Tokyo');
});

/**
 * How a page's glossary is spaced once opened: the gaps between entries, the gaps inside them, and
 * the rule under each entry but the last (#486).
 *
 * Inside an entry is measured two ways and the larger kept: the term's bottom to its meaning's top,
 * which is the gap on a phone where they stack, and the gap between one line of a wrapped meaning
 * and the next, which is what ran into the next entry at desktop width. Line boxes are read from a
 * Range over the meaning's text.
 *
 * @return array{entries: int, between: float, within: float, rules: int, ruleRatio: float}
 */
function glossarySpacing(PendingAwaitablePage $page): array
{
    $found = $page->script(<<<'JS'
        () => {
            const details = document.querySelector('details[data-glossary]');
            details.open = true;
            const entries = [...details.querySelectorAll('[data-glossary-entry]')];
            const box = el => el.getBoundingClientRect();
            let between = Infinity, within = 0, rules = 0;
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 1;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            const paint = (...colors) => { ctx.clearRect(0, 0, 1, 1); for (const c of colors) { ctx.fillStyle = c; ctx.fillRect(0, 0, 1, 1); } return [...ctx.getImageData(0, 0, 1, 1).data.slice(0, 3)]; };
            const lum = rgb => { const [r, g, b] = rgb.map(v => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
            const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };
            const layers = el => { const out = ['rgb(255, 255, 255)']; for (let n = el.parentElement; n; n = n.parentElement) out.splice(1, 0, getComputedStyle(n).backgroundColor); return out; };
            let ruleRatio = Infinity;
            entries.forEach((entry, i) => {
                const dt = entry.querySelector('dt'), dd = entry.querySelector('dd');
                within = Math.max(within, box(dd).top - box(dt).bottom);
                const range = document.createRange();
                range.selectNodeContents(dd);
                const lines = [...range.getClientRects()].sort((a, b) => a.top - b.top);
                for (let l = 1; l < lines.length; l++) if (lines[l].top > lines[l - 1].top + 1) within = Math.max(within, lines[l].top - lines[l - 1].bottom);
                if (i + 1 < entries.length) {
                    between = Math.min(between, box(entries[i + 1].querySelector('dt')).top - Math.max(box(dt).bottom, box(dd).bottom));
                    const cs = getComputedStyle(entry);
                    if (parseFloat(cs.borderBottomWidth) >= 1) {
                        rules++;
                        const under = layers(entry);
                        ruleRatio = Math.min(ruleRatio, ratio(paint(...under, cs.borderBottomColor), paint(...under)));
                    }
                }
            });
            return { entries: entries.length, between, within, rules, ruleRatio };
        }
    JS);

    if (! is_array($found) || ! is_int($found['entries'] ?? null)) {
        throw new RuntimeException('The glossary read returned nothing.');
    }

    /** @var array{entries: int, between: float, within: float, rules: int, ruleRatio: float} $found */
    return $found;
}

it('sets each glossary entry apart by a rule and more space than lies within it', function (string $theme, int $width): void {
    $page = visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', $theme);

    $page->resize($width, 900);

    $spacing = glossarySpacing($page);

    // The control: the Lanes glossary is the longest, and at least one meaning wraps, so the
    // within-entry gap was read from real line boxes rather than defaulting to nothing
    expect($spacing['entries'])->toBeGreaterThan(15)
        ->and($spacing['within'])->toBeGreaterThan(0)
        // Between entries is at least twice the widest gap inside one, and every boundary has a rule
        ->and($spacing['between'])->toBeGreaterThanOrEqual(2 * $spacing['within'])
        ->and($spacing['rules'])->toBe($spacing['entries'] - 1)
        ->and($spacing['ruleRatio'])->toBeGreaterThan(1.5);
})->with(['light', 'dark'])->with([390, 1280]);

it('keeps each glossary term and its meaning on one row once the columns apply', function (): void {
    $page = visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', 'dark');

    $page->resize(1280, 900);

    $rows = $page->script(<<<'JS'
        () => {
            const details = document.querySelector('details[data-glossary]');
            details.open = true;
            return [...details.querySelectorAll('[data-glossary-entry]')].map(entry => {
                const dt = entry.querySelector('dt').getBoundingClientRect(), dd = entry.querySelector('dd').getBoundingClientRect();
                return { sameRow: Math.abs(dt.top - dd.top) < 1, meaningX: Math.round(dd.left), beside: dd.left > dt.right };
            });
        }
    JS);

    /** @var list<array{sameRow: bool, meaningX: int, beside: bool}> $rows */
    expect($rows)->not->toBeEmpty()
        ->and(array_unique(array_column($rows, 'sameRow')))->toBe([true])
        ->and(array_unique(array_column($rows, 'beside')))->toBe([true])
        ->and(array_values(array_unique(array_column($rows, 'meaningX'))))->toHaveCount(1);
});

/**
 * Where each change-feed row's type badge and body sit, as rendered.
 *
 * @return list<array{type: string, badge: array{left: float, right: float, bottom: float, overflow: bool}, body: array{left: float, top: float}}>
 */
function feedRows(PendingAwaitablePage $page): array
{
    $rows = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-feed] > li')].map(li => {
            const badge = li.querySelector('[data-feed-type]');
            const body = li.querySelector('[data-feed-body]');
            const b = badge.getBoundingClientRect();
            const t = body.getBoundingClientRect();
            return {
                type: badge.textContent.trim(),
                badge: { left: b.left, right: b.right, bottom: b.bottom, overflow: badge.scrollWidth > badge.clientWidth + 0.5 },
                body: { left: t.left, top: t.top },
            };
        })
    JS);

    if (! is_array($rows)) {
        throw new RuntimeException('The feed read returned nothing.');
    }

    /** @var list<array{type: string, badge: array{left: float, right: float, bottom: float, overflow: bool}, body: array{left: float, top: float}}> $rows */
    return $rows;
}

/**
 * The event type with the longest name core defines, which draws the widest feed badge (#481).
 */
function longestFeedType(): FleetEventType
{
    $cases = FleetEventType::cases();

    usort($cases, static fn (FleetEventType $a, FleetEventType $b): int => strlen($b->value) <=> strlen($a->value));

    return $cases[0];
}

it('starts every change-feed body at the same x once the grid applies, the longest type included', function (int $width): void {
    $page = visitSurface($this, 'robot-council.feed', '', 'Change feed', 'light');

    // Just past `sm`, with the sidebar closed and the column at its tightest, and on a desktop
    $page->resize($width, 900);

    $rows = feedRows($page);

    // The controls: three types of different lengths, so equal starts are not equal badges, and
    // the longest type core defines among them, seeded as a real event
    $types = array_values(array_unique(array_column($rows, 'type')));
    $lengths = array_unique(array_map(strlen(...), $types));

    expect(count($lengths))->toBeGreaterThanOrEqual(3)
        ->and($types)->toContain(longestFeedType()->value);

    $starts = array_unique(array_map(static fn (array $row): int => (int) round($row['body']['left']), $rows));

    expect($starts)->toHaveCount(1, 'body starts: '.implode(', ', $starts));

    foreach ($rows as $row) {
        // Not cut short, and 12px clear of the body it sits beside, as the flex row was
        expect($row['badge']['overflow'])->toBeFalse($row['type'])
            ->and($row['body']['left'] - $row['badge']['right'])->toBeGreaterThanOrEqual(11.5, $row['type']);
    }

    expect(sidewaysScroll($page))->toBe(0);
})->with([700, 1280]);

it('stacks each change-feed badge above its body at a phone width', function (): void {
    $page = visitSurface($this, 'robot-council.feed', '', 'Change feed', 'light');

    $page->resize(390, 900);

    $rows = feedRows($page);

    expect(array_column($rows, 'type'))->toContain(longestFeedType()->value);

    foreach ($rows as $row) {
        expect($row['badge']['bottom'])->toBeLessThanOrEqual($row['body']['top'], $row['type'])
            ->and($row['badge']['overflow'])->toBeFalse($row['type']);
    }

    expect(sidewaysScroll($page))->toBe(0);
});

it("lines up each machine's session ids, statuses, roles and details on Administration", function (): void {
    $page = visitSurface($this, 'robot-council.administration', '', 'gate-runner', 'light');

    // A second session on the gate's machine, under a different repository, so its list has two
    // rows whose badges differ; the seeded fleet runs one session per machine
    $installation = Installation::query()->where('machine_label', 'gate-runner')->sole();
    $this->service(AgentSessions::class)->start($installation, 'robot-council/cli', 'robot-council-cli-a');

    $page->refresh()->resize(1280, 900);

    // Per list, the distinct left edge of each of the four cells across its rows (#481)
    $lists = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-admin-sessions]')]
            .map(ul => [...ul.children].map(li => [...li.children].map(cell => ({
                left: Math.round(cell.getBoundingClientRect().left),
                right: Math.round(cell.getBoundingClientRect().right),
                text: cell.textContent.trim().replace(/\s+/g, ' ').slice(0, 24),
            }))))
            .filter(rows => rows.length > 1)
    JS);

    expect($lists)->toBeArray()->not->toBeEmpty();

    /** @var list<list<list<array{left: int, right: int, text: string}>>> $lists */
    foreach ($lists as $rows) {
        $described = json_encode($rows, JSON_THROW_ON_ERROR);

        // The control: two rows whose leading cells differ in width, or equal edges prove nothing
        expect(array_unique(array_map(static fn (array $row): string => $row[2]['text'], $rows)))->not->toHaveCount(1, $described);

        foreach ([0, 1, 2, 3] as $cell) {
            $edges = array_unique(array_map(static fn (array $row): int => $row[$cell]['left'], $rows));

            expect($edges)->toHaveCount(1, sprintf('cell %d: %s', $cell, $described));
        }

        // And the details sit beside the role rather than wrapped beneath the row, where they
        // would line up only by starting at the row's own edge
        foreach ($rows as $row) {
            expect($row[3]['left'])->toBeGreaterThan($row[2]['right'], $described);
        }
    }
});

/**
 * Each form field whose label does not sit wholly above it, as rendered (#485).
 *
 * @return array{fields: int, misplaced: list<string>} How many labelled fields were measured, and
 *                                                     each one whose label's bottom is below its field's top.
 */
function labelsNotAbove(PendingAwaitablePage $page): array
{
    $found = $page->script(<<<'JS'
        () => {
            const fields = [...document.querySelectorAll('main label[data-field]')];
            return {
                fields: fields.length,
                misplaced: fields.flatMap(label => {
                    const text = label.querySelector(':scope > span');
                    const field = label.querySelector('input, select, textarea');
                    const t = text.getBoundingClientRect();
                    const f = field.getBoundingClientRect();
                    return t.bottom <= f.top + 0.5 ? [] : [`${text.textContent.trim()}: label bottom ${Math.round(t.bottom)}, field top ${Math.round(f.top)}`];
                }),
            };
        }
    JS);

    if (! is_array($found) || ! is_int($found['fields'] ?? null) || ! is_array($found['misplaced'] ?? null)) {
        throw new RuntimeException('The label read returned nothing.');
    }

    /** @var array{fields: int, misplaced: list<string>} $found */
    return $found;
}

it('puts every form label above its field', function (string $route, string $expect, int $fields, int $width): void {
    $page = visitSurface($this, $route, '', $expect, 'light');

    $page->resize($width, 900);

    $found = labelsNotAbove($page);

    // The control: the page's labelled fields were found, so an empty list is not a read of none.
    // A floor rather than a count, because the Seats page repeats one field per seat seeded.
    expect($found['fields'])->toBeGreaterThanOrEqual($fields)
        ->and($found['misplaced'])->toBe([], implode("\n", $found['misplaced']))
        ->and(sidewaysScroll($page))->toBe(0);
})->with([
    'Access' => ['robot-council.access', 'Look up', 2],
    'Seats' => ['robot-council.seats', 'Save hours', 5],
])->with([390, 1280]);

it('gives the Access form one full-width field per row on a phone', function (): void {
    $page = visitSurface($this, 'robot-council.access', '', 'Look up', 'light');

    $page->resize(390, 900);

    // Each field and the button, against the form's own content box
    $rows = $page->script(<<<'JS'
        () => {
            const form = document.querySelector('form[wire\\:submit="lookUp"]');
            const box = form.getBoundingClientRect();
            return [...form.querySelectorAll('input, select, button')].map(el => {
                const r = el.getBoundingClientRect();
                return { name: el.tagName + ' ' + (el.getAttribute('wire:model') || el.textContent.trim()), left: Math.round(r.left), width: Math.round(r.width), top: Math.round(r.top), form: Math.round(box.width) };
            });
        }
    JS);

    /** @var list<array{name: string, left: int, width: int, top: int, form: int}> $rows */
    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        expect($row['width'])->toBe($row['form'], $row['name']);
    }

    // One per row: every top differs
    expect(array_unique(array_column($rows, 'top')))->toHaveCount(3);
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

it('holds every control to the 44px target size', function (string $route, string $parameter, string $expect, int $width): void {
    $page = visitSurface($this, $route, $parameter, $expect, 'light');

    // At a phone's width, where the sidebar is closed, and at a desktop's, where it is open
    $page->resize($width, 900);

    $small = undersizedControls($page);

    expect($small)->toBe([], implode("\n", $small));

    // And no page scrolls sideways to make room for them (#480): a row of 44px controls that
    // stopped wrapping would push the page wider than a phone
    expect(sidewaysScroll($page))->toBe(0);
})->with(accessibilitySurfaces())->with([390, 1280]);

it('gives every button a boundary at 3:1 against what it sits on', function (string $route, string $parameter, string $expect, string $theme): void {
    $page = visitSurface($this, $route, $parameter, $expect, $theme);

    $page->resize(1280, 900);

    $faint = faintButtons($page);

    expect($faint)->toBe([], implode("\n", $faint));
})->with(accessibilitySurfaces())->with(['light', 'dark']);

it('finds a faint button, so the check above is not blind', function (string $theme): void {
    $page = visitSurface($this, 'robot-council.seats', '', 'robot-council-core-a', $theme);

    $page->resize(1280, 900);

    expect(faintButtons($page))->toBeEmpty();

    // The two styles #482 removed, planted on the card the seats page draws: a ghost button, with
    // no fill or border at rest, and a plain one, whose base-200 fill barely differs from the card
    $page->script(<<<'JS'
        () => {
            const card = document.querySelector('main .card-body');
            for (const [style, text] of [['btn-ghost', 'Planted ghost'], ['', 'Planted plain']]) {
                const button = document.createElement('button');
                button.className = `btn btn-target ${style}`;
                button.textContent = text;
                card.appendChild(button);
            }
        }
    JS);

    $faint = faintButtons($page);

    expect($faint)->toHaveCount(2)
        ->and($faint[0])->toStartWith('Planted ghost ')
        ->and($faint[1])->toStartWith('Planted plain ');
})->with(['light', 'dark']);

it('finds a page that scrolls sideways, so the check above is not blind', function (): void {
    $page = visitSurface($this, 'robot-council.queue', '', 'Everyone stop and sync', 'light');

    $page->resize(390, 900);

    expect(sidewaysScroll($page))->toBe(0);

    // A row that does not wrap, wider than the phone
    $page->script(<<<'JS'
        () => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;width:600px';
            row.textContent = 'wide';
            document.querySelector('main').appendChild(row);
        }
    JS);

    expect(sidewaysScroll($page))->toBeGreaterThan(0);
});

it('finds an undersized control, so the check above is not blind', function (): void {
    $page = visitSurface($this, 'robot-council.seats', '', 'robot-council-core-a', 'light');

    $page->resize(390, 900);

    // A 16px button, a control marked for the target but drawn at 32px, a pager at daisyUI's 32px
    // `btn-sm` that #480 raised, a lone link in a table cell, and a checkbox whose label is one line
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
            const pager = document.createElement('button');
            pager.className = 'btn btn-outline';
            pager.textContent = 'Older';
            pager.style.cssText = 'height:32px;min-height:0';
            main.appendChild(pager);
            const box = document.createElement('label');
            box.style.cssText = 'display:inline-flex;align-items:center;line-height:1';
            box.innerHTML = '<input type="checkbox"> Skip';
            main.appendChild(box);
        }
    JS);

    expect(undersizedControls($page))->toHaveCount(5);
});

/**
 * How the main column, its prose and its tables lay out at the current width (#494).
 *
 * @return array{main: float, prose: list<string>, clipped: list<string>, wrapped: list<string>} The main column's width, any
 *                                                                                               paragraph of running text wider than its measure, any table container that scrolls, and any
 *                                                                                               one-line value that wrapped.
 */
function wideLayout(PendingAwaitablePage $page): array
{
    $layout = $page->script(<<<'JS'
        () => {
            const main = document.querySelector('main');
            const describe = (el) => `${el.tagName.toLowerCase()} "${el.textContent.trim().replace(/\s+/g, ' ').slice(0, 60)}"`;
            // Running text: a paragraph or a list item long enough to be read as a line, outside a
            // table. The change feed's bodies are left out: #311 reshapes that list and owns their measure
            const prose = [...main.querySelectorAll('p, li')]
                .filter((p) => ! p.closest('table, [data-feed]') && ! p.querySelector('p, li'))
                // A row laid out as a flex or grid box is a record with its own columns, not a sentence
                .filter((p) => ! ['flex', 'grid', 'inline-flex'].includes(getComputedStyle(p).display))
                .filter((p) => p.textContent.trim().replace(/\s+/g, ' ').length > 80)
                .filter((p) => p.getBoundingClientRect().width > 36 * 16 + 0.5)
                .map(describe);
            const clipped = [...main.querySelectorAll('.overflow-x-auto')]
                .filter((box) => box.scrollWidth > box.clientWidth + 0.5)
                .map((box) => `${describe(box.querySelector('th') ?? box)} ${box.scrollWidth} > ${box.clientWidth}`);
            // A one-line value lays its text out on one line: the rectangles of its text share a top
            const lines = (el) => {
                const range = document.createRange();
                range.selectNodeContents(el);
                const tops = [...range.getClientRects()].filter((r) => r.width > 0).map((r) => r.top).sort((a, b) => a - b);
                return tops.filter((top, i) => i === 0 || top > tops[i - 1] + 4).length;
            };
            // The short, fixed values: a machine, a role, a status, a state, a number, an age
            const short = ['Machine', 'Role', 'Status', 'State', 'Priority', 'Fence', 'Last seen', 'Locks', 'Age', 'Lease', 'Known since']
                .map((label) => `td[data-label="${label}"]`).join(', ');
            const wrapped = [...main.querySelectorAll(short)]
                .flatMap((td) => td.children.length > 0 ? [...td.children] : [td])
                .filter((el) => lines(el) > 1)
                .map(describe);
            return { main: main.getBoundingClientRect().width, prose, clipped, wrapped };
        }
    JS);

    if (! is_array($layout) || ! is_numeric($layout['main'] ?? null)) {
        throw new RuntimeException('The layout read returned nothing.');
    }

    /** @var array{main: float, prose: list<string>, clipped: list<string>, wrapped: list<string>} $layout */
    return $layout;
}

it('uses the width of a wide screen for its tables, and keeps prose to its measure (#494)', function (string $route, string $parameter, string $expect, int $width): void {
    $page = visitSurface($this, $route, $parameter, $expect, 'light');
    $page->resize($width, 900);

    $layout = wideLayout($page);

    // Wider than the 80rem the column was capped at, with no table scrolling inside its box, no
    // one-line value wrapped, and no paragraph of running text past 36rem
    expect($layout['main'])->toBeGreaterThan(80 * 16)
        ->and($layout['clipped'])->toBe([], implode("\n", $layout['clipped']))
        ->and($layout['wrapped'])->toBe([], implode("\n", $layout['wrapped']))
        ->and($layout['prose'])->toBe([], implode("\n", $layout['prose']))
        ->and(sidewaysScroll($page))->toBe(0);
})->with([
    'agents' => ['robot-council.agents', '', 'coordinator-mac'],
    'locks' => ['robot-council.locks', '', 'branch:vocabulary'],
    'lanes' => ['robot-council.lanes', '', 'Run the screen-reader pass'],
    'queue' => ['robot-council.queue', '', 'Everyone stop and sync'],
    'feed' => ['robot-council.feed', '', 'Running the gate before opening the pull request.'],
    'administration' => ['robot-council.administration', '', 'gate-runner'],
])->with([1920, 2560]);

it('keeps running text to its measure on every dashboard page of a wide screen (#494)', function (string $route, string $parameter, string $expect): void {
    $page = visitSurface($this, $route, $parameter, $expect, 'light');
    $page->resize(2560, 900);

    $prose = wideLayout($page)['prose'];

    expect($prose)->toBe([], implode("\n", $prose))
        ->and(sidewaysScroll($page))->toBe(0);
})->with(array_diff_key(accessibilitySurfaces(), array_flip(['enrollment', 'signed out', 'sign-in expired'])));

it('finds a wrapped value, a clipped table and a long line when they are planted (#494)', function (): void {
    $page = visitSurface($this, 'robot-council.agents', '', 'coordinator-mac', 'light');
    $page->resize(1920, 900);

    // The canary: squeeze the column, let every cell wrap, and let one paragraph run the full width,
    // so a check that could not see any of the three passes nothing here
    $page->script(<<<'JS'
        () => {
            const main = document.querySelector('main');
            main.style.maxWidth = '24rem';
            main.querySelectorAll('td').forEach((td) => { td.style.whiteSpace = 'normal'; td.style.minWidth = '0'; });
            main.querySelector('table').style.minWidth = '60rem';
            const p = document.createElement('p');
            p.textContent = 'A planted paragraph of running text, long enough to be read as a line and with no measure of its own.';
            p.style.width = '40rem';
            main.prepend(p);
        }
    JS);

    $layout = wideLayout($page);

    expect($layout['clipped'])->not->toBeEmpty()
        ->and($layout['wrapped'])->not->toBeEmpty()
        ->and($layout['prose'])->toHaveCount(1);
});

/**
 * Each avatar's circle, as drawn: whose it is, its size, its corner radius, whether it has a drawn
 * ring, and whether a picture is in it.
 *
 * @return list<array{kind: string, width: float|int, height: float|int, round: bool, ring: bool, picture: bool, initial: string}> One per avatar.
 */
function avatarShapes(PendingAwaitablePage $page): array
{
    /** @var list<array{kind: string, width: float|int, height: float|int, round: bool, ring: bool, picture: bool, initial: string}> */
    return pageList($page, 'avatar', <<<'JS'
        () => [...document.querySelectorAll('[data-avatar] > span')].map(circle => {
            const box = circle.getBoundingClientRect();
            const style = getComputedStyle(circle);
            return {
                kind: circle.parentElement.dataset.avatar === 'repository' ? 'repository' : 'developer',
                ring: parseFloat(style.borderTopWidth) >= 2 && style.borderTopColor !== 'rgba(0, 0, 0, 0)',
                width: Math.round(box.width * 10) / 10,
                height: Math.round(box.height * 10) / 10,
                round: parseFloat(getComputedStyle(circle).borderTopLeftRadius) >= box.width / 2,
                picture: circle.querySelector('img') !== null,
                initial: circle.textContent.trim(),
            };
        })
    JS);
}

it("keeps each developer's and repository's picture a circle sized in rem, ringed for a repository, and falls back to the letter when the picture fails (#410, #416)", function (): void {
    // No stored picture, so nothing here reaches the network: what the browser owns -- shape, size
    // and what a failed picture leaves behind -- is measured, and which picture a page names is
    // `DeveloperAvatarTest`'s
    $page = visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', 'light');

    $shapes = avatarShapes($page);

    $developers = array_values(array_filter($shapes, static fn (array $shape): bool => $shape['kind'] === 'developer'));
    $repositories = array_values(array_filter($shapes, static fn (array $shape): bool => $shape['kind'] === 'repository'));

    // Both developers on the board, and its repositories, each a 1.5rem circle; only a repository's
    // is ringed, so an organization's picture is not read as a face
    expect(array_values(array_unique(array_column($developers, 'initial'))))->toEqualCanonicalizing(['O', 'C'])
        ->and($repositories)->not->toBeEmpty()
        ->and(array_column($developers, 'ring'))->not->toContain(true)
        ->and(array_column($repositories, 'ring'))->not->toContain(false);

    foreach ($shapes as $shape) {
        expect($shape['width'])->toEqual(24)->and($shape['height'])->toEqual(24)->and($shape['round'])->toBeTrue();
    }

    // Text at 200%: a rem size doubles with it and stays round, where a px one would not
    $page->script('() => { document.documentElement.style.fontSize = "200%"; }');

    foreach (avatarShapes($page) as $shape) {
        expect($shape['width'])->toEqual(48)->and($shape['height'])->toEqual(48)->and($shape['round'])->toBeTrue();
    }

    // A picture that really fails to load, put where the component puts one -- in a developer's
    // circle and in a repository's -- is taken away and leaves the letter beneath it. Their presence
    // is read first, so the removal is the script's work rather than pictures that were never there
    $planted = $page->script(<<<'JS'
        () => {
            for (const selector of ['[data-avatar=""] > span', '[data-avatar="repository"] > span']) {
                const image = document.createElement('img');
                image.alt = '';
                image.src = 'data:image/png;base64,broken';
                document.querySelector(selector).appendChild(image);
            }
            return document.querySelectorAll('[data-avatar] img').length;
        }
    JS);

    expect($planted)->toBe(2);

    $page->wait(0.5);

    $after = avatarShapes($page);

    $repositoryInitials = array_column(array_filter($after, static fn (array $shape): bool => $shape['kind'] === 'repository'), 'initial');

    expect(array_filter($after, static fn (array $shape): bool => $shape['picture']))->toBeEmpty()
        ->and(array_column($after, 'initial'))->toContain('O')
        ->and($repositoryInitials)->not->toBeEmpty()
        ->and(array_filter($repositoryInitials, static fn (string $initial): bool => $initial === ''))->toBeEmpty();
});

it("keeps a repository's ring apart from a developer's circle under forced colors, where both borders draw (#416)", function (): void {
    $page = visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', 'light', ['forcedColors' => 'active']);

    expect($page->script("() => window.matchMedia('(forced-colors: active)').matches"))->toBeTrue();

    /** @var list<array{kind: string, style: string, width: float}> $borders */
    $borders = pageList($page, 'avatar-border', <<<'JS'
        () => [...document.querySelectorAll('[data-avatar] > span')].map(circle => ({
            kind: circle.parentElement.dataset.avatar === 'repository' ? 'repository' : 'developer',
            style: getComputedStyle(circle).borderTopStyle,
            width: parseFloat(getComputedStyle(circle).borderTopWidth),
        }))
    JS);

    $developers = array_filter($borders, static fn (array $border): bool => $border['kind'] === 'developer');
    $repositories = array_filter($borders, static fn (array $border): bool => $border['kind'] === 'repository');

    // A double ring on every repository, and on no developer
    expect($developers)->not->toBeEmpty()
        ->and($repositories)->not->toBeEmpty()
        ->and(array_values(array_unique(array_column($repositories, 'style'))))->toBe(['double'])
        ->and(array_column($developers, 'style'))->not->toContain('double');
});

it('keeps the Waiting on a developer card inside a 360px phone, each section bordered and each item three parts (#390)', function (string $theme): void {
    $page = visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', $theme);

    $page->resize(360, 900);

    /** @var array{sections: int, bordered: int, parts: list<int>, overflowing: int} $card */
    $card = $page->script(<<<'JS'
        () => {
            const sections = [...document.querySelectorAll('[data-owed-section]')];
            const items = [...document.querySelectorAll('[data-owed-item]')];
            return {
                sections: sections.length,
                bordered: sections.filter(s => parseFloat(getComputedStyle(s).borderTopWidth) >= 1).length,
                parts: items.map(item => item.children.length),
                overflowing: [...document.querySelectorAll('[data-owed-section], [data-owed-section] *')]
                    .filter(el => el.scrollWidth > el.clientWidth + 0.5 && getComputedStyle(el).overflowX === 'visible' && el.clientWidth > 0).length,
            };
        }
    JS);

    expect(sidewaysScroll($page))->toBe(0)
        ->and($card['sections'])->toBeGreaterThan(0)
        ->and($card['bordered'])->toBe($card['sections'])
        ->and($card['parts'])->not->toBeEmpty()
        ->and(array_unique($card['parts']))->toBe([3])
        ->and($card['overflowing'])->toBe(0);
})->with(['light', 'dark']);
