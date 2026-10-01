<?php

declare(strict_types=1);

/**
 * The safe Markdown subset agent-written prose is shown in on the dashboard (#537).
 *
 * @command  vendor/bin/pest --compact tests/AgentTextTest.php
 */

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use RobotCouncil\Livewire\ChangeFeed;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Livewire\TaskBoard;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentText;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\OwedItems;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Support\TicketLink;

/**
 * Agent text rendered through the component, in either mode.
 */
function agentText(string $text, bool $inline = false): string
{
    return Blade::render(
        $inline ? '<x-robot-council::agent-text :text="$text" inline />' : '<x-robot-council::agent-text :text="$text" />',
        ['text' => $text]
    );
}

/**
 * Rendered markup without the markers Livewire writes round each Blade condition and loop.
 */
function withoutLivewireMarkers(string $html): string
{
    return (string) preg_replace('#<!--\[if [A-Z]+\]><!\[endif\]-->#', '', $html);
}

/**
 * Every element and attribute in some rendered markup that the component itself does not write.
 *
 * The component writes only these elements, a class on its container, `start` on a numbered list,
 * and through `external-link` a link to GitHub with its fixed `target` and `rel`. Anything else in
 * the output came from what an agent wrote.
 *
 * @return list<string>
 */
function foreignMarkup(string $html): array
{
    $foreign = [];

    preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#', $html, $tags, PREG_SET_ORDER);

    foreach ($tags as [$whole, $closing, $name, $attributes]) {
        if (! in_array(strtolower($name), ['div', 'p', 'ul', 'ol', 'li', 'em', 'strong', 'code', 'br', 'a'], true)) {
            $foreign[] = $whole;

            continue;
        }

        if ($closing !== '') {
            continue;
        }

        preg_match_all('#([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?#', $attributes, $pairs, PREG_SET_ORDER);

        foreach ($pairs as $pair) {
            $attribute = strtolower($pair[1]);
            $value = trim($pair[2] ?? '', '"\'');

            $allowed = match ($attribute) {
                'class' => true,
                'start' => strtolower($name) === 'ol' && preg_match('/^[0-9]+$/', $value) === 1,
                'href' => strtolower($name) === 'a' && preg_match('#^https://github\.com/[^"\'<>\s]+$#', $value) === 1,
                'target' => $value === '_blank',
                'rel' => $value === 'noopener noreferrer',
                default => false,
            };

            if (! $allowed) {
                $foreign[] = $whole;
            }
        }
    }

    return $foreign;
}

it('admits only the markup the component writes, so the check above can tell the two apart', function (): void {
    // The instrument's controls: a forged element and a forged attribute are both found
    expect(foreignMarkup('<p><img src=x></p>'))->toBe(['<img src=x>'])
        ->and(foreignMarkup('<a href="https://evil.example">x</a>'))->toBe(['<a href="https://evil.example">'])
        ->and(foreignMarkup('<p onclick="x">y</p>'))->toBe(['<p onclick="x">'])
        ->and(foreignMarkup('<a href="https://github.com/a/b/issues/1" target="_blank" rel="noopener noreferrer" class="link">x</a>'))->toBeEmpty();
});

