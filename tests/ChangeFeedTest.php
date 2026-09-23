<?php

declare(strict_types=1);

/**
 * The fleet's change feed, as a developer sees it.
 *
 * @command  vendor/bin/pest --compact tests/ChangeFeedTest.php
 */

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RobotCouncil\Access\Ability;
use RobotCouncil\Livewire\ChangeFeed;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\FleetFeed;
use RobotCouncil\Support\Installations;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);

    $this->installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);

    [$this->session, $this->token] = $this->startAgentSession($this->installation);

    $this->actingAs($this->developer, 'web');
});

/**
 * A session under a second developer, whose narration is nobody else's to read under #29.
 *
 * @param  TestCase  $case  The test case.
 * @param  list<string>  $abilities  What the installation is granted.
 * @return array{AgentSession, string} The session and its token.
 */
function otherSession(TestCase $case, array $abilities = [Ability::EventsPost->value]): array
{
    $other = $case->enrollDeveloper(77, login: 'somebody-else');

    $installation = $case->approveInstallation($other, $abilities, machineLabel: 'their-box');

    return $case->startAgentSession($installation);
}

it('names both the owner and the admin on an administrative event', function (): void {
    // **The gap that let the defect through the first time.** #115 repointed `actor` from the
    // admin to the installation's owner and put the admin in `performed_by` -- but no view read
    // `performed_by`, so the one rendered audit surface went from naming the admin to naming the
    // developer whose agent was acted ON. The payload was right and the page was inverted, and
    // every assertion in this file was about narration and session events, so nothing caught it.
    //
    // **Asserted in ORDER, on one row, rather than as two page-wide substrings.** `assertSee` is a
    // whole-page check: `beforeEach` starts a session, whose `session.joined` row already names
    // `octodev`, so a bare `assertSee('octodev')` here would be satisfied by that row whatever the
    // administrative row said. `latest()` orders newest first, so the administrative row renders
    // above it and the sequence pins both names to that row.
    $admin = $this->enrollDeveloper(77, 'octoadmin');

    $this->service(Installations::class)
        ->setAbility($this->installation, Ability::LocksAcquire, true, keyValue($admin->getKey()));

    Livewire::test(ChangeFeed::class)
        ->assertSeeInOrder([
            FleetEventType::InstallationAbilityGranted->value,
            'octodev',
            'by octoadmin',
        ]);
});

it('names one person once, where the actor and the subject are the same developer', function (): void {
    // **A developer re-enrolling their own machine supersedes their own installation**, so
    // `Installations::createFrom()` revokes it and passes the approver -- who is that
    // installation's own owner. Actor and subject are then the same person, and a view that only
    // asked whether `performed_by` was present would print `octodev by octodev`.
    $code = $this->service(DeviceCodes::class)->issue(
        [Ability::EventsPost->value],
        'claude-code',
        'workbench',
        hash('sha256', 'v'),
        null
    );

    $this->service(DeviceCodes::class)
        ->approve($code->record, keyValue($this->developer->getKey()), [Ability::EventsPost->value]);

    $this->service(Installations::class)->createFrom($code->record->refresh());

    Livewire::test(ChangeFeed::class)
        ->assertSee(FleetEventType::InstallationRevoked->value)
        ->assertSee('octodev')
        // The whole point: one name, not the same name twice.
        ->assertDontSee('by octodev');
});

it('names nobody where nobody signed in acted', function (): void {
    // The control for the administrative test. **What it discriminates is naming `actor` in the
    // by-clause** -- a view that read the wrong key would render `octodev by octodev` here.
    // Deleting the `@if` outright is caught elsewhere and more loudly: `performed_by` is null on
    // an agent's event, so an unguarded read raises `Trying to access array offset on null`, which
    // `HandleExceptions` turns into an `ErrorException` and four other tests in this file fail on.
    app(FleetEvents::class)->record(
        FleetEventType::Narration,
        $this->session,
        'Nobody administered this.',
    );

    Livewire::test(ChangeFeed::class)
        ->assertSee('Nobody administered this.')
        ->assertSee('octodev')
        ->assertDontSee('by octodev');
});

