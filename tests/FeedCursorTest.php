<?php

declare(strict_types=1);

/**
 * That a session's feed position survives the response that created it, and that it is the reader
 * who moves it.
 *
 * #61 handed a starting cursor back once and stored nothing, so a process that lost it could only
 * replay the whole feed or guess -- and both read surfaces treated a missing `after` as zero,
 * which is that same full walk. #86 settled that the column holds the position the READER has
 * acknowledged: a request for everything after N is the reader's own statement that it processed
 * through N.
 *
 * **The property worth the most here is the one in `it redelivers a page the reader never
 * acknowledged`.** Advancing on what the server last sent would be exact until something failed,
 * and would then skip the lost page permanently. These tests pin at-least-once.
 *
 * @command  vendor/bin/pest --compact tests/FeedCursorTest.php
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->mine = $this->enrollDeveloper(4242, login: 'octodev');
});

/**
 * Start a session through the endpoint, as a bridge does.
 *
 * Through the HTTP surface rather than the store, because what is under test is what a process can
 * recover, and a process only ever sees responses.
 *
 * The developer arrives as a typed parameter rather than being read off the case, exactly as
 * `sessionFor()` in `tests/FleetEventFeedTest.php` does: a fixture assigned in `beforeEach` is a
 * dynamic property, so everything a helper reaches for through the case arrives as `mixed`.
 *
 * @param  TestCase  $case  The running test.
 * @param  User  $developer  The developer the session belongs to.
 * @param  string  $label  The machine label, so several sessions can coexist.
 * @return array{0: int, 1: string, 2: int} The session id, its token, and its starting cursor.
 */
function startedSession(TestCase $case, User $developer, string $label = 'bridge'): array
{
    $installation = $case->approveInstallation($developer, machineLabel: $label);

    $started = $case->machine($case->installationCredential($installation))
        ->postJson(route('robot-council.sessions.start'))
        ->assertCreated();

    return [
        intValue($started->json('session_id')),
        stringValue($started->json('token')),
        intValue($started->json('feed_cursor')),
    ];
}

/**
 * The position the row holds for a session.
 *
 * Read from the table rather than from a model, because the claim is about what was persisted.
 *
 * @param  int  $sessionId  The session.
 * @return int The stored cursor.
 */
function storedCursor(int $sessionId): int
{
    return intValue(DB::table('robot_council_agent_sessions')->where('id', $sessionId)->value('feed_cursor'));
}

/**
 * The bodies a feed read returned.
 *
 * @param  array<array-key, mixed>  $events  The events from a page.
 * @return array<int, mixed> Their bodies.
 */
function bodiesOf(array $events): array
{
    return array_column($events, 'body');
}

/**
 * Read the feed through the MCP tool.
 *
 * Spelled out here rather than borrowed from `tests/McpToolsTest.php`, whose helpers are only
 * loaded when that file is part of the run: a test that passes in a full suite and errors when its
 * own file is named is worse than a few duplicated lines.
 *
 * @param  TestCase  $case  The running test.
 * @param  string  $token  The session's token.
 * @param  array<string, mixed>  $arguments  The tool's arguments.
 * @return array<int, mixed> The bodies the page carried.
 */
function mcpFeedBodies(TestCase $case, string $token, array $arguments = []): array
{
    $response = $case->machine($token)->postJson('/robot-council/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'events_read', 'arguments' => $arguments],
    ])->assertOk();

    $result = arrayValue(arrayValue($response->json())['result'] ?? []);

    expect($result['isError'] ?? false)->toBeFalse();

    $structured = arrayValue($result['structuredContent'] ?? []);

    return bodiesOf(arrayValue($structured['events'] ?? []));
}

it('keeps the starting cursor on the row, not only in the response', function (): void {
    [$id, , $cursor] = startedSession($this, $this->mine);

    // The whole of the first criterion: the value outlives the one response that stated it.
    expect($cursor)->toBeGreaterThan(0)
        ->and(storedCursor($id))->toBe($cursor);
});

it('resumes from the stored position when a read names no cursor', function (): void {
    $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'Before the session.');

    [, $token] = startedSession($this, $this->mine);

    $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'After the session.');

    $resumed = $this->machine($token)->getJson(route('robot-council.events.index'))->assertOk();

    // Both halves, because each is the other's control. Read only without a cursor, the assertion
    // would pass identically against a feed that simply had no earlier event in it.
    $fromZero = $this->machine($token)
        ->getJson(route('robot-council.events.index', ['after' => 0]))
        ->assertOk();

    expect(bodiesOf(arrayValue($resumed->json('events'))))
        ->toContain('After the session.')
        ->not->toContain('Before the session.')
        ->and(bodiesOf(arrayValue($fromZero->json('events'))))
        ->toContain('Before the session.');
});

it('redelivers a page the reader never acknowledged', function (): void {
    [$id, $token, $cursor] = startedSession($this, $this->mine);

    $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'Sent but never acknowledged.');

    $first = $this->machine($token)->getJson(route('robot-council.events.index'))->assertOk();

    // The reader asked from its starting position and said nothing about having processed the
    // page, so the row must not have moved past it.
    expect(storedCursor($id))->toBe($cursor);

    $again = $this->machine($token)->getJson(route('robot-council.events.index'))->assertOk();

    // This is the at-least-once guarantee. A server-advanced cursor would have moved on the first
    // read and this event would be gone for this session for good.
    expect(bodiesOf(arrayValue($first->json('events'))))->toContain('Sent but never acknowledged.')
        ->and(bodiesOf(arrayValue($again->json('events'))))->toContain('Sent but never acknowledged.');
});

