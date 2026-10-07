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
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Pest\Browser\Api\PendingAwaitablePage;
use RobotCouncil\Access\AccessList;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\AssignmentHours;
use RobotCouncil\Models\DeviceCode;
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

    // A title that is only a link, and one with a link inside it (#554): the first is a lone link,
    // held to the 44px target, on the Queue and, held, on the Lanes page; the second is a link in a
    // sentence, which keeps its line's height
    $tasks->create($working, ['title' => '[Fix the queue crash](https://github.com/robot-council/core/pull/9)', 'priority' => 2], false);
    $tasks->create($working, ['title' => 'Follow up on [#9](https://github.com/robot-council/core/pull/9) after the crash', 'priority' => 2], false);

    $loneHeld = $tasks->create($working, ['title' => '[Fix the lane crash](https://github.com/robot-council/core/pull/10)', 'priority' => 2], false);
    $tasks->transition($loneHeld->id, TaskTransition::Claim, $working, asCoordinator: false);
    $tasks->transition($loneHeld->id, TaskTransition::Start, $working, asCoordinator: false, branch: 'lane-crash');
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

    // A body long enough to be read as running text, so the feed's measure is checked (#311)
    $events->record(FleetEventType::Narration, $working, str_repeat('The gate ran the full suite on the merged state, and every job it depends on reported success before the pull request was opened. ', 4));
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
 *
 * @return list<string> Each faint button, with its best ratio.
 */
function faintButtons(PendingAwaitablePage $page): array
{
    return buttonBoundaries($page, null);
}

/**
 * Every button matching a selector, with its best boundary ratio, whether or not it is faint.
 *
 * What `faintButtons()` measures, reported for every match rather than only the faint ones, so a
 * test can show the buttons it means were on the page and record what they measured (#498).
 *
 * @return list<string> Each matching button, with its best ratio.
 */
function buttonRatios(PendingAwaitablePage $page, string $selector): array
{
    return buttonBoundaries($page, $selector);
}

/**
 * The boundary measurement behind `faintButtons()` and `buttonRatios()`.
 *
 * @param  string|null  $selector  Report every button matching this, or, when null, only the faint ones.
 * @return list<string> Each reported button, with its best ratio.
 */
function buttonBoundaries(PendingAwaitablePage $page, ?string $selector): array
{
    $script = <<<'JS'
        () => {
            const only = __SELECTOR__;
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
                if (el.matches('.drawer-button')) continue;
                // SC 1.4.11 does not cover a control that cannot be used
                if (el.matches(':disabled, [aria-disabled=true]')) continue;
                const cs = getComputedStyle(el);
                const under = behind(el);
                const ground = paint(...under);
                const fill = ratio(paint(...under, cs.backgroundColor), ground);
                const border = parseFloat(cs.borderTopWidth) > 0 ? ratio(paint(...under, cs.borderTopColor), ground) : 1;
                const best = Math.max(fill, border);
                if (only === null ? best < 3 : el.matches(only)) out.push(`${(el.getAttribute('aria-label') || el.textContent).trim().replace(/\s+/g, ' ').slice(0, 40)} ${best.toFixed(2)}:1`);
            }
            return out;
        }
    JS;

    return pageList($page, 'button-boundary', str_replace('__SELECTOR__', json_encode($selector, JSON_THROW_ON_ERROR), $script));
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
 * @return list<array{type: string, badge: array{left: float, right: float, bottom: float, overflow: bool}, body: array{left: float, top: float}, beside: float}>
 */
function feedRows(PendingAwaitablePage $page): array
{
    $rows = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-feed] tbody > tr')].map(li => {
            const badge = li.querySelector('[data-feed-type]');
            const body = li.querySelector('[data-feed-body]');
            const b = badge.getBoundingClientRect();
            const t = body.getBoundingClientRect();
            return {
                type: badge.textContent.trim(),
                badge: { left: b.left, right: b.right, bottom: b.bottom, overflow: badge.scrollWidth > badge.clientWidth + 0.5 },
                body: { left: t.left, top: t.top },
                // Where the text in the cell after the type's starts: who acted, since #311
                beside: li.querySelector('[data-feed-who]').getBoundingClientRect().left,
            };
        })
    JS);

    if (! is_array($rows)) {
        throw new RuntimeException('The feed read returned nothing.');
    }

    /** @var list<array{type: string, badge: array{left: float, right: float, bottom: float, overflow: bool}, body: array{left: float, top: float}, beside: float}> $rows */
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

it('starts every change-feed body at the same x once the table has columns, the longest type included', function (int $width): void {
    $page = visitSurface($this, 'robot-council.feed', '', 'Change feed', 'light');

    // Just past `md`, where the table stops stacking (#311), with the sidebar closed and the column
    // at its tightest, and on a desktop
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
        // Not cut short, and 12px clear of the text it sits beside, as the flex row was: since #311
        // that is who acted rather than the body, which the column check below keeps aligned
        expect($row['badge']['overflow'])->toBeFalse($row['type'])
            ->and($row['beside'] - $row['badge']['right'])->toBeGreaterThanOrEqual(11.5, $row['type']);
    }

    // Every fixed value lines up too, not only the body: one left edge per column across the rows
    $columns = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-feed] tbody > tr')]
            .map(tr => [...tr.children].map(td => Math.round(td.getBoundingClientRect().left)))
    JS);

    if (! is_array($columns) || $columns === []) {
        throw new RuntimeException('The feed read returned no rows.');
    }

    /** @var list<list<int>> $columns */
    $columns = array_values($columns);

    foreach ([0, 1, 2, 3] as $cell) {
        expect(array_unique(array_column($columns, $cell)))->toHaveCount(1, 'column '.$cell);
    }

    // And each fixed value on one line, where a squeezed column would break a login word by word
    $wrapped = wideLayout($page)['wrapped'];

    expect($wrapped)->toBe([], implode("\n", $wrapped))
        ->and(sidewaysScroll($page))->toBe(0);
})->with([800, 1280]);

it('keeps a change-feed body to its measure on a wide screen, and finds one planted without it (#311)', function (): void {
    $page = visitSurface($this, 'robot-council.feed', '', 'Change feed', 'light');
    $page->resize(2560, 900);

    expect(wideLayout($page)['prose'])->toBeEmpty()
        // At the body size, not the smaller one a table cell otherwise takes
        ->and($page->script("() => [...new Set([...document.querySelectorAll('[data-feed-body] p')].map((p) => getComputedStyle(p).fontSize))]"))->toBe(['16px']);

    // The canary: the same body with its cap taken off runs past 36rem, so a check that could not
    // see a feed body would pass nothing here
    $page->script("() => document.querySelectorAll('[data-feed-body] > div').forEach((div) => div.classList.remove('max-w-xl'))");

    expect(wideLayout($page)['prose'])->not->toBeEmpty();
});

it('stacks each change-feed badge above its body below `md`', function (int $width): void {
    $page = visitSurface($this, 'robot-council.feed', '', 'Change feed', 'light');

    $page->resize($width, 900);

    $rows = feedRows($page);

    expect(array_column($rows, 'type'))->toContain(longestFeedType()->value);

    foreach ($rows as $row) {
        expect($row['badge']['bottom'])->toBeLessThanOrEqual($row['body']['top'], $row['type'])
            ->and($row['badge']['overflow'])->toBeFalse($row['type']);
    }

    expect(sidewaysScroll($page))->toBe(0);
})->with([390, 700]);

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
 * The Administration page with two sessions under the gate's installation, so its list has two
 * rows to set apart, and a second harness on the same machine, so there is an installation rule
 * to tell them from; the seeded fleet runs one session and one harness per machine.
 *
 * @param  array<string, mixed>  $options  Browser options, as `visitSurface()` takes them.
 */
function twoSessionRows(TestCase $case, string $theme = 'light', array $options = []): PendingAwaitablePage
{
    $page = visitSurface($case, 'robot-council.administration', '', 'gate-runner', $theme, $options);

    $installation = Installation::query()->where('machine_label', 'gate-runner')->sole();
    $case->service(AgentSessions::class)->start($installation, 'robot-council/cli', 'robot-council-cli-a');
    $codex = $installation->replicate()->fill(['harness' => 'codex']);
    $codex->save();
    $case->service(AgentSessions::class)->start($codex, 'robot-council/core', 'robot-council-core-d');

    // Re-rendered in place, as the poll would, so the page object stays the one the helpers take
    expect($page->script('async () => { await Promise.all(window.Livewire.all().map(c => c.$wire.$refresh())); return "polled"; }'))->toBe('polled');

    return $page;
}

