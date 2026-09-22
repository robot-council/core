<?php

declare(strict_types=1);

/**
 * Naming the sessions a directive expects to act on.
 *
 * `directive_post` told the whole fleet and took no target, so an instruction meant for one agent
 * arrived at every agent with nothing recording which of them was expected to act. Each one then
 * had to decide for itself whether it was the addressee, and on a fleet where several are idle and
 * capable, more than one acts -- the duplicated work being discovered after it has been done.
 * Deciding "is this mine" is also the judgement the feed's visibility rule exists to avoid asking of
 * a process that may have shell access (#135).
 *
 * **Naming targets changes who is expected to act and never who receives it.** That is the property
 * these tests bound on both sides: a targeted directive still reaches every agent an untargeted one
 * reaches, and an untargeted directive is byte-identical to one posted before this existed.
 *
 * **Nothing recorded here is read back from what the poster claimed.** Every id is resolved to a
 * session row and the row's own key is what is written, so a string, a duplicate, or an id for a
 * session that has gone cannot reach the event. The tests assert on the ROW rather than on the
 * response, for the reason CLAUDE.md gives about the instance a store hands back.
 *
 * @command  vendor/bin/pest --compact tests/DirectiveTargetsTest.php
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\DirectiveTargets;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->coordinator = $this->enrollDeveloper(4242, login: 'octodev');
    $this->other = $this->enrollDeveloper(77, login: 'otherdev');
});

/**
 * Start a session for a developer, with the abilities its token should carry.
 *
 * Named apart from `FleetEventFeedTest`'s helper rather than shared, because a function redeclared
 * across two test files is a fatal error and `phpunit.xml.dist` orders files randomly, so which one
 * loads first is not fixed.
 *
 * @param  TestCase  $case  The running test.
 * @param  User  $developer  Whose session it is.
 * @param  list<string>  $abilities  What its token carries.
 * @return array{AgentSession, string} The session and its token.
 */
function targetedSessionFor(TestCase $case, User $developer, array $abilities): array
{
    $installation = $case->approveInstallation(
        $developer,
        $abilities,
        machineLabel: 'm-'.keyValue($developer->getKey()).'-'.Str::random(4)
    );

    return $case->startAgentSession($installation);
}

/**
 * The one directive event on record.
 */
function theDirective(): FleetEvent
{
    return FleetEvent::query()->where('type', FleetEventType::Directive->value)->sole();
}

it('records no targets when none are named, exactly as before', function (): void {
    [, $token] = targetedSessionFor($this, $this->coordinator, [Ability::CoordinatorDirect->value]);

    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'everyone stop'])
        ->assertCreated();

    // The whole compatibility claim in one assertion: an untargeted directive gains no key. A
    // `targets => []` here would be a new shape for every existing caller.
    expect(theDirective()->meta)->toBeNull();
});

it('leaves a client meta payload alone when no targets are named', function (): void {
    [, $token] = targetedSessionFor($this, $this->coordinator, [Ability::CoordinatorDirect->value]);

    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), [
            'body' => 'everyone stop',
            'meta' => ['reason' => 'deploying'],
        ])
        ->assertCreated();

    expect(orderedMeta(theDirective()->meta))->toBe(['client' => ['reason' => 'deploying']]);
});

it('records one named session', function (): void {
    [, $token] = targetedSessionFor($this, $this->coordinator, [Ability::CoordinatorDirect->value]);
    [$target] = targetedSessionFor($this, $this->other, [Ability::EventsPost->value]);

    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), [
            'body' => 'rebase your branch',
            'targets' => [$target->id],
        ])
        ->assertCreated();

    expect(arrayValue(theDirective()->meta)['targets'] ?? null)->toBe([$target->id]);
});

it('records several named sessions, deduplicated and ordered', function (): void {
    [, $token] = targetedSessionFor($this, $this->coordinator, [Ability::CoordinatorDirect->value]);
    [$first] = targetedSessionFor($this, $this->other, [Ability::EventsPost->value]);
    [$second] = targetedSessionFor($this, $this->other, [Ability::EventsPost->value]);

    $ids = [$second->id, $first->id, $second->id];

    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), [
            'body' => 'both of you stop',
            'targets' => $ids,
        ])
        ->assertCreated();

    // **Derived, not echoed.** The poster sent the second id twice and the pair out of order; what
    // is recorded is neither. A check that compared against the input would pass on an
    // implementation that wrote the input straight through, which is the thing #135 refuses.
    $recorded = arrayValue(theDirective()->meta)['targets'] ?? null;

    expect($recorded)->toBe([$first->id, $second->id])
        ->and($recorded)->not->toBe($ids);
});

it('refuses an id that names no session, and writes nothing', function (): void {
    [, $token] = targetedSessionFor($this, $this->coordinator, [Ability::CoordinatorDirect->value]);

    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), [
            'body' => 'you there',
            'targets' => [999_999],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('targets');

    expect(FleetEvent::query()->where('type', FleetEventType::Directive->value)->count())->toBe(0);
});

it('refuses a session that has gone, and writes nothing', function (): void {
    [, $token] = targetedSessionFor($this, $this->coordinator, [Ability::CoordinatorDirect->value]);
    [$departed] = targetedSessionFor($this, $this->other, [Ability::EventsPost->value]);

    $this->markSessionGone($departed);

    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), [
            'body' => 'you there',
            'targets' => [$departed->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('targets');

    expect(FleetEvent::query()->where('type', FleetEventType::Directive->value)->count())->toBe(0);
});

it('refuses a live session named alongside one that has gone', function (): void {
    [, $token] = targetedSessionFor($this, $this->coordinator, [Ability::CoordinatorDirect->value]);
    [$live] = targetedSessionFor($this, $this->other, [Ability::EventsPost->value]);
    [$departed] = targetedSessionFor($this, $this->other, [Ability::EventsPost->value]);

    $this->markSessionGone($departed);

    // All or nothing. Recording the live half of a partly-unresolvable list would leave a directive
    // whose target list is quietly shorter than what was asked for, which is the reading a
    // coordinator is least able to notice.
    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), [
            'body' => 'you two',
            'targets' => [$live->id, $departed->id],
        ])
        ->assertStatus(422);

    expect(FleetEvent::query()->where('type', FleetEventType::Directive->value)->count())->toBe(0);
});

