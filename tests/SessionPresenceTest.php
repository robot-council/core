<?php

declare(strict_types=1);

/**
 * Which agent sessions are alive: what records contact, what the sweep changes, what it refuses to
 * change, and what ending a session releases.
 *
 * Time is frozen at a fixed instant rather than moved relative to a real clock. `last_seen_at` is
 * stored to the second, so a threshold crossed at x.8 seconds is a test that fails on one CI cell
 * in eight and reads as flake.
 *
 * @command  vendor/bin/pest --compact tests/SessionPresenceTest.php
 */

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery\MockInterface;
use RobotCouncil\Events\SessionGone;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\SessionPresence;
use RobotCouncil\Support\SessionReleases;

/**
 * The instant every test in this file starts from.
 */
const STARTED_AT = '2026-01-01 12:00:00';

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
    $this->credential = $this->installationCredential($this->installation);

    $this->travelTo(Carbon::parse(STARTED_AT));
});

/**
 * How many events of one kind the feed holds.
 *
 * @param  FleetEventType  $type  The kind to count.
 * @return int The number recorded.
 */
function eventsOfType(FleetEventType $type): int
{
    return FleetEvent::query()->where('type', $type->value)->count();
}

/**
 * The one event of a kind the feed holds.
 *
 * @param  FleetEventType  $type  The kind to read.
 * @return FleetEvent The event.
 */
function theEventOfType(FleetEventType $type): FleetEvent
{
    return FleetEvent::query()->where('type', $type->value)->sole();
}

/**
 * Move a session's row without telling the instance, as a sweep committing mid-request does.
 *
 * Deliberately a raw update rather than the presence store: a test that moved the row with the code
 * under test would agree with it however wrong both were.
 *
 * @param  AgentSession  $session  The session whose row to move.
 * @param  AgentSessionStatus  $status  The status to write.
 */
function moveRowBehindTheInstance(AgentSession $session, AgentSessionStatus $status): void
{
    DB::table('robot_council_agent_sessions')
        ->where('id', $session->getKey())
        ->update(['status' => $status->value]);
}

it('records contact on every authenticated agent request', function (string $route, string $method): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    expect(dateValue($session->last_seen_at)->toDateTimeString())->toBe(STARTED_AT);

    $this->travelTo(Carbon::parse('2026-01-01 12:03:00'));

    $this->machine($token)->json($method, route($route))->assertOk();

    expect(dateValue($session->refresh()->last_seen_at)->toDateTimeString())->toBe('2026-01-01 12:03:00')

        // Contact on its own is not a change to report: the feed holds the session's enrollment
        // and nothing else
        ->and(FleetEvent::query()->count())->toBe(1);
})->with([
    'reading its own session' => ['robot-council.agent.session', 'GET'],
    'reading the feed' => ['robot-council.events.index', 'GET'],
    'the heartbeat' => ['robot-council.agent.heartbeat', 'POST'],
]);

it('does not record contact for a request it refuses', function (string $refuse, int $status): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    if ($refuse === 'installation') {
        // Expired rather than revoked: revoking deletes the tokens, so the guard would refuse
        // before the middleware ran and this would pass without the middleware's own check
        $this->installation->forceFill(['expires_at' => Carbon::parse('2026-01-01 11:59:00')])->save();
    } else {
        $this->setAccessLists(developers: []);
    }

    $this->travelTo(Carbon::parse('2026-01-01 12:03:00'));

    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertStatus($status);

    // The contact write sits after every check for this reason: a credential that is refused must
    // not be able to keep a session alive
    expect(dateValue($session->refresh()->last_seen_at)->toDateTimeString())->toBe(STARTED_AT);
})->with([
    'an installation past its maximum age' => ['installation', 401],
    'a developer off the access list' => ['allowlist', 403],
]);

it('refuses a session carrying the transient token a browser session gets', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    // Sanctum's guard tries the `web` guard before it reads a bearer token, so a principal signed
    // in there arrives as itself carrying a `TransientToken`, whose `can()` answers true to every
    // ability that was ever named. The principal-class check passes here; only the token check
    // does not, which makes this the one case that exercises it.
    $this->actingAs($session, 'web')
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();

    expect(dateValue($session->refresh()->last_seen_at)->toDateTimeString())->toBe(STARTED_AT);
});

