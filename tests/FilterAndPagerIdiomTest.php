<?php

declare(strict_types=1);

/**
 * One idiom for every filter and pager on the dashboard (#309).
 *
 * Every filter was a `btn-ghost` at rest, which daisyUI draws with no background and no border
 * until hover, so the unselected choices -- the ones a reader has to find in order to act -- were
 * plain text with padding. The Queue marked its selected filter `btn-active` and every other panel
 * `btn-primary`, and each pair of pagers was one borderless control beside one filled one.
 *
 * The idiom now: an unselected filter is `btn-outline`, whose border is `base-content` and measures
 * 17.73:1 against `base-100` in the light theme and 14.75:1 in the dark one (`DashboardThemeTest`
 * pins both); the selected one is `btn-primary`; a filter carries `aria-pressed`, because it is a
 * toggle and a screen reader otherwise hears two identical buttons; and both pagers of a pair are
 * `btn btn-sm btn-outline`.
 *
 * Asserted as the EXACT class list rather than as a substring, because a substring check for
 * `btn-outline` passes on `btn-outline btn-ghost`, and a check that `btn-ghost` is absent passes on
 * a button with no variant at all -- which is the default `btn`, whose `base-200` fill and border
 * measure 1.06:1 and 1.23:1 against the card and are no more of a boundary than the ghost was.
 *
 * @command  vendor/bin/pest --compact tests/FilterAndPagerIdiomTest.php
 */

use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Livewire\Agents;
use RobotCouncil\Livewire\Locks;
use RobotCouncil\Livewire\TaskBoard;
use RobotCouncil\Tests\TestCase;

/**
 * The classes a selected filter renders with, sorted.
 */
const SELECTED_FILTER = ['btn', 'btn-primary', 'btn-xs'];

/**
 * The classes an unselected filter renders with, sorted.
 */
const UNSELECTED_FILTER = ['btn', 'btn-outline', 'btn-xs'];

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // An admin, because the Administration panel is one of the four with a filter and refuses
    // anyone else at mount.
    $this->setAccessLists(developers: [4242], admins: [4242]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octoadmin');
});

/**
 * The one button whose `wire:click` is exactly the given expression.
 *
 * @param  Testable<Component>  $component  The rendered component.
 * @param  string  $action  The `wire:click` value.
 * @return array{classes: list<string>, pressed: string|null} Its sorted class list and its
 *                                                            `aria-pressed`, or null when it has none.
 *
 * @throws RuntimeException When no button, or more than one, carries that action.
 */
