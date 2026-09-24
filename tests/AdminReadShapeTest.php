<?php

declare(strict_types=1);

/**
 * The shape `Support\InstallationList` and `GET api/agent/session` return, pinned key by key.
 *
 * The administration panel is the only interface for revoking a credential, and the session
 * endpoint is what every enrolled agent reads about itself. Until #232 nothing asserted either
 * body's keys, so a build that dropped one reported green.
 *
 * Asserted whole rather than field by field, so a key ADDED without being accounted for fails too,
 * and a key REMOVED is a deliberate edit rather than a loosened assertion. #234 is why the first
 * matters: it put `repository` and `work_location` beside `project_id` rather than replacing it,
 * and both of these bodies grew by two fields while nothing here noticed. #285 is why the second
 * does: it retired `project_id`, and these lists are where that had to be accounted for.
 *
 * @command  vendor/bin/pest --compact tests/AdminReadShapeTest.php
 */

use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\InstallationList;
use RobotCouncil\Support\Scope;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242], admins: [4242]);
});

it('returns every key an installation row carries, and no others', function (): void {
    $developer = $this->enrollDeveloper(4242);
    $installation = $this->approveInstallation($developer);

    $this->startAgentSession($installation);

    $page = $this->service(InstallationList::class)->everything(10, Scope::All);

    expect($page)->toHaveKeys(['cursor', 'more', 'live', 'retired', 'installations'])
        ->and($page['installations'])->toHaveCount(1)
        ->and(array_keys($page['installations'][0]))->toBe([
            'id',
            'github_login',
            'harness',
            'machine_label',
            'revoked',
            'expired',
            'sessions',
        ]);

    // `revoked` and `expired` are both halves of `isUsable()`, kept apart because they are the same
    // to a guard and different to an admin. A live installation is neither.
    expect($page['installations'][0]['revoked'])->toBeFalse()
        ->and($page['installations'][0]['expired'])->toBeFalse();
});

it('returns every key a nested session row carries, including all three identifiers', function (): void {
    $developer = $this->enrollDeveloper(4242);
    $installation = $this->approveInstallation($developer);

    $this->startAgentSession($installation);

    $sessions = arrayValue(arrayValue(arrayValue(
        $this->service(InstallationList::class)->everything(10, Scope::All)['installations']
    )[0])['sessions']);

    // Asserted WHOLE, like the four other key-set checks in this file. `toHaveKeys()` is a subset
    // check, and this array also carries `gone` -- so the docblock's "asserted whole rather than
    // field by field" was true of every other assertion here and not of this one (#283).
    expect(array_keys($sessions))->toBe(['shown', 'hidden', 'gone'])
        ->and($sessions)
        ->and($sessions['shown'])->toHaveCount(1);

    // **Two identifiers where there was one, having briefly been three.** #234 put `repository`
    // and `work_location` beside `project_id` and #285 retired the label, so what reaches an
    // admin's panel is the pair. Dropping either reported green before #232 asserted the set.
    expect(array_keys(arrayValue(arrayValue($sessions['shown'])[0])))->toBe([
        'id',
        'status',
        'role',
        'requested_role',
        'requested_at',
        'repository',
        'work_location',
    ]);

    expect($sessions['hidden'])->toBe(0);
});

it('counts an empty installation table as zero rather than null', function (): void {
    // `sum(case when ...)` over no rows returns NULL, which is what `Support\AggregateCount` is for.
    $page = $this->service(InstallationList::class)->everything(10, Scope::All);

    expect($page['live'])->toBe(0)
        ->and($page['retired'])->toBe(0)
        ->and($page['installations'])->toBeEmpty();
});

it('returns both lists as JSON arrays rather than objects', function (): void {
    $developer = $this->enrollDeveloper(4242);
    $installation = $this->approveInstallation($developer);

    $this->startAgentSession($installation);

    $page = $this->service(InstallationList::class)->everything(10, Scope::All);

    // A collection whose keys are not sequential encodes as a JSON object, and a client reading
    // `[0]` gets nothing -- the defect `Support\Installations` records for `granted_abilities`.
    expect(json_encode($page['installations']))->toStartWith('[')
        ->and(json_encode(arrayValue(arrayValue(arrayValue($page['installations'])[0])['sessions'])['shown']))->toStartWith('[');
});

