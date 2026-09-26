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
 * **Browser tests run apart.** The `browser` group is excluded from every run that does not name
 * it, because it needs Playwright and a Chromium build the ordinary matrix does not install; the
 * `browser` job in `.github/workflows/ci.yml` runs it, and locally it is
 * `vendor/bin/pest --group=browser` after `npx playwright install chromium`.
 *
 * @command  vendor/bin/pest --group=browser
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Pest\Browser\Api\PendingAwaitablePage;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\HoldReason;
use RobotCouncil\Models\Lock;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Backlog;
use RobotCouncil\Support\DeveloperSettings;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\GateRuns;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\LaneHolds;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\OwedItems;
use RobotCouncil\Support\RoleRequests;
use RobotCouncil\Support\Seats;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

pest()->group('browser');

/**
 * The WCAG A and AA rule tags, which are the gate. axe's own default run adds its best-practice
 * rules, which are advice rather than conformance, so the tags are named rather than left to it.
 */
const AXE_GATE = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];

/**
 * The AAA rule tags, which are reported and never fail the run.
 */
const AXE_AAA = ['wcag2aaa', 'wcag21aaa', 'wcag22aaa'];

/**
 * Every surface, by the route that renders it and what it needs in the URL.
 *
 * @return array<string, array{string, string}> The route name, and `code` for the enrollment page.
 */
function accessibilitySurfaces(): array
{
    return [
        'overview' => ['robot-council.dashboard', ''],
        'lanes' => ['robot-council.lanes', ''],
        'queue' => ['robot-council.queue', ''],
        'agents' => ['robot-council.agents', ''],
        'locks' => ['robot-council.locks', ''],
        'feed' => ['robot-council.feed', ''],
        'administration' => ['robot-council.administration', ''],
        'seats' => ['robot-council.seats', ''],
        'enrollment' => ['robot-council.enroll.show', 'code'],
    ];
}

/**
 * A fleet with something in every panel, so each page is scanned populated rather than empty.
 *
 * Every state a page draws differently is here: a working lane with a branch and a hand-back, an
 * idle one, a blocked one, a parked one, a stale one and a gate running a pull request; a lock held,
 * one lapsed and one free; tasks in several statuses, one of them a coordinator's; a narration, a
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
    $sessions->start($case->approveInstallation($colleague, 'colleague-laptop'), 'robot-council/core', 'robot-council-core-c');
    $stale = $sessions->start($case->approveInstallation($colleague, 'colleague-desktop'), 'robot-council/cli', 'robot-council-cli-a')->owner;
    $gate = $sessions->start($case->approveInstallation($developer, 'gate-runner'), 'robot-council/core', 'robot-council-core-ci')->owner;

    $case->service(RoleRequests::class)->impose($gate, Role::Ci, 'test-administrator');
    $case->service(RoleRequests::class)->request($blocked, Role::Coordinator);

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
    $case->service(LaneHolds::class)->hold($coordinator, $blocked->id, 'robot-council/core#403', HoldReason::TicketLands);
    $parkedSeat = $case->service(Seats::class)->forDeveloper(HostKey::from($colleague->getAuthIdentifier()))[0];
    $case->service(Seats::class)->park(HostKey::from($colleague->getAuthIdentifier()), $parkedSeat->id);
    AgentSession::query()->whereKey($stale->id)->update(['status' => AgentSessionStatus::Stale->value]);
    $case->service(GateRuns::class)->start($gate, 'robot-council/core#453');

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

    $code = app(DeviceCodes::class)->issue(['fleet:read', 'tasks:create'], 'claude-code', 'workbench', hash('sha256', 'accessibility-verifier'), null);

    return ['developer' => $developer, 'code' => $code->record->user_code];
}

/**
 * Visit one surface as the seeded developer, in one theme.
 */
function visitSurface(TestCase $case, string $route, string $parameter, string $theme): PendingAwaitablePage
{
    $fleet = seedAccessibilityFleet($case);

    $case->actingAs($fleet['developer'], 'web');

    $url = route($route, $parameter === 'code' ? ['user_code' => $fleet['code']] : []);
    $page = visit($url);

    return $theme === 'dark' ? $page->inDarkMode() : $page->inLightMode();
}

