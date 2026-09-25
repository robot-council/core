<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/**
 * The package's one conversation with GitHub, and its only outbound HTTP besides the Slack mirror.
 *
 * **A deliberate, bounded exception to #318's "core reads nothing from GitHub" (#383).** That rule
 * exists so no coordination decision depends on GitHub being reachable or honest. What this reads is
 * an open-issue count for the lane board's meters: a display for people that decides nothing, and
 * that reads unreadable when GitHub does not answer. **It covers display-only counts and nothing
 * else.** Everything that frees a lane, places work, or changes a task keeps learning from the
 * webhook alone, and a second caller of this class for anything but a count is the rule lapsing.
 * `tests/SlackMirrorTest.php` names this file as the second and only other one that talks HTTP.
 *
 * Three requests, all to `api.github.com`: the App's installation on one account, signed with its
 * JWT; an installation token for it, minted with the same JWT; and one search per repository with
 * that token. The token is asked for with `issues: read` alone, so it carries less than the App
 * even if the App is later granted more.
 *
 * **Installations are looked up per account, not listed.** `GET /users/{owner}/installation`
 * answers for organizations and users alike, and a 404 means that account has none. Listing
 * `/app/installations` would page through every account that installed the App -- and a public App
 * can be installed by anyone, so third-party installations could push a real owner past any page
 * cap. `/repos/{owner}/{repo}/installation` was not used either: its 404 cannot tell an account with
 * no installation from one whose installation leaves that repository out.
 *
 * **No credential leaves through here.** A failure becomes a `GitHubRefusal` built from the status
 * alone. An installation token is cached encrypted with the application's key, so a cache store
 * that other code can read -- a shared Redis, a `cache` table -- holds ciphertext, and every
 * parameter that carries a credential is `#[SensitiveParameter]`.
 */
final class GitHubApp
{
    /**
     * Where every request goes.
     */
    public const string API = 'https://api.github.com';

    /**
     * How long before its `expires_at` a cached installation token is replaced, in seconds.
     *
     * GitHub issues them for an hour. Five minutes is room for a run that started on a token about
     * to lapse and for clock drift, without minting a new one every run.
     */
    public const int REFRESH_BEFORE_SECONDS = 300;

    /**
     * The shape of an account login this will put in a URL path.
     */
    public const string OWNER = '/^[A-Za-z0-9_.][A-Za-z0-9._-]{0,99}$/D';

    /**
     * The shape of an installation token GitHub issues, and of nothing else the cache might return.
     *
     * Wider than the `ghs_` and 36 alphanumerics GitHub once issued: measured on 2026-09-25, a token
     * minted for this App was 383 characters carrying two `.` and one `-`, and the narrower pattern
     * refused every one as unparseable. It still admits nothing that could end a header -- no
     * whitespace, no line break -- and the length is bounded rather than trusted.
     */
    public const string TOKEN = '/^[A-Za-z0-9_.\-]{1,2048}$/D';

    /**
     * @param  GitHubAppKey  $key  The App's id and key, and the JWT they sign.
     * @param  Cache  $cache  The host's configured cache store, for installation tokens.
     * @param  Container  $container  Resolves the application's encrypter when a token is cached
     *                                or read, and not before: resolving it throws on a host with
     *                                no `APP_KEY`, and a host with no App configured must not fail
     *                                every five minutes for a key it never needs.
     */
    public function __construct(
        private readonly GitHubAppKey $key,
        private readonly Cache $cache,
        private readonly Container $container
    ) {}