/**
 * Each pair of adjacent session rows on Administration, as drawn (#516): how far apart their
 * contents are, whether every button lies inside its own row's box, and the rule between them with
 * its contrast against what it sits on.
 *
 * @return list<array{gap: float, overlap: float, escaped: list<string>, style: string, ratio: float|null, wrapped: bool}> One per pair.
 */
function sessionRowPairs(PendingAwaitablePage $page): array
{
    $pairs = $page->script(<<<'JS'
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
            const content = li => {
                const rects = [...li.children].map(c => c.getBoundingClientRect()).filter(r => r.height > 0);
                return { top: Math.min(...rects.map(r => r.top)), bottom: Math.max(...rects.map(r => r.bottom)) };
            };
            const out = [];
            for (const ul of document.querySelectorAll('[data-admin-sessions]')) {
                const rows = [...ul.children];
                for (let i = 1; i < rows.length; i++) {
                    const [a, b] = [rows[i - 1], rows[i]];
                    const [ra, rb] = [a.getBoundingClientRect(), b.getBoundingClientRect()];
                    const escaped = [a, b].flatMap(li => {
                        const box = li.getBoundingClientRect();
                        return [...li.querySelectorAll('button')]
                            .filter(btn => { const r = btn.getBoundingClientRect(); return r.top < box.top - 0.5 || r.bottom > box.bottom + 0.5; })
                            .map(btn => `${li.querySelector('[data-session-id]').textContent.trim()} ${btn.textContent.trim()}`);
                    });
                    const cs = getComputedStyle(b);
                    const layers = ['rgb(255, 255, 255)'];
                    for (let node = b.parentElement; node; node = node.parentElement) {
                        layers.splice(1, 0, getComputedStyle(node).backgroundColor);
                    }
                    const drawn = cs.borderTopStyle !== 'none' && parseFloat(cs.borderTopWidth) > 0;
                    const actions = a.querySelector('[data-session-actions]').getBoundingClientRect();
                    out.push({
                        gap: content(b).top - content(a).bottom,
                        overlap: ra.bottom - rb.top,
                        escaped,
                        style: drawn ? cs.borderTopStyle : 'none',
                        ratio: drawn ? ratio(paint(...layers, cs.borderTopColor), paint(...layers)) : null,
                        wrapped: actions.height > 60,
                    });
                }
            }
            return out;
        }
    JS);

    if (! is_array($pairs)) {
        throw new RuntimeException('The session-row read returned nothing.');
    }

    /** @var list<array{gap: float, overlap: float, escaped: list<string>, style: string, ratio: float|null, wrapped: bool}> $pairs */
    return $pairs;
}

/**
 * Why a pair of session rows is not set apart, or null when it is (#516).
 *
 * @param  array{gap: float, overlap: float, escaped: list<string>, style: string, ratio: float|null, wrapped: bool}  $pair  The pair.
 */
function sessionRowFault(array $pair): ?string
{
    return match (true) {
        $pair['gap'] < 12 => sprintf('contents %.1fpx apart', $pair['gap']),
        $pair['overlap'] > 0.5 => sprintf('rows overlap by %.1fpx', $pair['overlap']),
        $pair['escaped'] !== [] => 'buttons outside their row: '.implode(', ', $pair['escaped']),
        $pair['style'] !== 'dashed' => sprintf('a %s rule, not the dashed one', $pair['style']),
        $pair['ratio'] === null || $pair['ratio'] < 3 => sprintf('a rule at %s:1', $pair['ratio'] === null ? 'none' : number_format($pair['ratio'], 2)),
        default => null,
    };
}

it('sets each session row apart on Administration, with its buttons inside it, at every width and in both themes (#516)', function (int $width, string $theme): void {
    $page = twoSessionRows($this, $theme);
    $page->resize($width, 900);

    $pairs = sessionRowPairs($page);

    // At least the planted pair, so a clean result is about rows that were there
    expect($pairs)->not->toBeEmpty();

    foreach ($pairs as $pair) {
        expect(sessionRowFault($pair))->toBeNull(json_encode($pair, JSON_THROW_ON_ERROR));
    }

    // Where the actions wrap onto a second line of buttons, the rows stay apart as well
    if ($width === 390) {
        expect(array_filter(array_column($pairs, 'wrapped')))->not->toBeEmpty();
    }

    // The installations stay a different rule: solid, so the two levels read as two. Read off the
    // gate's machine, where the planted second harness gives its list a rule to draw
    expect($page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-installation-machine="gate-runner"] > ul > li:not(:last-child)')]
            .map(li => `${getComputedStyle(li).borderBottomStyle} ${parseFloat(getComputedStyle(li).borderBottomWidth) > 0}`)
    JS))->toBe(['solid true'])
        ->and(sidewaysScroll($page))->toBe(0);
})->with([390, 800, 1280])->with(['light', 'dark']);

it('draws the session-row rule under forced colors (#516)', function (): void {
    $page = twoSessionRows($this, 'light', ['forcedColors' => 'active']);
    $page->resize(1280, 900);

    expect($page->script("() => window.matchMedia('(forced-colors: active)').matches"))->toBeTrue();

    $pairs = sessionRowPairs($page);

    expect($pairs)->not->toBeEmpty();

    foreach ($pairs as $pair) {
        expect($pair['style'])->toBe('dashed');
    }
});

it('finds each way a pair of session rows can fail to be set apart, so the check above is not blind (#516)', function (string $plant, string $fault): void {
    $page = twoSessionRows($this);
    $page->resize(1280, 900);

    // Applied to every row after the first in each list, on the rendered page
    $page->script(sprintf("() => document.querySelectorAll('[data-admin-sessions]').forEach(ul => { %s })", $plant));

    $faults = array_values(array_filter(array_map(sessionRowFault(...), sessionRowPairs($page))));

    expect($faults)->not->toBeEmpty()
        // The fault's opening words, compared whole, since a planted fault has to be the one reported
        ->and(mb_substr($faults[0], 0, mb_strlen($fault)))->toBe($fault);
})->with([
    // The markup before #516: the 4px row gap and no rule
    'the old 4px rows' => ["ul.classList.remove('session-rows'); ul.style.rowGap = '0.25rem';", 'contents 4.0px apart'],
    // A row held shorter than its contents, so its buttons spill over the rule into the next
    'buttons spilling out of a row' => ["[...ul.children].slice(1).forEach(li => { li.style.height = '20px'; li.style.overflow = 'visible'; li.style.marginBottom = '4rem'; });", 'buttons outside their row'],
    'a solid rule' => ["[...ul.children].slice(1).forEach(li => { li.style.borderTopStyle = 'solid'; });", 'a solid rule'],
    'a rule in the card color' => ["[...ul.children].slice(1).forEach(li => { li.style.borderTopColor = 'var(--color-base-100)'; });", 'a rule at 1.00:1'],
]);

/**
 * Why the parts of the pending-request session row on Administration do not each take a line of
 * their own, as drawn (#519): one entry per fault, empty when every part has its line.
 *
 * Read from the seeded `home-windows` session, which has asked to be a coordinator, so all four
 * lines are present. A line "shares" with another part when their boxes overlap vertically.
 *
 * Below `sm` a group of buttons may wrap within its own block, and only there: at 390px the cell is
 * 284px wide, and the request's badge and two answers need 334px and the actions 410px at the 44px
 * target every control keeps, which no layout fits on one line without shrinking a target.
 *
 * @param  bool  $oneLine  Whether each group must also fit on a single line.
 * @return array{parts: int, faults: list<string>} How many parts were measured, and each fault.
 */
function sessionRowLines(PendingAwaitablePage $page, bool $oneLine = true): array
{
    $found = $page->script(sprintf(<<<'JS'
        () => {
            const oneLine = %s;
            const row = document.querySelector('[data-installation-machine="home-windows"] [data-admin-sessions] > li');
            if (! row) { return null; }
            // A part counts only when it is drawn: a hidden group measures as nothing and could
            // otherwise never be found sharing a line
            const part = (name, el) => {
                const box = el ? el.getBoundingClientRect() : null;
                return box && box.height > 0 ? { name, box } : null;
            };
            const lines = ['joined', 'seen', 'request', 'actions']
                .map(name => part(name, row.querySelector(`[data-session-${name}]`)));
            const others = [
                ...[...row.children].filter(el => ! el.matches('[data-session-detail]'))
                    .map(el => part(el.matches('[data-session-id]') ? 'id' : `badge ${el.textContent.trim()}`, el)),
                part('where', row.querySelector('[data-session-where]')),
            ];
            const faults = [];
            lines.forEach((line, i) => {
                if (! line) { faults.push(`no ${['joined', 'seen', 'request', 'actions'][i]} line`); return; }
                for (const other of [...lines.filter(l => l && l !== line), ...others.filter(Boolean)]) {
                    const shared = Math.min(line.box.bottom, other.box.bottom) - Math.max(line.box.top, other.box.top);
                    if (shared > 0.5) { faults.push(`${line.name} shares a line with ${other.name}`); }
                }
                // Together on one line: some height crosses every part of the group, which a badge
                // centered beside taller buttons does and a group that wrapped does not
                const boxes = [...row.querySelector(`[data-session-${line.name}]`).children].map(el => el.getBoundingClientRect());
                if (oneLine && boxes.length > 1 && Math.max(...boxes.map(b => b.top)) >= Math.min(...boxes.map(b => b.bottom))) {
                    faults.push(`${line.name} wraps onto another line`);
                }
            });
            return { parts: lines.filter(Boolean).length + others.filter(Boolean).length, faults: [...new Set(faults)] };
        }
    JS, $oneLine ? 'true' : 'false'));

    if (! is_array($found)) {
        throw new RuntimeException('The pending-request session row was not found.');
    }

    /** @var array{parts: int, faults: list<string>} $found */
    return $found;
}

