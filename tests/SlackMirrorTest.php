<?php

declare(strict_types=1);

/**
 * The Slack mirror: what reaches Slack, what never does, and what happens when Slack does not
 * answer.
 *
 * Slack is one-way. Nothing in the package reads from it and no coordination decision depends on
 * it, so every failure here has to cost visibility and never correctness.
 *
 * The queue driver is switched to `database` throughout. Under Testbench's default `sync` driver a
 * job runs inline the moment it is dispatched, which would both hide the `afterCommit` deferral
 * this file exists to check and run the mirror before any assertion could be made about it.
 *
 * @command  vendor/bin/pest --compact tests/SlackMirrorTest.php
 */

use Illuminate\Contracts\Queue\Job as QueuedJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use RobotCouncil\Access\Ability;
use RobotCouncil\Jobs\MirrorEventToSlack;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\FleetEvents;
use RobotCouncil\Support\HostUsers;
use RobotCouncil\Support\SlackMirror;
use RobotCouncil\Tests\TestCase;

const WEBHOOK = 'https://hooks.slack.example/services/T000/B000/a-secret-path-nobody-should-log';

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    config()->set('queue.default', 'database');

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
});

/**
 * Run the mirror for one event, as a worker would.
 *
 * @param  TestCase  $case  The test case.
 * @param  FleetEvent  $event  The event to mirror.
 * @param  QueuedJob|MockInterface|null  $queued  The queue's handle, when the test needs to observe a release.
 */
function runTheMirror(TestCase $case, FleetEvent $event, mixed $queued = null): void
{
    $job = new MirrorEventToSlack($event->id);

    if ($queued instanceof QueuedJob) {
        $job->setJob($queued);
    }

    $job->handle($case->service(SlackMirror::class), $case->service(HostUsers::class));
}

/**
 * The text of every message sent to Slack so far.
 *
 * @return list<string> The messages, in the order they were sent.
 */
function slackMessages(): array
{
    /** @var list<string> $messages */
    $messages = collect(Http::recorded())
        ->map(function (array $pair): string {
            $text = arrayValue($pair[0]->data())['text'] ?? '';

            return \is_string($text) ? $text : '';
        })
        ->values()
        ->all();

    return $messages;
}