    /**
     * The App's installation on one account, if it has one.
     *
     * @param  string  $owner  The account's login, organization or user.
     * @return int|null The installation's id, or null when GitHub says the account has none (404).
     *
     * @throws GitHubRefusal When GitHub could not be asked, refused otherwise, or answered with no id.
     * @throws InvalidArgumentException When the owner is not a login, which would change the path.
     */
    public function installation(string $owner): ?int
    {
        if (preg_match(self::OWNER, $owner) !== 1) {
            throw new InvalidArgumentException('An owner is an account login.');
        }

        $jwt = $this->jwt();

        try {
            $response = $this->send(fn (): Response => $this->client()
                ->withToken($jwt)
                ->get(sprintf('/users/%s/installation', rawurlencode($owner))));
        } catch (GitHubRefusal $gitHubRefusal) {
            // A 404 here is an answer, not a failure: the account has no installation
            if ($gitHubRefusal->outcome === BacklogFetchOutcome::Refused && $gitHubRefusal->status === 404) {
                return null;
            }

            throw $gitHubRefusal;
        }

        $id = $response->json('id');

        if (! \is_int($id) || $id < 1) {
            throw new GitHubRefusal(BacklogFetchOutcome::Unparseable, $response->status());
        }

        return $id;
    }

    /**
     * An installation token for one installation, from the cache while it has time left.
     *
     * @param  int  $installation  The installation's id.
     * @return string The token.
     *
     * @throws GitHubRefusal When one could not be minted.
     */
    public function token(int $installation): string
    {
        $cacheKey = $this->cacheKey($installation);

        // Reuse the cached token while it is short of its refresh margin
        $cached = $this->cached($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // Mint a new one with the App's JWT, asking for issues read and nothing more
        $jwt = $this->jwt();

        $response = $this->send(fn (): Response => $this->client()
            ->withToken($jwt)
            ->post(sprintf('/app/installations/%d/access_tokens', $installation), ['permissions' => ['issues' => 'read']]));

        $token = $response->json('token');
        $expiresAt = self::instant($response->json('expires_at'));

        if (! \is_string($token) || preg_match(self::TOKEN, $token) !== 1 || ! $expiresAt instanceof CarbonImmutable) {
            throw new GitHubRefusal(BacklogFetchOutcome::Unparseable, $response->status());
        }

        // Cache it until the refresh margin, encrypted; one with less time than that is used once
        $seconds = $expiresAt->getTimestamp() - self::REFRESH_BEFORE_SECONDS - CarbonImmutable::now()->getTimestamp();

        if ($seconds > 0) {
            $this->cache->put(
                $cacheKey,
                $this->encrypter()->encryptString((string) json_encode(['token' => $token, 'expires_at' => $expiresAt->getTimestamp()])),
                $seconds
            );
        }

        return $token;
    }

    /**
     * Drop an installation's cached token, after GitHub refused it.
     *
     * @param  int  $installation  The installation's id.
     */
    public function forgetToken(int $installation): void
    {
        $this->cache->forget($this->cacheKey($installation));
    }

    /**
     * A repository's open issues, pull requests excluded.
     *
     * GitHub search's `is:issue is:open`, whose `total_count` counts issues alone, rather than
     * `open_issues_count`, which counts pull requests with them. One request, one result asked for.
     *
     * @param  string  $repository  `owner/name`.
     * @param  string  $token  The installation token for the repository's owner.
     * @return int The count.
     *
     * @throws GitHubRefusal When GitHub could not be asked, refused, or answered with no usable count.
     * @throws InvalidArgumentException When the repository is not `owner/name`, which would let it
     *                                  add qualifiers to the query.
     */
    public function openIssues(string $repository, #[SensitiveParameter] string $token): int
    {
        if (mb_strlen($repository) > WorkIdentity::MAX_REPOSITORY || preg_match(WorkIdentity::REPOSITORY, $repository) !== 1) {
            throw new InvalidArgumentException('A repository is named as owner/name.');
        }

        $response = $this->send(fn (): Response => $this->client()
            ->withToken($token)
            ->get('/search/issues', ['q' => sprintf('repo:%s is:issue is:open', $repository), 'per_page' => 1]));

        // A count GitHub itself says may be short is a wrong one, so it is not a reading
        if ($response->json('incomplete_results') === true) {
            throw new GitHubRefusal(BacklogFetchOutcome::Incomplete, $response->status());
        }

        $total = $response->json('total_count');

        if (! \is_int($total) || $total < 0 || $total > Backlog::MAX_COUNT) {
            throw new GitHubRefusal(BacklogFetchOutcome::Unparseable, $response->status());
        }

        return $total;
    }

    /**
     * The App's JWT, signed now.
     *
     * @return string The JWT.
     *
     * @throws GitHubRefusal When the id or the key cannot sign one.
     */
    private function jwt(): string
    {
        return $this->key->jwt(CarbonImmutable::now()) ?? throw new GitHubRefusal(BacklogFetchOutcome::KeyUnusable);
    }

    /**
     * A request to GitHub's API, bounded in time.
     *
     * @return PendingRequest The request, not yet sent.
     */
    private function client(): PendingRequest
    {
        return Http::baseUrl(self::API)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'robot-council',
            ])
            // The client's defaults are 10 seconds to connect and 30 to read; a run asks once per
            // repository, so an unhealthy GitHub would otherwise hold it for minutes
            ->connectTimeout(5)
            ->timeout(10);
    }