it('moves the stored position only when the reader names one', function (): void {
    [$id, $token, $cursor] = startedSession($this, $this->mine);

    $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'Read me.');

    $page = $this->machine($token)->getJson(route('robot-council.events.index'))->assertOk();

    $reached = intValue($page->json('cursor'));

    $this->machine($token)
        ->getJson(route('robot-council.events.index', ['after' => $reached]))
        ->assertOk();

    expect($reached)->toBeGreaterThan($cursor)
        ->and(storedCursor($id))->toBe($reached);

    // And now a read naming nothing starts from there, which is what a restarted bridge gets.
    $after = $this->machine($token)->getJson(route('robot-council.events.index'))->assertOk();

    expect(bodiesOf(arrayValue($after->json('events'))))->not->toContain('Read me.');
});

it('never drags a session backwards when it re-reads old history', function (): void {
    [$id, $token] = startedSession($this, $this->mine);

    $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'Read me.');

    $reached = intValue($this->machine($token)
        ->getJson(route('robot-council.events.index'))
        ->json('cursor'));

    $this->machine($token)->getJson(route('robot-council.events.index', ['after' => $reached]))->assertOk();

    $advanced = storedCursor($id);

    // A reader is allowed to go back over history, and doing so must not cost it its place --
    // otherwise re-reading once would replay everything from then on.
    $this->machine($token)->getJson(route('robot-council.events.index', ['after' => 0]))->assertOk();

    expect($advanced)->toBe($reached)
        ->and(storedCursor($id))->toBe($advanced);
});

it('refuses a position past the end of the feed', function (): void {
    [$id, $token, $cursor] = startedSession($this, $this->mine);

    $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'Still here.');

    // A millisecond timestamp where an event id belongs -- a plausible client bug, and nothing
    // lowers this column, so storing it would blind the session to the fleet permanently.
    $this->machine($token)
        ->getJson(route('robot-council.events.index', ['after' => 1799999999999]))
        ->assertOk();

    expect(storedCursor($id))->toBe($cursor);

    // The proof it is not merely unchanged but still working: the session reads the feed as
    // before, from the position it really had.
    $resumed = $this->machine($token)->getJson(route('robot-council.events.index'))->assertOk();

    expect(bodiesOf(arrayValue($resumed->json('events'))))->toContain('Still here.');
});

it('refuses an empty after rather than reading it as zero', function (): void {
    $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'Before the session.');

    [, $token] = startedSession($this, $this->mine);

    // A malformed `?after=` must not become a walk of the fleet's whole history. It does not,
    // and the reason is the validator rather than the controller: `sometimes` runs the `integer`
    // rule because the key is present, and an empty string is not an integer. Pinned because the
    // controller reads the argument with `filled()` on the strength of it.
    $this->machine($token)
        ->getJson(route('robot-council.events.index').'?after=')
        ->assertStatus(422);
});

it('restates the acknowledged position when a session renews', function (): void {
    $installation = $this->approveInstallation($this->mine, machineLabel: 'restarts');

    $credential = $this->installationCredential($installation);

    $started = $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertCreated();

    $id = intValue($started->json('session_id'));
    $token = stringValue($started->json('token'));
    $start = intValue($started->json('feed_cursor'));

    $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'Processed.');

    $reached = intValue($this->machine($token)->getJson(route('robot-council.events.index'))->json('cursor'));

    $this->machine($token)->getJson(route('robot-council.events.index', ['after' => $reached]))->assertOk();

    $renewed = $this->machine($credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $id]))
        ->assertOk();

    // The second criterion. It is the position the session reached, not the one it started at and
    // not the feed's head -- a bridge that restarted with a live token recovers rather than
    // choosing between replaying everything and skipping everything.
    expect(intValue($renewed->json('feed_cursor')))->toBe($reached)
        ->and($reached)->toBeGreaterThan($start);
});

it('tells a session where it has read to when it asks', function (): void {
    [, $token, $cursor] = startedSession($this, $this->mine);

    $read = $this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk();

    expect(intValue($read->json('feed_cursor')))->toBe($cursor);
});

it('leaves a session that predates the column reading as it always did, then heals it', function (): void {
    $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'Before the session.');

    [$id, $token] = startedSession($this, $this->mine);

    // What the migration leaves on every session that already existed: the column's default. The
    // decision on #86 accepted this instead of a backfill guessing a position from event history.
    DB::table('robot_council_agent_sessions')->where('id', $id)->update(['feed_cursor' => 0]);

    $before = $this->machine($token)->getJson(route('robot-council.events.index'))->assertOk();

    // Zero means the whole history, which is exactly what such a session got before this change,
    // so nothing regresses for it.
    expect(bodiesOf(arrayValue($before->json('events'))))->toContain('Before the session.');

    $reached = intValue($before->json('cursor'));

    $this->machine($token)->getJson(route('robot-council.events.index', ['after' => $reached]))->assertOk();

    // And the first acknowledgement is what heals it: a bridge still holding its cursor passes it,
    // and from then on the row has a real position.
    expect(storedCursor($id))->toBe($reached);
});

it('resumes through MCP as well, which is the surface the model reads', function (): void {
    $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'Before the session.');

    [, $token] = startedSession($this, $this->mine);

    $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'After the session.');

    // The two surfaces share one code path precisely so they cannot drift, and the drift is what
    // #86 was filed about: the tool defaulted `after` to 0 where the controller did the same, and
    // an agent omitting the argument walked the fleet's whole history.
    $resumed = mcpFeedBodies($this, $token);

    $fromZero = mcpFeedBodies($this, $token, ['after' => 0]);

    expect($resumed)->toContain('After the session.')
        ->not->toContain('Before the session.')
        ->and($fromZero)->toContain('Before the session.');
});
