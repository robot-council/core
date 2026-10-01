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
 * that reads unreadable when GitHub does not answer. **It covers display-only counts, public account
 * profiles for the Access page (#484), and the issues a host's search qualifiers match (#530), and
 * nothing else.** The matching set narrows which tickets the shortlist offers a coordinator, which
 * is a decision, and CLAUDE.md names it as the exception that is; it refuses and places nothing. A profile names the account
 * behind an allowlisted ID, and resolves a login an administrator typed to the ID they then
 * confirm; the ID alone decides access, and the page can still add an ID when GitHub is down.
 * Everything that frees a lane, places work, or changes a task keeps learning from the webhook
 * alone, and a caller of this class for anything else is the rule lapsing.
 * `tests/SlackMirrorTest.php` names this file as the second and only other one that talks HTTP.
 *
 * Five requests, all to `api.github.com`: the App's installation on one account, signed with its
 * JWT; an installation token for it, minted with the same JWT; one search per repository with
 * that token; and a public profile, by ID or by login, with the same token. For a repository with
 * qualifiers the search is paged to list what it matched, at most `MAX_PAGES` requests. A profile needs no
 * permission at all, so it asks for nothing the count's token does not already carry. The token is asked for with `issues: read` alone, so it carries less than the App
 * even if the App is later granted more -- and with `organization_projects: read` beside it only
 * for an owner whose count is narrowed to a project (#488).
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
     * The most matching issues `matchingIssues()` lists: GitHub's search returns no more than this
     * for any query, however it is paged.
     */
    public const int MAX_MATCHING = 1000;

    /**
     * How many results one page of `matchingIssues()` asks for, search's largest page.
     */
    public const int PAGE_SIZE = 100;

    /**
     * The most searches `matchingIssues()` makes for one repository.
     */
    public const int MAX_PAGES = self::MAX_MATCHING / self::PAGE_SIZE;

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
     * **Projects read is asked for only when a count needs it** (#488). Search answers a `project:`
     * qualifier 422 for a token with `issues: read` alone -- measured 2026-09-29 with this App's
     * own installation token against `project:UAMS-Web/1` -- so a count narrowed to a project
     * needs `organization_projects: read` as well, which the App must hold and the organization
     * must have accepted. Otherwise the request is exactly what it was, and a token for one set of
     * permissions is cached apart from the other's.
     *
     * @param  int  $installation  The installation's id.
     * @param  bool  $projects  Whether to ask for `organization_projects: read` too.
     * @return string The token.
     *
     * @throws GitHubRefusal When one could not be minted, which is 422 when the App does not hold
     *                       a permission asked for.
     */
    public function token(int $installation, bool $projects = false): string
    {
        $cacheKey = $this->cacheKey($installation, $projects);

        // Reuse the cached token while it is short of its refresh margin
        $cached = $this->cached($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // Mint a new one with the App's JWT, asking for issues read and, for a project count,
        // projects read, and nothing more
        $jwt = $this->jwt();
        $permissions = $projects ? ['issues' => 'read', 'organization_projects' => 'read'] : ['issues' => 'read'];

        $response = $this->send(fn (): Response => $this->client()
            ->withToken($jwt)
            ->post(sprintf('/app/installations/%d/access_tokens', $installation), ['permissions' => $permissions]));

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
     * @param  bool  $projects  Whether it was the token that also reads projects.
     */
    public function forgetToken(int $installation, bool $projects = false): void
    {
        $this->cache->forget($this->cacheKey($installation, $projects));
    }

    /**
     * A repository's open issues, pull requests excluded.
     *
     * GitHub search's `is:issue is:open`, whose `total_count` counts issues alone, rather than
     * `open_issues_count`, which counts pull requests with them. One request, one result asked for.
     * Qualifiers from `Support\BacklogQualifiers` narrow it (#488); with none, the query is exactly
     * what it was before they existed. A qualifier GitHub cannot resolve -- a project the token
     * cannot see, or one that does not exist -- is answered 422, which is a refusal like any other.
     *
     * @param  string  $repository  `owner/name`.
     * @param  string  $token  The installation token for the repository's owner.
     * @param  string  $qualifiers  Qualifiers to append, `''` for none.
     * @return int The count.
     *
     * @throws GitHubRefusal When GitHub could not be asked, refused, or answered with no usable count.
     * @throws InvalidArgumentException When the repository is not `owner/name`, which would let it
     *                                  add qualifiers to the query, or the qualifiers are ones
     *                                  `BacklogQualifiers::refusal()` refuses.
     */
    public function openIssues(string $repository, #[SensitiveParameter] string $token, string $qualifiers = ''): int
    {
        if (mb_strlen($repository) > WorkIdentity::MAX_REPOSITORY || preg_match(WorkIdentity::REPOSITORY, $repository) !== 1) {
            throw new InvalidArgumentException('A repository is named as owner/name.');
        }

        if (BacklogQualifiers::refusal($qualifiers) !== null) {
            throw new InvalidArgumentException('Those qualifiers would change what the count counts.');
        }

        $query = sprintf('repo:%s is:issue is:open', $repository).($qualifiers === '' ? '' : ' '.$qualifiers);

        $response = $this->send(fn (): Response => $this->client()
            ->withToken($token)
            ->get('/search/issues', ['q' => $query, 'per_page' => 1]));

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
     * A repository's open issues that its qualifiers match, by number, with their count (#530).
     *
     * **The same query as `openIssues()`, paged**, so the count is the one the meter would store
     * and the numbers are the tickets that count counted. The shortlist keeps only these, because
     * the mirror holds no project membership and cannot evaluate a qualifier itself. Ordered by
     * creation, oldest first, so an issue opened while the pages are read lands on the last one
     * rather than shifting the rest.
     *
     * **A set that may be short is not returned.** GitHub's search lists at most 1,000 results, so
     * past `MAX_MATCHING` the numbers are null and only the count is used; and when the numbers
     * collected do not add up to the count GitHub gave -- an issue closed between two pages shifts
     * the next one, and one is skipped -- the whole answer is refused as incomplete, because a
     * skipped number would quietly drop a ticket from the shortlist.
     *
     * @param  string  $repository  `owner/name`.
     * @param  string  $token  The installation token for the repository's owner.
     * @param  string  $qualifiers  Qualifiers to append, never `''`: with none, nothing is filtered.
     * @return array{total: int, numbers: list<int>|null, searches: int} The count, the numbers or
     *                                                                   null when there are more than
     *                                                                   can be listed, and how many
     *                                                                   searches it took.
     *
     * @throws GitHubRefusal When GitHub could not be asked, refused, or answered with no usable list.
     * @throws InvalidArgumentException When the repository is not `owner/name`, or the qualifiers are
     *                                  empty or ones `BacklogQualifiers::refusal()` refuses.
     */
    public function matchingIssues(string $repository, #[SensitiveParameter] string $token, string $qualifiers): array
    {
        if (mb_strlen($repository) > WorkIdentity::MAX_REPOSITORY || preg_match(WorkIdentity::REPOSITORY, $repository) !== 1) {
            throw new InvalidArgumentException('A repository is named as owner/name.');
        }

        if ($qualifiers === '' || BacklogQualifiers::refusal($qualifiers) !== null) {
            throw new InvalidArgumentException('Only qualifiers that narrow the count are listed.');
        }

        $query = sprintf('repo:%s is:issue is:open %s', $repository, $qualifiers);
        $total = null;
        $numbers = [];
        $searches = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->send(fn (): Response => $this->client()
                ->withToken($token)
                ->get('/search/issues', ['q' => $query, 'sort' => 'created', 'order' => 'asc', 'per_page' => self::PAGE_SIZE, 'page' => $page]));

            $searches++;

            if ($response->json('incomplete_results') === true) {
                throw new GitHubRefusal(BacklogFetchOutcome::Incomplete, $response->status());
            }

            $count = $response->json('total_count');
            $items = $response->json('items');

            if (! \is_int($count) || $count < 0 || $count > Backlog::MAX_COUNT || ! \is_array($items)) {
                throw new GitHubRefusal(BacklogFetchOutcome::Unparseable, $response->status());
            }

            $total ??= $count;

            // More than search will list: the count stands, and no set is claimed
            if ($total > self::MAX_MATCHING) {
                return ['total' => $total, 'numbers' => null, 'searches' => $searches];
            }

            foreach ($items as $item) {
                $number = \is_array($item) ? ($item['number'] ?? null) : null;

                if (! \is_int($number) || $number < 1) {
                    throw new GitHubRefusal(BacklogFetchOutcome::Unparseable, $response->status());
                }

                $numbers[$number] = true;
            }

            if (\count($items) < self::PAGE_SIZE || \count($numbers) >= $total) {
                break;
            }
        }

        // A set that does not add up to the count missed or gained an issue between pages
        if (\count($numbers) !== $total) {
            throw new GitHubRefusal(BacklogFetchOutcome::Incomplete, 200);
        }

        $listed = array_keys($numbers);
        sort($listed);

        return ['total' => $total, 'numbers' => $listed, 'searches' => $searches];
    }

    /**
     * The public profile of the account with a numeric user ID (#484).
     *
     * @param  int  $id  The account's numeric user ID.
     * @param  string  $token  An installation token; a profile needs no permission.
     * @return GitHubAccount|null The account, or null when GitHub says there is none (404).
     *
     * @throws GitHubRefusal When GitHub could not be asked, refused otherwise, or answered with
     *                       something that is not a profile.
     * @throws InvalidArgumentException When the ID is not a positive whole number.
     */
    public function accountById(int $id, #[SensitiveParameter] string $token): ?GitHubAccount
    {
        if ($id < 1) {
            throw new InvalidArgumentException('A GitHub user ID is a positive whole number.');
        }

        return $this->account(sprintf('/user/%d', $id), $token);
    }

    /**
     * The public profile of the account with a login (#484).
     *
     * @param  string  $login  The login, compared without case by GitHub.
     * @param  string  $token  An installation token; a profile needs no permission.
     * @return GitHubAccount|null The account, or null when GitHub says there is none (404).
     *
     * @throws GitHubRefusal When GitHub could not be asked, refused otherwise, or answered with
     *                       something that is not a profile.
     * @throws InvalidArgumentException When the login is not a GitHub login, which would change the
     *                                  path.
     */
    public function accountByLogin(string $login, #[SensitiveParameter] string $token): ?GitHubAccount
    {
        if (preg_match(LaneHolds::LOGIN, $login) !== 1) {
            throw new InvalidArgumentException('A GitHub login is 1 to 39 letters, digits and single hyphens, not leading or trailing.');
        }

        return $this->account(sprintf('/users/%s', rawurlencode($login)), $token);
    }

    /**
     * One public profile, checked field by field.
     *
     * **A login that does not match `LaneHolds::LOGIN` is unparseable rather than stored**, because
     * what this returns goes onto a page, into a profile URL, and into the change feed. That refuses
     * an App's `name[bot]` login too, which is right: a bot cannot sign in.
     *
     * @param  string  $path  `/user/{id}` or `/users/{login}`, already built from checked values.
     * @param  string  $token  The installation token.
     * @return GitHubAccount|null The account, or null on a 404.
     *
     * @throws GitHubRefusal When GitHub could not be asked, refused otherwise, or answered with
     *                       something that is not a profile.
     */
    private function account(string $path, #[SensitiveParameter] string $token): ?GitHubAccount
    {
        try {
            $response = $this->send(fn (): Response => $this->client()->withToken($token)->get($path));
        } catch (GitHubRefusal $gitHubRefusal) {
            // A 404 is an answer: no account has that ID or login
            if ($gitHubRefusal->outcome === BacklogFetchOutcome::Refused && $gitHubRefusal->status === 404) {
                return null;
            }

            throw $gitHubRefusal;
        }

        $id = $response->json('id');
        $login = $response->json('login');
        $type = $response->json('type');

        if (! \is_int($id) || $id < 1 || ! \is_string($login) || preg_match(LaneHolds::LOGIN, $login) !== 1
            || ! \in_array($type, [GitHubAccount::USER, 'Organization', 'Bot'], true)) {
            throw new GitHubRefusal(BacklogFetchOutcome::Unparseable, $response->status());
        }

        return new GitHubAccount($id, $login, $type);
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
     * @param  bool  $projects  Whether the token also reads projects, which is cached apart.
     * @return string The key.
     *
     * @throws GitHubRefusal When no usable App id is configured.
     */
    private function cacheKey(int $installation, bool $projects): string
    {
        $app = $this->key->appId() ?? throw new GitHubRefusal(BacklogFetchOutcome::KeyUnusable);

        return sprintf('robot-council:github-app:%s:installation:%d:token%s', $app, $installation, $projects ? ':projects' : '');
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