it('shows hostile input as the characters that were written, with no element or attribute an agent supplied', function (string $text, string $shown, bool $inline): void {
    $html = agentText($text, $inline);

    expect(foreignMarkup($html))->toBeEmpty()
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toMatch('/\shref="(?!https:\/\/github\.com\/)/')
        ->and(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5))->toContain($shown);
})->with(function (): array {
    $corpus = [
        'a script element' => ['<script>alert(1)</script>', '<script>alert(1)</script>'],
        'an image with an error handler' => ['<img src=x onerror=alert(1)>', '<img src=x onerror=alert(1)>'],
        'a javascript: link' => ['[x](javascript:alert(1))', '[x](javascript:alert(1))'],
        'a data: link' => ['[x](data:text/html;base64,PHNjcmlwdD4=)', '[x](data:text/html;base64,PHNjcmlwdD4=)'],
        'an image' => ['![x](https://example.com/a.png)', '![x](https://example.com/a.png)'],
        'a raw link' => ['<a href="https://evil.example">x</a>', '<a href="https://evil.example">x</a>'],
        'a non-GitHub autolink' => ['<https://evil.example>', 'https://evil.example'],
        'a non-GitHub link' => ['[the ticket](https://evil.example/issues/1)', '[the ticket](https://evil.example/issues/1)'],
        'a GitHub link that is not a ticket' => ['[x](https://github.com/robot-council/core/settings)', '[x](https://github.com/robot-council/core/settings)'],
        'a GitHub look-alike host' => ['[x](https://github.com.evil.example/a/b/issues/1)', '[x](https://github.com.evil.example/a/b/issues/1)'],
        'a link with a title that breaks out' => ['[x](https://github.com/a/b/issues/1 "a\" onmouseover=\"alert(1)")', 'x'],
        // A text holding a definition is shown exactly as written, the definition included
        'a reference-style link' => ["[x][r]\n\n[r]: javascript:alert(1)", '[r]: javascript:alert(1)'],
        'a definition to a ticket' => ["[a][a][a]\n\n[a]: https://github.com/a/b/issues/1", '[a][a][a]'],
        'an image of a ticket' => ['![shot](https://github.com/a/b/issues/2)', '![shot](https://github.com/a/b/issues/2)'],
        'a label naming another ticket' => ['[`robot-council/core#5`](https://github.com/evil/x/issues/5)', '](https://github.com/evil/x/issues/5)'],
        'a label naming another number' => ['[#6](https://github.com/a/b/issues/5)', '[#6](https://github.com/a/b/issues/5)'],
        'a label naming another URL' => ['[https://github.com/a/b/issues/9](https://github.com/a/b/issues/8)', '[https://github.com/a/b/issues/9](https://github.com/a/b/issues/8)'],
        'an entity' => ['&lt;script&gt;', '&lt;script&gt;'],
        'an emphasis wrapping HTML' => ['*<b onclick="x">bold</b>*', '<b onclick="x">bold</b>'],
        'code holding HTML' => ['`<svg onload=alert(1)>`', '<svg onload=alert(1)>'],
        'a comment' => ['<!-- x --> after', '<!-- x --> after'],
    ];

    $cases = [];

    foreach ($corpus as $name => [$text, $shown]) {
        $cases[$name.' (blocks)'] = [$text, $shown, false];
        $cases[$name.' (inline)'] = [$text, $shown, true];
    }

    return $cases;
});

it('sizes a field that is only a link as a 44px target when it stands alone, and changes nothing else (#554)', function (string $text, bool $lone): void {
    $target = ' inline-block min-h-11 min-w-11 py-3';

    $inline = agentText($text, inline: true);
    $standalone = Blade::render('<x-robot-council::agent-text :text="$text" inline standalone />', ['text' => $text]);

    // The same subset either way: with the sizing classes taken out, the two are byte for byte
    expect(str_replace($target, '', $standalone))->toBe($inline)
        ->and(str_contains($standalone, $target))->toBe($lone)
        // Without `standalone`, a lone link keeps the line's height wherever else it is shown
        ->and($inline)->not->toContain($target);
})->with(function (): array {
    $cases = [
        'a lone issue link' => ['[Fix the crash](https://github.com/robot-council/core/issues/9)', true],
        'a lone pull request link' => ['[Fix the crash](https://github.com/robot-council/core/pull/9)', true],
        'a lone autolink' => ['<https://github.com/robot-council/core/pull/9>', true],
        'a lone link with emphasis in its label' => ['[Fix *the* crash](https://github.com/robot-council/core/pull/9)', true],
        'a link inside a title' => ['Follow up on [#9](https://github.com/robot-council/core/pull/9) after the crash', false],
        'a link before more words' => ['[#9](https://github.com/robot-council/core/pull/9) follow-up', false],
        'two links' => ['[a](https://github.com/a/b/issues/1)[b](https://github.com/a/b/issues/2)', false],
        'a link inside emphasis' => ['*[Fix the crash](https://github.com/robot-council/core/pull/9)*', false],
        'a lone link to somewhere else' => ['[Fix the crash](https://evil.example/issues/9)', false],
        'plain text' => ['Fix the crash', false],
    ];

    // And the hostile corpus above, none of which may change by more than the classes
    foreach ([
        '<script>alert(1)</script>',
        '[x](javascript:alert(1))',
        '<a href="https://evil.example">x</a>',
        '[x](https://github.com/a/b/issues/1 "a\" onmouseover=\"alert(1)")',
        '[`robot-council/core#5`](https://github.com/evil/x/issues/5)',
    ] as $i => $hostile) {
        $cases['hostile '.$i] = [$hostile, str_starts_with($hostile, '[x](https://github.com/a/b/issues/1 ')];
    }

    return $cases;
});