it('stacks each session row so joined, last seen, a pending request and the actions each take a line (#519)', function (int $width): void {
    $page = visitSurface($this, 'robot-council.administration', '', 'home-windows', 'light');
    $page->resize($width, 900);

    // The four lines, the id, two badges and the repository line, so a clean result measured them
    expect(sessionRowLines($page, oneLine: $width >= 640))->toBe(['parts' => 8, 'faults' => []])
        ->and(sidewaysScroll($page))->toBe(0);
})->with([390, 700, 1280]);

it('finds the parts of a session row run together, so the check above is not blind (#519)', function (string $plant, string $fault): void {
    $page = visitSurface($this, 'robot-council.administration', '', 'home-windows', 'light');
    $page->resize(1280, 900);

    $page->script(sprintf("() => { const detail = document.querySelector('[data-installation-machine=\"home-windows\"] [data-session-detail]'); %s }", $plant));

    $faults = sessionRowLines($page)['faults'];

    // The planted fault, in part, since which badge or line it collides with depends on heights
    expect(array_filter($faults, static fn (string $found): bool => str_contains($found, $fault)))->not->toBeEmpty(json_encode($faults, JSON_THROW_ON_ERROR));
})->with([
    // The request and the actions side by side on one line, under lines of their own above them
    'request beside the actions' => ["detail.style.flexDirection = 'row'; detail.style.flexWrap = 'wrap'; detail.querySelectorAll('[data-session-where], [data-session-joined], [data-session-seen]').forEach(el => { el.style.flexBasis = '100%'; });", 'request shares a line with actions'],
    // The request squeezed until its answers wrap under its badge
    'request wrapping' => ["detail.querySelector('[data-session-request]').style.width = '8rem';", 'request wraps'],
    // A line hidden, which measures as nothing
    'a hidden joined line' => ["detail.querySelector('[data-session-joined]').style.display = 'none';", 'no joined line'],
    // The markup before #519: one wrapping row holding every part. The groups are flattened into it
    // as `display: contents`, which is what a single wrapping container amounts to
    'the old single wrapping line' => ["detail.style.flexDirection = 'row'; detail.style.flexWrap = 'wrap'; detail.querySelectorAll('[data-session-where], [data-session-request], [data-session-actions]').forEach(el => { el.style.display = 'contents'; }); detail.querySelectorAll('[data-session-joined], [data-session-seen]').forEach(el => { el.style.display = 'inline'; });", 'joined shares a line with'],
    // The badges centered against the whole stack, as the row's `items-center` would put them
    'badges centered on the stack' => ["detail.parentElement.style.alignItems = 'center';", 'shares a line with badge'],
    // The actions squeezed until they wrap within their own line
    'actions wrapping' => ["detail.querySelector('[data-session-actions]').style.width = '8rem';", 'actions wraps'],
]);

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

it('draws a task title that is only a link as a 44px target, and a link inside a title at its line height', function (string $route, string $parameter, string $expect, string $selector, string $theme): void {
    $page = visitSurface($this, $route, $parameter, $expect, $theme);

    $page->resize(1280, 900);

    // Each title link: its size, and whether anything but the link is written in its title, which
    // is what decides whether `undersizedControls()` measures it or exempts it as inline (#554)
    $links = $page->script(str_replace('__SELECTOR__', json_encode($selector, JSON_THROW_ON_ERROR), <<<'JS'
        () => [...document.querySelectorAll(__SELECTOR__ + ' a[href]')].map(a => {
            const rect = a.getBoundingClientRect();
            const title = a.closest(__SELECTOR__);
            return {
                text: a.textContent.trim(),
                alone: title.textContent.replace(a.textContent, '').trim() === '',
                width: Math.round(rect.width),
                height: Math.round(rect.height),
            };
        })
    JS));

    if (! is_array($links)) {
        throw new RuntimeException('The title-link read did not run: '.json_encode($links));
    }

    $alone = array_values(array_filter($links, static fn (mixed $link): bool => is_array($link) && ($link['alone'] ?? false) === true));
    $inSentence = array_values(array_filter($links, static fn (mixed $link): bool => is_array($link) && ($link['alone'] ?? true) === false));

    // The seeded lone-link title is on the page, so the size below is measured rather than vacuous
    expect($alone)->not->toBeEmpty(json_encode($links, JSON_THROW_ON_ERROR));

    foreach ($alone as $link) {
        expect($link['width'])->toBeGreaterThanOrEqual(44, json_encode($link, JSON_THROW_ON_ERROR))
            ->and($link['height'])->toBeGreaterThanOrEqual(44, json_encode($link, JSON_THROW_ON_ERROR));
    }

    // A link with words round it keeps the line's height: the Queue seeds one
    foreach ($inSentence as $link) {
        expect($link['height'])->toBeLessThan(44, json_encode($link, JSON_THROW_ON_ERROR));
    }

    if ($route === 'robot-council.queue') {
        expect($inSentence)->not->toBeEmpty(json_encode($links, JSON_THROW_ON_ERROR));
    }
})->with([
    'queue' => ['robot-council.queue', '', 'Everyone stop and sync', '[data-task-title]'],
    'lanes' => ['robot-council.lanes', '', 'Run the screen-reader pass', '[data-lane-task-title]'],
])->with(['light', 'dark']);

it('gives every button a boundary at 3:1 against what it sits on', function (string $route, string $parameter, string $expect, string $theme): void {
    $page = visitSurface($this, $route, $parameter, $expect, $theme);

    $page->resize(1280, 900);

    $faint = faintButtons($page);

    expect($faint)->toBe([], implode("\n", $faint));
})->with(accessibilitySurfaces())->with(['light', 'dark']);

it('measures the warning buttons the seats, administration and access pages draw', function (string $route, string $parameter, string $expect, string $theme): void {
    $page = visitSurface($this, $route, $parameter, $expect, $theme);

    $page->resize(1280, 900);

    $ratios = buttonRatios($page, '.btn-warning');

    // Each page draws at least one, so the pass above is a measurement of them and not of nothing
    expect($ratios)->not->toBeEmpty();

    // And once pressed: daisyUI's pressed rule sets the border too, from a sublayer the warning
    // border is meant to beat, so a move of that rule into a sublayer would show here first
    $page->script(<<<'JS'
        () => document.querySelectorAll('.btn-warning').forEach(button => button.setAttribute('aria-pressed', 'true'))
    JS);

    $ratios = [...$ratios, ...buttonRatios($page, '.btn-warning')];

    foreach ($ratios as $ratio) {
        fwrite(STDERR, sprintf("warning %s %s: %s\n", $route, $theme, $ratio));

        expect((float) preg_replace('/^.* ([0-9.]+):1$/', '$1', $ratio))->toBeGreaterThanOrEqual(3.0);
    }
})->with([
    'seats' => ['robot-council.seats', '', 'robot-council-core-a'],
    'administration' => ['robot-council.administration', '', 'gate-runner'],
    'access' => ['robot-council.access', '', 'from configuration'],
])->with(['light', 'dark']);

it('borders only a solid, usable warning button in its label color', function (string $theme): void {
    $page = visitSurface($this, 'robot-council.seats', '', 'robot-council-core-a', $theme);

    // Each planted style, with whether its border is the label color: an outline, ghost or disabled
    // warning button keeps daisyUI's own border, which an unscoped rule would override
    $bordered = $page->script(<<<'JS'
        () => {
            const card = document.querySelector('main .card-body');
            const probe = document.createElement('span');
            probe.style.color = 'var(--color-warning-content)';
            card.appendChild(probe);
            const brown = getComputedStyle(probe).color;
            const out = {};
            for (const [name, style, disabled] of [['solid', '', false], ['outline', 'btn-outline', false], ['ghost', 'btn-ghost', false], ['disabled', '', true]]) {
                const button = document.createElement('button');
                button.className = `btn btn-target btn-warning ${style}`;
                button.disabled = disabled;
                button.textContent = name;
                card.appendChild(button);
                out[name] = getComputedStyle(button).borderTopColor === brown;
            }
            return out;
        }
    JS);

    expect($bordered)->toBe(['solid' => true, 'outline' => false, 'ghost' => false, 'disabled' => false]);
})->with(['light', 'dark']);

