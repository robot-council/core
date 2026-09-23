<?php

declare(strict_types=1);

/**
 * The dashboard's only writing panel: who may change authorization, and what a change reaches.
 *
 * The load-bearing half is that **the gate is on the action**. A Livewire action is a POST to
 * `/livewire/update` carrying a snapshot, so a control this package declines to render is markup a
 * client can simply not need -- and `Livewire::test()` calls the action with no page and no HTTP
 * middleware at all, which makes it the right instrument for that claim rather than a weaker one.
 * `Mechanisms\PersistentMiddleware` returns early for anything that is not a real request to the
 * update endpoint, so a component that relied on middleware would pass a page test and refuse
 * nobody here.
 *
 * @command  vendor/bin/pest --compact tests/AdministrationTest.php
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Access\Ability;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\FleetFeed;
use RobotCouncil\Support\InstallationList;
use RobotCouncil\Support\Installations;
use RobotCouncil\Support\Scope;
use RobotCouncil\Support\SessionPresence;
use RobotCouncil\Tests\Fixtures\HostileContent;

/**
 * The verifier `requestDeviceCode()` uses by default, named here so the page can be checked for it.
 */
const VERIFIER = 'a-verifier-only-the-helper-holds-and-nobody-else-at-all';
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // Two accounts, deliberately: the allowlist admits both, and only one of them is an admin.
    // A suite where every developer is an admin cannot tell the dashboard's gate apart from the
    // admin gate, and this panel is the one place the difference decides anything.
    $this->setAccessLists(developers: [4242, 4243], admins: [4242]);

    $this->admin = $this->enrollDeveloper(4242, login: 'octoadmin');
    $this->developer = $this->enrollDeveloper(4243, login: 'octodev');
});

/**
 * An installation belonging to the plain developer, with a live session under it.
 *
 * The case and the developer are passed rather than reached for through `test()`, which widens to
 * `TestCall|HigherOrderTapProxy` and leaves the analyzer unable to see either the helpers or the
 * properties. `requestDeviceCode()` in `tests/Pest.php` takes its case for the same reason.
 *
 * @param  TestCase  $case  The test case driving it.
 * @param  User  $developer  The developer the installation belongs to.
 * @return array{Installation, AgentSession, string} The installation, its session, and the
 *                                                   session's plaintext token.
 */
function installationWithSession(TestCase $case, User $developer): array
{
    $installation = $case->approveInstallation($developer, [Ability::EventsPost->value]);

    [$session, $token] = $case->startAgentSession($installation);

    return [$installation, $session, $token];
}

it('grants an ability, and rewrites the session tokens already in flight', function (): void {
    [$installation, $session] = installationWithSession($this, $this->developer);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('grant', $installation->id, Ability::TasksCreate->value);

    // The stored row, read fresh rather than from the instance the call was handed
    expect($installation->refresh()->abilities())
        ->toContain(Ability::TasksCreate->value)
        ->toContain(Ability::EventsPost->value);

    // And the token already issued, which is the half that matters: a session token lives for an
    // hour, so a grant that only changed the installation would not reach a running process until
    // it happened to renew.
    $abilities = $session->tokens()->get()->pluck('abilities')->all();

    // `not->toBeEmpty()` first, and it is load-bearing: `each` over an empty array asserts
    // nothing and passes, so without it this test would stay green against a session holding no
    // tokens at all -- which is exactly what a broken grant would leave behind.
    expect($abilities)->not->toBeEmpty()
        ->each->toContain(Ability::TasksCreate->value);
});

it('revokes an ability, and takes it off the tokens already in flight', function (): void {
    [$installation, $session] = installationWithSession($this, $this->developer);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('revokeAbility', $installation->id, Ability::EventsPost->value);

    $carried = $session->tokens()->get()->pluck('abilities')->all();

    expect($installation->refresh()->abilities())->not->toContain(Ability::EventsPost->value)
        ->and($carried)->not->toBeEmpty()
        ->each->not->toContain(Ability::EventsPost->value);
});

