<?php

declare(strict_types=1);

/**
 * Core fetching each board repository's open-issue count through a GitHub App (#383).
 *
 * Every test here fakes GitHub with `Http::fake()` and refuses anything else with
 * `Http::preventStrayRequests()`: the fake answers only the three endpoints it models, on
 * `api.github.com`, and returns nothing for any other request, which the framework then refuses as
 * stray -- so a request the fixture does not model fails the test rather than leaving the machine. The fixture models search's qualifiers rather than answering one number, so
 * the pull-request exclusion is a property of the query the package sends, not of the fake.
 *
 * @command  vendor/bin/pest --compact tests/BacklogFetchTest.php
 */

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery\MockInterface;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Backlog;
use RobotCouncil\Support\BacklogFetcher;
use RobotCouncil\Support\BacklogFetches;
use RobotCouncil\Support\BacklogFetchOutcome;
use RobotCouncil\Support\DiagnosisStatus;
use RobotCouncil\Support\Doctor;
use RobotCouncil\Support\GitHubApp;
use RobotCouncil\Support\GitHubAppKey;
use RobotCouncil\Support\GitHubRefusal;
use RobotCouncil\Support\LaneBoard;
use RobotCouncil\Tests\TestCase;

const FETCH_APP_ID = '424242';

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $this->installation = $this->approveInstallation($this->developer);

    // GitHub, faked once for the whole test: a second `Http::fake()` would be consulted only after
    // the first, so a test that changes what GitHub answers changes this state instead
    $state = gitHubFixtureState();
    $this->github = $state;
    fakeGitHubFrom($state);

    Http::preventStrayRequests();

    // Every log line, captured whole, for the redaction assertions
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message): void {
        $this->logged[] = $message;
    });
});

/**
 * A fixture RSA key, generated once per process, as PEM.
 *
 * @return array{private: string, public: string} The two halves.
 */
function fetchFixtureKey(): array
{
    /** @var array{private: string, public: string}|null $pair */
    static $pair = null;

    if ($pair !== null) {
        return $pair;
    }

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

    if ($key === false || ! openssl_pkey_export($key, $private) || ! is_string($private)) {
        throw new RuntimeException('Could not generate the fixture key.');
    }

    $details = openssl_pkey_get_details($key);

    if (! is_array($details) || ! is_string($details['key'] ?? null)) {
        throw new RuntimeException('Could not read the fixture key back.');
    }

    return $pair = ['private' => $private, 'public' => $details['key']];
}

/**
 * The PKCS#1 form of a PKCS#8 RSA key, which is what GitHub's `.pem` download holds.
 *
 * OpenSSL 3 exports PKCS#8 only, so the RSA key inside is unwrapped from the DER by hand:
 * PrivateKeyInfo is a SEQUENCE of a version, an algorithm, and an OCTET STRING whose contents are
 * the PKCS#1 RSAPrivateKey.
 *
 * @param  string  $pkcs8  A `BEGIN PRIVATE KEY` PEM.
 * @return string A `BEGIN RSA PRIVATE KEY` PEM.
 */
function pkcs1Pem(string $pkcs8): string
{
    $der = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pkcs8), true);

    // Read one DER element's tag, and where its contents start and how long they are
    $element = static function (string $der, int $at): array {
        $tag = ord($der[$at]);
        $length = ord($der[$at + 1]);
        $start = $at + 2;

        if ($length > 0x7F) {
            $bytes = $length & 0x7F;
            $length = (int) hexdec(bin2hex(substr($der, $start, $bytes)));
            $start += $bytes;
        }

        return [$tag, $start, $length];
    };

    [, $inside] = $element($der, 0);

    // Skip the version and the algorithm, then take the OCTET STRING's contents
    [, $versionStart, $versionLength] = $element($der, $inside);
    [, $algorithmStart, $algorithmLength] = $element($der, $versionStart + $versionLength);
    [$tag, $keyStart, $keyLength] = $element($der, $algorithmStart + $algorithmLength);

    if ($tag !== 0x04) {
        throw new RuntimeException('The fixture key is not the PKCS#8 shape expected.');
    }

    return "-----BEGIN RSA PRIVATE KEY-----\n".chunk_split(base64_encode(substr($der, $keyStart, $keyLength)), 64, "\n")."-----END RSA PRIVATE KEY-----\n";
}

/**
 * Run the scheduled command, as the scheduler would, and require it to exit zero.
 */
function runBacklogFetch(): void
{
    expect(Artisan::call('robot-council:backlog-fetch'))->toBe(0);
}

/**
 * Configure the App with the fixture key, base64-encoded as the README documents.
 */
function configureFetchApp(): void
{
    config()->set('robot-council.github.app.id', FETCH_APP_ID);
    config()->set('robot-council.github.app.private_key', base64_encode(fetchFixtureKey()['private']));
}

/**
 * Put a live lane on the board in a repository.
 *
 * @param  TestCase  $case  The test case.
 * @param  Installation  $installation  Whose lane.
 * @param  string  $repository  `owner/name`.
 */
function fetchLane(TestCase $case, Installation $installation, string $repository): void
{
    $case->service(AgentSessions::class)->start($installation, $repository, 'slot-'.substr(md5($repository), 0, 6));
}

/**
 * What the faked GitHub answers, empty: no installations and no repositories.
 *
 * @return ArrayObject<string, mixed> The state, which `fakeGitHub()` changes.
 */
function gitHubFixtureState(): ArrayObject
{
    /** @var ArrayObject<string, mixed> $state */
    $state = new ArrayObject;

    $state['installations'] = [];
    $state['repositories'] = [];
    $state['lookup'] = null;
    $state['mint'] = null;

    return $state;
}