it('states both thresholds as durations on the heartbeat', function (): void {
    [, $token] = $this->startAgentSession($this->installation);

    $this->machine($token)->postJson(route('robot-council.agent.heartbeat'))->assertOk()->assertExactJson([
        'session_id' => 1,
        'status' => 'active',
        'stale_in' => 300,
        'gone_in' => 1800,
    ]);
});

it('states the configured thresholds, not the default ones', function (): void {
    config()->set('robot-council.presence.stale_after_minutes', 2);
    config()->set('robot-council.presence.gone_after_minutes', 7);

    [, $token] = $this->startAgentSession($this->installation);

    $this->machine($token)->postJson(route('robot-council.agent.heartbeat'))
        ->assertOk()
        ->assertJsonPath('stale_in', 120)
        ->assertJsonPath('gone_in', 420);
});

it('refuses a heartbeat from anything but a live agent session', function (string $as): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    // One case per test rather than four in sequence. `actingAs()` caches a principal on the guard
    // and `withHeaders()` persists for the rest of the test, so a later case in the same test body
    // re-runs the earlier one's request and passes without ever exercising its own.
    if ($as === 'an installation credential') {
        $this->machine($this->credential);
    }

    if ($as === 'a signed-in human') {
        $this->actingAs($this->developer, 'web');
    }

    if ($as === 'a gone session') {
        $this->markSessionGone($session);
        $this->machine($token);
    }

    $this->postJson(route('robot-council.agent.heartbeat'))->assertUnauthorized();
})->with([
    'an installation credential',
    'a signed-in human',
    'a gone session',
    'nothing at all',
]);

it('marks a session stale once it has been quiet for the configured minutes', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    // Pins the cutoff arithmetic: at one second short of the threshold neither `<` nor `<=` fires,
    // so what this half rules out is `subMinutes(5)` having been computed wrongly
    $this->travelTo(Carbon::parse('2026-01-01 12:04:59'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 0])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Active);

    // And this half is what tells `<` from `<=`, exactly on the boundary
    $this->travelTo(Carbon::parse('2026-01-01 12:05:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 1, 'gone' => 0])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Stale)

        // The contact time is what the next threshold is measured from, so the sweep must not
        // touch it: a transition that reset it would restart the clock deciding when it goes
        ->and(dateValue($session->last_seen_at)->toDateTimeString())->toBe(STARTED_AT);

    $event = theEventOfType(FleetEventType::SessionStale);

    expect($event->body)->toBe('claude-code on workbench stopped answering.')
        ->and(orderedMeta($event->meta))->toBe(orderedMeta([
            'installation_id' => $this->installation->getKey(),
            'quiet_since' => Carbon::parse(STARTED_AT)->toIso8601String(),
        ]));
});

it('ends a session that has been quiet past the gone threshold, straight from active', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:30:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 1])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Gone)

        // One event for one change. A session silent past both thresholds is not marked stale on
        // the way past, which would put two changes in the feed for one thing happening.
        ->and(eventsOfType(FleetEventType::SessionStale))->toBe(0)

        // Its tokens go with it, and the one it holds is refused
        ->and(PersonalAccessToken::query()->where('tokenable_type', new AgentSession()->getMorphClass())->count())->toBe(0);

    $event = theEventOfType(FleetEventType::SessionGone);

    expect($event->body)->toBe('claude-code on workbench ended.')
        ->and(orderedMeta($event->meta))->toBe(orderedMeta([
            'installation_id' => $this->installation->getKey(),
            'reason' => SessionPresence::TIMEOUT,
        ]));

    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertUnauthorized();
});

it('brings a stale session back on its next request, once', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:06:00'));
    $this->service(SessionPresence::class)->sweep();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Stale);

    $this->travelTo(Carbon::parse('2026-01-01 12:07:00'));

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('status', 'active');

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Active)
        ->and(dateValue($session->last_seen_at)->toDateTimeString())->toBe('2026-01-01 12:07:00');

    $event = theEventOfType(FleetEventType::SessionResumed);

    expect($event->body)->toBe('claude-code on workbench is answering again.')
        ->and(orderedMeta($event->meta))->toBe(orderedMeta(['installation_id' => $this->installation->getKey()]));

    // A second request is contact, not a second change
    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk();

    expect(eventsOfType(FleetEventType::SessionResumed))->toBe(1);
});