it('reaches a session the directive does not name', function (): void {
    [, $token] = targetedSessionFor($this, $this->coordinator, [Ability::CoordinatorDirect->value]);
    [$named] = targetedSessionFor($this, $this->other, [Ability::EventsPost->value]);
    // No directive-related ability at all. Reading the feed needs a session, not a permission --
    // the visibility rule is what decides what comes back, and a directive is unrestricted.
    [, $bystander] = targetedSessionFor($this, $this->other, [Ability::TasksCreate->value]);

    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), [
            'body' => 'rebase your branch',
            'targets' => [$named->id],
        ])
        ->assertCreated();

    // The property the whole design rests on: naming somebody does not narrow delivery. A session
    // that is not a target reads the directive, and reads who it was for.
    $feed = $this->machine($bystander)
        ->getJson(route('robot-council.events.index'))
        ->assertOk()
        ->json('events');

    $directives = array_values(array_filter(
        arrayValue($feed),
        static fn (mixed $event): bool => \is_array($event)
            && ($event['type'] ?? null) === FleetEventType::Directive->value
    ));

    expect($directives)->toHaveCount(1)
        ->and(arrayValue(arrayValue($directives[0])['meta'])['targets'] ?? null)->toBe([$named->id]);
});

it('refuses more targets than one directive may name', function (): void {
    [, $token] = targetedSessionFor($this, $this->coordinator, [Ability::CoordinatorDirect->value]);

    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), [
            'body' => 'everyone',
            'targets' => range(1, DirectiveTargets::MAX + 1),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('targets');

    expect(FleetEvent::query()->where('type', FleetEventType::Directive->value)->count())->toBe(0);
});

it('holds its own bounds when the store is called directly', function (): void {
    // **The endpoint's validator protects the endpoint and nothing else.** `DirectiveTargets` is a
    // public method on a final class a host can resolve and call, and what it decides is who a
    // directive says must act -- so the bounds live here as well as in the two validators in front
    // of it. Driven through the store rather than the route on purpose.
    [$live] = targetedSessionFor($this, $this->other, [Ability::EventsPost->value]);

    expect(DirectiveTargets::resolve(null))->toBeEmpty()
        ->and(DirectiveTargets::resolve([]))->toBeEmpty()
        ->and(DirectiveTargets::resolve('not a list'))->toBeEmpty()

        // A numeric string is what Laravel's own `integer` rule admits, so the store admits it too
        // and records the resolved integer rather than the string it was handed.
        ->and(DirectiveTargets::resolve([(string) $live->id]))->toBe([$live->id]);

    // **Each refusal is checked by its message, not merely by its class.** All three throw
    // `ValidationException`, so a test asserting only the type passes on an implementation that
    // reaches the wrong branch. Mutation testing found exactly that: `0` is refused by the id check
    // and, if that check were weakened, by the lookup behind it -- and nothing could tell which.
    expect(fn (): array => DirectiveTargets::resolve([0]))
        ->toThrow(ValidationException::class, 'The targets field must name session ids.');

    expect(fn (): array => DirectiveTargets::resolve(['nonsense']))
        ->toThrow(ValidationException::class, 'The targets field must name session ids.')
        ->and(fn (): array => DirectiveTargets::resolve([999_999]))->toThrow(ValidationException::class, 'The targets field must name sessions that can still be worked.');
});

it('admits exactly as many targets as it says it does', function (): void {
    // The boundary, and why it earns a test: `>` and `>=` differ on exactly one input, and a list
    // of `MAX` ids is the only one that tells them apart. Read by which refusal comes back rather
    // than by success, so it needs no fixtures -- at `MAX` the count check has to pass, leaving the
    // lookup to refuse.
    expect(fn (): array => DirectiveTargets::resolve(range(1, DirectiveTargets::MAX)))
        ->toThrow(ValidationException::class, 'The targets field must name sessions that can still be worked.');

    expect(fn (): array => DirectiveTargets::resolve(range(1, DirectiveTargets::MAX + 1)))
        ->toThrow(ValidationException::class, 'may not name more than');
});

it('names the same bound the README does', function (): void {
    // The README tells a reader they may pass up to 50 ids. Nothing else would notice the two
    // drifting apart, and a documented bound that is wrong is worse than an undocumented one.
    // Whitespace is collapsed first, because the README is hard-wrapped and the sentence this
    // looks for spans a line break -- a byte-exact search finds nothing and reports it as drift.
    $readme = preg_replace('/\s+/', ' ', (string) file_get_contents(__DIR__.'/../README.md'));

    expect(DirectiveTargets::MAX)->toBe(50)
        ->and($readme)->toContain('up to 50 session ids');
});

it('orders the recorded targets by the database, not by luck', function (): void {
    // `get()` promises no order, so the guarantee has to come from the query. Named ids are passed
    // descending; what comes back is ascending.
    [$first] = targetedSessionFor($this, $this->other, [Ability::EventsPost->value]);
    [$second] = targetedSessionFor($this, $this->other, [Ability::EventsPost->value]);

    expect(DirectiveTargets::resolve([$second->id, $first->id]))->toBe([$first->id, $second->id]);
});