it('refuses a signed-in developer who is not an admin', function (string $action, array $arguments): void {
    [$installation, $session] = installationWithSession($this, $this->developer);

    // The ids are resolved here rather than passed in, because a dataset is built before the
    // database has anything in it
    $arguments = array_map(static fn (mixed $name): int|string => match ($name) {
        'installation' => $installation->id,
        'session' => $session->id,
        default => stringValue($name),
    }, $arguments);

    // Mounted as the admin, so the component exists and holds a valid snapshot -- then the same
    // account loses its admin rights and calls the action. That is the real shape of this: a
    // developer whose admin status is taken away is still holding a page that renders every
    // control, and nothing re-runs the dashboard's route middleware on `/livewire/update`.
    $component = Livewire::actingAs($this->admin)->test(Administration::class);

    $this->setAccessLists(developers: [4242, 4243], admins: []);

    // A status rather than a thrown exception, because Livewire's test harness renders both
    // `AuthorizationException` and `HttpException` into a response instead of propagating them
    // (`SupportTesting\RequestBroker` excepts exactly those two from `withoutExceptionHandling`).
    // A test written to expect a throw fails on a component that refuses correctly, and
    // `Testable::__call` forwards these assertions to the response underneath.
    $component->call($action, ...$arguments)->assertForbidden();

    // And the refusal was a refusal: nothing moved
    expect($installation->refresh()->revoked_at)->toBeNull()
        ->and($installation->abilities())->toBe([Ability::EventsPost->value])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Active);
})
    ->with([
        'grant' => ['grant', ['installation', 'tasks:create']],
        'revoke an ability' => ['revokeAbility', ['installation', 'events:post']],
        'revoke an installation' => ['revokeInstallation', ['installation']],
        'revoke a session' => ['revokeSession', ['session']],
        'render' => ['$refresh', []],
    ]);

it('decides who is an admin on the package guard, not the host default', function (): void {
    // `robot-council.auth.guard` is what signs a developer in, checks them, and signs them out, and
    // every other human-facing path in the package reads it -- `EnsureAllowlistedDeveloper`, the
    // GitHub callback, the enrollment decision, and `actor()` twelve lines below `authorizeAdmin()`.
    // `AllowlistAccessTest` pins the same invariant for the middleware: a host whose default guard
    // is another one must still admit the developer the package guard holds.
    //
    // The container's `Gate` does not read it. It is built as
    // `new Gate($app, fn () => $app['auth']->userResolver()())`, and that resolver is
    // `guard(null)->user()` -- the DEFAULT guard. So a bare `Gate::authorize()` here decides on a
    // different principal from the one the page signed in, and from the one the event names.
    [$installation] = installationWithSession($this, $this->developer);

    $component = Livewire::actingAs($this->admin)->test(Administration::class);

    // The host's default guard becomes a token guard this admin never used, exactly as
    // `AllowlistAccessTest` does it. The package guard still names `web`, which is what holds them.
    config()->set('auth.guards.api', ['driver' => 'token', 'provider' => 'users']);
    config()->set('auth.defaults.guard', 'api');

    $component->call('grant', $installation->id, Ability::TasksCreate->value)->assertOk();

    expect($installation->refresh()->abilities())->toContain(Ability::TasksCreate->value);
});

it('refuses to mount for a developer who was never an admin', function (): void {
    // The control for the test above, and a different claim: that one shows the action re-checks,
    // this one shows a non-admin cannot get a component in the first place. Without it, a
    // component that authorized only in `mount()` would pass the test above for the wrong reason.
    Livewire::actingAs($this->developer)->test(Administration::class)->assertForbidden();

    // The other half of the pair: the same mount for the admin is not forbidden, so the assertion
    // above is about who is asking rather than about the component being broken for everyone
    Livewire::actingAs($this->admin)->test(Administration::class)->assertOk();
});