it('resumes a session the sweep marked stale after this request had already read it', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    // The window every agent request has. Sanctum's guard materializes the session, then the rate
    // limiter and two more checks run, and a sweep can commit in between.
    moveRowBehindTheInstance($session, AgentSessionStatus::Stale);

    expect($session->status)->toBe(AgentSessionStatus::Active);

    $this->travelTo(Carbon::parse('2026-01-01 12:07:00'));

    $this->service(SessionPresence::class)->sighted($session);

    // Reading the instance instead of the row leaves the status `stale` with a fresh contact time,
    // which is unreachable by both sweep passes: the stale pass takes only active rows, and the
    // gone pass only an old contact time that every later request pushes further away. A live
    // process would report stale to the whole fleet until it died.
    expect($session->refresh()->status)->toBe(AgentSessionStatus::Active)
        ->and(dateValue($session->last_seen_at)->toDateTimeString())->toBe('2026-01-01 12:07:00')
        ->and(eventsOfType(FleetEventType::SessionResumed))->toBe(1);
});

it('writes nothing for a session that went while this request was in flight', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    moveRowBehindTheInstance($session, AgentSessionStatus::Gone);

    $this->travelTo(Carbon::parse('2026-01-01 12:07:00'));

    $this->service(SessionPresence::class)->sighted($session);

    // Gone is final. Contact must not move its clock, or a session whose claims were released
    // would read as recently alive.
    expect($session->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and(dateValue($session->last_seen_at)->toDateTimeString())->toBe(STARTED_AT)
        ->and(eventsOfType(FleetEventType::SessionResumed))->toBe(0);
});

it('brings a stale session back when its bridge renews the token', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:06:00'));
    $this->service(SessionPresence::class)->sweep();

    $this->travelTo(Carbon::parse('2026-01-01 12:07:00'));

    // Renewal runs on the installation guard, so it never passes through the agent middleware. A
    // bare write to `last_seen_at` here would leave a stale row with a fresh contact time.
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertOk();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Active)
        ->and(eventsOfType(FleetEventType::SessionResumed))->toBe(1);
});

it("leaves a session active when contact lands between the sweep's read and its write", function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    $injected = 0;

    // Fired after the sweep's read of the candidates has returned and before it writes anything,
    // which is the window a real request lands in. Filtered to selects against the sessions table:
    // a listener that fired on the first query of any kind would land somewhere else entirely,
    // because the rate limiter's cache store alone can issue a dozen queries first.
    DB::listen(function (QueryExecuted $query) use (&$injected, $session): void {
        if ($injected > 0 || ! str_contains($query->sql, 'robot_council_agent_sessions')) {
            return;
        }

        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }

        $injected++;

        DB::table('robot_council_agent_sessions')
            ->where('id', $session->getKey())
            ->update(['last_seen_at' => Carbon::now()]);
    });

    $swept = $this->service(SessionPresence::class)->sweep();

    // The instrument fired: without this the assertions below would pass on a sweep that read
    // nothing, which is what a silently broken filter looks like
    expect($injected)->toBe(1)
        ->and($swept)->toBe(['stale' => 0, 'gone' => 0])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Active)
        ->and(eventsOfType(FleetEventType::SessionGone))->toBe(0);
});

it('ends that same session when nothing lands in between', function (): void {
    // The control for the injection test above: same fixture, same clock, no listener. Without it
    // an assertion that the session is still active proves nothing about the injection.
    [$session] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 1])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Gone);
});

it("leaves a session active when contact lands inside the stale pass's window", function (): void {
    // The same injection against the other pass. The gone pass reads first, so a fixture that is
    // only stale-eligible makes the stale pass's read the first one the filter matches.
    [$session] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:06:00'));

    $injected = 0;

    DB::listen(function (QueryExecuted $query) use (&$injected, $session): void {
        if ($injected > 0 || ! str_contains($query->sql, 'robot_council_agent_sessions')) {
            return;
        }

        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }

        $injected++;

        DB::table('robot_council_agent_sessions')
            ->where('id', $session->getKey())
            ->update(['last_seen_at' => Carbon::now()]);
    });

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 0])
        ->and($injected)->toBe(1)
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Active)
        ->and(eventsOfType(FleetEventType::SessionStale))->toBe(0);
});