/**
 * The violations axe finds on a page, for the rule tags given.
 *
 * **Run through `script()` rather than the plugin's `assertNoAccessibilityIssues()`**, which turns an
 * absent or failed axe run into an empty list and so a pass. Here an axe that did not run is a
 * failure: the page returns a string, and the caller's expectation refuses anything but a list.
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
 * Every element whose color carries a meaning and that shows no word to carry it too.
 *
 * A semantic color -- a badge, `text-error`, an alert -- reaches no assistive technology and no
 * reader who cannot tell the hues apart, so each must carry its meaning as visible text as well: two
 * letters at least, which a dot, an icon or an empty box does not have.
 *
 * @return list<string> The start of each such element's markup.
 */
function colorOnlyElements(PendingAwaitablePage $page): array
{
    $bare = $page->script(<<<'JS'
        () => {
            const semantic = /(^|\s)(badge|badge-[a-z]+|text-(error|success|warning|info)|alert-[a-z]+|bg-(error|success|warning|info))(\s|$)/;
            return [...document.querySelectorAll('body *')]
                .filter(el => typeof el.className === 'string' && semantic.test(el.className) && el.getClientRects().length > 0)
                .filter(el => !/[A-Za-z]{2,}/.test(el.textContent))
                .map(el => el.outerHTML.slice(0, 120));
        }
    JS);

    if (! is_array($bare)) {
        throw new RuntimeException('The color check did not run on the page: '.json_encode($bare));
    }

    /** @var list<string> $bare */
    return $bare;
}

/**
 * Every control smaller than its target: 44px for a consequential one (`btn-target`), 24px for the
 * rest (SC 2.5.5 and SC 2.5.8).
 *
 * Measured as rendered. A link inside running text is exempt under SC 2.5.8's inline exception, and
 * a `label` is measured only where it is itself the control, as the drawer's toggle is; otherwise
 * the control it names is measured.
 *
 * @return list<string> Each undersized control, with its size.
 */
function undersizedControls(PendingAwaitablePage $page): array
{
    $small = $page->script(<<<'JS'
        () => {
            const out = [];
            const describe = el => (el.getAttribute('aria-label') || el.textContent || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 40);
            for (const el of document.querySelectorAll('button, a[href], summary, input:not([type=hidden]), select, textarea, label.btn')) {
                const rect = el.getBoundingClientRect();
                if (rect.width === 0 && rect.height === 0) continue;
                const side = el.closest('.drawer-side');
                if (side && getComputedStyle(side).visibility === 'hidden') continue;
                if (el.matches('a.link') || el.closest('p')) continue;
                if (el.matches('.drawer-toggle')) continue;
                const floor = el.matches('.btn-target') ? 44 : 24;
                if (rect.width < floor - 0.5 || rect.height < floor - 0.5) out.push(`${describe(el)} ${Math.round(rect.width)}x${Math.round(rect.height)} < ${floor}`);
            }
            return out;
        }
    JS);

    if (! is_array($small)) {
        throw new RuntimeException('The target-size check did not run on the page: '.json_encode($small));
    }

    /** @var list<string> $small */
    return $small;
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

it('meets the A and AA gate on every surface, in both themes', function (string $route, string $parameter, string $theme): void {
    $page = visitSurface($this, $route, $parameter, $theme);

    $blocking = blockingViolations(axeViolations($page, AXE_GATE));

    expect($blocking)->toBeEmpty(implode("\n", $blocking));
})->with(accessibilitySurfaces())->with(['light', 'dark']);

it('reports the AAA findings on every surface without failing on them', function (string $route, string $parameter, string $theme): void {
    $page = visitSurface($this, $route, $parameter, $theme);

    $violations = axeViolations($page, AXE_AAA);

    // Accumulated across the dataset into one file, read by whoever triages AAA work
    $path = dirname(__DIR__, 2).'/build/axe-aaa.json';
    $report = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $report = is_array($report) ? $report : [];
    $report[$route.' '.$theme] = $violations;

    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));

    foreach ($violations as $violation) {
        fwrite(STDERR, sprintf("AAA %s %s: %s [%s] x%d\n", $route, $theme, $violation['id'], $violation['impact'], count($violation['nodes'])));
    }

    expect($path)->toBeFile();
})->with(accessibilitySurfaces())->with(['light', 'dark']);