it('finds a faint button, so the check above is not blind', function (string $theme): void {
    $page = visitSurface($this, 'robot-council.seats', '', 'robot-council-core-a', $theme);

    $page->resize(1280, 900);

    expect(faintButtons($page))->toBeEmpty();

    // The two styles #482 removed, planted on the card the seats page draws: a ghost button, with
    // no fill or border at rest, and a plain one, whose base-200 fill barely differs from the card
    $page->script(<<<'JS'
        () => {
            const card = document.querySelector('main .card-body');
            for (const [style, text] of [['btn-ghost', 'Planted ghost'], ['', 'Planted plain'], ['btn-warning', 'Planted warning']]) {
                const button = document.createElement('button');
                button.className = `btn btn-target ${style}`;
                button.textContent = text;
                card.appendChild(button);
            }

            // And the warning style with daisyUI's stock border, its fill darkened 5% (#498)
            card.lastElementChild.style.setProperty('--btn-border', 'color-mix(in oklab, var(--btn-color), #000 5%)');
        }
    JS);

    $faint = faintButtons($page);

    // The stock warning button is faint only in the light theme: its yellow fill passes on the dark card
    expect($faint)->toHaveCount($theme === 'light' ? 3 : 2)
        ->and($faint[0])->toStartWith('Planted ghost ')
        ->and($faint[1])->toStartWith('Planted plain ');

    if ($theme === 'light') {
        expect($faint[2])->toStartWith('Planted warning ');
    }
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
            // table, where a table's cells are records rather than sentences. A change-feed body is the
            // exception: it is running text in a table cell, and holds its measure there too (#311)
            const prose = [...main.querySelectorAll('p, li')]
                .filter((p) => (! p.closest('table') || p.closest('[data-feed-body]')) && ! p.querySelector('p, li'))
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
            const short = ['Machine', 'Role', 'Status', 'State', 'Priority', 'Fence', 'Last seen', 'Locks', 'Age', 'Lease', 'Known since', 'Who']
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

/**
 * A picture with a transparent background: an 8 by 8 PNG, transparent but for a dark square in the
 * middle, which is what an organization's logo on no background amounts to (#515).
 */
const TRANSPARENT_LOGO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAYAAADED76LAAAAFklEQVR4nGNgoBoQEBD4j4wHQgHZAACu5xLxoxtuzAAAAABJRU5ErkJggg==';

/**
 * Put a developer's and a repository's avatar into the page as the component renders them with a
 * stored picture, each loading the source given, and read back what shows in each circle.
 *
 * The component is rendered here rather than on the page, because a stored picture on the page would
 * be fetched from GitHub; its URL is then swapped for `$source`, so the markup is the component's own.
 *
 * @param  string  $source  What each picture loads.
 * @return list<array{kind: string, picture: bool, loaded: bool, letter: string, backing: string, covers: bool}> One per avatar planted.
 */
function plantPictures(PendingAwaitablePage $page, string $source): array
{
    $stored = 'https://avatars.githubusercontent.com/u/9919?v=4';

    DB::table('robot_council_github_identities')->where('github_login', 'octodev')->update(['avatar_url' => $stored]);
    DB::table('robot_council_github_owners')->updateOrInsert(['login' => 'robot-council'], ['avatar_url' => $stored, 'noted_at' => now()]);

    // A fresh read of both, since what the component reads is remembered for the request
    app()->forgetScopedInstances();

    $markup = Blade::render('<x-robot-council::avatar login="octodev" /><x-robot-council::avatar repository="robot-council/core" />');

    expect(substr_count($markup, 'src="'.$stored.'"'))->toBe(2);

    $page->script(sprintf(
        '() => { const box = document.createElement("p"); box.dataset.planted = ""; box.innerHTML = %s; document.querySelector("main").prepend(box); }',
        json_encode(str_replace($stored, $source, $markup), JSON_THROW_ON_ERROR)
    ));

    $page->wait(0.5);

    /** @var list<array{kind: string, picture: bool, loaded: bool, letter: string, backing: string, covers: bool}> */
    return pageList($page, 'planted-avatar', <<<'JS'
        () => [...document.querySelectorAll('[data-planted] [data-avatar] > span')].map(circle => {
            const image = circle.querySelector('img');
            const letter = circle.querySelector('span');
            const drawn = image?.getBoundingClientRect();
            return {
                kind: circle.parentElement.dataset.avatar === 'repository' ? 'repository' : 'developer',
                picture: image !== null,
                loaded: image !== null && image.complete && image.naturalWidth > 0,
                letter: getComputedStyle(letter).visibility,
                backing: image ? getComputedStyle(image).backgroundColor : '',
                // The inside of the circle, within its ring, which under forced colors is 4px wide
                covers: drawn !== undefined && Math.abs(drawn.width - circle.clientWidth) < 0.5 && Math.abs(drawn.height - circle.clientHeight) < 0.5,
            };
        })
    JS);
}

it('shows a transparent picture on its own, over no letter, and the letter alone when a picture fails (#515)', function (string $theme, bool $forced): void {
    $page = visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', $theme, $forced ? ['forcedColors' => 'active'] : []);

    // The forced rows really are forced, or they would pass as copies of the plain ones
    expect($page->script("() => window.matchMedia('(forced-colors: active)').matches"))->toBe($forced);

    $shown = plantPictures($page, TRANSPARENT_LOGO);

    // Both variants: the picture loaded, filling the circle on an opaque white backing, and the letter
    // beneath it hidden -- so a transparent logo is not read through with a letter across it
    expect(array_column($shown, 'kind'))->toBe(['developer', 'repository']);

    foreach ($shown as $avatar) {
        expect($avatar['loaded'])->toBeTrue($avatar['kind'])
            ->and($avatar['covers'])->toBeTrue($avatar['kind'])
            ->and($avatar['backing'])->toBe('rgb(255, 255, 255)', $avatar['kind'])
            ->and($avatar['letter'])->toBe('hidden', $avatar['kind']);
    }

    $page->script('() => document.querySelector("[data-planted]").remove()');

    // A picture that fails is taken away, and the letter shows alone
    $failed = plantPictures($page, 'data:image/png;base64,broken');

    expect(array_column($failed, 'kind'))->toBe(['developer', 'repository']);

    foreach ($failed as $avatar) {
        expect($avatar['picture'])->toBeFalse($avatar['kind'])
            ->and($avatar['letter'])->toBe('visible', $avatar['kind']);
    }
})->with([
    'light' => ['light', false],
    'dark' => ['dark', false],
    // Dark as well as light: a light palette's system background is white too, so only the dark one
    // shows that the backing is kept rather than replaced
    'forced colors, light' => ['light', true],
    'forced colors, dark' => ['dark', true],
]);

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

it('groups the lane board by role and developer, names every table, and fits a phone (#514)', function (int $width): void {
    $page = visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', 'light');
    $page->resize($width, 900);

    $found = $page->script(<<<'JS'
        () => {
            const card = [...document.querySelectorAll('main h2')].find((h) => h.textContent.trim().endsWith('robot-council/core')).closest('.card-body');
            const outline = [...card.querySelectorAll('h3, h4, caption')]
                .map((el) => `${el.tagName.toLowerCase()} ${el.textContent.trim().replace(/\s+/g, ' ')}`);
            const tables = [...document.querySelectorAll('main [data-developer-group] table')];
            return {
                outline,
                tables: tables.length,
                unnamed: tables.filter((t) => ! t.caption || t.caption.textContent.trim() === '').length,
                clipped: [...card.querySelectorAll('.overflow-x-auto')].filter((box) => box.scrollWidth > box.clientWidth + 0.5).length,
            };
        }
    JS);

    // The seed's core lanes: octodev's two build lanes and gate, and the colleague's build lane.
    // The avatar's letter is part of each heading's text, hidden from a screen reader
    expect($found)->toBe([
        'outline' => [
            'h3 Build seats',
            'h4 Ccolleague',
            'caption colleague, build seats',
            'h4 Ooctodev',
            'caption octodev, build seats',
            'h3 Gate seats',
            'h4 Ooctodev',
            'caption octodev, gate seats',
            'h3 Pull requests',
        ],
        // Every table on the page: those four, and the colleague's lane in `robot-council/cli`
        'tables' => 5,
        'unnamed' => 0,
        'clipped' => 0,
    ])->and(sidewaysScroll($page))->toBe(0);
})->with([390, 1280]);

it('takes a picture that fails to load out of its avatar on the enrollment page too (#521)', function (): void {
    $page = visitSurface($this, 'robot-council.enroll.show', 'code', 'What the machine says about itself', 'light');

    $failed = plantPictures($page, 'data:image/png;base64,broken');

    expect(array_column($failed, 'kind'))->toBe(['developer', 'repository']);

    foreach ($failed as $avatar) {
        expect($avatar['picture'])->toBeFalse($avatar['kind'])
            ->and($avatar['letter'])->toBe('visible', $avatar['kind']);
    }

    // The dashboard script did it, which this page did not load before
    expect($page->script('() => [...document.scripts].some((s) => s.src.includes("/dashboard.js"))'))->toBeTrue();
});

it('runs Alpine on the enrollment page and acts on nothing the requester sent (#521)', function (): void {
    $page = visitSurface($this, 'robot-council.enroll.show', 'code', 'What the machine says about itself', 'light');
    $url = $page->script('() => location.href');

    if (! is_string($url)) {
        throw new RuntimeException('The page address read returned no string.');
    }

    // A tag carrying Alpine directives and a quote that would open an attribute, within each column
    DeviceCode::query()->whereNull('approved_at')->whereNull('denied_at')->update([
        'machine_label' => '<b x-data x-init="document.title=1">x</b>',
        'harness' => '" x-init="document.title=1" @a="',
    ]);

    $page = visit($url);

    // The control: a directive that does reach this page's markup runs, so the title check below
    // could fail rather than passing because Alpine never got to the page
    $page->script(<<<'JS'
        () => document.querySelector('main').insertAdjacentHTML('beforeend', '<i x-data x-init="document.body.dataset.alpineRan = 1"></i>')
    JS);
    $page->wait(0.5);

    expect($page->script('() => document.body.dataset.alpineRan ?? null'))->toBe('1');

    $read = $page->script(<<<'JS'
        () => ({
            alpine: typeof window.Alpine === 'object',
            title: document.title,
            shown: document.querySelector('main').textContent.includes('<b x-data x-init="document.title=1">x</b>'),
            directives: document.querySelectorAll('main [x-init]:not(i)').length,
        })
    JS);

    if (! is_array($read)) {
        throw new RuntimeException('The page read returned nothing.');
    }

    // Alpine is running, so a directive that reached the markup would have changed the title
    expect($read['alpine'] ?? null)->toBeTrue()
        ->and($read['title'] ?? null)->not->toBe('1')
        ->and($read['shown'] ?? null)->toBeTrue()
        ->and($read['directives'] ?? null)->toBe(0);
});

it('groups the administration page by developer and machine, nests its headings, and fits a phone (#518)', function (int $width): void {
    $page = visitSurface($this, 'robot-council.administration', '', 'gate-runner', 'light');
    $page->resize($width, 900);

    $found = $page->script(<<<'JS'
        () => {
            const main = document.querySelector('main');
            return {
                outline: [...main.querySelectorAll('h1, h2, h3')]
                    .map((el) => `${el.tagName.toLowerCase()} ${el.textContent.trim().replace(/\s+/g, ' ')}`),
                // Every installation entry sits in a list under a machine heading's group
                orphans: [...main.querySelectorAll('[wire\\:key^="installation-"]')]
                    .filter((li) => ! li.closest('[data-installation-machine] > ul')).length,
                entries: main.querySelectorAll('[wire\\:key^="installation-"]').length,
            };
        }
    JS);

    // The seed's two developers, octodev's four machines and the colleague's two, each by label.
    // The avatar's letter is part of each developer heading's text, hidden from a screen reader
    expect($found)->toBe([
        'outline' => [
            'h1 Installations',
            'h2 Ccolleague',
            'h3 colleague-desktop',
            'h3 colleague-laptop',
            'h2 Ooctodev',
            'h3 coordinator-mac',
            'h3 gate-runner',
            'h3 home-windows',
            'h3 office-mac',
        ],
        'orphans' => 0,
        'entries' => 6,
    ])->and(sidewaysScroll($page))->toBe(0);
})->with([390, 1280]);

/**
 * Seven more items owed by the signed-in developer, so a card list is long enough to fill rows.
 *
 * Recorded by the seed's coordinator after the page has been visited once, since `visitSurface()`
 * seeds the fleet itself, and the page then re-rendered.
 */
function owedCardsSeeded(PendingAwaitablePage $page): PendingAwaitablePage
{
    $coordinator = AgentSession::query()->where('role', Role::Coordinator->value)->firstOrFail();

    foreach (range(1, 7) as $n) {
        app(OwedItems::class)->record($coordinator, 'octodev', 'robot-council/core#'.(460 + $n), sprintf('Decide question %d of the card grid', $n), 'blocks a lane');
    }

    // Re-rendered as a poll would, so the page keeps its type for the helpers that read it
    $done = $page->script('async () => { await Promise.all(window.Livewire.all().map(c => c.$wire.$refresh())); return "polled"; }');

    expect($done)->toBe('polled');

    return $page;
}

/**
 * Every card list on the page: its role, how many columns its grid draws, how many cards it holds
 * and where each sits, and whether reading order is the order drawn (#527).
 *
 * @return list<array{list: string, role: string|null, display: string, width: float, columns: int, cards: int, perRow: int, narrowest: float, widest: float, minimum: float, inOrder: bool}>
 */
function cardLists(PendingAwaitablePage $page): array
{
    /** @var list<array{list: string, role: string|null, display: string, width: float, columns: int, cards: int, perRow: int, narrowest: float, widest: float, minimum: float, inOrder: bool}> $lists */
    $lists = pageList($page, 'card-lists', <<<'JS'
        () => [...document.querySelectorAll('main ul.card-grid')].map((ul) => {
            const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
            const cards = [...ul.children].map((li) => li.getBoundingClientRect());
            const tops = cards.map((r) => Math.round(r.top));
            // Drawn order: by row, then left to right. The DOM order is the reading order
            const drawn = cards.map((r, i) => [Math.round(r.top), Math.round(r.left), i])
                .sort((a, b) => a[0] - b[0] || a[1] - b[1]).map((x) => x[2]);
            return {
                list: (ul.closest('section, .card-body')?.querySelector('h2, h3')?.textContent ?? '').trim().replace(/\s+/g, ' '),
                role: ul.getAttribute('role'),
                display: getComputedStyle(ul).display,
                width: ul.getBoundingClientRect().width,
                columns: getComputedStyle(ul).gridTemplateColumns.split(' ').length,
                cards: cards.length,
                perRow: tops.filter((t) => t === tops[0]).length,
                narrowest: Math.min(...cards.map((r) => r.width)),
                widest: Math.max(...cards.map((r) => r.width)),
                // What the grid's minimum comes to here: 22rem, or the whole width where that is less
                minimum: Math.min(22 * rem, ul.getBoundingClientRect().width),
                inOrder: drawn.every((v, i) => v === i),
            };
        })
    JS);

    return $lists;
}

it('lays owed items and held lanes out as cards along rows, and one per row on a phone (#527)', function (string $route, int $width, int $columns): void {
    $page = owedCardsSeeded(visitSurface($this, $route, '', 'Run the screen-reader pass', 'light'));
    $page->resize($width, 1000);

    $lists = cardLists($page);
    $rem = 16.0;

    // The owed list, and on Waiting on me the held lanes too
    expect($lists)->toHaveCount($route === 'robot-council.waiting' ? 2 : 1);

    foreach ($lists as $list) {
        // A grid at every width, so one column on a phone is the grid's answer rather than a list
        // that lost its layout; and at 1920px every list, the held lanes too, has its columns
        expect($list['role'])->toBe('list')
            ->and($list['display'])->toBe('grid')
            ->and($list['columns'])->toBe($width === 1920 ? $list['columns'] : 1)
            ->and($list['columns'])->toBeGreaterThanOrEqual($columns)
            ->and($list['inOrder'])->toBeTrue()
            // Never narrower than the minimum, and never wider than the prose measure
            ->and($list['narrowest'])->toBeGreaterThanOrEqual($list['minimum'] - 0.5)
            ->and($list['widest'])->toBeLessThanOrEqual(36 * $rem + 0.5);
    }

    // The owed list is the long one: eight items, the seed's and seven more
    expect($lists[0]['cards'])->toBe(8)
        ->and($lists[0]['perRow'])->toBe($width === 1920 ? $lists[0]['columns'] : 1)
        ->and(sidewaysScroll($page))->toBe(0);

    // At 800px one column is wider than the prose measure, so the cap is what holds the card to it
    if ($width === 800) {
        expect($lists[0]['width'])->toBeGreaterThan(36 * $rem)
            ->and($lists[0]['widest'])->toEqualWithDelta(36 * $rem, 0.5);
    }
})->with([
    'Lanes on a phone' => ['robot-council.lanes', 390, 1],
    'Lanes at 800px, one column wider than the measure' => ['robot-council.lanes', 800, 1],
    'Lanes at 1920px' => ['robot-council.lanes', 1920, 3],
    'Waiting on me on a phone' => ['robot-council.waiting', 390, 1],
    'Waiting on me at 800px, one column wider than the measure' => ['robot-council.waiting', 800, 1],
    'Waiting on me at 1920px' => ['robot-council.waiting', 1920, 2],
]);

/**
 * Every card whose boundary is under 3:1 against what it sits on, composited as drawn, and every
 * card with no border to draw (#527).
 *
 * @return list<string> Each faint card, with its ratio.
 */
function faintCards(PendingAwaitablePage $page): array
{
    return pageList($page, 'card-boundary', <<<'JS'
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
            const out = [];
            for (const el of document.querySelectorAll('main ul.card-grid > li')) {
                const layers = ['rgb(255, 255, 255)'];
                for (let node = el.parentElement; node; node = node.parentElement) {
                    layers.splice(1, 0, getComputedStyle(node).backgroundColor);
                }
                const cs = getComputedStyle(el);
                const name = el.textContent.trim().replace(/\s+/g, ' ').slice(0, 30);
                if (cs.borderTopStyle === 'none' || parseFloat(cs.borderTopWidth) === 0) { out.push(`${name} no border`); continue; }
                const r = ratio(paint(...layers, cs.borderTopColor), paint(...layers));
                if (r < 3) out.push(`${name} ${r.toFixed(2)}:1`);
            }
            return out;
        }
    JS);
}

it('draws every card boundary at 3:1 against the surface, in both themes and under forced colors (#527)', function (string $route, string $theme, string $forced): void {
    $page = visitSurface($this, $route, '', 'Run the screen-reader pass', $theme, $forced === 'forced' ? ['forcedColors' => 'active'] : []);
    $page->resize(1280, 900);

    if ($forced === 'forced') {
        expect($page->script("() => window.matchMedia('(forced-colors: active)').matches"))->toBeTrue();
    }

    $counted = $page->script("() => document.querySelectorAll('main ul.card-grid > li').length");

    // At least the seed's owed item, so a clean result is about cards that were there
    expect($counted)->toBeGreaterThanOrEqual($route === 'robot-council.waiting' ? 2 : 1);

    $faint = faintCards($page);

    expect($faint)->toBe([], implode("\n", $faint));
})->with([
    'Lanes' => 'robot-council.lanes',
    'Waiting on me' => 'robot-council.waiting',
])->with(['light', 'dark'])->with(['plain', 'forced']);

it('finds a faint card and a card with no border, so the check above is not blind (#527)', function (string $theme): void {
    $page = visitSurface($this, 'robot-council.waiting', '', 'Run the screen-reader pass', $theme);
    $page->resize(1280, 900);

    expect(faintCards($page))->toBeEmpty();

    // The border every other block uses, which is what a card would have had, and none at all
    $page->script(<<<'JS'
        () => {
            const list = document.querySelector('main ul.card-grid');
            for (const [style, text] of [['border-color: var(--color-base-300)', 'Planted faint'], ['border: 0', 'Planted bare']]) {
                const card = document.createElement('li');
                card.className = 'item-card';
                card.setAttribute('style', style);
                card.textContent = text;
                list.appendChild(card);
            }
        }
    JS);

    $faint = faintCards($page);

    expect($faint)->toHaveCount(2)
        ->and($faint[0])->toStartWith('Planted faint 1.')
        ->and($faint[1])->toBe('Planted bare no border');
})->with(['light', 'dark']);

/**
 * Every new-tab link on the page whose text is a reference in `<code>` (#536): its rendered text,
 * and where "(new tab)" starts against the reference's last line and its left edge.
 *
 * @return list<array{text: string, gap: float|null, sameLine: bool, underneath: bool, lines: int, height: float}>
 */
function newTabRuns(PendingAwaitablePage $page): array
{
    $runs = $page->script(<<<'JS'
        () => [...document.querySelectorAll('main a[target="_blank"]')]
            .filter(a => a.querySelector('code') && a.getClientRects().length > 0)
            .map(a => {
                const code = a.querySelector('code');
                const text = [...a.childNodes].find(n => n.nodeType === Node.TEXT_NODE && n.textContent.includes('(new tab)'));
                const range = document.createRange();
                const from = text.textContent.indexOf('(');
                range.setStart(text, from);
                range.setEnd(text, from + 1);
                const mark = range.getBoundingClientRect();
                // Read off the box and its line height rather than its client rects: a reference
                // made a flex item is a block, which reports one rect however many lines it wraps
                const box = code.getBoundingClientRect();
                const height = parseFloat(getComputedStyle(code).lineHeight);
                const lastTop = box.bottom - height;
                const sameLine = Math.abs(mark.top - lastTop) < height / 2;
                return {
                    text: a.innerText.replace(/\s+/g, ' ').trim(),
                    // Drawn apart, not merely apart in the text: a flex box keeps the space in
                    // innerText and draws none
                    gap: sameLine ? mark.left - (code.getClientRects().length > 1 ? [...code.getClientRects()].pop().right : box.right) : null,
                    sameLine,
                    // Wrapped to the line after the reference's last, keyed to the line height
                    // rather than the box, whose padding and border run past the line box
                    underneath: mark.top >= lastTop + height / 2 && mark.left <= box.left + 0.5,
                    lines: Math.round(box.height / height),
                    height: a.getBoundingClientRect().height,
                };
            })
    JS);

    if (! is_array($runs)) {
        throw new RuntimeException('The new-tab link read returned nothing.');
    }

    /** @var list<array{text: string, gap: float|null, sameLine: bool, underneath: bool, lines: int, height: float}> $runs */
    return $runs;
}

/**
 * Why each new-tab run does not read as one spaced run of text, by its text (#536).
 *
 * @param  list<array{text: string, gap: float|null, sameLine: bool, underneath: bool, lines: int, height: float}>  $runs  The runs.
 * @return list<string> One entry per fault.
 */
function newTabFaults(array $runs): array
{
    $faults = [];

    foreach ($runs as $run) {
        if (! $run['sameLine'] && ! $run['underneath']) {
            $faults[] = 'a column of its own: '.$run['text'];
        }

        if ($run['gap'] !== null && $run['gap'] < 2) {
            $faults[] = 'run together: '.$run['text'];
        }
    }

    return $faults;
}

/**
 * Seed an owed item whose reference is long enough to wrap on a phone, and re-render.
 */
function longOwedReference(PendingAwaitablePage $page): PendingAwaitablePage
{
    $coordinator = AgentSession::query()->where('role', Role::Coordinator->value)->firstOrFail();
    app(OwedItems::class)->record($coordinator, 'octodev', 'UAMS-Web/wordpress-importer-exports-archive#12720', 'Decide whether the long reference wraps with its indicator', 'blocks a lane');

    expect($page->script('async () => { await Promise.all(window.Livewire.all().map(c => c.$wire.$refresh())); return "polled"; }'))->toBe('polled');

    return $page;
}

it('keeps "(new tab)" with its reference as one spaced run of text, at every width (#536)', function (string $route, string $expect, int $width): void {
    $page = longOwedReference(visitSurface($this, $route, '', $expect, 'light'));
    $page->resize($width, 900);

    $runs = newTabRuns($page);
    $described = json_encode($runs, JSON_THROW_ON_ERROR);

    // The long reference is among them, and on a phone it really does wrap
    $long = array_values(array_filter($runs, static fn (array $run): bool => str_starts_with($run['text'], 'UAMS-Web/wordpress-importer-exports-archive#12720')));
    expect($long)->toHaveCount(1, $described);

    if ($width === 390) {
        expect($long[0]['lines'])->toBeGreaterThan(1, $described);
    }

    // A space between them, and "(new tab)" either on the reference's last line or wrapped under
    // it, never a column of its own beside it
    expect(newTabFaults($runs))->toBe([], $described)
        ->and(sidewaysScroll($page))->toBe(0);
})->with([
    'Waiting on me' => ['robot-council.waiting', 'Run the screen-reader pass'],
    'Lanes' => ['robot-council.lanes', 'Run the screen-reader pass'],
])->with([390, 1280]);

/**
 * Every line on a page that separates one thing from the next, measured against what it is drawn
 * on, that reaches less than 3:1 (#520).
 *
 * Every border side the page draws, on every element, with its colour composited over the element's
 * own background and every ancestor's, read through a canvas as `faintButtons()` does. A border is
 * drawn over the element's own background, which is why that is the last layer under it.
 *
 * **Skipped, and why each is not this check's**: a control's boundary -- a button, a field, a
 * checkbox, a toggle -- which is SC 1.4.11's other half and #498's; a badge, whose word carries what
 * it says; and an avatar's ring, which is decoration around a picture. A transparent border draws
 * nothing. What is left is every divider, rule, outline and table row on the page.
 *
 * @return list<string> Each faint separator, named by its tag, its classes and the side, with its ratio.
 */
function faintSeparators(PendingAwaitablePage $page): array
{
    return pageList($page, 'separator', <<<'JS'
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
            const behind = el => {
                const layers = ['rgb(255, 255, 255)'];
                for (let node = el; node; node = node.parentElement) {
                    layers.splice(1, 0, getComputedStyle(node).backgroundColor);
                }
                return layers;
            };
            // An element's own opacity and every ancestor's, which fade the line with them. Applied
            // to the line alone, which errs low rather than high: the backgrounds fade too
            const opacity = el => {
                let product = 1;
                for (let node = el; node; node = node.parentElement) product *= parseFloat(getComputedStyle(node).opacity);
                return product;
            };
            const faded = (under, color, alpha) => {
                ctx.clearRect(0, 0, 1, 1);
                for (const c of under) { ctx.globalAlpha = 1; ctx.fillStyle = c; ctx.fillRect(0, 0, 1, 1); }
                ctx.globalAlpha = alpha; ctx.fillStyle = color; ctx.fillRect(0, 0, 1, 1); ctx.globalAlpha = 1;
                return [...ctx.getImageData(0, 0, 1, 1).data.slice(0, 3)];
            };
            const out = [];
            for (const el of document.querySelectorAll('body *')) {
                if (el.closest('.btn, .badge, input, select, textarea, .checkbox, .toggle, .radio, [data-avatar]')) continue;
                const rect = el.getBoundingClientRect();
                if (rect.width === 0 && rect.height === 0) continue;
                const side = el.closest('.drawer-side');
                if (side && getComputedStyle(side).visibility === 'hidden') continue;
                const cs = getComputedStyle(el);
                for (const edge of ['Top', 'Right', 'Bottom', 'Left']) {
                    if (parseFloat(cs[`border${edge}Width`]) === 0 || cs[`border${edge}Style`] === 'none') continue;
                    const color = cs[`border${edge}Color`];
                    if (color === 'rgba(0, 0, 0, 0)' || color === 'transparent') continue;
                    const under = behind(el);
                    const found = ratio(faded(under, color, opacity(el)), paint(...under));
                    if (found < 3) {
                        const classes = [...el.classList].slice(0, 4).join('.');
                        out.push(`${el.tagName.toLowerCase()}${classes ? '.' + classes : ''} ${edge.toLowerCase()} ${found.toFixed(2)}:1`);
                    }
                }
            }
            return out;
        }
    JS);
}