/**
 * Change what the faked GitHub answers from here on.
 *
 * Search is modeled rather than answered: each repository has open issues and open pull requests,
 * and the count returned is what the query's `is:` qualifiers select, as GitHub's search does.
 *
 * @param  TestCase  $case  The test case, whose fixture state this changes.
 * @param  array<string, int>  $installations  Installation ids by account login, lower-cased.
 * @param  array<string, array{issues: int, pulls: int}|Closure>  $repositories  Counts by repository, or a
 *                                                                               closure answering for it.
 * @param  Closure|null  $lookup  Answers `/users/{owner}/installation` instead, given the owner.
 * @param  Closure|null  $mint  Answers `access_tokens` instead, given the installation id.
 */
function fakeGitHub(TestCase $case, array $installations, array $repositories, ?Closure $lookup = null, ?Closure $mint = null): void
{
    $state = $case->github;

    $state['installations'] = $installations;
    $state['repositories'] = $repositories;
    $state['lookup'] = $lookup;
    $state['mint'] = $mint;
}

/**
 * Fake GitHub's three endpoints from a state the test can change.
 *
 * @param  ArrayObject<string, mixed>  $state  What GitHub answers.
 */
function fakeGitHubFrom(ArrayObject $state): void
{
    Http::fake(function (Request $request) use ($state) {
        // Anything but GitHub's API is not modeled: null, which the framework refuses as stray
        if (parse_url($request->url(), PHP_URL_SCHEME) !== 'https' || parse_url($request->url(), PHP_URL_HOST) !== 'api.github.com') {
            return null;
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $installations = is_array($state['installations']) ? $state['installations'] : [];
        $repositories = is_array($state['repositories']) ? $state['repositories'] : [];

        if ($request->method() === 'GET' && preg_match('#^/users/([^/]+)/installation$#', $path, $match) === 1) {
            $owner = rawurldecode($match[1]);

            if ($state['lookup'] instanceof Closure) {
                return ($state['lookup'])($owner);
            }

            // GitHub compares logins without case
            $id = $installations[mb_strtolower($owner)] ?? null;

            return $id === null
                ? Http::response(['message' => 'Not Found'], 404)
                : Http::response(['id' => $id, 'account' => ['login' => $owner]]);
        }

        if ($request->method() === 'POST' && preg_match('#^/app/installations/(\d+)/access_tokens$#', $path, $match) === 1) {
            if ($state['mint'] instanceof Closure) {
                return ($state['mint'])((int) $match[1]);
            }

            return Http::response([
                'token' => 'ghs_installation'.$match[1].'SENTINEL',
                'expires_at' => Carbon::now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z'),
            ], 201);
        }

        if ($request->method() === 'GET' && $path === '/search/issues') {
            $terms = preg_split('/\s+/', is_string($query['q'] ?? null) ? $query['q'] : '') ?: [];
            $repository = '';

            foreach ($terms as $term) {
                if (str_starts_with($term, 'repo:')) {
                    $repository = substr($term, 5);
                }
            }

            $spec = $repositories[$repository] ?? null;

            if ($spec instanceof Closure) {
                return $spec();
            }

            if (! is_array($spec) || ! in_array('is:open', $terms, true)) {
                return Http::response(['message' => 'Validation Failed'], 422);
            }

            // Issues, pull requests, or both when the query names neither
            $issues = in_array('is:issue', $terms, true) || ! in_array('is:pr', $terms, true);
            $pulls = in_array('is:pr', $terms, true) || ! in_array('is:issue', $terms, true);

            return Http::response([
                'total_count' => ($issues ? intValue($spec['issues'] ?? null) : 0) + ($pulls ? intValue($spec['pulls'] ?? null) : 0),
                'incomplete_results' => false,
                'items' => [],
            ]);
        }

        return null;
    });
}

/**
 * Every line logged in this test, captured by the listener `beforeEach` registers.
 *
 * @param  TestCase  $case  The test case.
 * @return list<MessageLogged> The lines, in order.
 */
function loggedMessages(TestCase $case): array
{
    return array_values(array_filter(
        arrayValue($case->logged),
        static fn (mixed $message): bool => $message instanceof MessageLogged
    ));
}

/**
 * The requests sent to one endpoint.
 *
 * @param  string  $path  The path, such as `/search/issues`.
 * @return list<Request> The requests.
 */
function sentTo(string $path): array
{
    return array_values(array_map(
        static fn (array $pair): Request => $pair[0],
        array_filter(
            Http::recorded()->all(),
            static fn (array $pair): bool => str_starts_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), $path)
        )
    ));
}

/**
 * Which repository a search request asked about.
 *
 * @param  Request  $request  The request.
 * @return string The repository.
 */
function searchedRepository(Request $request): string
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    preg_match('/repo:(\S+)/', is_string($query['q'] ?? null) ? $query['q'] : '', $match);

    return $match[1] ?? '';
}

/**
 * The fetched readings, by repository.
 *
 * @return array<string, int> Counts.
 */
function fetchedReadings(): array
{
    $readings = [];

    foreach (DB::table('robot_council_backlog_readings')->whereNull('reported_by')->orderBy('id')->get() as $row) {
        $readings[stringValue($row->repository)] = intValue($row->open_issues);
    }

    return $readings;
}

