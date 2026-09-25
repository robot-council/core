<?php

declare(strict_types=1);

/**
 * The live sessions an agent can read (#325).
 *
 * @command  vendor/bin/pest --compact tests/LiveSessionsTest.php
 */

use Illuminate\Support\Carbon;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\SessionPresence;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77, 55, 56]);

    $installation = $this->approveInstallation($this->enrollDeveloper(4242, login: 'octodev'));
    [$this->session, $this->token] = $this->startAgentSession($installation);

    [$this->coordinatorSession, $this->coordinatorToken] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );
});

/**
 * A session for another developer, on its own machine.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $githubId  The developer, which must be one not yet enrolled.
 * @return AgentSession The session.
 */
function otherDevelopersSession(TestCase $case, int $githubId = 55): AgentSession
{
    [$session] = $case->startAgentSession(
        $case->approveInstallation($case->enrollDeveloper($githubId, login: $githubId === 55 ? 'elsewhere' : 'another'), machineLabel: 'far-box')
    );

    return $session;
}

/**
 * Read one page as a session.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $token  The reader's token.
 * @param  array<string, mixed>  $query  The filters and cursor.
 * @return array<array-key, mixed> The response body.
 */
function readLanes(TestCase $case, string $token, array $query = []): array
{
    $response = $case->machine($token)->getJson(route('robot-council.lanes.index', $query));

    $response->assertOk();

    return arrayValue($response->json());
}

/**
 * The session ids a page lists, in order.
 *
 * @param  array<array-key, mixed>  $page  The response body.
 * @return list<mixed> The ids.
 */
function laneIds(array $page): array
{
    return array_column(arrayValue($page['sessions'] ?? []), 'id');
}

/**
 * One session's row from a page.
 *
 * @param  array<array-key, mixed>  $page  The response body.
 * @param  int  $id  The session.
 * @return array<array-key, mixed> The row.
 */
function laneRow(array $page, int $id): array
{
    foreach (arrayValue($page['sessions'] ?? []) as $row) {
        if (arrayValue($row)['id'] === $id) {
            return arrayValue($row);
        }
    }

    throw new RuntimeException('Session '.$id.' is not on the page.');
}

it('lists every active and stale session with what identifies it, and no gone one', function (): void {
    $stale = otherDevelopersSession($this);
    $stale->forceFill([
        'status' => AgentSessionStatus::Stale,
        'repository' => 'robot-council/cli',
        'work_location' => 'slot-a',
        // Apart from every other timestamp on the row, so the wrong column cannot pass for it
        'last_seen_at' => Carbon::parse('2026-09-01 08:30:00', 'UTC'),
    ])->save();

    $gone = otherDevelopersSession($this, 56);
    $this->markSessionGone($gone);

    $page = readLanes($this, $this->token);

    expect(laneIds($page))->toBe([$stale->id, $this->coordinatorSession->id, $this->session->id])
        ->and(laneRow($page, $stale->id))->toMatchArray([
            'github_login' => 'elsewhere',
            'machine_label' => 'far-box',
            'role' => 'build',
            'repository' => 'robot-council/cli',
            'work_location' => 'slot-a',
            'status' => 'stale',
            'tasks' => [],
        ])
        ->and(laneRow($page, $stale->id)['last_seen_at'])->toBe('2026-09-01T08:30:00+00:00')
        ->and(laneRow($page, $stale->id)['harness'])->toBe($stale->installation->harness)
        ->and(laneRow($page, $stale->id)['harness'])->toBeString()->not->toBeEmpty()
        ->and(laneRow($page, $this->coordinatorSession->id)['role'])->toBe('coordinator')
        ->and($page['cursor'])->toBeNull();
});

it('lists a session whose join has been pruned from the feed', function (): void {
    $session = otherDevelopersSession($this);

    // The control: the join is there to be pruned, so the delete below removes something
    expect(FleetEvent::query()->where('type', FleetEventType::SessionJoined->value)->where('agent_session_id', $session->id)->count())->toBe(1);

    FleetEvent::query()->where('type', FleetEventType::SessionJoined->value)->delete();

    expect(laneIds(readLanes($this, $this->token)))->toContain($session->id);
});

