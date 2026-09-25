<?php

declare(strict_types=1);

/**
 * What the fleet is waiting on each developer for (#335).
 *
 * @command  vendor/bin/pest --compact tests/OwedItemsTest.php
 */

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Support\LaneBoard;
use RobotCouncil\Support\OwedItems;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77, 4343]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $this->enrollDeveloper(4343, login: 'General');

    [$this->coordinatorSession, $this->coordinatorToken] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );
});

/**
 * Record an item through the store.
 *
 * @param  TestCase  $case  The test case.
 * @param  string|null  $developer  The developer, or null for General.
 * @param  string  $ticket  The ticket.
 * @return int The item.
 */
function owe(TestCase $case, ?string $developer, string $ticket = 'robot-council/core#12'): int
{
    return $case->service(OwedItems::class)->record($case->coordinatorSession, $developer, $ticket, 'Which option?', 'Blocks two lanes.');
}

/**
 * Deliver a signed `issues` delivery.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $action  The action.
 * @param  string  $state  The issue's state.
 * @param  string|null  $label  The label the delivery is about.
 * @param  int  $second  When GitHub says it changed, as seconds past a fixed minute -- increasing
 *                       across one test, since an older report loses to a newer one.
 */
function issueDeliveryFor(TestCase $case, string $action, string $state, ?string $label = null, int $second = 0): void
{
    $secret = 'a-webhook-secret-long-enough-to-count';
    $case->container()->make('config')->set('robot-council.github.webhook_secret', $secret);

    $body = json_encode(array_filter([
        'action' => $action,
        'label' => $label === null ? null : ['name' => $label],
        'repository' => ['full_name' => 'robot-council/core'],
        'issue' => [
            'number' => 12, 'state' => $state, 'title' => 'Issue', 'labels' => [], 'body' => null,
            'updated_at' => sprintf('2030-01-01T00:00:%02dZ', $second), 'repository_url' => 'https://api.github.com/repos/robot-council/core',
        ],
    ]), JSON_THROW_ON_ERROR);

    $case->call('POST', route('robot-council.github.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'issues',
        'HTTP_X_GITHUB_DELIVERY' => bin2hex(random_bytes(8)),
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body)->assertOk();
}

it('records an item through the endpoint and settles it, asserted on the row', function (): void {
    $id = $this->machine($this->coordinatorToken)
        ->postJson(route('robot-council.owed.store'), ['developer' => 'OctoDev', 'ticket' => 'robot-council/core#12', 'question' => 'Which option?', 'why' => 'Blocks two lanes.'])
        ->assertCreated()
        ->json('id');

    $row = DB::table('robot_council_owed_items')->sole();

    // Stored as the fleet spells the login
    expect($row->developer)->toBe('octodev')
        ->and($row->recorded_by)->toBe($this->coordinatorSession->getKey())
        ->and($row->settled_at)->toBeNull();

    $this->machine($this->coordinatorToken)->deleteJson(route('robot-council.owed.settle', ['item' => $id]))->assertOk()->assertExactJson(['settled' => true]);

    expect(DB::table('robot_council_owed_items')->value('settled_because'))->toBe('coordinator');
});

it('refuses a session without coordinator:direct', function (): void {
    [, $token] = $this->startAgentSession($this->approveInstallation($this->developer));

    $this->machine($token)
        ->postJson(route('robot-council.owed.store'), ['ticket' => 'robot-council/core#12', 'question' => 'Q?', 'why' => 'W.'])
        ->assertForbidden();

    expect(DB::table('robot_council_owed_items')->count())->toBe(0);
});

it('refuses an unknown developer, a bare number, and blank or overlong text, at the edge and in the store', function (?string $developer, string $ticket, string $question): void {
    $this->machine($this->coordinatorToken)
        ->postJson(route('robot-council.owed.store'), array_filter(['developer' => $developer, 'ticket' => $ticket, 'question' => $question, 'why' => 'W.'], static fn (?string $v): bool => $v !== null))
        ->assertUnprocessable();

    expect(fn () => $this->service(OwedItems::class)->record($this->coordinatorSession, $developer, $ticket, $question, 'W.'))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('robot_council_owed_items')->count())->toBe(0);
})->with([
    'a developer the fleet has never seen' => ['stranger', 'robot-council/core#12', 'Q?'],
    'a bare #N' => [null, '#12', 'Q?'],
    'an overlong question' => [null, 'robot-council/core#12', str_repeat('q', OwedItems::MAX_TEXT + 1)],
]);

it('settles when its ticket closes, and when its hitl label is removed -- not when another label is', function (): void {
    $id = owe($this, 'octodev');

    issueDeliveryFor($this, 'unlabeled', 'open', 'documentation', second: 1);

    expect(DB::table('robot_council_owed_items')->where('id', $id)->value('settled_at'))->toBeNull();

    issueDeliveryFor($this, 'unlabeled', 'open', 'hitl', second: 2);

    expect(DB::table('robot_council_owed_items')->where('id', $id)->value('settled_because'))->toBe('hitl_removed');

    $closing = owe($this, null);
    issueDeliveryFor($this, 'closed', 'closed', second: 3);

    expect(DB::table('robot_council_owed_items')->where('id', $closing)->value('settled_because'))->toBe('ticket_closed');
});

it('shows General first, then a section per developer, and keeps a developer named General apart', function (): void {
    owe($this, 'octodev');
    owe($this, null);
    owe($this, 'General');

    $sections = array_map(static fn (array $section): ?string => $section['developer'], $this->service(LaneBoard::class)->read()['waiting']);

    expect($sections)->toBe([null, 'General', 'octodev']);
});

it('does not render an item whose developer the fleet no longer knows, though the item is still recorded', function (): void {
    owe($this, 'octodev');
    owe($this, null, 'robot-council/core#13');

    // The developer leaves the fleet after the item was recorded
    GithubIdentity::query()->where('github_login', 'octodev')->delete();

    expect(DB::table('robot_council_owed_items')->whereNull('settled_at')->count())->toBe(2);

    $html = Livewire::actingAs($this->developer)->test(Lanes::class)->html();

    preg_match_all('/data-owed-section="([^"]*)"/', $html, $sections);
    preg_match_all('/data-owed-item/', $html, $items);

    // One section and one item: the General one. The other was not moved into General either.
    expect($sections[1])->toBe(['General'])
        ->and($items[0])->toHaveCount(1)
        ->and($html)->toContain('robot-council/core#13')
        ->and($html)->not->toContain('robot-council/core#12');
});