it('stores one reading per board repository, and the meter shows it against the 08:00 baseline', function (): void {
    // Fetched before 08:00, which becomes today's baseline
    Carbon::setTestNow('2026-09-24 07:55:00');

    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/core');

    fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => ['issues' => 10, 'pulls' => 4]]);
    runBacklogFetch();

    Carbon::setTestNow('2026-09-24 08:05:00');
    $this->service(Backlog::class)->takeBaselines(Carbon::now());

    // Two issues opened since
    fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => ['issues' => 12, 'pulls' => 4]]);

    Carbon::setTestNow('2026-09-24 09:00:00');
    runBacklogFetch();

    $row = DB::table('robot_council_backlog_readings')->orderByDesc('id')->first();

    expect($row?->reported_by)->toBeNull()
        ->and($row?->open_issues)->toBe(12)
        ->and($this->service(LaneBoard::class)->read()['meters']['robot-council/core'])->toBe(['count' => 12, 'delta' => 2, 'age_seconds' => 0]);

    $html = Livewire::actingAs($this->developer)->test(Lanes::class)->html();

    expect($html)->toContain('data-meter="read"')
        ->and($html)->not->toContain('data-meter="unreadable"')
        ->and($html)->toMatch('/data-meter-delta>.*?<span[^>]*>up 2<\/span>/s');
});

it('sends each repository the token for its own owner, and asks nothing for an owner with no installation', function (): void {
    configureFetchApp();

    fetchLane($this, $this->installation, 'robot-council/core');
    fetchLane($this, $this->installation, 'robot-council/cli');
    fetchLane($this, $this->installation, 'UAMS-Web/site');
    fetchLane($this, $this->installation, 'nobody-installed/thing');

    // `uams-web` lower-cased, as GitHub compares logins; the board spells it `UAMS-Web`
    fakeGitHub($this, ['robot-council' => 7, 'uams-web' => 9], [
        'robot-council/core' => ['issues' => 3, 'pulls' => 1],
        'robot-council/cli' => ['issues' => 5, 'pulls' => 0],
        'UAMS-Web/site' => ['issues' => 8, 'pulls' => 2],
        'nobody-installed/thing' => ['issues' => 99, 'pulls' => 0],
    ]);

    runBacklogFetch();

    $tokens = [];

    foreach (sentTo('/search/issues') as $request) {
        $tokens[searchedRepository($request)] = $request->header('Authorization')[0] ?? null;
    }

    ksort($tokens);

    expect($tokens)->toBe([
        'UAMS-Web/site' => 'Bearer ghs_installation9SENTINEL',
        'robot-council/cli' => 'Bearer ghs_installation7SENTINEL',
        'robot-council/core' => 'Bearer ghs_installation7SENTINEL',
    ])
        ->and(fetchedReadings())->not->toHaveKey('nobody-installed/thing')
        ->and($this->service(BacklogFetches::class)->latest(['nobody-installed/thing'])['nobody-installed/thing']['outcome'])->toBe(BacklogFetchOutcome::NoInstallation)
        ->and($this->service(LaneBoard::class)->read()['meters']['nobody-installed/thing']['count'])->toBeNull();

    // One lookup per owner and one token per installation, not one per repository; the lookup
    // asks for the account as the board spells it, which GitHub reads without case
    expect(sentTo('/users/robot-council/installation'))->toHaveCount(1)
        ->and(sentTo('/users/UAMS-Web/installation'))->toHaveCount(1)
        ->and(sentTo('/users/nobody-installed/installation'))->toHaveCount(1)
        ->and(sentTo('/app/installations/7/access_tokens'))->toHaveCount(1)
        ->and(sentTo('/app/installations/9/access_tokens'))->toHaveCount(1);
});

it('reuses an installation token until shortly before it expires, and mints one after', function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/core');
    fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => ['issues' => 3, 'pulls' => 0]]);

    $minted = static fn (): int => count(sentTo('/app/installations/7/access_tokens'));

    // Minted at 12:00, expiring at 13:00
    Carbon::setTestNow('2026-09-24 12:00:00');
    runBacklogFetch();
    expect($minted())->toBe(1);

    // Three more runs inside the hour, short of the five-minute margin: no new token
    foreach (['12:05:00', '12:30:00', '12:54:00'] as $time) {
        Carbon::setTestNow('2026-09-24 '.$time);
        runBacklogFetch();
    }

    expect($minted())->toBe(1)
        ->and(sentTo('/search/issues'))->toHaveCount(4);

    // Inside the margin: a new one
    Carbon::setTestNow('2026-09-24 12:56:00');
    runBacklogFetch();

    expect($minted())->toBe(2);
});

it('drops a cached token GitHub refuses, so the next run mints another', function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/core');

    fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => fn () => Http::response(['message' => 'Bad credentials'], 401)]);
    runBacklogFetch();

    fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => ['issues' => 3, 'pulls' => 0]]);
    runBacklogFetch();

    // Two mints: the first token, refused and dropped, and its replacement. A token kept after the
    // refusal would have been presented again, and this would read one
    expect(sentTo('/app/installations/7/access_tokens'))->toHaveCount(2)
        ->and(fetchedReadings())->toBe(['robot-council/core' => 3]);
});

it('signs an RS256 JWT with the App id as its issuer and a window GitHub accepts', function (): void {
    configureFetchApp();
    Carbon::setTestNow('2026-09-24 12:00:00');

    $jwt = $this->service(GitHubAppKey::class)->jwt(Carbon::now());

    expect($jwt)->toBeString();

    [$header, $payload, $signature] = explode('.', (string) $jwt);

    $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'), true);

    $claims = arrayValue(json_decode($decode($payload), true));
    $now = Carbon::now()->getTimestamp();

    expect(json_decode($decode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and($claims)->toBe(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => 424242])
        ->and(openssl_verify($header.'.'.$payload, $decode($signature), fetchFixtureKey()['public'], OPENSSL_ALGO_SHA256))->toBe(1);

    // GitHub's own limits: issued no later than now, expiring no more than ten minutes out
    expect(intValue($claims['iat'] ?? null))->toBeLessThan($now)
        ->and(intValue($claims['exp'] ?? null) - $now)->toBeLessThanOrEqual(600);

    // The same JWT went out on the installations request
    fetchLane($this, $this->installation, 'robot-council/core');
    fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => ['issues' => 1, 'pulls' => 0]]);
    $this->service(BacklogFetcher::class)->run();

    expect(sentTo('/users/robot-council/installation')[0]->header('Authorization'))->toBe(['Bearer '.$jwt]);
});