/**
 * One of #520's surfaces, populated so that every separator it can draw is drawn.
 *
 * Administration through `twoSessionRows()`: the seeded fleet runs one harness and one session per
 * machine, so the installation divider and the session rule would each have a single row and draw
 * nothing, and the check would pass without having seen either.
 */
function separatorPage(TestCase $case, string $route, string $expect, string $theme): PendingAwaitablePage
{
    return $route === 'robot-council.administration'
        ? twoSessionRows($case, $theme)
        : visitSurface($case, $route, '', $expect, $theme);
}

/**
 * The surfaces #520 names: one with nested lists, one with a table and a glossary, one with a table.
 *
 * @return array<string, array{string, string}> Route and the words only it draws.
 */
function separatorSurfaces(): array
{
    return [
        'administration' => ['robot-council.administration', 'gate-runner'],
        'feed' => ['robot-council.feed', 'Running the gate before opening the pull request.'],
        'agents' => ['robot-council.agents', 'coordinator-mac'],
    ];
}

it('draws every separator at 3:1 against what it sits on, in both themes (#520)', function (string $route, string $expect, string $theme): void {
    $page = separatorPage($this, $route, $expect, $theme);

    // The glossary's rules are inside a closed disclosure, so open it and they are measured too
    $page->script('() => { for (const d of document.querySelectorAll("details")) d.open = true; }');

    $faint = faintSeparators($page);

    expect($faint)->toBe([], implode("\n", $faint));
})->with(separatorSurfaces())->with(['light', 'dark']);