it('refuses an ability outside the grantable list, and changes nothing', function (string $action, string $ability): void {
    [$installation] = installationWithSession($this, $this->developer);

    $before = $installation->abilities();

    // 422 rather than a throw, for the reason the refusal test above records: Livewire's harness
    // renders an `HttpException` into a response. 422 rather than 403 deliberately -- the caller
    // is an admin and is allowed here; the value they sent is the thing being refused.
    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call($action, $installation->id, $ability)
        ->assertStatus(422);

    expect($installation->refresh()->abilities())->toBe($before);
})->with(['grant', 'revokeAbility'])->with([
    // Sanctum reads this as every ability, so it is the one value that must never be stored
    'the wildcard' => '*',

    // A real case, and not grantable: it belongs to an installation credential rather than to a
    // session token, so granting it would write a value no guard ever checks
    'the installation credential' => 'sessions:start',

    'an unknown name' => 'tasks:destroy',
    'an empty string' => '',
]);

it('grants every ability the panel offers, so the refusal above is not refusing everything', function (): void {
    // The negative control for the test above. Four refusals prove nothing on their own: a `grant`
    // that threw for every input would satisfy them and be entirely broken.
    [$installation] = installationWithSession($this, $this->developer);

    $component = Livewire::actingAs($this->admin)->test(Administration::class);

    foreach (Ability::grantable() as $ability) {
        $component->call('grant', $installation->id, $ability->value);
    }

    // Compared as a set: `events:post` was granted before the loop ran, so it keeps its position
    // in the stored array and the list is not in `grantable()` order
    $held = $installation->refresh()->abilities();

    sort($held);

    $expected = Ability::values(Ability::grantable());

    sort($expected);

    expect($held)->toBe($expected);
});

it('revokes an installation, and its credential stops working on the next request', function (): void {
    /** @var Installation $installation */
    $installation = $this->approveInstallation($this->developer);

    $credential = $this->installationCredential($installation);

    // It works first, or the assertion below passes against a credential that never worked
    $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertCreated();

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('revokeInstallation', $installation->id);

    $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertUnauthorized();

    expect($installation->refresh()->revoked_at)->not->toBeNull();
});