it('reads a key given as a PEM pasted whole, and one with its newlines written as \n', function (string $form): void {
    $pem = $form === 'pkcs1' ? pkcs1Pem(fetchFixtureKey()['private']) : fetchFixtureKey()['private'];

    // The form really is the one named, so the row tests what it says
    expect($pem)->toStartWith($form === 'pkcs1' ? '-----BEGIN RSA PRIVATE KEY-----' : '-----BEGIN PRIVATE KEY-----');

    expect(GitHubAppKey::pem($pem))->toBe(trim($pem))
        ->and(GitHubAppKey::pem(str_replace("\n", '\n', $pem)))->toBe(trim($pem))
        ->and(GitHubAppKey::pem(chunk_split(base64_encode($pem), 64)))->toBe(trim($pem))
        ->and(GitHubAppKey::pem('not a key'))->toBeNull();

    // And it signs: a JWT from it verifies against the fixture's public half
    config()->set('robot-council.github.app.id', FETCH_APP_ID);
    config()->set('robot-council.github.app.private_key', base64_encode($pem));

    [$header, $payload, $signature] = explode('.', (string) $this->service(GitHubAppKey::class)->jwt(Carbon::now()));

    expect(openssl_verify($header.'.'.$payload, (string) base64_decode(strtr($signature, '-_', '+/'), true), fetchFixtureKey()['public'], OPENSSL_ALGO_SHA256))->toBe(1);
})->with([
    'PKCS#8' => 'pkcs8',
    'PKCS#1, the form GitHub issues' => 'pkcs1',
]);

it('counts open issues without the open pull requests', function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/core');
    fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => ['issues' => 6, 'pulls' => 11]]);

    runBacklogFetch();

    parse_str((string) parse_url(sentTo('/search/issues')[0]->url(), PHP_URL_QUERY), $query);

    expect(fetchedReadings())->toBe(['robot-council/core' => 6])
        ->and($query)->toMatchArray(['q' => 'repo:robot-council/core is:issue is:open', 'per_page' => '1']);
});

it('makes no request and exits zero with no App configured, and the meters read as they do today', function (): void {
    fetchLane($this, $this->installation, 'robot-council/core');

    // A session's report still reaches the meter
    [$session] = $this->startAgentSession($this->installation);
    $this->service(Backlog::class)->report($session, 'robot-council/core', 17);

    runBacklogFetch();

    Http::assertNothingSent();

    expect($this->service(LaneBoard::class)->read()['meters']['robot-council/core']['count'])->toBe(17)
        ->and(DB::table('robot_council_backlog_fetches')->count())->toBe(0);
});

it('stores nothing for a repository GitHub fails on, still fetches the others, and warns with the repository and status', function (Closure $failure, string $outcome, ?int $status): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/broken');
    fetchLane($this, $this->installation, 'robot-council/core');

    fakeGitHub($this, ['robot-council' => 7], [
        'robot-council/broken' => $failure,
        'robot-council/core' => ['issues' => 4, 'pulls' => 0],
    ]);

    runBacklogFetch();

    $warnings = array_values(array_filter(loggedMessages($this), static fn (MessageLogged $message): bool => $message->level === 'warning'));

    expect(fetchedReadings())->toBe(['robot-council/core' => 4])
        ->and($this->service(LaneBoard::class)->read()['meters']['robot-council/broken']['count'])->toBeNull()
        ->and($warnings)->toHaveCount(1)
        ->and(($warnings[0] ?? null)?->context)->toBe(['repository' => 'robot-council/broken', 'outcome' => $outcome, 'status' => $status])
        ->and($this->service(BacklogFetches::class)->latest(['robot-council/broken'])['robot-council/broken'])->toMatchArray([
            'outcome' => BacklogFetchOutcome::from($outcome),
            'status' => $status,
        ]);
})->with([
    '401' => [fn () => Http::response(['message' => 'Bad credentials'], 401), 'refused', 401],
    '403' => [fn () => Http::response(['message' => 'Resource not accessible by integration'], 403), 'refused', 403],
    '404' => [fn () => Http::response(['message' => 'Not Found'], 404), 'refused', 404],
    '422' => [fn () => Http::response(['message' => 'Validation Failed'], 422), 'refused', 422],
    'a timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out'), 'unreachable', null],
    'an unparseable body' => [fn () => Http::response('<html>unicorn</html>', 200), 'unparseable', 200],
    'a negative count' => [fn () => Http::response(['total_count' => -1, 'incomplete_results' => false]), 'unparseable', 200],
    'a count GitHub calls incomplete' => [fn () => Http::response(['total_count' => 3, 'incomplete_results' => true]), 'incomplete', 200],
]);

it('stops the run at a rate limit rather than being refused for every repository after it', function (array $headers, int $status): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/aaa');
    fetchLane($this, $this->installation, 'robot-council/zzz');

    fakeGitHub($this, ['robot-council' => 7], [
        'robot-council/aaa' => fn () => Http::response(['message' => 'API rate limit exceeded'], $status, $headers),
        'robot-council/zzz' => ['issues' => 4, 'pulls' => 0],
    ]);

    runBacklogFetch();

    expect(array_map(searchedRepository(...), sentTo('/search/issues')))->toBe(['robot-council/aaa'])
        ->and(fetchedReadings())->toBeEmpty();

    // The one not reached is tried first next time
    expect($this->service(BacklogFetches::class)->oldestFirst(['robot-council/aaa', 'robot-council/zzz']))->toBe(['robot-council/zzz', 'robot-council/aaa']);
})->with([
    'a 403 with no requests remaining' => [['X-RateLimit-Remaining' => '0'], 403],
    'a 403 with Retry-After' => [['Retry-After' => '60'], 403],
    'a 429' => [[], 429],
]);

