<?php

declare(strict_types=1);

/**
 * The shape `Support\FleetPresence` returns, pinned key by key.
 *
 * **Every field here reaches somebody else's agent or somebody else's developer**, and until #232
 * nothing asserted any of them was present. A build that dropped `project_id` from the dashboard
 * row reported green, which is what a surviving `RemoveArrayItem` mutant means: the behaviour
 * changed and no test noticed.
 *
 * The key sets are asserted whole rather than field by field, so a key ADDED without being accounted
 * for fails too, and a key REMOVED is a deliberate edit here rather than a loosened assertion. Both
 * directions have now happened: #234 put `repository` and `work_location` beside `project_id`
 * rather than replacing it, taking the row from one identifier to three while the survivor list
 * stood still, and #285 retired `project_id` and took it back to two.
 *
 * @command  vendor/bin/pest --compact tests/PresenceReadShapeTest.php
 */

use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Lock;
use RobotCouncil\Support\FleetPresence;
use RobotCouncil\Support\Locks;
use RobotCouncil\Support\PresenceClock;
use RobotCouncil\Support\Scope;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242], admins: [4242]);
});

/**
 * A developer with one session, ready to hold locks.
 *
 * @param  TestCase  $test  The test case.
 * @return AgentSession The session.
 */
function presenceFixture(TestCase $test): AgentSession
{
    $developer = $test->enrollDeveloper(4242);

    $installation = $test->approveInstallation($developer);

    [$session] = $test->startAgentSession($installation);

    return $session;
}

it('returns every key a session row carries, and no others', function (): void {
    presenceFixture($this);

    $page = $this->service(FleetPresence::class)->sessions(10);

    expect($page)->toHaveKeys(['cursor', 'more', 'live', 'gone', 'sessions'])
        ->and($page['sessions'])->toHaveCount(1);

    // Whole, not piecemeal: a dropped key fails, and so does one added without being accounted for.
    // `repository` and `work_location` arrived beside `project_id` in #234 and outlived it in #285.
    expect(array_keys($page['sessions'][0]))->toBe([
        'id',
        'github_login',
        'harness',
        'machine_label',
        'role',
        'repository',
        'work_location',
        'status',
        'seconds_since_contact',
    ]);
});

it('resolves the login from the row rather than leaving it null', function (): void {
    presenceFixture($this);

    $row = $this->service(FleetPresence::class)->sessions(10)['sessions'][0];

    // The `?? null` fallback is for a session whose developer has no identity row. Asserting the
    // resolved value is what stops the lookup being removed and the fallback standing in for it.
    expect($row['github_login'])->toBe('octodev');
});

it('counts seconds since contact as a positive number of seconds', function (): void {
    $session = presenceFixture($this);

    AgentSession::query()->whereKey($session->getKey())->update([
        'last_seen_at' => PresenceClock::now()->subSeconds(90),
    ]);

    $row = $this->service(FleetPresence::class)->sessions(10)['sessions'][0];

    // **The `* -1` is load-bearing and three mutants live on it.** Carbon's `diffInSeconds($other,
    // false)` counts from the receiver to the argument, so a contact in the past is NEGATIVE and the
    // sign flip is what makes this an age. Divide instead of multiply and it is fractional; change
    // the 1 and it is off by a whole multiple. A `toBeGreaterThan(0)` would pass under all three.
    expect($row['seconds_since_contact'])->toBeGreaterThanOrEqual(90)
        ->and($row['seconds_since_contact'])->toBeLessThan(95);
});

it('floors a contact time in the future at zero rather than reporting a negative age', function (): void {
    $session = presenceFixture($this);

    // A clock that ran backwards, or a row written by a host whose `app.timezone` moved. #51 is the
    // whole reason `PresenceClock` exists, and this is the state it protects against reaching a
    // reader as `-3600s ago`.
    AgentSession::query()->whereKey($session->getKey())->update([
        'last_seen_at' => PresenceClock::now()->addHour(),
    ]);

    $row = $this->service(FleetPresence::class)->sessions(10)['sessions'][0];

    // **This is what makes the `0` in `max(0, …)` load-bearing.** On a contact in the past the
    // subject is always the larger operand, so raising the floor to 1 or dropping it to -1 changes
    // nothing and two mutants survive. Only a future contact time reaches the floor.
    expect($row['seconds_since_contact'])->toBe(0);
});

it('returns every key a lock row carries, including both holders', function (): void {
    $session = presenceFixture($this);

    $this->service(Locks::class)->acquire($session, 'deploy', 60, false);

    $page = $this->service(FleetPresence::class)->locks(10, Scope::All);

    expect($page)->toHaveKeys(['cursor', 'more', 'held', 'free', 'locks'])
        ->and($page['locks'])->toHaveCount(1)
        ->and(array_keys($page['locks'][0]))->toBe([
            'id',
            'name',
            'fence',
            'held',
            'holder',
            'previous_holder',
            'expires_at',
        ]);

    // The holder is an object rather than a bare id, and its login is resolved. Both halves of the
    // ternary matter: negate it and a held lock reports no holder.
    expect($page['locks'][0]['holder'])->toBe(['session_id' => $session->id, 'github_login' => 'octodev'])
        ->and($page['locks'][0]['previous_holder'])->toBeNull();
});