it('queues nothing when no webhook is configured', function (): void {
    config()->set('robot-council.slack.webhook_url');

    $installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);
    $this->startAgentSession($installation);

    // The control: an event was written, so an empty queue means the mirror declined rather than
    // nothing having happened
    expect(FleetEvent::query()->count())->toBeGreaterThan(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('queues exactly one job per committed event, on its own queue', function (): void {
    config()->set('robot-council.slack.webhook_url', WEBHOOK);
    config()->set('robot-council.slack.queue', 'the-slack-queue');

    $installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);
    [, $token] = $this->startAgentSession($installation);

    $this->machine($token)
        ->postJson(route('robot-council.events.store'), ['body' => 'a thing happened'])
        ->assertCreated();

    expect(DB::table('jobs')->count())->toBe(FleetEvent::query()->count())
        ->and(DB::table('jobs')->pluck('queue')->unique()->all())->toBe(['the-slack-queue']);
});

it('queues nothing, and records nothing, when the surrounding transaction rolls back', function (): void {
    config()->set('robot-council.slack.webhook_url', WEBHOOK);

    $before = FleetEvent::query()->count();

    try {
        DB::transaction(function (): void {
            $this->service(FleetEvents::class)->record(
                FleetEventType::SessionJoined,
                null,
                'a session that never was'
            );

            // The state change the event was recorded alongside fails
            throw new RuntimeException('the state change failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(FleetEvent::query()->count())->toBe($before)
        ->and(FleetEvent::query()->where('body', 'a session that never was')->exists())->toBeFalse()

        // The job was dispatched after commit, and there was no commit
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('sends the type, the actor, and the body, and nothing else', function (): void {
    config()->set('robot-council.slack.webhook_url', WEBHOOK);
    Http::fake([WEBHOOK => Http::response('ok', 200)]);

    $installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);
    [$session] = $this->startAgentSession($installation);

    $event = $this->service(FleetEvents::class)->record(
        FleetEventType::Narration,
        $session,
        'rebased onto main',
        ['client' => ['secret_looking' => 'a-token-shaped-value'], 'payload' => 'never', 'result' => 'never']
    );

    runTheMirror($this, $event);

    $messages = slackMessages();

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('narration')
        ->toContain('octodev')
        ->toContain('rebased onto main')->not->toContain('a-token-shaped-value')->not->toContain('payload')->not->toContain('result')->not->toContain('meta');
});

it('escapes what Slack would otherwise read as markup or a mention', function (): void {
    config()->set('robot-council.slack.webhook_url', WEBHOOK);
    Http::fake([WEBHOOK => Http::response('ok', 200)]);

    $installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);
    [$session] = $this->startAgentSession($installation);

    $event = $this->service(FleetEvents::class)->record(
        FleetEventType::Narration,
        $session,
        'hey <!channel> see <https://example.com|this link> & decide'
    );

    runTheMirror($this, $event);

    // Nothing Slack would act on survives, and the ampersand is escaped once rather than twice
    expect(slackMessages()[0])->toContain('&lt;!channel&gt;')
        ->toContain('&lt;https://example.com|this link&gt;')
        ->toContain('&amp; decide')
        ->not->toContain('<!channel>')
        ->not->toContain('&amp;lt;');
});

it('does not put the webhook URL in the exception when Slack cannot be reached', function (): void {
    config()->set('robot-council.slack.webhook_url', WEBHOOK);

    Http::fake(fn () => throw new ConnectionException('cURL error 6: could not resolve host for '.WEBHOOK));

    $installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);
    [$session] = $this->startAgentSession($installation);

    $event = $this->service(FleetEvents::class)->record(FleetEventType::Narration, $session, 'anything');

    $thrown = null;

    try {
        runTheMirror($this, $event);
    } catch (RuntimeException $runtimeException) {
        $thrown = $runtimeException;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class);

    // An exception lands in the failed-jobs table, in the log, and in whatever error reporter the
    // host uses, so the whole chain has to be clean rather than just the top message
    $chain = '';

    for ($e = $thrown; $e instanceof Throwable; $e = $e->getPrevious()) {
        $chain .= $e->getMessage().' '.$e->getTraceAsString();
    }

    expect($chain)->not->toContain(WEBHOOK)
        ->not->toContain('a-secret-path-nobody-should-log')

        // The original is dropped rather than chained, because its message carries the URL
        ->and($thrown?->getPrevious())->toBeNull();
});

it('waits as long as Slack asks after a 429, rather than failing', function (): void {
    config()->set('robot-council.slack.webhook_url', WEBHOOK);

    Http::fake([WEBHOOK => Http::response('rate limited', 429, ['Retry-After' => '17'])]);

    $installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);
    [$session] = $this->startAgentSession($installation);

    $event = $this->service(FleetEvents::class)->record(FleetEventType::Narration, $session, 'anything');

    $queued = Mockery::mock(QueuedJob::class);
    $queued->shouldReceive('release')->once()->with(17);

    // No exception: a 429 is Slack pacing the fleet, not the job failing
    runTheMirror($this, $event, $queued);
});

it('bounds a malformed Retry-After rather than trusting it', function (string $header, int $expected): void {
    config()->set('robot-council.slack.webhook_url', WEBHOOK);

    Http::fake([WEBHOOK => Http::response('rate limited', 429, ['Retry-After' => $header])]);

    $installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);
    [$session] = $this->startAgentSession($installation);

    $event = $this->service(FleetEvents::class)->record(FleetEventType::Narration, $session, 'anything');

    $queued = Mockery::mock(QueuedJob::class);
    $queued->shouldReceive('release')->once()->with($expected);

    runTheMirror($this, $event, $queued);
})->with([
    'a day, which would stall the queue' => ['86400', 300],
    'prose' => ['soon', 1],
    'nothing' => ['', 1],
    'negative' => ['-5', 1],
]);

it('reads nothing from Slack', function (): void {
    // The expression first, against a fixture it MUST match. Without this the silence below says
    // nothing: the package writes its own call as `Http::asJson()->post(...)`, so an expression
    // anchored on `Http::get(` could never have seen a read even if one were added.
    $readVerb = '/->\s*(get|head|patch|put|delete|send|pool)\s*\(/i';

    expect('Http::withToken(\'x\')->get($url);')->toMatch($readVerb)
        ->and("Http::asJson()->get('https://slack.com/api/conversations.history');")->toMatch($readVerb)
        ->and('Http::asJson()->post($url, []);')->not->toMatch($readVerb);

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src'));

    $callers = [];

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/\bHttp::/i', $source) !== 1) {
            continue;
        }

        $callers[] = $file->getFilename();

        // Any read verb anywhere in a file that talks HTTP at all, whatever it is chained to
        expect($source)->not->toMatch($readVerb);
    }

    // Exactly one file talks HTTP, and it issues exactly one call
    expect($callers)->toBe(['MirrorEventToSlack.php']);

    $job = (string) file_get_contents(__DIR__.'/../src/Jobs/MirrorEventToSlack.php');

    expect(preg_match_all('/\bHttp::/i', $job))->toBe(1)
        ->and($job)->toContain('->post(');
});

