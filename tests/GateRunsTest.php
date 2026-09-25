<?php

declare(strict_types=1);

/**
 * Which pull request each gate is validating (#336).
 *
 * @command  vendor/bin/pest --compact tests/GateRunsTest.php
 */
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Access\Role;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\GateRuns;
use RobotCouncil\Support\LaneBoard;
use RobotCouncil\Support\RoleRequests;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $this->installation = $this->approveInstallation($this->developer);

    // A gate: a session an administrator put in the `ci` role
    $issued = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'gate');
    $this->service(RoleRequests::class)->impose($issued->owner, Role::Ci, 'test-administrator');
    $this->session = $issued->owner->refresh();
    $this->token = $issued->plainTextToken;
});

/**
 * An open pull request the fleet knows about.
 *
 * @param  int  $number  The pull request.
 * @param  bool  $draft  Whether it is a draft.
 */
function openPull(int $number, bool $draft = false): void
{
    GitHubItem::query()->insert([
        'repository' => 'robot-council/core', 'number' => $number, 'is_pull_request' => true, 'state' => 'open', 'draft' => $draft,
        'title' => 'Pull '.$number, 'labels' => '[]', 'github_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Deliver a signed `pull_request` delivery closing a pull request.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $number  The pull request.
 * @param  bool  $merged  Whether it merged.
 */
function closePull(TestCase $case, int $number, bool $merged): void
{
    $secret = 'a-webhook-secret-long-enough-to-count';
    $case->container()->make('config')->set('robot-council.github.webhook_secret', $secret);

    $body = json_encode([
        'action' => 'closed',
        'repository' => ['full_name' => 'robot-council/core'],
        'pull_request' => [
            'number' => $number, 'state' => 'closed', 'merged' => $merged, 'draft' => false, 'title' => 'Pull '.$number,
            'labels' => [], 'body' => null, 'updated_at' => '2030-01-01T00:00:00Z',
            'head' => ['ref' => 'feature/x', 'repo' => ['full_name' => 'robot-council/core']],
        ],
    ], JSON_THROW_ON_ERROR);

    $case->call('POST', route('robot-council.github.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'pull_request',
        'HTTP_X_GITHUB_DELIVERY' => 'close-'.$number,
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body)->assertOk();
}

it('records the pull request a gate reports, replaces it, and clears it', function (): void {
    $this->machine($this->token)
        ->postJson(route('robot-council.gates.run'), ['pull_request' => 'robot-council/core#40'])
        ->assertOk()
        ->assertExactJson(['pull_request' => 'robot-council/core#40', 'applied' => true]);

    $this->machine($this->token)->postJson(route('robot-council.gates.run'), ['pull_request' => 'robot-council/core#41'])->assertOk();

    expect($this->service(GateRuns::class)->running())->toBe([$this->session->id => 'robot-council/core#41']);

    $this->machine($this->token)->deleteJson(route('robot-council.gates.finish'))->assertOk()->assertExactJson(['cleared' => true]);

    expect(DB::table('robot_council_gate_runs')->count())->toBe(0);
});

it('refuses a session that is not a gate, and records nothing', function (): void {
    [$build, $buildToken] = $this->startAgentSession($this->installation);

    expect($build->role)->toBe(Role::Build);

    $this->machine($buildToken)
        ->postJson(route('robot-council.gates.run'), ['pull_request' => 'robot-council/core#40'])
        ->assertForbidden();

    expect(DB::table('robot_council_gate_runs')->count())->toBe(0);
});

it('refuses a pull request named without its repository', function (string $reference): void {
    $this->machine($this->token)->postJson(route('robot-council.gates.run'), ['pull_request' => $reference])->assertUnprocessable();

    expect(fn () => $this->service(GateRuns::class)->start($this->session, $reference))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('robot_council_gate_runs')->count())->toBe(0);
})->with(['#40', '40', 'core#40']);

it('ends a run when GitHub reports its pull request closed or merged', function (bool $merged): void {
    openPull(40);
    $this->service(GateRuns::class)->start($this->session, 'robot-council/core#40');

    closePull($this, 40, $merged);

    expect(DB::table('robot_council_gate_runs')->count())->toBe(0);
})->with(['merged' => [true], 'closed unmerged' => [false]]);

it('marks the pull request running, shows the gate on it, and counts the queue without it', function (): void {
    openPull(40);
    openPull(41);
    openPull(42, draft: true);
    $this->service(GateRuns::class)->start($this->session, 'robot-council/core#40');

    $board = $this->service(LaneBoard::class)->read();
    $states = array_column($board['pull_requests']['robot-council/core'], 'state', 'number');

    expect($states)->toBe([40 => 'running', 41 => 'queued', 42 => 'draft'])
        // Open, not a draft, and no gate on it: only 41
        ->and($board['queue_depth'])->toBe(['robot-council/core' => 1]);

    $row = collect($board['lanes']['robot-council/core'])->firstWhere('id', $this->session->id);

    expect($row['state'] ?? null)->toBe('Working')
        ->and($row['on_what'] ?? null)->toBe(['gate_pull_request' => 'robot-council/core#40'])
        ->and($row['is_gate'] ?? null)->toBeTrue();

    $html = Livewire::actingAs($this->developer)->test(Lanes::class)->html();

    // Captured outside the expectation: inside it, Rector rewrites `preg_match(...) === 1` into
    // `toMatch()` and the capture is lost
    $found = preg_match('/<span data-gate-run>(.*?)<\/span>/s', $html, $cell);
    $text = $found === 1 ? (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($cell[1]))) : '';

    expect($text)->toContain('validating robot-council/core#40 · 1 queued');
});

it('lets a gate end only its own run', function (): void {
    $this->service(GateRuns::class)->start($this->session, 'robot-council/core#40');

    $issued = $this->service(AgentSessions::class)->start($this->installation, 'robot-council/core', 'gate-2');
    $this->service(RoleRequests::class)->impose($issued->owner, Role::Ci, 'test-administrator');

    $this->machine($issued->plainTextToken)->deleteJson(route('robot-council.gates.finish'))->assertOk()->assertExactJson(['cleared' => false]);

    expect($this->service(GateRuns::class)->running())->toBe([$this->session->id => 'robot-council/core#40']);
});