it('reports no holder for a lock nobody holds, which is the other side of the ternary', function (): void {
    $session = presenceFixture($this);

    $locks = $this->service(Locks::class);
    $locks->acquire($session, 'deploy', 60, false);
    $locks->release($session, 'deploy', false);

    $row = $this->service(FleetPresence::class)->locks(10, Scope::All)['locks'][0];

    // Released, so `holder_id` is null. Negating the ternary invents a holder for a free lock,
    // which the held case alone cannot catch.
    expect($row['holder'])->toBeNull();
});

it('names the previous holder once a lock has changed hands', function (): void {
    $first = presenceFixture($this);

    // A second developer, so the two holders are distinguishable by login rather than only by id.
    //
    // The list REPLACES rather than appends, which is why `4242` is named again -- and `Access\
    // Allowlist` tests it with `in_array()`, so the order carries no meaning. Written out because
    // a reader can otherwise take the sequence for a precondition that neither store has (#283).
    $other = $this->enrollDeveloper(77, login: 'otherdev');
    $this->setAccessLists(developers: [4242, 77], admins: [4242]);
    [$second] = $this->startAgentSession(
        $this->approveInstallation($other, machineLabel: 'second-box')
    );

    $locks = $this->service(Locks::class);
    $locks->acquire($first, 'deploy', 60, false);

    // Expire the lease rather than releasing it. **`previous_holder_id` is written on ACQUIRE from
    // the row's prior `holder_id`, and a release sets that to null first** -- so a clean handoff
    // copies a null forward and the field stays empty. The only way it is ever populated is a
    // takeover of a lease that lapsed with its holder still named, which is exactly the state a
    // reader needs it for: telling a lock being handed round from one that was seized.
    // `PresenceClock::now()`, not `now()`: this file sets the clock through it twice above, and
    // `Support\Locks` compares against it. Identical under Testbench's UTC default, which is the
    // hazard `PresenceClock` exists for (#283).
    Lock::query()->where('name', 'deploy')->update(['expires_at' => PresenceClock::now()->subMinute()]);

    $locks->acquire($second, 'deploy', 60, false);

    $row = $this->service(FleetPresence::class)->locks(10, Scope::All)['locks'][0];

    expect($row['holder'])->toBe(['session_id' => $second->id, 'github_login' => 'otherdev'])
        ->and($row['previous_holder'])->toBe(['session_id' => $first->id, 'github_login' => 'octodev']);
});

it('returns both lists as JSON arrays rather than objects', function (): void {
    presenceFixture($this);

    $sessions = $this->service(FleetPresence::class)->sessions(10)['sessions'];
    $locks = $this->service(FleetPresence::class)->locks(10, Scope::All)['locks'];

    // **`array_values()` is what keeps these lists.** A collection whose keys are not sequential
    // encodes as a JSON object, which is the defect `Support\Installations` records at length for
    // `granted_abilities` -- and a client reading `[0]` gets nothing. Asserted on the encoded form,
    // because that is where the difference shows.
    expect(json_encode($sessions))->toStartWith('[')
        ->and(json_encode($locks))->toStartWith('[');
});

it('counts an empty fleet as zero rather than null', function (): void {
    // `sum(case when ...)` over no rows returns NULL, not 0 -- measured on SQLite with an empty
    // table, `count(*)` came back 0 and the `sum` came back NULL in the same row. That is the whole
    // reason `Support\AggregateCount` exists, and this is the case that reaches it.
    $page = $this->service(FleetPresence::class)->sessions(10);

    expect($page['live'])->toBe(0)
        ->and($page['gone'])->toBe(0)
        ->and($page['sessions'])->toBeEmpty();

    $locks = $this->service(FleetPresence::class)->locks(10, Scope::All);

    expect($locks['held'])->toBe(0)
        ->and($locks['free'])->toBe(0);
});

it('answers a request for more rows than the ceiling without erroring', function (): void {
    // **This does not prove the ceiling, and an earlier name said it did.** The fixture holds one
    // session, so asking for `MAX_PAGE + 50` and getting one row is equally true of an
    // implementation with no ceiling at all -- the exact trap
    // `FleetPresenceTest > it clamps a page size that makes no sense, on both lists` documents
    // avoiding, by seeding more rows than the clamp before asserting on it. That test owns both
    // ends of `max(1, min($limit, MAX_PAGE))` and pins the constant's value; this one only shows an
    // over-large request is answered rather than refused.
    //
    // **It does not move the constant's mutants out of `uncovered` either.** Nothing can: a
    // constant declaration is not executed where coverage can see it, which is why the annotation
    // on `FleetPresence::MAX_PAGE` is what accounts for them.
    presenceFixture($this);

    $page = $this->service(FleetPresence::class)->sessions(FleetPresence::MAX_PAGE + 50);

    expect($page['sessions'])->toHaveCount(1)
        ->and($page['more'])->toBeFalse();
});