it("records an owner's repositories as unread when its installation lookup is refused, and still fetches the other owners", function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'refused-owner/one');
    fetchLane($this, $this->installation, 'refused-owner/two');
    fetchLane($this, $this->installation, 'robot-council/core');

    fakeGitHub(
        $this,
        ['robot-council' => 7],
        ['robot-council/core' => ['issues' => 2, 'pulls' => 0]],
        fn (string $owner) => $owner === 'refused-owner'
            ? Http::response(['message' => 'A JSON web token could not be decoded'], 401)
            : Http::response(['id' => 7])
    );

    runBacklogFetch();

    $latest = $this->service(BacklogFetches::class)->latest(['refused-owner/one', 'refused-owner/two']);

    expect(fetchedReadings())->toBe(['robot-council/core' => 2])
        ->and($latest['refused-owner/one']['status'] ?? null)->toBe(401)
        ->and($latest['refused-owner/two']['status'] ?? null)->toBe(401)
        // Asked once for the owner, not once per repository
        ->and(sentTo('/users/refused-owner/installation'))->toHaveCount(1)
        ->and(array_map(searchedRepository(...), sentTo('/search/issues')))->toBe(['robot-council/core']);
});

it('never lets the key, the JWT, or an installation token reach a log, an exception, the cache, or a page', function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/core');
    fetchLane($this, $this->installation, 'robot-council/broken');
    fetchLane($this, $this->installation, 'robot-council/timeout');

    fakeGitHub($this, ['robot-council' => 7], [
        'robot-council/core' => ['issues' => 2, 'pulls' => 0],
        'robot-council/broken' => fn () => Http::response(['message' => 'Bad credentials'], 401),
        'robot-council/timeout' => fn () => throw new ConnectionException('cURL error 28 for https://api.github.com/search/issues'),
    ]);

    runBacklogFetch();

    // The sentinels, read from what was actually sent: the JWT from the installations request and
    // the token from a search. Positive controls first: each must be a real, non-empty credential,
    // or every "does not contain" below would pass on an empty string.
    $jwt = substr(stringValue(sentTo('/users/robot-council/installation')[0]->header('Authorization')[0] ?? null), 7);
    $token = 'ghs_installation7SENTINEL';
    $keyBody = trim(str_replace(['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\n"], '', fetchFixtureKey()['private']));
    $keyLine = substr($keyBody, 64, 48);

    expect(substr_count($jwt, '.'))->toBe(2)
        ->and(sentTo('/search/issues')[0]->header('Authorization'))->toBe(['Bearer '.$token])
        ->and($keyLine)->toHaveLength(48);

    $sentinels = [
        'jwt' => $jwt,
        'token' => $token,
        'key line' => $keyLine,
        'configured key' => substr(stringValue(config('robot-council.github.app.private_key')), 100, 48),
    ];

    // Every log line, message and context
    $logs = implode("\n", array_map(static fn (MessageLogged $message): string => $message->message.json_encode($message->context), loggedMessages($this)));

    expect(loggedMessages($this))->not->toBeEmpty();

    // Every refusal the client can raise, by provoking each -- with the trace printing arguments
    // whole. By default PHP leaves arguments out of a trace, or cuts a string to 15 characters, so
    // a token passed without `#[SensitiveParameter]` would not show and this could not tell.
    $exceptions = [];
    $ignoreArgs = ini_get('zend.exception_ignore_args');
    $maxLength = ini_get('zend.exception_string_param_max_len');

    ini_set('zend.exception_ignore_args', '0');
    ini_set('zend.exception_string_param_max_len', '1000000');

    try {
        // The positive control: a parameter NOT marked sensitive prints the token whole
        $control = (static fn (string $plain): Throwable => new RuntimeException('control'))($token);

        expect($control->getTraceAsString())->toContain($token);

        foreach ([
            fn () => Http::response(['message' => 'Bad credentials'], 401),
            fn () => throw new ConnectionException('cURL error 28 carrying Bearer '.$token),
            fn () => Http::response('not json '.$token, 200),
        ] as $answer) {
            fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => $answer]);

            try {
                $this->service(GitHubApp::class)->openIssues('robot-council/core', $token);
            } catch (GitHubRefusal $refusal) {
                $exceptions[] = $refusal->getMessage().$refusal->getTraceAsString();
            }
        }
    } finally {
        ini_set('zend.exception_ignore_args', is_string($ignoreArgs) ? $ignoreArgs : '1');
        ini_set('zend.exception_string_param_max_len', is_string($maxLength) ? $maxLength : '15');
    }

    expect($exceptions)->toHaveCount(3);

    // The cache as any other reader sees it: something was cached under the token's key
    $cached = $this->service(Cache::class)->get(sprintf('robot-council:github-app:%s:installation:7:token', FETCH_APP_ID));

    expect($cached)->toBeString()->not->toBe('');

    // What the package renders: the board, and doctor's report
    $page = Livewire::actingAs($this->developer)->test(Lanes::class)->html();
    Artisan::call('robot-council:doctor');
    $doctor = Artisan::output();

    expect($doctor)->toContain('github app')
        ->and($doctor)->toContain('backlog fetch');

    // `str_contains()` rather than `not->toContain()`: Pest's `toContain()` is variadic, so a
    // message passed as its second argument is read as a second needle, and the negation then
    // passes whenever that sentence is absent -- which is always. Measured: the cache held the
    // token in the clear and the negated form still passed.
    $surfaces = [
        'a log line' => $logs,
        'an exception' => implode("\n", $exceptions),
        'the cache' => is_string($cached) ? $cached : '',
        'the board' => $page,
        "doctor's output" => $doctor,
    ];

    foreach ($sentinels as $name => $sentinel) {
        foreach ($surfaces as $surface => $text) {
            expect(str_contains($text, $sentinel))->toBeFalse(sprintf('%s carried the %s', $surface, $name));
        }
    }

    // The positive control for the cache: what it holds IS the token, only encrypted -- so the
    // check above looked at the right value rather than at something else stored under that key
    expect($this->service(StringEncrypter::class)->decryptString(is_string($cached) ? $cached : ''))->toContain($token);
});