it('fails on a planted violation, so a clean run means axe looked', function (): void {
    $page = visitSurface($this, 'robot-council.dashboard', '', 'light');

    // Two plants: an image with no text alternative (critical), and a button with no name (critical)
    $page->script(<<<'JS'
        () => {
            const main = document.querySelector('main');
            const image = document.createElement('img');
            image.src = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
            main.appendChild(image);
            const button = document.createElement('button');
            main.appendChild(button);
        }
    JS);

    $ids = array_column(axeViolations($page, AXE_GATE), 'id');

    expect($ids)->toContain('image-alt', 'button-name')
        ->and(blockingViolations(axeViolations($page, AXE_GATE)))->not->toBeEmpty();
});

it('fails on a planted contrast failure, so the stylesheet is what axe measured', function (): void {
    $page = visitSurface($this, 'robot-council.dashboard', '', 'dark');

    $page->script(<<<'JS'
        () => {
            // About 1.3:1 on the dark theme's page, below even the 3:1 large-text bar. Not the
            // background's own color: axe files identical colors as undecidable, not as a failure
            document.querySelector('h1').style.color = 'rgb(60, 60, 60)';
        }
    JS);

    expect(array_column(axeViolations($page, AXE_GATE), 'id'))->toContain('color-contrast');
});

it('carries every status in a word, never in color alone', function (string $route, string $parameter, string $theme): void {
    $bare = colorOnlyElements(visitSurface($this, $route, $parameter, $theme));

    expect($bare)->toBe([], implode("\n", $bare));
})->with(accessibilitySurfaces())->with(['light', 'dark']);

it('finds a status carried by color alone, so the check above is not blind', function (): void {
    $page = visitSurface($this, 'robot-council.lanes', '', 'light');

    $page->script(<<<'JS'
        () => {
            const main = document.querySelector('main');
            const dot = document.createElement('span');
            dot.className = 'badge badge-error';
            main.appendChild(dot);
            const icon = document.createElement('span');
            icon.className = 'text-error';
            icon.textContent = '\u25CF';
            main.appendChild(icon);
        }
    JS);

    expect(colorOnlyElements($page))->toHaveCount(2);
});

it('keeps every status word under forced colors, where every color is replaced', function (string $route, string $parameter): void {
    $fleet = seedAccessibilityFleet($this);

    $this->actingAs($fleet['developer'], 'web');

    $page = visit(route($route, $parameter === 'code' ? ['user_code' => $fleet['code']] : []), ['forcedColors' => 'active']);

    $state = $page->script(<<<'JS'
        () => ({
            active: window.matchMedia('(forced-colors: active)').matches,
            hidden: [...document.querySelectorAll('.badge')]
                .filter(el => { const cs = getComputedStyle(el); return el.getClientRects().length > 0 && (cs.visibility === 'hidden' || cs.color === cs.backgroundColor || parseFloat(cs.opacity) === 0); })
                .map(el => el.textContent.trim()),
        })
    JS);

    if (! is_array($state)) {
        throw new RuntimeException('The forced-colors check did not run on the page: '.json_encode($state));
    }

    // The emulation is live, or every assertion after it passes against ordinary colors
    expect($state['active'] ?? null)->toBeTrue()
        ->and($state['hidden'] ?? null)->toBe([]);
})->with(accessibilitySurfaces());

it('holds every control to its target size, 44px for the consequential ones and 24px for the rest', function (string $route, string $parameter): void {
    $page = visitSurface($this, $route, $parameter, 'light');

    $page->resize(390, 900);

    $small = undersizedControls($page);

    expect($small)->toBe([], implode("\n", $small));
})->with(accessibilitySurfaces());

it('finds an undersized control, so the check above is not blind', function (): void {
    $page = visitSurface($this, 'robot-council.seats', '', 'light');

    $page->resize(390, 900);

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
        }
    JS);

    expect(undersizedControls($page))->toHaveCount(2);
});

it('reports an AAA-only failure, so an empty AAA report means the rules ran', function (): void {
    $page = visitSurface($this, 'robot-council.dashboard', '', 'light');

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