function filterButton(Testable $component, string $action): array
{
    $html = $component->html();

    if ($html === '') {
        throw new RuntimeException(sprintf('The component rendered nothing, so there is no [%s] to read.', $action));
    }

    $document = new DOMDocument;

    // The fragment is HTML5, and `loadHTML` warns about what it does not know; the markup is the
    // subject here rather than the parser's opinion of it.
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $found = [];

    foreach ($document->getElementsByTagName('button') as $button) {
        if ($button->getAttribute('wire:click') === $action) {
            $found[] = $button;
        }
    }

    // Exactly one, so a renamed action fails here instead of reading as a button with no classes.
    if (count($found) !== 1) {
        throw new RuntimeException(sprintf('Expected one button for [%s], found %d.', $action, count($found)));
    }

    $classes = preg_split('/\s+/', trim($found[0]->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
    $classes = $classes === false ? [] : $classes;
    sort($classes);

    return [
        'classes' => $classes,
        'pressed' => $found[0]->hasAttribute('aria-pressed') ? $found[0]->getAttribute('aria-pressed') : null,
    ];
}

it('draws a selected and an unselected filter the same way on every page', function (Closure $mount, string $selected, string $unselected): void {
    /** @var Testable<Component> $component */
    $component = $mount($this);

    expect(filterButton($component, $selected))->toBe(['classes' => SELECTED_FILTER, 'pressed' => 'true'])
        ->and(filterButton($component, $unselected))->toBe(['classes' => UNSELECTED_FILTER, 'pressed' => 'false']);
})->with([
    // The Queue's default is every status; one status stands for the rest.
    'the Queue' => [
        static fn (): Testable => Livewire::test(TaskBoard::class),
        "showStatus('')",
        "showStatus('pending')",
    ],
    'Agents' => [
        static fn (): Testable => Livewire::test(Agents::class),
        "show('all')",
        "show('live')",
    ],
    'Locks' => [
        static fn (): Testable => Livewire::test(Locks::class),
        "show('live')",
        "show('all')",
    ],
    'Administration' => [
        static fn (TestCase $case): Testable => Livewire::actingAs($case->developer)->test(Administration::class),
        "showScope('live')",
        "showScope('all')",
    ],
]);

it('moves the selected treatment to the filter that was chosen', function (Closure $mount, string $method, string $chosen, string $chosenAction, string $left): void {
    /** @var Testable<Component> $component */
    $component = $mount($this);
    $component->call($method, $chosen);

    // The other branch of each ternary, so a view whose unselected class is right only for the
    // filter that happens to be the default still fails.
    expect(filterButton($component, $chosenAction))->toBe(['classes' => SELECTED_FILTER, 'pressed' => 'true'])
        ->and(filterButton($component, $left))->toBe(['classes' => UNSELECTED_FILTER, 'pressed' => 'false']);
})->with([
    'the Queue' => [
        static fn (): Testable => Livewire::test(TaskBoard::class),
        'showStatus', 'pending', "showStatus('pending')", "showStatus('')",
    ],
    'Agents' => [
        static fn (): Testable => Livewire::test(Agents::class),
        'show', 'live', "show('live')", "show('all')",
    ],
    'Locks' => [
        static fn (): Testable => Livewire::test(Locks::class),
        'show', 'all', "show('all')", "show('live')",
    ],
    'Administration' => [
        static fn (TestCase $case): Testable => Livewire::actingAs($case->developer)->test(Administration::class),
        'showScope', 'all', "showScope('all')", "showScope('live')",
    ],
]);

it('gives both pagers of every pair the same bordered variant', function (string $view, int $pagers): void {
    $source = (string) file_get_contents(__DIR__.'/../resources/views/livewire/'.$view.'.blade.php');

    // Read from the view source rather than rendered: a pager renders only past the first page, and
    // every page seeds that differently. Each button element whose action pages the list.
    preg_match_all('/<button\b[^>]*?wire:click="(?:showFirst|showLatest|showNext|showOlder)\b.*?<\/button>/s', $source, $buttons);

    // The count first, so a pattern that stopped matching reads as a failure rather than a pass
    // over nothing.
    expect($buttons[0])->toHaveCount($pagers);

    // Each button's whole class attribute, or null where it has none, so a pager missing the
    // attribute fails rather than being skipped.
    $classes = array_map(
        static fn (string $button): ?string => preg_match('/\sclass="([^"]*)"/', $button, $class) === 1 ? $class[1] : null,
        $buttons[0],
    );

    expect($classes)->toBe(array_fill(0, $pagers, 'btn btn-sm btn-outline'));
})->with([
    'the Queue' => ['task-board', 2],
    'Agents' => ['agents', 2],
    'Locks' => ['locks', 2],
    'Administration' => ['administration', 2],
    'the change feed' => ['change-feed', 2],
]);

it('marks every status selected when the Queue was asked for one that is not a status', function (): void {
    // The list widens to every task for a status it cannot read, so the filter has to say so. It
    // compared the raw `status` property against `''`, which is null for every status and holds
    // whatever the client sent otherwise, so no filter was ever marked selected here (#309).
    $component = Livewire::withQueryParams(['status' => 'not-a-status'])->test(TaskBoard::class);

    expect(filterButton($component, "showStatus('')"))->toBe(['classes' => SELECTED_FILTER, 'pressed' => 'true'])
        ->and(filterButton($component, "showStatus('pending')"))->toBe(['classes' => UNSELECTED_FILTER, 'pressed' => 'false']);
});

it('tells an admin whose every machine is retired where the others are', function (): void {
    // The hint and the scope buttons read the same view variable, which Livewire overwrote with
    // the raw `scope` property until #309 renamed it -- so the hint never showed either.
    $this->approveInstallation($this->developer, machineLabel: 'long-gone')
        ->forceFill(['revoked_at' => now()])->save();

    Livewire::actingAs($this->developer)->test(Administration::class)
        ->assertSeeText('No machine is currently usable. Choose All to see the revoked and expired ones.')
        ->assertDontSeeText('No machine has enrolled yet.');
});