it("reports the App and each repository's latest fetch through doctor", function (): void {
    $diagnosis = fn (string $name) => collect($this->service(Doctor::class)->examine([$name]))->first();

    // Not configured: a choice
    expect($diagnosis('github app')?->status)->toBe(DiagnosisStatus::Passed)
        ->and($diagnosis('backlog fetch')?->status)->toBe(DiagnosisStatus::Passed);

    // Half configured
    config()->set('robot-council.github.app.id', FETCH_APP_ID);
    expect($diagnosis('github app')?->status)->toBe(DiagnosisStatus::Failed)
        ->and($diagnosis('github app')?->detail)->toContain('ROBOT_COUNCIL_GITHUB_APP_PRIVATE_KEY is not');

    // A key that does not parse, and one that does
    config()->set('robot-council.github.app.private_key', base64_encode('-----BEGIN PRIVATE KEY-----'."\nnonsense\n".'-----END PRIVATE KEY-----'));
    expect($diagnosis('github app')?->status)->toBe(DiagnosisStatus::Failed)
        ->and($diagnosis('github app')?->detail)->toContain('does not parse');

    configureFetchApp();
    expect($diagnosis('github app')?->status)->toBe(DiagnosisStatus::Passed)
        ->and($diagnosis('github app')?->detail)->toBe('App 424242 is configured, and its key parses.');

    // Board repositories never fetched: the scheduler may not be running
    fetchLane($this, $this->installation, 'robot-council/core');
    fetchLane($this, $this->installation, 'UAMS-Web/site');

    expect($diagnosis('backlog fetch')?->status)->toBe(DiagnosisStatus::Undetermined);

    // One owner installed and read, one not installed
    fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => ['issues' => 1, 'pulls' => 0]]);
    $this->service(BacklogFetcher::class)->run();

    $fetch = $diagnosis('backlog fetch');

    expect($fetch?->status)->toBe(DiagnosisStatus::Failed)
        ->and($fetch?->detail)->toContain('UAMS-Web: no installation')
        ->and($fetch?->detail)->toContain('robot-council: installed')
        ->and($fetch?->detail)->toContain('robot-council/core: read (HTTP 200)')
        ->and($fetch?->detail)->toContain('UAMS-Web/site: no installation');

    // Both installed and read
    fakeGitHub($this, ['robot-council' => 7, 'uams-web' => 9], [
        'robot-council/core' => ['issues' => 1, 'pulls' => 0],
        'UAMS-Web/site' => ['issues' => 1, 'pulls' => 0],
    ]);
    $this->service(BacklogFetcher::class)->run();

    expect($diagnosis('backlog fetch')?->status)->toBe(DiagnosisStatus::Passed);
});

it('schedules the fetch every five minutes, last, in the background and without overlap, and not when the host turns it off', function (): void {
    $scheduled = static fn (Schedule $schedule): array => array_values(array_filter(
        $schedule->events(),
        static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'robot-council:backlog-fetch')
    ));

    $events = $scheduled($this->service(Schedule::class));

    // In the background, never overlapping itself, with a lock that lapses in ten minutes
    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/5 * * * *')
        ->and($events[0]->runInBackground)->toBeTrue()
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->expiresAt)->toBe(10);

    // And last of the package's entries, so the coordination checks are not queued behind it
    $ours = array_values(array_filter(
        $this->service(Schedule::class)->events(),
        static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'robot-council:')
    ));

    expect(end($ours))->toBe($events[0]);

    $this->rebootWith('robot-council.schedule.backlog_fetch', false);

    expect($scheduled($this->service(Schedule::class)))->toBeEmpty();
});

it("stores a session's report and a fetched count side by side, and rolls the column back without the fetched ones", function (): void {
    $migration = require __DIR__.'/../database/migrations/2026_09_25_000002_allow_sessionless_robot_council_backlog_readings.php';

    $up = [$migration, 'up'];
    $down = [$migration, 'down'];

    if (! is_callable($up) || ! is_callable($down)) {
        throw new RuntimeException('The migration file did not return something with an up() and a down().');
    }

    $nullable = static function (): ?bool {
        foreach (Schema::getColumns('robot_council_backlog_readings') as $column) {
            $column = arrayValue($column);

            if ($column['name'] === 'reported_by') {
                return (bool) $column['nullable'];
            }
        }

        return null;
    };

    [$session] = $this->startAgentSession($this->installation);
    $this->service(Backlog::class)->report($session, 'robot-council/core', 5);
    $this->service(Backlog::class)->record('robot-council/core', 6);

    expect(DB::table('robot_council_backlog_readings')->orderBy('id')->pluck('reported_by')->all())->toBe([$session->getKey(), null])
        ->and($nullable())->toBeTrue();

    // Down removes the fetched reading and makes the column NOT NULL again; a second down does nothing
    $down();
    $down();

    expect($nullable())->toBeFalse()
        ->and(DB::table('robot_council_backlog_readings')->pluck('reported_by')->all())->toBe([$session->getKey()]);

    // Up restores it, twice over
    $up();
    $up();

    expect($nullable())->toBeTrue();

    $this->service(Backlog::class)->record('robot-council/core', 7);

    expect(DB::table('robot_council_backlog_readings')->count())->toBe(2);
});