it('shows an event with its type, body, actor and age', function (): void {
    app(FleetEvents::class)->record(
        FleetEventType::Narration,
        $this->session,
        'Rebuilding the index now.',
    );

    Livewire::test(ChangeFeed::class)
        ->assertSee('Rebuilding the index now.')
        ->assertSee('octodev')
        ->assertSeeHtml('<span class="badge badge-sm">'.FleetEventType::Narration->value.'</span>')
        ->assertSee('ago')

        // Asserted absent, because nothing else in this file does. Without it the badge's condition
        // could be deleted -- rendering `coordinator` against every row -- and the whole suite
        // would stay green, including the test that exists to prove the flag is not retroactive.
        ->assertDontSee('coordinator');
});

it("shows another developer's narration, which is what #73 decided", function (): void {
    // #29 shows narration only to its own developer's sessions and to coordinators. A signed-in
    // developer is neither, so the agent-facing read hides this and the dashboard does not.
    [$theirs] = otherSession($this);

    app(FleetEvents::class)->record(FleetEventType::Narration, $theirs, 'Only they would normally see this.');

    // The control: read as this developer's own agent, that narration is absent while the state
    // changes #29 sends to everyone are present -- so the read worked and the filter is what hid it
    $asAgent = app(FleetFeed::class)->after($this->session, 0, 50);

    $bodies = array_column($asAgent['events'], 'body');

    expect($bodies)->not->toContain('Only they would normally see this.')
        ->and($bodies)->not->toBeEmpty();

    Livewire::test(ChangeFeed::class)
        ->assertSee('Only they would normally see this.')
        ->assertSee('somebody-else');
});

it('puts the newest event first', function (): void {
    $events = app(FleetEvents::class);

    $events->record(FleetEventType::Narration, $this->session, 'The older one.');
    $events->record(FleetEventType::Narration, $this->session, 'The newer one.');

    Livewire::test(ChangeFeed::class)->assertSeeHtmlInOrder(['The newer one.', 'The older one.']);
});

it('keeps the coordinator flag as it was when the event was written', function (): void {
    [$coordinator] = otherSession($this, [Ability::CoordinatorDirect->value, Ability::EventsPost->value]);

    app(FleetEvents::class)->record(
        FleetEventType::Directive,
        $coordinator,
        'Everyone pause.',
        withCoordinator: true,
    );

    Livewire::test(ChangeFeed::class)->assertSee('coordinator');

    // Revoking the ability afterwards does not rewrite history: #23 records what was true at write
    // time precisely so that a later revocation is not retroactive
    $coordinator->installation->forceFill(['granted_abilities' => [Ability::EventsPost->value]])->save();

    Livewire::test(ChangeFeed::class)
        ->assertSee('Everyone pause.')
        ->assertSee('coordinator');
});

it('shows an event an agent commits without the page being reloaded', function (): void {
    $feed = Livewire::test(ChangeFeed::class)->assertDontSee('Posted while the page was open.');

    app(FleetEvents::class)->record(FleetEventType::Narration, $this->session, 'Posted while the page was open.');

    $feed->call('$refresh')->assertSee('Posted while the page was open.');
});

it('renders a hostile body as text', function (): void {
    // A body is prose and has no charset limit, which is exactly what #67 exists for
    app(FleetEvents::class)->record(FleetEventType::Narration, $this->session, '<script>alert(1)</script>');

    $html = Livewire::test(ChangeFeed::class)->html();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});

it('says the server acted when an event has no session', function (): void {
    // The sweep writes events with no session at all, and a feed that said "an unknown account"
    // for those would suggest a missing record rather than a server-side action
    app(FleetEvents::class)->record(FleetEventType::SessionGone, null, 'A session ended.');

    Livewire::test(ChangeFeed::class)->assertSee('the server')->assertDontSee('an unknown account');
});