it('dispatches SessionGone once for each session that goes, and never twice', function (): void {
    Event::fake([SessionGone::class]);

    [$first] = $this->startAgentSession($this->installation);
    [$second] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 2]);

    // A second sweep finds them already gone, so it changes nothing and says nothing
    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 0]);

    Event::assertDispatchedTimes(SessionGone::class, 2);

    foreach ([$first, $second] as $session) {
        Event::assertDispatched(
            SessionGone::class,
            fn (SessionGone $event): bool => $event->session->getKey() === $session->getKey()
                && $event->reason === SessionPresence::TIMEOUT
        );
    }

    expect(eventsOfType(FleetEventType::SessionGone))->toBe(2);
});

it('dispatches SessionGone only once the transaction that ended the session has committed', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $openTransactions = null;
    $statusWhenHeard = null;

    Event::listen(function (SessionGone $event) use (&$openTransactions, &$statusWhenHeard): void {
        $openTransactions = DB::transactionLevel();

        $statusWhenHeard = AgentSession::query()->whereKey($event->session->getKey())->value('status');
    });

    $this->service(SessionPresence::class)->end($session);

    // A listener that ran inside the transaction could release a claim that a rollback then
    // un-ended, and #25 and #26 register exactly that kind of listener
    expect($openTransactions)->toBe(0)
        ->and($statusWhenHeard)->toBe(AgentSessionStatus::Gone);
});

it('runs every registered release step on every sweep, including one that changed nothing', function (): void {
    $order = [];

    $releases = $this->service(SessionReleases::class);

    $releases->register(function () use (&$order): void {
        $order[] = 'tasks';
    });

    $releases->register(function () use (&$order): void {
        $order[] = 'locks';
    });

    $swept = $this->service(SessionPresence::class)->sweep();
    $this->service(SessionPresence::class)->sweep();

    // Both steps, both sweeps, in the order they were registered -- and the sweeps had nothing to
    // mark, which is the case the steps exist for: a release a missed `SessionGone` left undone is
    // picked up by the next sweep, not by the next session that happens to end.
    expect($order)->toBe(['tasks', 'locks', 'tasks', 'locks'])
        ->and($swept)->toBe(['stale' => 0, 'gone' => 0]);
});

it('lets no release step suppress another, and still fails the sweep', function (): void {
    Log::spy();

    $ran = false;

    $releases = $this->service(SessionReleases::class);

    $releases->register(function (): never {
        throw new RuntimeException('releasing tasks failed');
    });

    $releases->register(function () use (&$ran): void {
        $ran = true;
    });

    // #25 releases tasks and #26 releases locks, and they are independent subsystems. A bare loop
    // would let the first one's failure stop the second on every sweep forever, so every gone
    // session's locks would be held with nothing reporting it.
    expect(fn (): array => $this->service(SessionPresence::class)->sweep())
        ->toThrow(RuntimeException::class, 'releasing tasks failed');

    expect($ran)->toBeTrue();

    // Through the spy instance rather than the facade: `shouldHaveReceived()` is Mockery's, and
    // the facade does not declare it
    $logger = Log::getFacadeRoot();

    expect($logger)->toBeInstanceOf(MockInterface::class);

    if ($logger instanceof MockInterface) {
        $logger->shouldHaveReceived('error')->once();
    }
});

it('ends a session through the installation credential, and releases its tokens', function (): void {
    Event::fake([SessionGone::class]);

    [$session, $token] = $this->startAgentSession($this->installation);

    $this->machine($this->credential)
        ->deleteJson(route('robot-council.sessions.end', ['session' => $session->getKey()]))
        ->assertOk()
        ->assertExactJson(['session_id' => $session->getKey(), 'status' => 'gone']);

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and(orderedMeta(theEventOfType(FleetEventType::SessionGone)->meta))->toBe(orderedMeta([
            'installation_id' => $this->installation->getKey(),
            'reason' => SessionPresence::ENDED,
        ]));

    Event::assertDispatchedTimes(SessionGone::class, 1);

    Event::assertDispatched(
        SessionGone::class,
        fn (SessionGone $event): bool => $event->reason === SessionPresence::ENDED
    );

    // The session's own token stops working, and the installation cannot renew it back into service
    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertUnauthorized();

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertStatus(409);
});