it('holds the fetched store to the bounds the session store holds', function (): void {
    expect(fn () => $this->service(Backlog::class)->record('not a repository', 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->service(Backlog::class)->record('robot-council/core', Backlog::MAX_COUNT + 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->service(BacklogFetches::class)->record('robot-council/core', BacklogFetchOutcome::Refused, 1000))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->service(GitHubApp::class)->openIssues('robot-council/core is:pr', 'x'))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('robot_council_backlog_readings')->count())->toBe(0);
});

it('refuses a request the fixture does not model, so a stray one cannot pass unnoticed', function (): void {
    // The fixture's own positive control: another host, and an unmodeled GitHub path, both throw
    expect(fn () => Http::get('https://example.com/'))->toThrow(RuntimeException::class)
        ->and(fn () => Http::get('https://api.github.com/app/installations'))->toThrow(RuntimeException::class)
        ->and(Http::get('https://api.github.com/users/robot-council/installation')->status())->toBe(404);
});

it('stops a run after two requests in a row get no answer, and not after one', function (array $answers, array $searched): void {
    configureFetchApp();

    foreach (array_keys($answers) as $repository) {
        fetchLane($this, $this->installation, $repository);
    }

    // Counted as each is asked: a request that got no answer never reaches `Http::recorded()`
    /** @var ArrayObject<int, string> $asked */
    $asked = new ArrayObject;
    $specs = [];

    foreach ($answers as $key => $answer) {
        $repository = keyValue($key);

        $specs[$repository] = function () use ($asked, $repository, $answer) {
            $asked[] = $repository;

            if ($answer === 'timeout') {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['total_count' => 1, 'incomplete_results' => false]);
        };
    }

    fakeGitHub($this, ['robot-council' => 7], $specs);

    runBacklogFetch();

    expect($asked->getArrayCopy())->toBe($searched);
})->with([
    'two in a row' => [
        ['robot-council/a' => 'timeout', 'robot-council/b' => 'timeout', 'robot-council/c' => 'ok'],
        ['robot-council/a', 'robot-council/b'],
    ],
    'separated by an answer' => [
        ['robot-council/a' => 'timeout', 'robot-council/b' => 'ok', 'robot-council/c' => 'timeout', 'robot-council/d' => 'ok'],
        ['robot-council/a', 'robot-council/b', 'robot-council/c', 'robot-council/d'],
    ],
]);

it('accepts an installation token in the shape GitHub issues now, and refuses one that could end a header', function (string $token, bool $accepted): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/core');

    fakeGitHub(
        $this,
        ['robot-council' => 7],
        ['robot-council/core' => ['issues' => 4, 'pulls' => 0]],
        null,
        fn (int $installation) => Http::response(['token' => $token, 'expires_at' => Carbon::now()->addHour()->utc()->format('Y-m-d\\TH:i:s\\Z')], 201)
    );

    runBacklogFetch();

    $latest = $this->service(BacklogFetches::class)->latest(['robot-council/core'])['robot-council/core'];

    if ($accepted) {
        expect($latest['outcome'])->toBe(BacklogFetchOutcome::Read)
            ->and(sentTo('/search/issues')[0]->header('Authorization'))->toBe(['Bearer '.$token]);
    } else {
        expect($latest['outcome'])->toBe(BacklogFetchOutcome::Unparseable)
            ->and(sentTo('/search/issues'))->toBeEmpty();
    }
})->with([
    // The shape measured on 2026-09-25: 383 characters, two dots and a dash
    'the current shape' => ['ghs_'.str_repeat('a', 200).'.'.str_repeat('B', 100).'-'.str_repeat('9', 40).'.'.str_repeat('z', 36), true],
    'the older shape' => ['ghs_'.str_repeat('a', 36), true],
    'a space' => ['ghs_abc def', false],
    'a line break' => ["ghs_abc\r\nX-Injected: 1", false],
    'past the bound' => ['ghs_'.str_repeat('a', 2045), false],
]);

it('mints once a run for an installation GitHub will not mint for, and asks nothing for its repositories', function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'suspended/one');
    fetchLane($this, $this->installation, 'suspended/two');
    fetchLane($this, $this->installation, 'suspended/three');

    fakeGitHub(
        $this,
        ['suspended' => 5],
        [],
        null,
        fn (int $installation) => Http::response(['message' => 'This installation has been suspended'], 403)
    );

    runBacklogFetch();

    $latest = $this->service(BacklogFetches::class)->latest(['suspended/one', 'suspended/two', 'suspended/three']);

    expect(sentTo('/app/installations/5/access_tokens'))->toHaveCount(1)
        ->and(sentTo('/search/issues'))->toBeEmpty()
        ->and(array_map(static fn (array $fetch): ?int => $fetch['status'], $latest))->toBe([
            'suspended/one' => 403,
            'suspended/three' => 403,
            'suspended/two' => 403,
        ]);
});

