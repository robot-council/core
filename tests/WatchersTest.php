<?php

declare(strict_types=1);

/**
 * The bridge watcher's own heartbeat, apart from the session's contact (#337).
 *
 * @command  vendor/bin/pest --compact tests/WatchersTest.php
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Watchers;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $issued = $this->service(AgentSessions::class)->start($this->approveInstallation($this->developer), 'robot-council/core', 'a');
    [$this->session, $this->token] = [$issued->owner, $issued->plainTextToken];

    Carbon::setTestNow('2026-09-24 12:00:00');
});

/**
 * The session's watcher column, as the row holds it.
 *
 * @param  int  $id  The session.
 * @return mixed The value.
 */
function watcherSeenAt(int $id): mixed
{
    return DB::table('robot_council_agent_sessions')->where('id', $id)->value('watcher_seen_at');
}

it('records the watcher heartbeat apart from the session contact, and an agent request does not refresh it', function (): void {
    // The agent's own heartbeat, and a tool-shaped request: session contact, never the watcher
    $this->machine($this->token)->postJson(route('robot-council.agent.heartbeat'))->assertOk();
    $this->machine($this->token)->getJson(route('robot-council.tasks.index'))->assertOk();

    expect(watcherSeenAt($this->session->id))->toBeNull();

    $this->machine($this->token)->postJson(route('robot-council.agent.watcher'))
        ->assertOk()
        ->assertExactJson(['session_id' => $this->session->id, 'watching' => true]);

    expect(Carbon::parse((string) watcherSeenAt($this->session->id))->format('Y-m-d H:i:s'))->toBe('2026-09-24 12:00:00');

    // And later agent requests leave it where the watcher put it
    Carbon::setTestNow('2026-09-24 12:05:00');
    $this->machine($this->token)->postJson(route('robot-council.agent.heartbeat'))->assertOk();

    expect(Carbon::parse((string) watcherSeenAt($this->session->id))->format('H:i:s'))->toBe('12:00:00');
});

it('reads a watcher that never reported as absent, and a reporting one by its age', function (?string $seenAt, string $state, ?int $age): void {
    expect($this->service(Watchers::class)->reading($seenAt, Carbon::parse('2026-09-24 12:00:00', 'UTC')))
        ->toBe(['state' => $state, 'age_seconds' => $age]);
})->with([
    'never reported' => [null, 'absent', null],
    'just now' => ['2026-09-24 11:59:30', 'alive', 30],
    'at the edge of alive' => ['2026-09-24 11:58:30', 'alive', 90],
    'stopped' => ['2026-09-24 11:58:29', 'stale', 91],
    'fifteen minutes ago' => ['2026-09-24 11:45:00', 'stale', 900],
    'too long ago to say' => ['2026-09-24 11:44:59', 'unknown', 901],
]);

it('shows the reading in the watcher column of the lane board', function (): void {
    $cell = function (): string {
        $html = Livewire::actingAs($this->developer)->test(Lanes::class)->html();
        preg_match('/<td data-watcher="([a-z]+)">(.*?)<\/td>/s', $html, $found);

        return ($found[1] ?? '').': '.trim((string) preg_replace('/\s+/', ' ', strip_tags($found[2] ?? '')));
    };

    expect($cell())->toBe('absent: absent');

    $this->machine($this->token)->postJson(route('robot-council.agent.watcher'))->assertOk();
    Carbon::setTestNow('2026-09-24 12:00:20');

    expect($cell())->toBe('alive: alive 20s ago');

    Carbon::setTestNow('2026-09-24 12:03:00');

    expect($cell())->toBe('stale: stale 180s');
});