it('revokes one session, and its token stops working on the next request', function (): void {
    [$installation, $session, $token] = installationWithSession($this, $this->developer);

    $this->machine($token)->getJson(route('robot-council.events.index'))->assertOk();

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('revokeSession', $session->id);

    $this->machine($token)->getJson(route('robot-council.events.index'))->assertUnauthorized();

    // The session, not the installation: revoking one process must not take the machine with it
    expect($session->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and($installation->refresh()->revoked_at)->toBeNull();
});

it('writes an event for every change, and the change feed shows it', function (): void {
    [$installation, $session] = installationWithSession($this, $this->developer);

    $component = Livewire::actingAs($this->admin)->test(Administration::class);

    $component->call('grant', $installation->id, Ability::TasksCreate->value);
    $component->call('revokeAbility', $installation->id, Ability::EventsPost->value);
    $component->call('revokeSession', $session->id);
    $component->call('revokeInstallation', $installation->id);

    $recorded = FleetEvent::query()->orderBy('id')->get();

    $types = $recorded->pluck('type')->all();

    expect($types)->toContain(FleetEventType::InstallationAbilityGranted)
        ->toContain(FleetEventType::InstallationAbilityRevoked)
        ->toContain(FleetEventType::SessionGone)
        ->toContain(FleetEventType::InstallationRevoked);

    // The feed the dashboard reads, not the table: #29's visibility rule decides what reaches a
    // reader, and an authorization change nobody can see is not "visible in the same feed as
    // everything else".
    $shown = $this->service(FleetFeed::class)->latest(50);

    $shownTypes = array_map(static fn (array $event): mixed => $event['type'], $shown);

    foreach ([
        FleetEventType::InstallationAbilityGranted->value,
        FleetEventType::InstallationAbilityRevoked->value,
        FleetEventType::InstallationRevoked->value,
    ] as $type) {
        expect($shownTypes)->toContain($type);
    }
});

it('names the admin who made the change, rather than leaving it unattributed', function (): void {
    [$installation] = installationWithSession($this, $this->developer);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('grant', $installation->id, Ability::TasksCreate->value);

    $event = FleetEvent::query()
        ->where('type', FleetEventType::InstallationAbilityGranted)
        ->firstOrFail();

    // **Two columns, two people, and they are different accounts here precisely so a copy of the
    // wrong one cannot pass.** Before #115 the admin's key went into `user_id`, the column #29's
    // visibility rule reads -- so marking any `installation.*` type restricted would have served
    // the event to the admin and hidden it from the owner. `user_id` is the owner now.
    expect($event->user_id)->toBe(keyValue($this->developer->getKey()))
        ->and($event->actor_user_id)->toBe(keyValue($this->admin->getKey()))
        ->and($event->user_id)->not->toBe($event->actor_user_id);

    // And the feed resolves both, which is what the dashboard renders
    $shown = collect($this->service(FleetFeed::class)->latest(50))
        ->firstWhere('type', FleetEventType::InstallationAbilityGranted->value);

    expect(arrayValue($shown['actor'] ?? [])['github_login'] ?? null)->toBe('octodev')
        ->and(arrayValue($shown['performed_by'] ?? [])['github_login'] ?? null)->toBe('octoadmin');
});

it('names the admin who revoked a session, through the page rather than the store', function (): void {
    // **The fourth administrative action, and the one that went unattributed.** The other three
    // named the admin; `revokeSession()` went through a store method that took no actor, so the
    // feed could say a session was revoked and not by whom -- for the action that kills another
    // developer's running agent (#115).
    //
    // Driven through the component rather than through `SessionPresence::revoke()`, because the
    // store's own coverage passes whatever the test hands it: only this path shows that the page
    // passes the signed-in admin at all.
    [, $session] = installationWithSession($this, $this->developer);

    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('revokeSession', $session->id);

    $event = FleetEvent::query()->where('type', FleetEventType::SessionGone)->firstOrFail();

    expect($event->actor_user_id)->toBe(keyValue($this->admin->getKey()))
        // And the session's own developer is still who the event is about.
        ->and($event->user_id)->toBe(keyValue($this->developer->getKey()))
        ->and($event->user_id)->not->toBe($event->actor_user_id);
});

it('shows no credential, device code, or verifier on the page', function (): void {
    /** @var Installation $installation */
    $installation = $this->approveInstallation($this->developer);

    $credential = $this->installationCredential($installation);

    [, $sessionToken] = $this->startAgentSession($installation);

    // Rendered after the device code exists, so the page had the chance to show it
    $html = Livewire::actingAs($this->admin)->test(Administration::class)->html();

    // The page rendered the installation at all, so the absences below are absences rather than an
    // empty render
    expect($html)->toContain('workbench');

    // A real device code and its verifier, because the criterion names them and the table is
    // otherwise EMPTY in this test -- and "the page shows no device code" is true of a page that
    // showed every one of them when none exists. This is the absence-without-an-instrument shape,
    // and creating the row is what turns it into a measurement.
    $enrollment = requestDeviceCode($this);

    $deviceCode = stringValue($enrollment['device_code']);
    $userCode = $enrollment['record']->user_code;

    expect($deviceCode)->not->toBeEmpty()
        ->and($userCode)->not->toBeEmpty();

    // Both halves of each plaintext token: Sanctum's form is `<id>|<plain>`, and the id alone is
    // not a secret, so asserting on the whole string only would miss a page that printed the
    // hashed half or the tail.
    foreach ([$credential, $sessionToken, $deviceCode, VERIFIER, $userCode] as $secret) {
        expect($html)->not->toContain($secret)
            ->not->toContain(explode('|', $secret)[1] ?? $secret);
    }

    // Nothing hashed either, which is what a naive `$installation->tokens` render would print
    $hashes = $installation->tokens()->get()->pluck('token')->all();

    expect($hashes)->not->toBeEmpty();

    foreach ($hashes as $hash) {
        expect($html)->not->toContain(stringValue($hash));
    }
});

it('renders a hostile machine label inert on the admin panel', function (string $payload, array $forbidden, ?string $escaped): void {
    // `machine_label` is `varchar(64)`, and Postgres refuses an overlong value where SQLite stores
    // it whole -- so a payload that does not fit would pass locally and fail only in the `postgres`
    // job. Asserted rather than left to chance.
    expect(mb_strlen($payload))->toBeLessThanOrEqual(64);

    /** @var Installation $installation */
    $installation = $this->approveInstallation($this->developer);

    // Written past the endpoint's validation deliberately: `MachineIdentity` charset-limits this
    // field, so the string cannot arrive through the API and the page's escaping has to be its own
    // guarantee rather than the validator's.
    $installation->forceFill(['machine_label' => $payload])->save();

    $html = Livewire::actingAs($this->admin)->test(Administration::class)->html();

    expect($html)->toContain($escaped ?? $payload);

    foreach ($forbidden as $live) {
        expect($html)->not->toContain($live);
    }
})->with(HostileContent::dataset());

it('reports an installation as revoked and as expired, which are different things', function (): void {
    // Neither branch had a test, and each is one word away from its opposite. A guard treats a
    // revoked and an expired installation alike; an admin does not, because one is a decision
    // somebody made and the other is only the clock.
    $live = $this->approveInstallation($this->developer);

    $revoked = $this->approveInstallation($this->developer, machineLabel: 'revoked-box');
    $revoked->forceFill(['revoked_at' => Carbon::now()])->save();

    $expired = $this->approveInstallation($this->developer, machineLabel: 'expired-box');
    $expired->forceFill(['expires_at' => Carbon::now()->subDay()])->save();

    $listed = $this->service(InstallationList::class)->everything(50, Scope::All)['installations'];

    $row = static fn (int $id): array => arrayValue(collect($listed)->firstWhere('id', $id));

    expect($row($live->id)['revoked'])->toBeFalse()
        ->and($row($live->id)['expired'])->toBeFalse()
        ->and($row($revoked->id)['revoked'])->toBeTrue()
        ->and($row($revoked->id)['expired'])->toBeFalse()
        ->and($row($expired->id)['expired'])->toBeTrue()
        ->and($row($expired->id)['revoked'])->toBeFalse();
});

it('lists the newest installation first', function (): void {
    $first = $this->approveInstallation($this->developer, machineLabel: 'older-box');
    $second = $this->approveInstallation($this->developer, machineLabel: 'newer-box');

    $ids = array_column($this->service(InstallationList::class)->everything(50)['installations'], 'id');

    // Pinned as a pair rather than asserted on one end, so reversing the order fails rather than
    // matching whichever row happened to come back first
    expect($ids)->toBe([$second->id, $first->id]);
});

it('bounds the sessions it lists, and says how many it left out', function (): void {
    $installation = $this->approveInstallation($this->developer);

    $limit = InstallationList::SESSIONS_PER_INSTALLATION;

    // Two past the bound, so `hidden` is a number this test chose rather than zero
    $live = $limit + 2;

    for ($i = 0; $i < $live; $i++) {
        $this->startAgentSession($installation);
    }

    // And three that have ended, which are counted rather than listed
    foreach (range(1, 3) as $ignored) {
        [$session] = $this->startAgentSession($installation);

        $this->service(SessionPresence::class)->revoke($session);
    }

    $sessions = arrayValue($this->service(InstallationList::class)->everything(50)['installations'][0]['sessions']);

    expect($sessions['shown'])->toHaveCount($limit)
        ->and($sessions['hidden'])->toBe(2)
        ->and($sessions['gone'])->toBe(3);

    // Every listed session is live, which is what lets the view draw a control on all of them
    expect(array_column(arrayValue($sessions['shown']), 'status'))
        ->not->toContain(AgentSessionStatus::Gone->value);
});

it('does not rewrite tokens for sessions that have already gone', function (): void {
    // The loops in `Installations` run inside the transaction holding the feed's sentinel row,
    // which every writer in the fleet takes before inserting -- so their length is the fleet's
    // write latency. Nothing deletes a session row, so unbounded means unbounded forever.
    $installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);

    [$gone] = $this->startAgentSession($installation);
    [$alive] = $this->startAgentSession($installation);

    $this->service(SessionPresence::class)->revoke($gone);

    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $rewritten = $this->service(Installations::class)
        ->setAbility($installation, Ability::TasksCreate, true);

    // One live session holds one token. A loop over every row ever created would report two here
    // before it reported anything else, and the count is what tells them apart.
    expect($rewritten)->toBe(1)
        ->and($queries)->toBeGreaterThan(0);
});