it('finds the separators #520 replaced, so the check above is not blind', function (string $route, string $expect, string $theme): void {
    $page = separatorPage($this, $route, $expect, $theme);

    // The colours each separator was drawn in before #520, planted over the shared one: `base-200`
    // between list items, `base-300` for a rule or an outline, and daisyUI's 5% for a table row
    $page->script(<<<'JS'
        () => {
            for (const d of document.querySelectorAll("details")) d.open = true;
            const style = document.createElement('style');
            style.textContent = `
                .divide-separator > * { border-color: var(--color-base-200) !important; }
                .border-separator { border-color: var(--color-base-300) !important; }
                .table :is(td, th) { border-color: color-mix(in oklch, var(--color-base-content) 5%, #0000) !important; }
                .session-rows > li { border-color: var(--color-base-300) !important; }
            `;
            document.head.appendChild(style);
        }
    JS);

    $faint = faintSeparators($page);

    // Each kind is found, on every page that draws it
    expect($faint)->not->toBeEmpty()
        ->and(array_filter($faint, static fn (string $line): bool => str_starts_with($line, 'nav') || str_starts_with($line, 'header')))->not->toBeEmpty();

    $starting = static fn (string ...$tags): array => array_filter($faint, static fn (string $line): bool => array_filter($tags, static fn (string $tag): bool => str_starts_with($line, $tag.' ') || str_starts_with($line, $tag.'.')) !== []);

    // The glossary's rules, on every page
    expect($starting('div'))->not->toBeEmpty();

    if ($route === 'robot-council.administration') {
        // The installation divider and the dashed session rule, both drawn by `separatorPage()`
        expect(array_filter($starting('li'), static fn (string $line): bool => str_contains($line, ' top ')))->not->toBeEmpty()
            ->and(array_filter($starting('li'), static fn (string $line): bool => str_contains($line, ' bottom ')))->not->toBeEmpty();
    } else {
        expect($starting('td', 'th'))->not->toBeEmpty();
    }
})->with(separatorSurfaces())->with(['light', 'dark']);