    /**
     * Send a request, and turn anything but a 2xx into a refusal.
     *
     * @param  Closure(): Response  $request  Sends it.
     * @return Response The answer, a 2xx.
     *
     * @throws GitHubRefusal When it was not answered, or not with a 2xx.
     */
    private function send(Closure $request): Response
    {
        try {
            $response = $request();
        } catch (Throwable) {
            // Every throwable, and without the original: a connection error's message carries the
            // URL, and a future one could carry more. Nothing of it is needed to say "unreachable".
            throw new GitHubRefusal(BacklogFetchOutcome::Unreachable);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        // GitHub signals a rate limit with 429, or with 403 and a header saying so; a plain 403 is a
        // refusal, and the run goes on to the next repository
        $limited = $status === 429
            || ($status === 403 && ($response->header('Retry-After') !== '' || $response->header('X-RateLimit-Remaining') === '0'));

        throw new GitHubRefusal($limited ? BacklogFetchOutcome::RateLimited : BacklogFetchOutcome::Refused, $status);
    }

    /**
     * The cached token under a key, while it has more than the refresh margin left.
     *
     * @param  string  $cacheKey  The key.
     * @return string|null The token, or null when there is none worth using.
     */
    private function cached(string $cacheKey): ?string
    {
        $stored = $this->cache->get($cacheKey);

        if (! \is_string($stored)) {
            return null;
        }

        try {
            $entry = json_decode($this->encrypter()->decryptString($stored), true);
        } catch (Throwable) {
            // Written under another application key, or not by this class: a miss, not a failure
            return null;
        }

        $token = \is_array($entry) ? ($entry['token'] ?? null) : null;
        $expiresAt = \is_array($entry) ? ($entry['expires_at'] ?? null) : null;

        // Checked against the clock as well as trusted to the cache's own expiry, so a store that
        // outlives its TTL cannot hand back a token about to lapse
        if (! \is_string($token) || preg_match(self::TOKEN, $token) !== 1 || ! \is_int($expiresAt)) {
            return null;
        }

        return $expiresAt - self::REFRESH_BEFORE_SECONDS > CarbonImmutable::now()->getTimestamp() ? $token : null;
    }

    /**
     * The application's encrypter, resolved now.
     *
     * @return StringEncrypter The encrypter.
     */
    private function encrypter(): StringEncrypter
    {
        return $this->container->make(StringEncrypter::class);
    }

    /**
     * Where an installation's token is cached.
     *
     * Keyed by the App as well as the installation, so a host that moves to another App does not
     * reuse the first one's tokens.
     *
     * @param  int  $installation  The installation's id.
     * @return string The key.
     *
     * @throws GitHubRefusal When no usable App id is configured.
     */
    private function cacheKey(int $installation): string
    {
        $app = $this->key->appId() ?? throw new GitHubRefusal(BacklogFetchOutcome::KeyUnusable);

        return sprintf('robot-council:github-app:%s:installation:%d:token', $app, $installation);
    }

    /**
     * An instant GitHub wrote as ISO 8601.
     *
     * @param  mixed  $value  What GitHub sent.
     * @return CarbonImmutable|null The instant, or null when it is not one.
     */
    private static function instant(mixed $value): ?CarbonImmutable
    {
        if (! \is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
