<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Which open issues each repository's search qualifiers match, as the backlog fetch last found them,
 * and whether the shortlist may filter by them (#530).
 *
 * **One set of filters for every surface.** `robot-council.backlog.search_qualifiers` narrows the
 * meter's count (#488), and the mirror the shortlist reads holds no project membership, so a
 * `project:` qualifier cannot be evaluated against it. The fetch therefore records the issue numbers
 * the same search returned, and the shortlist and the documentation-ahead warning keep only those.
 *
 * **A set that cannot be trusted is never presented as a filter.** Each repository is `unfiltered`
 * when it has no qualifiers, `filtered` when a set was fetched with exactly the qualifiers in effect
 * within `robot-council.backlog.stale_after_minutes` -- the window a meter reading is read in -- and
 * `unresolved` otherwise, with the reason. An unresolved repository lists nothing, rather than
 * every open ticket as if it had been filtered: the same rule #488 applies to the meter, which
 * reads unreadable rather than showing the unfiltered count.
 */
final class BacklogMembers
{
    /**
     * A repository with no qualifiers: every open ticket is listed, exactly as before #530.
     */
    public const string UNFILTERED = 'unfiltered';

    /**
     * A repository whose qualifiers' matches are known: only those are listed.
     */
    public const string FILTERED = 'filtered';

    /**
     * A repository with qualifiers whose matches are not known: nothing is listed.
     */
    public const string UNRESOLVED = 'unresolved';

    /**
     * @param  Repository  $config  The application's configuration.
     * @param  BacklogQualifiers  $qualifiers  The qualifiers in effect.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly BacklogQualifiers $qualifiers
    ) {}

    /**
     * Record what a repository's qualifiers matched, replacing what it matched before.
     *
     * @param  string  $repository  `owner/name`.
     * @param  string  $qualifiers  The qualifiers the search was asked with.
     * @param  list<int>|null  $numbers  The matching issue numbers, or null when there were more than
     *                                   the search lists.
     *
     * @throws InvalidArgumentException When the repository, the qualifiers, or the numbers are
     *                                  outside what is stored.
     */
    public function record(string $repository, string $qualifiers, ?array $numbers): void
    {
        if (mb_strlen($repository) > WorkIdentity::MAX_REPOSITORY || preg_match(WorkIdentity::REPOSITORY, $repository) !== 1) {
            throw new InvalidArgumentException('A repository is named as owner/name.');
        }

        if ($qualifiers === '' || BacklogQualifiers::refusal($qualifiers) !== null) {
            throw new InvalidArgumentException('Only qualifiers that narrow the count are recorded.');
        }

        if ($numbers !== null && \count($numbers) > GitHubApp::MAX_MATCHING) {
            throw new InvalidArgumentException(sprintf('At most %d numbers are recorded.', GitHubApp::MAX_MATCHING));
        }

        DB::table('robot_council_backlog_members')->upsert(
            [[
                'repository' => mb_strtolower($repository),
                'qualifiers' => $qualifiers,
                'numbers' => $numbers === null ? null : json_encode($numbers, JSON_THROW_ON_ERROR),
                'fetched_at' => PresenceClock::now(),
            ]],
            ['repository'],
            ['qualifiers', 'numbers', 'fetched_at']
        );
    }

    /**
     * How each repository's tickets are filtered, and by what.
     *
     * @param  list<string>  $repositories  The repositories, `owner/name`.
     * @return array<string, array{status: string, qualifiers: string|null, reason: string|null, numbers: array<int, true>|null}> By
     *                                                                                                                            repository
     *                                                                                                                            as given;
     *                                                                                                                            `numbers`
     *                                                                                                                            only when
     *                                                                                                                            filtered.
     */
    public function filters(array $repositories): array
    {
        $rows = [];
        $lower = array_values(array_unique(array_map(mb_strtolower(...), $repositories)));

        if ($lower !== []) {
            foreach (DB::table('robot_council_backlog_members')->whereIn('repository', $lower)->get(['repository', 'qualifiers', 'numbers', 'fetched_at']) as $row) {
                if (\is_string($row->repository)) {
                    $rows[$row->repository] = $row;
                }
            }
        }

        $fresh = CarbonImmutable::instance(PresenceClock::now())->utc()->subMinutes($this->staleAfterMinutes());
        $filters = [];

        foreach ($repositories as $repository) {
            $qualifiers = $this->qualifiers->for($repository);

            if ($qualifiers === '') {
                $filters[$repository] = ['status' => self::UNFILTERED, 'qualifiers' => null, 'reason' => null, 'numbers' => null];

                continue;
            }

            $filters[$repository] = $this->resolve($qualifiers, $rows[mb_strtolower($repository)] ?? null, $fresh);
        }

        return $filters;
    }

    /**
     * One repository's filter, from its qualifiers and the set last fetched for it.
     *
     * @param  string|null  $qualifiers  The qualifiers in effect, or null when they are refused.
     * @param  object|null  $row  The stored set, if any.
     * @param  CarbonImmutable  $fresh  The oldest fetch still read.
     * @return array{status: string, qualifiers: string|null, reason: string|null, numbers: array<int, true>|null} The filter.
     */
    private function resolve(?string $qualifiers, ?object $row, CarbonImmutable $fresh): array
    {
        $unresolved = static fn (string $reason): array => ['status' => self::UNRESOLVED, 'qualifiers' => $qualifiers, 'reason' => $reason, 'numbers' => null];

        if ($qualifiers === null) {
            return $unresolved('The search qualifiers configured for it are refused, so which tickets they match is unknown.');
        }

        $fetchedAt = $row !== null && property_exists($row, 'fetched_at') && \is_string($row->fetched_at) ? CarbonImmutable::parse($row->fetched_at, 'UTC') : null;

        if ($row === null || ! property_exists($row, 'qualifiers') || $row->qualifiers !== $qualifiers || ! $fetchedAt instanceof CarbonImmutable) {
            return $unresolved('No fetch has listed the tickets its search qualifiers match.');
        }

        if ($fetchedAt->lt($fresh)) {
            return $unresolved('The tickets its search qualifiers match were last listed too long ago to rely on.');
        }

        $decoded = property_exists($row, 'numbers') && \is_string($row->numbers) ? json_decode($row->numbers, true) : null;

        if (! \is_array($decoded)) {
            return $unresolved(sprintf('Its search qualifiers match more than %s open issues, more than a search lists.', number_format(GitHubApp::MAX_MATCHING)));
        }

        $numbers = [];

        foreach ($decoded as $number) {
            if (\is_int($number)) {
                $numbers[$number] = true;
            }
        }

        return ['status' => self::FILTERED, 'qualifiers' => $qualifiers, 'reason' => null, 'numbers' => $numbers];
    }

    /**
     * How old a fetched set may be and still be read: the meter's window.
     *
     * @return int Minutes, at least one.
     */
    private function staleAfterMinutes(): int
    {
        $minutes = $this->config->get('robot-council.backlog.stale_after_minutes', 60);

        return max(1, \is_int($minutes) ? $minutes : 60);
    }
}