/**
 * Where each column of every seat table on the Lanes page starts and how wide it is, measured from
 * the table's own left edge (#560).
 *
 * With `$long`, a lane name and an "On what" value far wider than their columns are written into
 * the first table first, so the measurement shows whether one long value moves the columns.
 *
 * @return array{tables: int, columns: list<list<array{left: float, width: float}>>, overflowing: list<string>, broken: list<string>, stacked: list<string>}
 */
function laneColumns(PendingAwaitablePage $page, bool $long = false): array
{
    $found = $page->script(sprintf(<<<'JS'
        () => {
            const tables = [...document.querySelectorAll('main [data-developer-group] table')];
            if (%s) {
                const row = tables[0].querySelector('tbody tr');
                // No space or hyphen in either, so nothing but the column's own width can make them wrap
                row.querySelector('td[data-label="Lane"] code').textContent = 'amachinelabel'.repeat(12);
                // Into the cell's own code where it has one, so the rest of what it draws stays beside it
                const onWhat = row.querySelector('td[data-label="On what"]');
                (onWhat.querySelector('code') ?? onWhat).textContent = 'anunbrokenvalue'.repeat(16) + ' and then some words that wrap';
            }
            const round = (n) => Math.round(n * 10) / 10;
            return {
                tables: tables.length,
                columns: tables.map((table) => {
                    const left = table.getBoundingClientRect().left;
                    return [...table.querySelectorAll('thead th')].map((th) => {
                        const box = th.getBoundingClientRect();
                        return { left: round(box.left - left), width: round(box.width) };
                    });
                }),
                // A cell whose content runs past it, or a table wider than the box it scrolls in
                overflowing: [
                    ...tables.flatMap((table) => [...table.querySelectorAll('td')].filter((td) => td.scrollWidth > td.clientWidth + 0.5).map((td) => `${td.dataset.label} cell`)),
                    ...tables.filter((table) => table.parentElement.scrollWidth > table.parentElement.clientWidth + 0.5).map((table) => table.caption.textContent.trim()),
                ],
                // A word of a state, a watcher or a time drawn across two lines: a narrow column broke it
                broken: tables.flatMap((table) => [...table.querySelectorAll('td[data-label="State"], td[data-label="Watcher"], td[data-label="Known since"]')].flatMap((td) => {
                    const walker = document.createTreeWalker(td, NodeFilter.SHOW_TEXT);
                    const split = [];
                    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                        for (const word of node.textContent.matchAll(/\S+/g)) {
                            const range = document.createRange();
                            range.setStart(node, word.index);
                            range.setEnd(node, word.index + word[0].length);
                            if ([...range.getClientRects()].filter((r) => r.width > 0).length > 1) split.push(`${td.dataset.label}: ${word[0]}`);
                        }
                    }
                    return split;
                })),
                // Each table not laid out as a table: on a phone, every one of them
                stacked: tables.filter((table) => getComputedStyle(table).display === 'block').map((table) => table.caption.textContent.trim()),
            };
        }
    JS, $long ? 'true' : 'false'));

    if (! is_array($found) || ! is_array($found['columns'] ?? null)) {
        throw new RuntimeException('The column read returned no tables.');
    }

    /** @var array{tables: int, columns: list<list<array{left: float, width: float}>>, overflowing: list<string>, broken: list<string>, stacked: list<string>} $found */
    return $found;
}