it('keeps the shown sessions a JSON array when a gone session sits ahead of a live one', function (): void {
    $developer = $this->enrollDeveloper(4242);
    $installation = $this->approveInstallation($developer);

    // **The NEWER session is the gone one, and getting that backwards makes this test prove
    // nothing.** `Collection::filter()` preserves keys, so filtering the OLDER session away leaves
    // `[0]` and `array_values()` is a no-op here: the assertions below would pass either way.
    // Measured before this was corrected.
    //
    // **Filtering is one of two sufficient causes, not the only one** (#283). This test covers the
    // filtered path -- measured, it yields `{"1":{…}` with a single element at key 1. The sibling
    // below covers the other: `sortBy('id')` over the same descending relation opens the same gap
    // with nothing filtered at all. Removing `array_values()` turns both red, and a comment naming
    // only one cause is how the call gets deleted after the other is refactored away.
    $this->startAgentSession($installation);
    [$gone] = $this->startAgentSession($installation);

    AgentSession::query()->whereKey($gone->getKey())->update(['status' => 'gone']);

    $shown = arrayValue(arrayValue(arrayValue(arrayValue(
        $this->service(InstallationList::class)->everything(10, Scope::All)['installations']
    )[0])['sessions'])['shown']);

    expect($shown)->toHaveCount(1)
        ->and(json_encode($shown))->toStartWith('[');

    // **Unlike the list beside it**, this `array_values()` is not defensive: the outer one narrows
    // a query result whose keys are already 0..n-1, while this one narrows a filtered relation and
    // the gap is reachable with two sessions.
    expect(array_keys($shown))->toBe([0]);
});

it('keeps the shown sessions a JSON array when nothing is filtered at all', function (): void {
    // **The second sufficient cause, which the sibling above cannot reach** (#283). Two live
    // sessions, so `filter()` removes nothing: whatever opens the gap here is not the filtering.
    // The relation is eager-loaded `orderByDesc('id')`, so `sortBy('id')` reverses keys `[0, 1]`
    // into `[1, 0]`, and without `array_values()` the list encodes as `{"1":{…},"0":{…}` -- a JSON
    // object where every consumer expects an array. Measured.
    //
    // Both tests go red when `array_values()` is removed, and they fail on different shapes: the
    // sibling on one element at key 1, this one on two in reversed order. That is what makes them
    // two tests rather than one.
    $developer = $this->enrollDeveloper(4242);
    $installation = $this->approveInstallation($developer);

    $this->startAgentSession($installation);
    $this->startAgentSession($installation);

    $shown = arrayValue(arrayValue(arrayValue(arrayValue(
        $this->service(InstallationList::class)->everything(10, Scope::All)['installations']
    )[0])['sessions'])['shown']);

    // The fixture's own property, so a later change that filters one of these away turns this into
    // the sibling test rather than silently weakening it.
    expect($shown)->toHaveCount(2)
        ->and(json_encode($shown))->toStartWith('[')
        ->and(array_keys($shown))->toBe([0, 1]);
});

it('pins the key set of the session every agent reads about itself', function (): void {
    $developer = $this->enrollDeveloper(4242);
    $installation = $this->approveInstallation($developer);

    [, $token] = $this->startAgentSession($installation);

    $body = $this->machine($token)->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->json();

    // **Mutation testing cannot reach this criterion, which is why it is here.** `--mutate` removes
    // an array item and asks whether a test notices; it never ADDS one. So a key appearing in this
    // body -- the one every enrolled agent reads about itself -- would be caught by nothing at all.
    // Asserted whole, so both directions are a deliberate edit.
    expect(array_keys((array) $body))->toBe([
        'session_id',
        'installation_id',
        'status',
        'role',
        'requested_role',
        'repository',
        'work_location',
        'feed_cursor',
        'abilities',
        'fleet_can_direct',
    ]);
});

it('separates a revoked installation from an expired one', function (): void {
    $developer = $this->enrollDeveloper(4242);

    $revoked = $this->approveInstallation($developer, machineLabel: 'revoked-box');
    $expired = $this->approveInstallation($developer, machineLabel: 'expired-box');

    Installation::query()->whereKey($revoked->getKey())->update(['revoked_at' => now()]);
    Installation::query()->whereKey($expired->getKey())->update(['expires_at' => now()->subDay()]);

    $rows = [];

    foreach (arrayValue($this->service(InstallationList::class)->everything(10, Scope::All)['installations']) as $row) {
        $row = arrayValue($row);

        $rows[stringValue($row['machine_label'] ?? null)] = $row;
    }

    // Both are unusable to a guard and different to an admin, which is the distinction the two
    // booleans exist to carry. A single flag would report the same thing for both rows.
    expect($rows)->toHaveKeys(['revoked-box', 'expired-box']);

    expect($rows['revoked-box']['revoked'])->toBeTrue()
        ->and($rows['revoked-box']['expired'])->toBeFalse()
        ->and($rows['expired-box']['revoked'])->toBeFalse()
        ->and($rows['expired-box']['expired'])->toBeTrue();
});