it('renders a GitHub ticket link through external-link, with the new-tab words', function (): void {
    $html = agentText('See [the decision](https://github.com/robot-council/core/issues/537).');

    expect($html)->toContain('<a href="https://github.com/robot-council/core/issues/537" target="_blank" rel="noopener noreferrer" class="link">the decision (new tab)</a>.')
        ->and(foreignMarkup($html))->toBeEmpty();
});

it('links a pull request, and shows a bare GitHub autolink by its reference', function (): void {
    expect(agentText('[the fix](https://github.com/robot-council/core/pull/539)', true))
        ->toContain('<a href="https://github.com/robot-council/core/issues/539" target="_blank" rel="noopener noreferrer" class="link">the fix (new tab)</a>')
        ->and(agentText('<https://github.com/robot-council/core/issues/12>', true))
        ->toContain('class="link">https://github.com/robot-council/core/issues/12 (new tab)</a>');
});

it('adds no auto-linking: a bare reference or a bare URL stays text', function (string $text): void {
    $html = agentText($text, true);

    expect($html)->not->toContain('<a ')
        ->and(trim(html_entity_decode(strip_tags($html))))->toBe($text);
})->with([
    'a reference' => ['robot-council/core#537'],
    'a URL' => ['https://github.com/robot-council/core/issues/537'],
]);

it('renders the owed item that asked for this with two code spans (#537)', function (): void {
    $html = agentText('Confirm with the holder whether the `patientslearn.uams.edu` `hkey` link parameter is still read.');

    expect(substr_count($html, '<code>'))->toBe(2)
        ->and($html)->toContain('<code>patientslearn.uams.edu</code> <code>hkey</code>');
});

it('renders emphasis, strong, and real lists', function (): void {
    $html = (string) preg_replace('/\s+</', '<', agentText("Two *options* and a **choice**:\n\n- one\n- two\n\n3. three\n4. four"));

    expect($html)->toContain('<em>options</em>')
        ->and($html)->toContain('<strong>choice</strong>')
        ->and($html)->toContain('<ul><li>one</li><li>two</li></ul>')
        ->and($html)->toContain('<ol start="3"><li>three</li><li>four</li></ol>');
});

it('shows headings, block quotes, fenced code, and tables as the characters written', function (string $text): void {
    $html = agentText($text);

    expect($html)->not->toMatch('#<(h[1-6]|blockquote|pre|table|hr)\b#')
        ->and(foreignMarkup($html))->toBeEmpty();

    foreach (preg_split('/\n/', $text) ?: [] as $line) {
        expect(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5))->toContain(trim($line));
    }
})->with([
    'a heading' => ['# Heading'],
    'a block quote' => ['> quoted'],
    'a table' => ["| a | b |\n| - | - |\n| 1 | 2 |"],
    'a thematic break' => ['***'],
    'a spaced thematic break' => ['* * *'],
    'a dashed thematic break' => ['- - -'],
]);

it('shows a title shaped like a reference definition as written, in either mode', function (string $text): void {
    foreach ([false, true] as $inline) {
        expect(trim(html_entity_decode(strip_tags(agentText($text, $inline)), ENT_QUOTES | ENT_HTML5)))
            ->toBe(str_replace("\n", '', $text));
    }
})->with([
    'an issue-template title' => ['[Bug]: crash'],
    'a definition above a question' => ["[x]: y\nreal question?"],
]);