it('starts every column at the same place in every seat table on the Lanes page (#560)', function (int $width, string $theme, bool $long): void {
    $page = visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', $theme);
    $page->resize($width, 1000);

    $found = laneColumns($page, $long);

    // The seed's five seat tables, each with its five columns
    expect($found['tables'])->toBe(5)
        ->and($found['stacked'])->toBeEmpty()
        ->and($found['overflowing'])->toBeEmpty()
        ->and($found['broken'])->toBeEmpty();

    $first = $found['columns'][0];

    // One set of widths, shared, and not five equal shares: from a full-width page up, "On what"
    // carries a list of tickets and is the widest, and the lane's name is wider than its state
    expect($first)->toHaveCount(5);

    if ($width >= 1280) {
        expect(max(0.0, ...array_column($first, 'width')))->toBe($first[3]['width'])
            ->and($first[0]['width'])->toBeGreaterThan($first[1]['width']);
    }

    foreach ($found['columns'] as $index => $columns) {
        foreach ($columns as $column => $box) {
            expect($box['left'])->toEqualWithDelta($first[$column]['left'], 0.5, "table {$index}, column {$column}")
                ->and($box['width'])->toEqualWithDelta($first[$column]['width'], 0.5, "table {$index}, column {$column}");
        }
    }

    expect(sidewaysScroll($page))->toBe(0);
})->with([800, 1024, 1280, 1536, 1920])->with(['light', 'dark'])->with(['as seeded' => false, 'with a long lane name and On what' => true]);

it('still stacks every seat table on a phone, with no column widths left over (#560)', function (): void {
    $page = visitSurface($this, 'robot-council.lanes', '', 'Run the screen-reader pass', 'light');
    $page->resize(390, 900);

    $found = laneColumns($page, true);

    // Each cell is a block the full width of its row, as before #560: the column widths, which
    // a stacked table has no columns to apply to, leave no mark
    $cells = $page->script(<<<'JS'
        () => [...document.querySelectorAll('main [data-developer-group] tbody tr')].flatMap((tr) => {
            const row = tr.getBoundingClientRect();
            const style = getComputedStyle(tr);
            const inner = row.width - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            return [...tr.children].filter((td) => Math.abs(td.getBoundingClientRect().width - inner) > 0.5).map((td) => td.dataset.label);
        })
    JS);

    expect($found['tables'])->toBe(5)
        ->and($found['stacked'])->toHaveCount(5)
        ->and($found['overflowing'])->toBeEmpty()
        ->and($cells)->toBe([])
        ->and(sidewaysScroll($page))->toBe(0);
});

/**
 * Where each seat row on the seats page draws its title, its controls and its Park or Lift button.
 *
 * @param  bool  $plantOld  Put back the row's classes from before #561 first, for the control.
 * @return list<array{name: string, title: array{left: float, right: float, bottom: float}, controls: array{left: float, right: float, top: float}, button: array{left: float, right: float}}>
 */
function seatRows(PendingAwaitablePage $page, bool $plantOld = false): array
{
    $rows = $page->script(sprintf(<<<'JS'
        () => {
            if (%s) {
                for (const title of document.querySelectorAll('[data-seat-title]')) {
                    title.className = '';
                    title.firstElementChild.classList.remove('wrap-anywhere');
                }
                for (const controls of document.querySelectorAll('[data-seat-controls]')) {
                    controls.className = 'flex flex-wrap items-center gap-2';
                }
            }
            return [...document.querySelectorAll('[data-seat-title]')].map(title => {
                const row = title.parentElement;
                const controls = row.querySelector('[data-seat-controls]');
                const button = controls.querySelector('button[wire\\:click^="park("], button[wire\\:click^="lift("]');
                const t = title.getBoundingClientRect();
                const c = controls.getBoundingClientRect();
                const b = button.getBoundingClientRect();
                return {
                    name: title.firstElementChild.textContent.replace(/\s+/g, ' ').trim(),
                    title: { left: t.left, right: t.right, bottom: t.bottom },
                    controls: { left: c.left, right: c.right, top: c.top },
                    button: { left: b.left, right: b.right },
                };
            });
        }
    JS, $plantOld ? 'true' : 'false'));

    if (! is_array($rows)) {
        throw new RuntimeException('The seat-row read returned nothing.');
    }

    /** @var list<array{name: string, title: array{left: float, right: float, bottom: float}, controls: array{left: float, right: float, top: float}, button: array{left: float, right: float}}> $rows */
    return $rows;
}

/**
 * The signed-in developer's seats, read at one width, with the long-named one proved present.
 *
 * @return list<array{name: string, title: array{left: float, right: float, bottom: float}, controls: array{left: float, right: float, top: float}, button: array{left: float, right: float}}>
 */
function seatRowsAt(TestCase $case, int $width, bool $plantOld = false): array
{
    $page = visitSurface($case, 'robot-council.seats', '', 'robot-council-core-a', 'light');

    // A seat whose name runs past one line, added here rather than to the shared fleet, whose other
    // pages are measured row by row; every name is within `WorkIdentity`'s bounds
    $developer = auth('web')->user();

    if (! $developer instanceof User) {
        throw new RuntimeException('No developer is signed in.');
    }

    $case->service(AgentSessions::class)->start($case->approveInstallation($developer, 'long-names-box'), 'example-org/a-repository-whose-name-runs-long-enough-to-wrap', 'a-checkout-with-a-long-label-b');

    $page->refresh()->resize($width, 900);

    $rows = seatRows($page, $plantOld);
    $lengths = array_map(static fn (array $row): int => mb_strlen($row['name']), $rows);

    // A short name and the long one, or the comparisons below compare nothing
    expect(array_filter($lengths, static fn (int $length): bool => $length > 70))->toHaveCount(1)
        ->and(array_filter($lengths, static fn (int $length): bool => $length < 45))->not->toBeEmpty()
        ->and(sidewaysScroll($page))->toBe(0);

    return $rows;
}

it("keeps every seat row's controls at the right, whatever the length of its name (#561)", function (int $width): void {
    $rows = seatRowsAt($this, $width);

    foreach ($rows as $row) {
        // Beside the title rather than under it, and the name kept off them
        expect($row['controls']['top'])->toBeLessThan($row['title']['bottom'], $row['name'])
            ->and($row['title']['right'])->toBeLessThanOrEqual($row['controls']['left'], $row['name']);
    }

    // Every row's controls end at one edge, and every Park button starts at one offset
    expect(array_unique(array_map(static fn (array $row): int => (int) round($row['controls']['right']), $rows)))->toHaveCount(1)
        ->and(array_unique(array_map(static fn (array $row): int => (int) round($row['button']['left']), $rows)))->toHaveCount(1);
})->with([1100, 1920]);

it("stacks every seat row's controls under its title below `md` (#561)", function (int $width): void {
    $rows = seatRowsAt($this, $width);

    foreach ($rows as $row) {
        expect($row['controls']['top'])->toBeGreaterThanOrEqual($row['title']['bottom'], $row['name'])
            ->and((int) round($row['controls']['left']))->toBe((int) round($row['title']['left']), $row['name']);
    }
})->with([390, 700]);

it('finds a long-named seat row whose controls jump, so the check above is not blind (#561)', function (): void {
    $rows = seatRowsAt($this, 1100, plantOld: true);

    // The layout before #561: the long name pushes its controls onto a line of their own, at the left
    expect(array_unique(array_map(static fn (array $row): int => (int) round($row['button']['left']), $rows)))->not->toHaveCount(1);
});