it('records an error and goes on when something other than GitHub fails, logging the class and never the message', function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/core');
    fetchLane($this, $this->installation, 'robot-council/cli');
    fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => ['issues' => 1, 'pulls' => 0], 'robot-council/cli' => ['issues' => 1, 'pulls' => 0]]);

    // A cache store that is down, whose error names where it lives
    $this->mock(Cache::class, function (MockInterface $cache): void {
        $cache->shouldReceive('get')->andThrow(new RuntimeException('connection refused: redis://user:secret@cache.internal:6379'));
    });

    runBacklogFetch();

    $warnings = array_values(array_filter(loggedMessages($this), static fn (MessageLogged $message): bool => $message->level === 'warning'));

    expect(fetchedReadings())->toBeEmpty()
        ->and(array_map(static fn (array $fetch): BacklogFetchOutcome => $fetch['outcome'], $this->service(BacklogFetches::class)->latest(['robot-council/core', 'robot-council/cli'])))
        ->toBe(['robot-council/cli' => BacklogFetchOutcome::Error, 'robot-council/core' => BacklogFetchOutcome::Error])
        ->and(array_map(static fn (MessageLogged $message): array => $message->context, $warnings))->toBe([
            ['repository' => 'robot-council/cli', 'exception' => RuntimeException::class],
            ['repository' => 'robot-council/core', 'exception' => RuntimeException::class],
        ])
        ->and(str_contains((string) json_encode(array_map(static fn (MessageLogged $message): string => $message->message, $warnings)), 'secret'))->toBeFalse();
});

it('records an error when storing the reading fails, and the command still exits zero', function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/core');
    fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => ['issues' => 1, 'pulls' => 0]]);

    // The reading's table is gone: the insert throws a `QueryException`, whose message holds SQL
    Schema::drop('robot_council_backlog_readings');

    runBacklogFetch();

    $warning = collect(loggedMessages($this))->first(static fn (MessageLogged $message): bool => $message->level === 'warning');

    expect($this->service(BacklogFetches::class)->latest(['robot-council/core'])['robot-council/core']['outcome'] ?? null)->toBe(BacklogFetchOutcome::Error)
        ->and($warning?->context)->toBe(['repository' => 'robot-council/core', 'exception' => QueryException::class]);
});

it('exits zero when the fetch cannot even read the board, logging the class alone', function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/core');

    Schema::drop('robot_council_backlog_fetches');

    runBacklogFetch();

    expect(array_map(static fn (MessageLogged $message): array => $message->context, loggedMessages($this)))
        ->toBe([['exception' => QueryException::class]])
        ->and(Http::recorded())->toBeEmpty();
});

it('logs a missing installation when it begins, not on every run while it lasts', function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'nobody-installed/thing');

    runBacklogFetch();
    runBacklogFetch();
    runBacklogFetch();

    $warnings = array_filter(loggedMessages($this), static fn (MessageLogged $message): bool => $message->level === 'warning');

    // Asked every run, and recorded every run, but said once
    expect($warnings)->toHaveCount(1)
        ->and(sentTo('/users/nobody-installed/installation'))->toHaveCount(3);

    // And said again when it begins again, after a run that read it
    fakeGitHub($this, ['nobody-installed' => 3], ['nobody-installed/thing' => ['issues' => 1, 'pulls' => 0]]);
    runBacklogFetch();
    fakeGitHub($this, [], []);
    runBacklogFetch();

    expect(array_filter(loggedMessages($this), static fn (MessageLogged $message): bool => $message->level === 'warning'))->toHaveCount(2);
});

it('replaces a cached token inside its refresh margin, even when the cache would keep it longer', function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'robot-council/core');
    fakeGitHub($this, ['robot-council' => 7], ['robot-council/core' => ['issues' => 1, 'pulls' => 0]]);

    Carbon::setTestNow('2026-09-24 12:00:00');

    // A token a minute from expiring, in a store that would keep it an hour
    $this->service(Cache::class)->put(
        sprintf('robot-council:github-app:%s:installation:7:token', FETCH_APP_ID),
        $this->service(StringEncrypter::class)->encryptString((string) json_encode(['token' => 'ghs_aboutToLapse', 'expires_at' => Carbon::now()->addMinute()->getTimestamp()])),
        3600
    );

    runBacklogFetch();

    expect(sentTo('/app/installations/7/access_tokens'))->toHaveCount(1)
        ->and(sentTo('/search/issues')[0]->header('Authorization'))->toBe(['Bearer ghs_installation7SENTINEL']);
});

it('needs no application key while no App is configured', function (): void {
    fetchLane($this, $this->installation, 'robot-council/core');

    // A host with no APP_KEY: resolving the encrypter now throws
    config()->set('app.key', '');
    $this->app?->forgetInstance('encrypter');

    expect(fn () => $this->service(StringEncrypter::class))->toThrow(MissingAppKeyException::class);

    runBacklogFetch();

    expect(loggedMessages($this))->toBeEmpty();
});

it('treats a passing failure as undetermined and a standing one as failed, grouping owners without case', function (): void {
    configureFetchApp();
    fetchLane($this, $this->installation, 'UAMS-Web/site');
    fetchLane($this, $this->installation, 'uams-web/other');

    $diagnosis = fn () => collect($this->service(Doctor::class)->examine(['backlog fetch']))->first();

    // GitHub not answering: it may pass on its own
    fakeGitHub($this, ['uams-web' => 9], [
        'UAMS-Web/site' => fn () => throw new ConnectionException('cURL error 28'),
        'uams-web/other' => ['issues' => 1, 'pulls' => 0],
    ]);
    runBacklogFetch();

    expect($diagnosis()?->status)->toBe(DiagnosisStatus::Undetermined)
        ->and(substr_count((string) $diagnosis()?->detail, ': installed'))->toBe(1)
        ->and($diagnosis()?->detail)->toContain('UAMS-Web: installed');

    // A refusal: somebody has something to fix
    fakeGitHub($this, ['uams-web' => 9], [
        'UAMS-Web/site' => fn () => Http::response(['message' => 'Not Found'], 404),
        'uams-web/other' => ['issues' => 1, 'pulls' => 0],
    ]);
    runBacklogFetch();

    expect($diagnosis()?->status)->toBe(DiagnosisStatus::Failed);
});
