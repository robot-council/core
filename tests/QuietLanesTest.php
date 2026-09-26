<?php

declare(strict_types=1);

/**
 * Telling the coordinator when a build lane has authored nothing for an hour (#332).
 *
 * @command  vendor/bin/pest --compact tests/QuietLanesTest.php
 */

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\FleetFeed;
use RobotCouncil\Support\GateRuns;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\QuietLanes;
use RobotCouncil\Support\RoleRequests;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 77]);

    Carbon::setTestNow('2026-09-24 12:00:00');

    $installation = $this->approveInstallation($this->enrollDeveloper(4242, login: 'octodev'));
    $this->lane = $this->service(AgentSessions::class)->start($installation, 'robot-council/core', 'a')->owner;
    $this->bystander = $this->service(AgentSessions::class)->start($installation, 'robot-council/core', 'b')->owner;

    [$this->coordinatorSession] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );
});

/**
 * Run the check at a moment, minutes after the lane started.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $minutes  Minutes after 12:00.
 * @return int How many lanes it told about.
 */
function quietCheckAt(TestCase $case, int $minutes): int
{
    return $case->service(QuietLanes::class)->check(Carbon::parse('2026-09-24 12:00:00', 'UTC')->addMinutes($minutes));
}

/**
 * The quiet notices about a lane.
 *
 * @param  AgentSession  $lane  The lane.
 * @return int How many there are.
 */
function quietNotices(AgentSession $lane): int
{
    return FleetEvent::query()->where('type', FleetEventType::LaneQuiet->value)->get()
        ->filter(static fn (FleetEvent $event): bool => ($event->meta['session_id'] ?? null) === $lane->id)
        ->count();
}

/**
 * Act on a lane's behalf, at a moment.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $minutes  Minutes after 12:00.
 * @param  callable(): mixed  $act  What it does.
 */
function actAt(TestCase $case, int $minutes, callable $act): void
{
    Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC')->addMinutes($minutes));
    $act();
}

it('tells the coordinator once about a lane quiet past the hour, and not one that spoke at minute 59', function (): void {
    actAt($this, 59, fn () => $this->service(FleetEvents::class)->record(FleetEventType::Narration, $this->bystander, 'Still here.'));

    expect(quietCheckAt($this, 61))->toBeGreaterThanOrEqual(1)
        ->and(quietNotices($this->lane))->toBe(1)
        ->and(quietNotices($this->bystander))->toBe(0);

    // Checked again, and again later in the same quiet stretch: nothing more
    quietCheckAt($this, 66);
    quietCheckAt($this, 120);

    expect(quietNotices($this->lane))->toBe(1);
});

it('does not tell the coordinator about an ephemeral session gone quiet, which is not a lane (#424)', function (): void {
    $ephemeral = $this->service(AgentSessions::class)->start($this->lane->installation, 'robot-council/core', 'c', ephemeral: true)->owner;

    quietCheckAt($this, 61);

    // The lane started at the same moment and has authored as little, so it is the control
    expect(quietNotices($this->lane))->toBe(1)
        ->and(quietNotices($ephemeral))->toBe(0);
});

it('lets a heartbeat, a join and a received directive leave the clock running', function (string $what): void {
    actAt($this, 30, function () use ($what): void {
        match ($what) {
            'heartbeat' => AgentSession::query()->whereKey($this->lane->id)->update(['last_seen_at' => Carbon::now()]),
            'a join' => $this->service(FleetEvents::class)->record(FleetEventType::SessionJoined, $this->lane, 'Joined.'),
            default => $this->service(FleetEvents::class)->record(FleetEventType::Directive, $this->coordinatorSession, 'Stop.', ['targets' => [$this->lane->id]], true),
        };
    });

    quietCheckAt($this, 61);

    expect(quietNotices($this->lane))->toBe(1);
})->with(['heartbeat', 'a join', 'a received directive']);

it('lets each authored act reset the clock', function (string $what): void {
    actAt($this, 30, function () use ($what): void {
        match ($what) {
            'a narration' => $this->service(FleetEvents::class)->record(FleetEventType::Narration, $this->lane, 'Working on it.'),
            'a task transition' => (function (): void {
                $task = $this->service(Tasks::class)->create($this->lane, ['title' => 'Mine'], false);
                $this->service(Tasks::class)->transition($task->id, TaskTransition::Claim, $this->lane, false);
            })(),
            'a lock acquired' => $this->service(Locks::class)->acquire($this->lane, 'deploy', 60, false),
            default => (function (): void {
                $this->service(Locks::class)->acquire($this->bystander, 'deploy', 60, false);
                Carbon::setTestNow(Carbon::now()->addMinutes(5));
                $this->service(Locks::class)->acquire($this->lane, 'other', 60, false);
                $this->service(Locks::class)->release($this->lane, 'other', false);
            })(),
        };
    });

    // 61 minutes after the lane started, but under an hour since it acted
    quietCheckAt($this, 61);

    expect(quietNotices($this->lane))->toBe(0);
})->with(['a narration', 'a task transition', 'a lock acquired', 'a lock released']);

it("does not count another developer's act under a reused session id", function (): void {
    // An event recorded against this lane's id but another developer's key: not the lane speaking
    FleetEvent::query()->insert([
        'type' => FleetEventType::Narration->value, 'agent_session_id' => $this->lane->id, 'user_id' => 'someone-else',
        'body' => 'Not me.', 'posted_with_coordinator' => false, 'created_at' => '2026-09-24 12:40:00',
    ]);

    quietCheckAt($this, 61);

    expect(quietNotices($this->lane))->toBe(1);
});

it('leaves a gate alone while it holds no pull request, and tells about one quiet on a run', function (): void {
    $this->service(RoleRequests::class)->impose($this->lane, Role::Ci, 'test-administrator');

    quietCheckAt($this, 61);

    expect(quietNotices($this->lane))->toBe(0);

    $this->service(GateRuns::class)->start($this->lane->refresh(), 'robot-council/core#40');
    quietCheckAt($this, 70);

    expect(quietNotices($this->lane))->toBe(1);
});

it('reaches the coordinator and no other session', function (): void {
    quietCheckAt($this, 61);

    $sees = fn (AgentSession $reader): bool => collect($this->service(FleetFeed::class)->after($reader, 0, 200)['events'])
        ->contains(static fn (array $event): bool => $event['type'] === FleetEventType::LaneQuiet->value);

    expect($sees($this->coordinatorSession))->toBeTrue()
        ->and($sees($this->lane))->toBeFalse()
        ->and($sees($this->bystander))->toBeFalse();
});

it('schedules the check every five minutes', function (): void {
    $scheduled = collect($this->service(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'robot-council:quiet-lanes'))
        ->values();

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled->first() instanceof ScheduledEvent ? $scheduled->first()->expression : null)->toBe('*/5 * * * *');
});