it('writes one event for a change, and none for a repeat of it', function (): void {
    // `SessionPresence` makes every write conditional on the row and treats the changed count as
    // the decision, so `SessionGone` fires exactly once however a session ended. These two paths
    // did not, and a control the view declines to draw is not a boundary -- the action is
    // reachable whatever the page renders.
    [$installation] = installationWithSession($this, $this->developer);

    $component = Livewire::actingAs($this->admin)->test(Administration::class);

    $component->call('grant', $installation->id, Ability::TasksCreate->value);
    $component->call('grant', $installation->id, Ability::TasksCreate->value);

    expect(FleetEvent::query()->where('type', FleetEventType::InstallationAbilityGranted)->count())->toBe(1);

    $component->call('revokeInstallation', $installation->id);

    $revokedAt = $installation->refresh()->revoked_at;

    // Far enough ahead that a second stamp cannot read as the same instant
    Carbon::setTestNow(Carbon::now()->addMinutes(5));

    $component->call('revokeInstallation', $installation->id);

    expect(FleetEvent::query()->where('type', FleetEventType::InstallationRevoked)->count())->toBe(1)
        // The time the decision was actually made, not the time somebody clicked again
        ->and($installation->refresh()->revoked_at?->toIso8601String())->toBe($revokedAt?->toIso8601String());

    Carbon::setTestNow();
});