it('ends a session that had already gone stale', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:06:00'));
    $this->service(SessionPresence::class)->sweep();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Stale);

    $this->machine($this->credential)
        ->deleteJson(route('robot-council.sessions.end', ['session' => $session->getKey()]))
        ->assertOk();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and(eventsOfType(FleetEventType::SessionGone))->toBe(1);
});

it('answers the same way when a session is ended twice, and dispatches nothing the second time', function (): void {
    Event::fake([SessionGone::class]);

    [$session] = $this->startAgentSession($this->installation);

    $route = route('robot-council.sessions.end', ['session' => $session->getKey()]);

    $this->machine($this->credential)->deleteJson($route)->assertOk();

    // A bridge that is already exiting has nothing useful to do with a 409, and the statement it
    // is making -- this session is over -- is true either way
    $this->machine($this->credential)->deleteJson($route)->assertOk()->assertJsonPath('status', 'gone');

    Event::assertDispatchedTimes(SessionGone::class, 1);

    expect(eventsOfType(FleetEventType::SessionGone))->toBe(1);
});

it("refuses to end another installation's session", function (): void {
    $other = $this->approveInstallation($this->developer, machineLabel: 'laptop');

    [$session] = $this->startAgentSession($other);

    $this->machine($this->credential)
        ->deleteJson(route('robot-council.sessions.end', ['session' => $session->getKey()]))
        ->assertForbidden();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Active)
        ->and(eventsOfType(FleetEventType::SessionGone))->toBe(0);

    // The installation that started it can
    $this->machine($this->installationCredential($other))
        ->deleteJson(route('robot-council.sessions.end', ['session' => $session->getKey()]))
        ->assertOk();
});

it('answers 404 for a session that cannot be ended because it does not exist', function (string $id): void {
    $this->machine($this->credential)
        ->deleteJson(route('robot-council.sessions.end', ['session' => $id]))
        ->assertNotFound();
})->with([
    'a number nobody used' => ['987654'],

    // SQLite matches no rows and Postgres raises `22P02 invalid input syntax for bigint`, which
    // without the route constraint is a 500 on the database CI runs
    'not a number at all' => ['not-a-number'],

    // And a number no bigint can hold, which `whereNumber` would have admitted: Postgres answers
    // `22003 value out of range` where SQLite again matches no rows
    'larger than a bigint' => ['99999999999999999999999'],
]);

it('answers 404 rather than a database error for an overlong id on renew', function (): void {
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => '99999999999999999999999']))
        ->assertNotFound();
});

it('refuses a session token on the end endpoint', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    // The credential that may end a session is the installation's, not the session's own
    $this->machine($token)
        ->deleteJson(route('robot-council.sessions.end', ['session' => $session->getKey()]))
        ->assertUnauthorized();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Active);
});

it('writes exactly one event for each status change across a whole session', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:06:00'));
    $this->service(SessionPresence::class)->sweep();

    $this->travelTo(Carbon::parse('2026-01-01 12:07:00'));
    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk();

    $this->travelTo(Carbon::parse('2026-01-01 12:13:00'));
    $this->service(SessionPresence::class)->sweep();

    $this->travelTo(Carbon::parse('2026-01-01 12:38:00'));
    $this->service(SessionPresence::class)->sweep();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Gone);

    $recorded = FleetEvent::query()->orderBy('id')->pluck('type')->all();

    expect($recorded)->toBe([
        FleetEventType::SessionJoined,
        FleetEventType::SessionStale,
        FleetEventType::SessionResumed,
        FleetEventType::SessionStale,
        FleetEventType::SessionGone,
    ]);
});