it('keeps a link whose label names the ticket it goes to', function (string $label): void {
    expect(agentText('['.$label.'](https://github.com/robot-council/core/issues/5)', true))->toContain('class="link">');
})->with([
    'the reference' => ['robot-council/core#5'],
    'the number' => ['#5'],
    'the URL' => ['https://github.com/robot-council/core/issues/5'],
    'words' => ['the decision'],
]);

it('shows a fence as a code span at most, never a code block', function (): void {
    $html = agentText("```\nfenced\n```");

    expect($html)->not->toContain('<pre')
        ->and($html)->toContain('<code>fenced</code>');
});

it('keeps an inline field inline: a list marker stays text and paragraphs join with a break', function (): void {
    $html = agentText("- one\n\nnext", true);

    expect($html)->not->toContain('<ul')
        ->and($html)->not->toContain('<p')
        ->and($html)->toContain('<br>');
});

it('flattens what nests past the cap into text, so the view recursion is bounded', function (): void {
    // Emphasis, which CommonMark does not bound itself, so the cap reached is this class's own
    $deep = str_repeat('*a ', 12).'bottom'.str_repeat(' b*', 12);
    $nodes = AgentText::blocks($deep);

    $depth = static function (array $nodes) use (&$depth): int {
        $max = 0;

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            foreach (['children', 'items'] as $key) {
                foreach (is_array($node[$key] ?? null) ? $node[$key] : [] as $child) {
                    $max = max($max, 1 + $depth(is_array($child) && array_is_list($child) ? $child : [$child]));
                }
            }
        }

        return $max;
    };

    // The paragraph, then at most MAX_DEPTH levels of emphasis below it
    expect($depth($nodes))->toBe(AgentText::MAX_DEPTH + 1)
        ->and(html_entity_decode(strip_tags(agentText($deep))))->toContain('bottom');
});

it('reads a ticket reference out of a GitHub issue or pull request URL, and nothing else', function (string $url, ?string $reference): void {
    expect(TicketLink::reference($url))->toBe($reference);
})->with([
    'an issue' => ['https://github.com/robot-council/core/issues/537', 'robot-council/core#537'],
    'a pull request, trailing slash' => ['https://github.com/robot-council/core/pull/539/', 'robot-council/core#539'],
    'http' => ['http://github.com/robot-council/core/issues/537', null],
    'another host' => ['https://github.com.evil.example/a/b/issues/1', null],
    'a query' => ['https://github.com/a/b/issues/1?x=1', null],
    'a fragment' => ['https://github.com/a/b/issues/1#issuecomment-1', null],
    'a deeper path' => ['https://github.com/a/b/issues/1/files', null],
    'a zero' => ['https://github.com/a/b/issues/0', null],
    'a trailing newline' => ["https://github.com/a/b/issues/1\n", null],
    'credentials' => ['https://user@github.com/a/b/issues/1', null],
    'a settings page' => ['https://github.com/a/b/settings', null],
]);