it('can revoke an installation that sorts past the page', function (): void {
    // #114's sharpest criterion. This list is the only interface for revoking a credential, so an
    // installation the page cannot reach is a control that is not there -- and enrolment is
    // something any allowlisted developer can approve, with no unique constraint on
    // (user_id, harness, machine_label), so pushing one off the page costs an attacker nothing.
    $size = Administration::PER_PAGE;

    $target = $this->approveInstallation($this->developer, machineLabel: 'the-oldest');

    // Seeded past the page, all of them newer, so the target cannot be on page one by luck
    foreach (range(1, $size + 2) as $n) {
        $this->approveInstallation($this->developer, machineLabel: 'box-'.$n);
    }

    $reader = $this->service(InstallationList::class);

    $first = $reader->everything($size);

    expect($first['installations'])->toHaveCount($size)
        ->and($first['more'])->toBeTrue()
        ->and(array_column($first['installations'], 'id'))->not->toContain($target->id);

    // Walk to it exactly as the button does, bounded so a broken cursor fails rather than hangs
    $ids = [];
    $page = $first;

    for ($i = 0; $i < 10 && $page['more']; $i++) {
        $page = $reader->everything($size, Scope::Live, $page['cursor']);
        $ids = [...$ids, ...array_column($page['installations'], 'id')];
    }

    expect($ids)->toContain($target->id);

    // And reaching it is not the point unless it can then be acted on
    Livewire::actingAs($this->admin)
        ->test(Administration::class)
        ->call('revokeInstallation', $target->id);

    expect($target->refresh()->revoked_at)->not->toBeNull();
});