it('names the states and the events the same way on the wire as in the database', function (): void {
    // Both are contract. The status is a stored column value and reaches an agent in the session
    // and heartbeat responses; the event types are what an agent matches on when it reads the feed.
    // Every other assertion in this file compares an enum with itself and would not notice.
    expect(AgentSessionStatus::Active->value)->toBe('active')
        ->and(AgentSessionStatus::Stale->value)->toBe('stale')
        ->and(AgentSessionStatus::Gone->value)->toBe('gone')
        ->and(FleetEventType::SessionStale->value)->toBe('session.stale')
        ->and(FleetEventType::SessionResumed->value)->toBe('session.resumed')
        ->and(FleetEventType::SessionGone->value)->toBe('session.gone');
});

it('keeps the warning state reachable however the thresholds are configured', function (): void {
    config()->set('robot-council.presence.stale_after_minutes', 10);
    config()->set('robot-council.presence.gone_after_minutes', 2);

    [$session] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:09:00'));

    // Nine minutes is past the configured gone threshold and short of the stale one. Read as
    // written, the session would be gone without ever having been stale.
    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 0])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Active);

    $this->travelTo(Carbon::parse('2026-01-01 12:10:00'));

    // Clamping gone up to *equal* stale would end it here, because the gone pass runs first --
    // so the state an operator is meant to see before anything is released would never appear
    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 1, 'gone' => 0])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Stale);

    $this->travelTo(Carbon::parse('2026-01-01 12:11:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 1])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Gone);
});

it('marks no more than the configured number of sessions in one pass', function (): void {
    config()->set('robot-council.presence.max_per_sweep', 2);

    $sessions = collect(range(1, 3))->map(fn (): AgentSession => $this->startAgentSession($this->installation)[0]);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    // A fleet that went silent at once is bounded rather than held as one batch, because every
    // session it ends takes the feed's single writer lock in turn. The ceiling is per pass, so the
    // third session is not ended here -- but it is still silent, so the stale pass marks it.
    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 1, 'gone' => 2]);

    // What is left over is not lost: it is a minute older when the next run reads it
    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 1])
        ->and($sessions->filter(fn (AgentSession $session): bool => $session->refresh()->hasGone())->count())->toBe(3);
});

it('leaves a session that has already gone alone', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $this->service(SessionPresence::class)->end($session);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 0])
        ->and(eventsOfType(FleetEventType::SessionGone))->toBe(1);
});