describe('the fields that use it', function (): void {
    beforeEach(function (): void {
        $this->migrateUsersTableWithPackageColumns();
        $this->setAccessLists(developers: [4242, 77]);

        $this->developer = $this->enrollDeveloper(4242, login: 'octodev');

        [$this->coordinatorSession] = $this->startCoordinatorSession(
            $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
        );
    });

    it("renders an owed item's question and reason", function (): void {
        $this->service(OwedItems::class)->record(
            $this->coordinatorSession,
            'octodev',
            'robot-council/core#12',
            'Keep `hkey`? <img src=x onerror=alert(1)>',
            'Blocks **two** lanes.'
        );

        $html = withoutLivewireMarkers(Livewire::actingAs($this->developer)->test(Lanes::class)->html());

        preg_match('#data-owed-question="data-owed-question">(.*?)</div>#s', $html, $question);
        preg_match('#<p [^>]*data-owed-why>(.*?)</p>#s', $html, $why);

        expect($question[1] ?? '')->toContain('<code>hkey</code>')
            ->and($question[1] ?? '')->toContain('&lt;img src=x onerror=alert(1)&gt;')
            ->and($why[1] ?? '')->toContain('<strong>two</strong>');
    });

    it('renders a task title on the Queue', function (): void {
        $this->service(Tasks::class)->create($this->coordinatorSession, ['title' => 'Bump `league/commonmark` *now*'], false);

        $html = withoutLivewireMarkers(Livewire::actingAs($this->developer)->test(TaskBoard::class)->html());

        preg_match('#data-task-title>(.*?)</div>#s', $html, $title);

        expect($title[1] ?? '')->toContain('<code>league/commonmark</code>')
            ->and($title[1] ?? '')->toContain('<em>now</em>');
    });

    it('renders narration and directive bodies on the feed, and leaves a body the package wrote plain', function (): void {
        $this->actingAs($this->developer, 'web');

        app(FleetEvents::class)->record(FleetEventType::Narration, $this->coordinatorSession, 'Rebuilding `idx`.', withCoordinator: true);
        app(FleetEvents::class)->record(FleetEventType::Directive, $this->coordinatorSession, 'Sync **now**.', withCoordinator: true);
        app(FleetEvents::class)->record(FleetEventType::TaskCreated, $this->coordinatorSession, 'Task `raw` *as written*');

        $html = withoutLivewireMarkers(Livewire::test(ChangeFeed::class)->html());

        expect($html)->toContain('<code>idx</code>')
            ->and($html)->toContain('<strong>now</strong>')
            ->and($html)->toContain('<p class="break-words">Task `raw` *as written*</p>');
    });

    it("renders a placement's instruction on the feed, escaped exactly as narration is (#540)", function (): void {
        $this->actingAs($this->developer, 'web');

        [$lane] = $this->startAgentSession($this->approveInstallation($this->developer));

        $text = "Take `#540`; **no** release. <script>alert(1)</script>\n\n- build\n- merge";

        app(FleetEvents::class)->record(FleetEventType::Narration, $this->coordinatorSession, $text, withCoordinator: true);
        app(FleetEvents::class)->record(
            FleetEventType::PlacementInstruction,
            $this->coordinatorSession,
            $text,
            ['task_id' => 1, 'hand_back' => false, 'to' => [$lane->id]],
            withCoordinator: true,
            addressees: [$lane]
        );

        $html = withoutLivewireMarkers(Livewire::test(ChangeFeed::class)->html());

        preg_match_all('#<td role="cell" data-label="What happened" data-feed-body>(.*?)</td>#s', $html, $bodies);

        $rendered = array_values(array_filter($bodies[1], static fn (string $body): bool => str_contains($body, 'release')));

        // Both events are on the page, rendered, and rendered identically: the instruction is
        // escaped by exactly the path narration takes
        expect($rendered)->toHaveCount(2)
            ->and($rendered[0])->toBe($rendered[1])
            ->and($rendered[0])->toContain('data-feed-prose')
            ->and($rendered[0])->toContain('<code>#540</code>')
            ->and($rendered[0])->toContain('<strong>no</strong>')
            ->and($rendered[0])->toContain('<li>build</li>')
            ->and($rendered[0])->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->and($rendered[0])->not->toContain('<script');
    });

    it('leaves the body of every other event type plain on the feed', function (): void {
        $this->actingAs($this->developer, 'web');

        $rendered = [FleetEventType::Narration, FleetEventType::Directive, FleetEventType::PlacementInstruction];
        $plain = array_values(array_filter(FleetEventType::cases(), static fn (FleetEventType $type): bool => ! in_array($type, $rendered, true)));

        // With the coordinator flag, so a restricted type reaches this reader as well
        foreach ($plain as $type) {
            app(FleetEvents::class)->record($type, $this->coordinatorSession, sprintf('Body of %s with `code` and *stress*', $type->value), withCoordinator: true);
        }

        $html = withoutLivewireMarkers(Livewire::test(ChangeFeed::class)->html());

        expect($plain)->not->toBeEmpty();

        foreach ($plain as $type) {
            expect($html)->toContain(sprintf('<p class="break-words">Body of %s with `code` and *stress*</p>', $type->value));
        }
    });

    it('sizes a title that is only a link as a 44px target on the Queue and the Lanes page, and not an owed reason (#554)', function (): void {
        [$lane] = $this->startAgentSession($this->approveInstallation($this->developer));

        $lane->forceFill(['repository' => 'robot-council/core', 'work_location' => 'a'])->save();

        $tasks = $this->service(Tasks::class);
        $task = $tasks->create($lane, ['title' => '[Fix the crash](https://github.com/robot-council/core/pull/9)'], withCoordinator: false);
        $tasks->transition($task->id, TaskTransition::Claim, $lane, asCoordinator: false);
        $tasks->create($lane, ['title' => 'Follow up on [#9](https://github.com/robot-council/core/pull/9) later'], withCoordinator: false);

        $this->service(OwedItems::class)->record(
            $this->coordinatorSession,
            'octodev',
            'robot-council/core#12',
            'Merge it?',
            '[the pull request](https://github.com/robot-council/core/pull/9)'
        );

        $lanes = withoutLivewireMarkers(Livewire::actingAs($this->developer)->test(Lanes::class)->html());
        $queue = withoutLivewireMarkers(Livewire::actingAs($this->developer)->test(TaskBoard::class)->html());

        preg_match('#<span data-lane-task-title>(.*?)</span>\s*<span class="text-meta#s', $lanes, $onLanes);
        preg_match_all('#data-task-title>(.*?)</div>#s', $queue, $onQueue);
        preg_match('#<p [^>]*data-owed-why>(.*?)</p>#s', $lanes, $why);

        $sized = 'class="link inline-block min-h-11 min-w-11 py-3"';

        $lone = array_values(array_filter($onQueue[1], static fn (string $title): bool => str_contains($title, 'Fix the crash')));
        $inside = array_values(array_filter($onQueue[1], static fn (string $title): bool => str_contains($title, 'Follow up')));

        expect($onLanes[1] ?? '')->toContain($sized)
            ->and($lone)->toHaveCount(1)
            ->and($lone[0] ?? '')->toContain($sized)
            ->and($inside)->toHaveCount(1)
            ->and($inside[0] ?? '')->toContain('class="link"')
            ->and($inside[0] ?? '')->not->toContain('min-h-11')
            // An owed item's reason is followed by when it was recorded, so its link is in a line
            ->and($why[1] ?? '')->toContain('class="link"')
            ->and($why[1] ?? '')->not->toContain('min-h-11');
    });

    it('renders a task title on the Lanes page as the Queue does (#540)', function (): void {
        [$lane] = $this->startAgentSession($this->approveInstallation($this->developer));

        $lane->forceFill(['repository' => 'robot-council/core', 'work_location' => 'a'])->save();

        $title = 'Bump `league/commonmark` *now* <img src=x onerror=alert(1)>';

        $tasks = $this->service(Tasks::class);
        $task = $tasks->create($lane, ['title' => $title], withCoordinator: false);
        $tasks->transition($task->id, TaskTransition::Claim, $lane, asCoordinator: false);

        $lanes = withoutLivewireMarkers(Livewire::actingAs($this->developer)->test(Lanes::class)->html());
        $queue = withoutLivewireMarkers(Livewire::actingAs($this->developer)->test(TaskBoard::class)->html());

        preg_match('#<span data-lane-task-title>(.*?)</span>\s*<span class="text-meta#s', $lanes, $onLanes);
        preg_match('#data-task-title>(.*?)</div>#s', $queue, $onQueue);

        expect($onLanes[1] ?? null)->toBeString()
            ->and(trim($onLanes[1] ?? ''))->toBe(trim($onQueue[1] ?? ''))
            ->and($onLanes[1] ?? '')->toContain('<code>league/commonmark</code>')
            ->and($onLanes[1] ?? '')->toContain('<em>now</em>')
            ->and($onLanes[1] ?? '')->toContain('&lt;img src=x onerror=alert(1)&gt;')
            ->and($onLanes[1] ?? '')->not->toContain('<img');
    });
});