it('queues the mirror only after the transaction commits', function (): void {
    config()->set('robot-council.slack.webhook_url', WEBHOOK);

    // Observed from INSIDE the open transaction, which is the only place the deferral is visible.
    // Asserting after the commit cannot tell `afterCommit` from an immediate dispatch, because the
    // queue writes to `jobs` on this same connection and would roll back with everything else.
    DB::transaction(function (): void {
        $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'inside');

        expect(DB::table('jobs')->count())->toBe(0, 'the mirror must not be queued before the commit');
    });

    expect(DB::table('jobs')->count())->toBe(1);
});

it('treats anything but a 2xx as a refusal, without naming the webhook', function (int $status): void {
    config()->set('robot-council.slack.webhook_url', WEBHOOK);

    Http::fake([WEBHOOK => Http::response('no', $status)]);

    $installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);
    [$session] = $this->startAgentSession($installation);

    $event = $this->service(FleetEvents::class)->record(FleetEventType::Narration, $session, 'anything');

    $thrown = null;

    try {
        runTheMirror($this, $event);
    } catch (RuntimeException $runtimeException) {
        $thrown = $runtimeException;
    }

    // A redirect is not a delivery: `failed()` would have read 3xx as success and dropped the
    // message silently
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toContain((string) $status)
        ->and($thrown?->getMessage())->not->toContain(WEBHOOK);
})->with([
    'a redirect' => [302],
    'a client error' => [400],
    'a server error' => [500],
]);

it('does not leak a malformed webhook URL either', function (): void {
    // Guzzle raises `MalformedUriException` for this, which is not a connection failure and would
    // sail past a catch narrowed to one
    // The bracket is unclosed, so Guzzle raises `MalformedUriException` while building the URI --
    // not a connection failure, and not something a catch narrowed to one would see. No fake here
    // on purpose: a fake intercepts before the URI is parsed, so it would hide the very path this
    // test exists for, and a URI this broken never reaches the network.
    config()->set('robot-council.slack.webhook_url', 'https://[hooks.slack.example/secret-path');

    $installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);
    [$session] = $this->startAgentSession($installation);

    $event = $this->service(FleetEvents::class)->record(FleetEventType::Narration, $session, 'anything');

    $thrown = null;

    try {
        runTheMirror($this, $event);
    } catch (RuntimeException $runtimeException) {
        $thrown = $runtimeException;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->not->toContain('secret-path')
        ->and($thrown?->getPrevious())->toBeNull();
});

it('does not mirror narration when a host turns that off', function (): void {
    config()->set('robot-council.slack.webhook_url', WEBHOOK);
    config()->set('robot-council.slack.mirror_restricted', false);

    $installation = $this->approveInstallation($this->developer, [Ability::EventsPost->value]);
    [$session] = $this->startAgentSession($installation);

    $queuedForTheSessionStart = DB::table('jobs')->count();

    // The session start is a state change and was mirrored; the narration is restricted and is not
    expect($queuedForTheSessionStart)->toBeGreaterThan(0);

    $this->service(FleetEvents::class)->record(FleetEventType::Narration, $session, 'kept off the channel');

    expect(DB::table('jobs')->count())->toBe($queuedForTheSessionStart);
});