it('lists the tasks each session holds, with words only where the reader may act on them', function (): void {
    $tasks = $this->service(Tasks::class);
    $other = otherDevelopersSession($this);

    $theirs = $tasks->create($other, ['title' => 'Their secret', 'description' => 'Do this'], withCoordinator: false);
    $tasks->transition($theirs->id, TaskTransition::Claim, $other, asCoordinator: false);

    // Finished work is not held, although `claimed_by` still names the session
    $done = $tasks->create($other, ['title' => 'Finished'], withCoordinator: false);
    $tasks->transition($done->id, TaskTransition::Claim, $other, asCoordinator: false);
    $tasks->transition($done->id, TaskTransition::Complete, $other, asCoordinator: false);

    $mine = $this->createClaimedTask('My own');

    $asBuild = readLanes($this, $this->token);

    expect(laneRow($asBuild, $other->id)['tasks'])->toBe([
        ['id' => $theirs->id, 'status' => 'claimed', 'readable' => false, 'title' => null, 'description' => null],
    ])->and(laneRow($asBuild, $this->session->id)['tasks'])->toBe([
        ['id' => $mine, 'status' => 'claimed', 'readable' => true, 'title' => 'My own', 'description' => null],
    ]);

    // Work a coordinator opened to the fleet is readable by any build session, as `task_list` has it
    $opened = $tasks->create($this->coordinatorSession, ['title' => 'For anyone'], withCoordinator: true);
    $tasks->transition($opened->id, TaskTransition::Claim, $other, asCoordinator: false);

    expect(arrayValue(laneRow(readLanes($this, $this->token), $other->id)['tasks'])[1] ?? null)->toMatchArray([
        'id' => $opened->id, 'readable' => true, 'title' => 'For anyone',
    ]);

    // A coordinator reads every task's words, as it does in `task_list`
    expect(arrayValue(laneRow(readLanes($this, $this->coordinatorToken), $other->id)['tasks'])[0] ?? null)->toBe(
        ['id' => $theirs->id, 'status' => 'claimed', 'readable' => true, 'title' => 'Their secret', 'description' => 'Do this'],
    );
});

it('filters by repository and by role, and lists everything when neither is given', function (): void {
    $this->session->forceFill(['repository' => 'robot-council/core'])->save();
    $other = otherDevelopersSession($this);
    $other->forceFill(['repository' => 'robot-council/cli'])->save();

    expect(laneIds(readLanes($this, $this->token, ['repository' => 'robot-council/cli'])))->toBe([$other->id])
        // GitHub compares repository names without case, and so does the filter
        ->and(laneIds(readLanes($this, $this->token, ['repository' => 'Robot-Council/CLI'])))->toBe([$other->id])
        ->and(laneIds(readLanes($this, $this->token, ['role' => Role::Coordinator->value])))->toBe([$this->coordinatorSession->id])
        ->and(laneIds(readLanes($this, $this->token, ['repository' => 'robot-council/core', 'role' => 'build'])))->toBe([$this->session->id])
        ->and(laneIds(readLanes($this, $this->token)))->toBe([$other->id, $this->coordinatorSession->id, $this->session->id]);
});

it('refuses a filter no session could hold', function (array $query): void {
    $this->machine($this->token)->getJson(route('robot-council.lanes.index', $query))->assertUnprocessable();
})->with([
    'an unknown role' => [['role' => 'admin']],
    'a repository with no owner' => [['repository' => 'core']],
    'a page past the bound' => [['limit' => 101]],
]);

it('shows a session that went stale on the next read, with nobody reading the feed', function (): void {
    $other = otherDevelopersSession($this);

    Carbon::setTestNow(Carbon::now()->addMinutes(10));

    $this->service(SessionPresence::class)->sweep();

    // The reader's own request brings it back to active, which is what makes the other the control
    expect(laneRow(readLanes($this, $this->token), $other->id)['status'])->toBe('stale')
        ->and(laneRow(readLanes($this, $this->token), $this->session->id)['status'])->toBe('active');
});

it('pages newest first and says when there is no next page', function (): void {
    $other = otherDevelopersSession($this);

    $first = readLanes($this, $this->token, ['limit' => 2]);
    $second = readLanes($this, $this->token, ['limit' => 2, 'after' => $first['cursor']]);

    expect(laneIds($first))->toBe([$other->id, $this->coordinatorSession->id])
        ->and($first['cursor'])->toBe($this->coordinatorSession->id)
        ->and(laneIds($second))->toBe([$this->session->id])
        ->and($second['cursor'])->toBeNull();

    // A page exactly as long as what is left offers no cursor to an empty page
    expect(readLanes($this, $this->token, ['limit' => 3])['cursor'])->toBeNull();
});