it('reports what it marked, through the command', function (): void {
    // Two stale and one gone, so the two counts differ: with one of each, swapping the command's
    // arguments prints a byte-identical line
    [$gone] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:26:00'));

    [$firstStale] = $this->startAgentSession($this->installation);
    [$secondStale] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    expect(Artisan::call('robot-council:sweep-sessions'))->toBe(0)
        ->and(Artisan::output())->toContain('Marked 2 session(s) stale and 1 gone.')
        ->and($gone->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and($firstStale->refresh()->status)->toBe(AgentSessionStatus::Stale)
        ->and($secondStale->refresh()->status)->toBe(AgentSessionStatus::Stale);
});

it('stores the contact time as a column that cannot be null', function (): void {
    $column = collect(Schema::getColumns('robot_council_agent_sessions'))
        ->firstWhere('name', 'last_seen_at');

    // Nullable would exempt a row from both cutoffs, because neither comparison matches null --
    // a session nothing could ever mark stale or gone
    expect($column)->toBeArray()
        ->and(arrayValue($column)['nullable'])->toBeFalse();

    $index = collect(Schema::getIndexes('robot_council_agent_sessions'))
        ->first(fn (mixed $index): bool => is_array($index) && $index['columns'] === ['status', 'last_seen_at']);

    expect($index)->not->toBeNull();
});

it('schedules the sweep every minute', function (): void {
    $scheduled = collect($this->service(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'robot-council:sweep-sessions'))
        ->values();

    expect($scheduled)->toHaveCount(1);

    $sweep = $scheduled->first();

    expect($sweep)->toBeInstanceOf(ScheduledEvent::class)
        ->and($sweep instanceof ScheduledEvent ? $sweep->expression : null)->toBe('* * * * *');
});

it('adds nothing to the schedule when the host turns the sweep off', function (): void {
    // Set before the application boots: the provider reads it as it registers the schedule, so a
    // value set in a test body would arrive after the decision it is meant to change
    $this->rebootWith('robot-council.schedule.sweep_sessions', false);

    $scheduled = collect($this->service(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'robot-council:sweep-sessions'));

    expect($scheduled)->toBeEmpty()

        // And the prune, which has its own switch, is still there
        ->and(collect($this->service(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'robot-council:prune-device-codes'))
        )->toHaveCount(1);
});

it('records a revocation as its own reason, and revokes only once', function (): void {
    Event::fake([SessionGone::class]);

    [$session, $token] = $this->startAgentSession($this->installation);

    expect(Artisan::call('robot-council:revoke-session', ['session' => $session->getKey()]))->toBe(0)
        ->and(Artisan::output())->toContain('1 token(s) deleted');

    // An admin revoking is not a process exiting and not a timeout, and the feed says which it was
    expect(orderedMeta(theEventOfType(FleetEventType::SessionGone)->meta))->toBe(orderedMeta([
        'installation_id' => $this->installation->getKey(),
        'reason' => SessionPresence::REVOKED,
    ]));

    Event::assertDispatchedTimes(SessionGone::class, 1);

    Event::assertDispatched(
        SessionGone::class,
        fn (SessionGone $event): bool => $event->reason === SessionPresence::REVOKED
    );

    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertUnauthorized();

    // Revoking a session that has already gone changes nothing and says nothing a second time
    expect(Artisan::call('robot-council:revoke-session', ['session' => $session->getKey()]))->toBe(0)
        ->and(Artisan::output())->toContain('0 token(s) deleted');

    Event::assertDispatchedTimes(SessionGone::class, 1);

    expect(eventsOfType(FleetEventType::SessionGone))->toBe(1);
});

it('revokes a session the sweep has already found silent, without a second event', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    $this->service(SessionPresence::class)->sweep();

    expect(Artisan::call('robot-council:revoke-session', ['session' => $session->getKey()]))->toBe(0)
        ->and(eventsOfType(FleetEventType::SessionGone))->toBe(1)
        ->and(orderedMeta(theEventOfType(FleetEventType::SessionGone)->meta))->toBe(orderedMeta([
            'installation_id' => $this->installation->getKey(),
            'reason' => SessionPresence::TIMEOUT,
        ]));
});

it('says a session has gone even when something else ended it first', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $ended = 0;

    // Between the endpoint's read of the session and its write. The request's earlier queries are
    // against `personal_access_tokens` and `robot_council_installations`, so the first select this
    // filter matches is the controller's own.
    DB::listen(function (QueryExecuted $query) use (&$ended, $session): void {
        if ($ended > 0 || ! str_contains($query->sql, 'robot_council_agent_sessions')) {
            return;
        }

        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }

        $ended++;

        moveRowBehindTheInstance($session, AgentSessionStatus::Gone);
    });

    // The endpoint's conditional write changes nothing, so the instance it loaded still says
    // `active`. Reading the status back off that instance would answer `active` for a session that
    // has gone, which is the one thing this endpoint must never say.
    $this->machine($this->credential)
        ->deleteJson(route('robot-council.sessions.end', ['session' => $session->getKey()]))
        ->assertOk()
        ->assertExactJson(['session_id' => $session->getKey(), 'status' => 'gone']);

    expect($ended)->toBe(1)
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Gone);
});

it('sweeps a fleet in a host that has turned lazy loading off', function (): void {
    [$first] = $this->startAgentSession($this->installation);
    [$second] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    // Two sessions, not one, and that is the whole test. `Builder::hydrate()` sets the
    // lazy-loading flag on a hydrated model only when the query returned more than one row, so a
    // model loaded with `first()` can never raise a violation however strict the host is -- and
    // the sweep's chunk read is the only query in the package that hydrates several. Its event
    // bodies name the harness and the machine, which live on the installation.
    Model::preventLazyLoading();

    try {
        expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 2])
            ->and($first->refresh()->status)->toBe(AgentSessionStatus::Gone)
            ->and($second->refresh()->status)->toBe(AgentSessionStatus::Gone)
            ->and(FleetEvent::query()->where('type', FleetEventType::SessionGone->value)->pluck('body')->all())
            ->toBe(['claude-code on workbench ended.', 'claude-code on workbench ended.']);
    } finally {
        // Static, and it outlives the test that set it
        Model::preventLazyLoading(false);
    }
});