it('does not let a revoked or expired installation displace a live one', function (): void {
    // A retired installation is behind the scope rather than sorted below a live one. Sorting
    // would put a mutable column in the ordering, and #83 records what a cursor over one of those
    // does: it skips rows and says nothing.
    $size = Administration::PER_PAGE;

    $live = $this->approveInstallation($this->developer, machineLabel: 'still-here');

    // Every one of these is newer than the live installation, so under a plain `id desc` with no
    // scope they would push it off the page entirely
    foreach (range(1, $size + 2) as $n) {
        $dead = $this->approveInstallation($this->developer, machineLabel: 'dead-'.$n);

        $dead->forceFill($n % 2 === 0
            ? ['revoked_at' => Carbon::now()]
            : ['expires_at' => Carbon::now()->subDay()])->save();
    }

    $reader = $this->service(InstallationList::class);

    $page = $reader->everything($size);

    // The live one is on the first page, and it is the only thing there
    expect(array_column($page['installations'], 'id'))->toBe([$live->id])
        ->and($page['more'])->toBeFalse()
        ->and($page['live'])->toBe(1)
        ->and($page['retired'])->toBe($size + 2);

    // The control: they are not gone, only out of scope. Widening finds them, which is what makes
    // the assertion above about scoping rather than about the rows being absent.
    $all = $reader->everything($size, Scope::All);

    expect($all['installations'])->toHaveCount($size)
        ->and($all['more'])->toBeTrue();
});

it('does not offer a next page of installations on an exact multiple', function (): void {
    // The off-by-one, in the only shape that separates `>` from `>=` and `$size + 1` from
    // `$size + 0`. Every other paging test here seeds size+2.
    $size = 2;

    // One installation exists from `installationWithSession()` elsewhere; build exactly four here
    foreach (range(1, 4) as $n) {
        $this->approveInstallation($this->developer, machineLabel: 'exact-'.$n);
    }

    $reader = $this->service(InstallationList::class);

    $first = $reader->everything($size);

    expect($first['installations'])->toHaveCount($size)
        ->and($first['more'])->toBeTrue();

    $second = $reader->everything($size, Scope::Live, $first['cursor']);

    expect($second['installations'])->toHaveCount($size)
        ->and($second['more'])->toBeFalse();
});

it('clamps a page size that makes no sense', function (): void {
    // Both ends of `max(1, min($limit, MAX_PAGE))`, neither of which any other call here exercises.
    // The fixture is larger than the clamp on purpose: with one installation in the table,
    // `max(1, …)` and `max(2, …)` both return that one row and the assertion cannot see it move.
    foreach (range(1, 3) as $n) {
        $this->approveInstallation($this->developer, machineLabel: 'clamp-'.$n);
    }

    $reader = $this->service(InstallationList::class);

    expect($reader->everything(0)['installations'])->toHaveCount(1)
        ->and($reader->everything(-5)['installations'])->toHaveCount(1)
        ->and(InstallationList::MAX_PAGE)->toBe(200);
});

it('says how many of an installation`s sessions it left out', function (): void {
    // `hidden` is the count that stops a bounded session list reading as the whole of one. Seeded
    // past `SESSIONS_PER_INSTALLATION` so the number is one this test chose rather than zero, and
    // the floor at nought is exercised by every other test here, where nothing is hidden.
    $installation = $this->approveInstallation($this->developer);

    $over = 2;

    foreach (range(1, InstallationList::SESSIONS_PER_INSTALLATION + $over) as $ignored) {
        $this->startAgentSession($installation);
    }

    $row = arrayValue(collect($this->service(InstallationList::class)->everything(50)['installations'])
        ->firstWhere('id', $installation->id));

    $sessions = arrayValue($row['sessions']);

    expect($sessions['shown'])->toHaveCount(InstallationList::SESSIONS_PER_INSTALLATION)
        ->and($sessions['hidden'])->toBe($over)
        ->and($sessions['gone'])->toBe(0);

    // The floor, which is the half a count-only assertion misses: an installation holding fewer
    // sessions than the bound hides none, and `max(0, …)` is what stops the subtraction going
    // negative and reporting a number of hidden rows that do not exist.
    $small = $this->approveInstallation($this->developer, machineLabel: 'just-one');

    $this->startAgentSession($small);

    $smallRow = arrayValue(collect($this->service(InstallationList::class)->everything(50)['installations'])
        ->firstWhere('id', $small->id));

    expect(arrayValue($smallRow['sessions'])['hidden'])->toBe(0);
});