it('polls on the interval the dashboard resolved', function (): void {
    Livewire::test(ChangeFeed::class)->assertSeeHtml('wire:poll.5s');

    Livewire::test(ChangeFeed::class, ['pollSeconds' => 30])->assertSeeHtml('wire:poll.30s');
});

it('will not let a client change the polling interval', function (): void {
    Livewire::test(ChangeFeed::class)->set('pollSeconds', 0);
})->throws(CannotUpdateLockedPropertyException::class);

it('shows the feed through the gate, and refuses a stranger', function (): void {
    app(FleetEvents::class)->record(FleetEventType::Narration, $this->session, 'Visible to a developer.');

    $this->get(route('robot-council.feed'))->assertOk()->assertSee('Visible to a developer.');

    $stranger = $this->enrollDeveloper(9999, login: 'stranger');

    $this->actingAs($stranger, 'web')
        ->get(route('robot-council.feed'))
        ->assertForbidden()
        ->assertDontSee('Visible to a developer.');
});

it('reaches events older than the first page, and comes back', function (): void {
    // #76 asked for a cursor rather than a fixed head window, and a feed is why: what falls out of
    // a window is unreachable rather than merely unsorted. One presence sweep over a hundred lapsed
    // sessions writes a hundred events in a burst.
    $events = app(FleetEvents::class);

    foreach (range(1, ChangeFeed::PER_PAGE + 1) as $n) {
        // Zero-padded, because `Event number 1` is a substring of `Event number 10` and an
        // `assertDontSee` on the unpadded form can never pass once the page holds a teens row
        $events->record(FleetEventType::Narration, $this->session, sprintf('Event number %03d', $n));
    }

    $oldest = 'Event number 001';

    $feed = Livewire::test(ChangeFeed::class);

    // The newest page does not reach the oldest event
    $feed->assertSee(sprintf('Event number %03d', ChangeFeed::PER_PAGE + 1))->assertDontSee($oldest);

    $seen = FleetEvent::query()->orderByDesc('id')->skip(ChangeFeed::PER_PAGE - 1)->first();

    $feed->call('showOlder', $seen?->id)->assertSee($oldest);

    // And back, because a cursor that cannot be left is a trap
    $feed->call('showLatest')->assertDontSee($oldest);
});

it('will not let the updates map move the feed cursor', function (): void {
    Livewire::test(ChangeFeed::class)->set('before', 1);
})->throws(CannotUpdateLockedPropertyException::class);

it("does not carry an event's meta into the render context", function (): void {
    // `meta` is up to 4096 bytes of agent-supplied structured data per row. Asserted on the view
    // data rather than on the rendered HTML: the view renders no meta either way, so an assertion
    // against the output cannot fail and would have passed with the projection removed. What this
    // guards is that the data is not one `{{ }}` away from the page with nobody having re-derived
    // whether it may be shown.
    app(FleetEvents::class)->record(
        FleetEventType::Narration,
        $this->session,
        'A body.',
        ['client' => ['secret_looking_key' => 'should-not-render']],
    );

    $events = arrayValue(Livewire::test(ChangeFeed::class)->viewData('events'));

    $newest = arrayValue($events[0] ?? []);

    expect($events)->not->toBeEmpty()
        ->and($newest)->not->toHaveKey('meta')
        ->and($newest['body'])->toBe('A body.');
});

it('clamps what it returns however much is asked for', function (): void {
    // `latest()` has no test of its own otherwise: every other case here reaches it through the
    // component, which passes a constant.
    foreach (range(1, 5) as $n) {
        app(FleetEvents::class)->record(FleetEventType::Narration, $this->session, sprintf('Body %d', $n));
    }

    $feed = app(FleetFeed::class);

    expect($feed->latest(FleetFeed::MAX_PAGE + 1000))->toHaveCount(6)
        ->and($feed->latest(2))->toHaveCount(2)
        ->and($feed->latest(0))->toHaveCount(1);
});
