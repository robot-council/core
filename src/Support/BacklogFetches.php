<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * How each repository's latest backlog fetch went (#383), one row per repository.
 *
 * A failed fetch stores no reading, so this is the only record of why a meter is blank. It holds
 * an outcome and a status, never anything GitHub said beyond that, and never a credential.
 */
final class BacklogFetches
{
    /**
     * The widest outcome the table holds, `robot_council_backlog_fetches.outcome`'s width.
     */
    public const int MAX_OUTCOME = 32;

    /**
     * Record an attempt, replacing the repository's previous one.
     *
     * @param  string  $repository  `owner/name`.
     * @param  BacklogFetchOutcome  $outcome  How it went.
     * @param  int|null  $status  The HTTP status GitHub answered with, when it answered.
     *
     * @throws InvalidArgumentException When the repository or the status is outside what is stored.
     */
    public function record(string $repository, BacklogFetchOutcome $outcome, ?int $status): void
    {
        if (mb_strlen($repository) > WorkIdentity::MAX_REPOSITORY || preg_match(WorkIdentity::REPOSITORY, $repository) !== 1) {
            throw new InvalidArgumentException('A repository is named as owner/name.');
        }

        // An HTTP status is three digits; anything else is not one, and the column is a smallint
        if ($status !== null && ($status < 100 || $status > 599)) {
            throw new InvalidArgumentException('An HTTP status is between 100 and 599.');
        }

        DB::table('robot_council_backlog_fetches')->upsert(
            [[
                'repository' => $repository,
                'outcome' => $outcome->value,
                'status' => $status,
                'attempted_at' => PresenceClock::now(),
            ]],
            ['repository'],
            ['outcome', 'status', 'attempted_at']
        );
    }

    /**
     * The latest attempt for each repository asked about that has one.
     *
     * @param  list<string>  $repositories  The repositories.
     * @return array<string, array{outcome: BacklogFetchOutcome, status: int|null, attempted_at: CarbonImmutable}> By
     *                                                                                                             repository.
     */
    public function latest(array $repositories): array
    {
        if ($repositories === []) {
            return [];
        }

        $latest = [];

        $rows = DB::table('robot_council_backlog_fetches')
            ->whereIn('repository', $repositories)
            ->get(['repository', 'outcome', 'status', 'attempted_at']);

        // Keep each row whose outcome this version knows; one written by a later version is skipped
        // rather than guessed at
        foreach ($rows as $row) {
            $outcome = \is_string($row->outcome) ? BacklogFetchOutcome::tryFrom($row->outcome) : null;

            if (! \is_string($row->repository) || ! $outcome instanceof BacklogFetchOutcome || ! \is_string($row->attempted_at)) {
                continue;
            }

            $latest[$row->repository] = [
                'outcome' => $outcome,
                'status' => is_numeric($row->status) ? (int) $row->status : null,
                'attempted_at' => CarbonImmutable::parse($row->attempted_at, 'UTC'),
            ];
        }

        return $latest;
    }

    /**
     * The repositories in the order a run should try them: never attempted first, then oldest.
     *
     * So a board with more repositories than one run asks about reaches each in turn, rather than
     * the same ones every time.
     *
     * @param  list<string>  $repositories  The repositories.
     * @return list<string> The same repositories, reordered.
     */
    public function oldestFirst(array $repositories): array
    {
        $latest = $this->latest($repositories);

        $order = $repositories;

        // Compared as timestamps, because `<=>` on two date objects compares their properties
        $when = static fn (string $repository): int => isset($latest[$repository]) ? $latest[$repository]['attempted_at']->getTimestamp() : PHP_INT_MIN;

        usort($order, static fn (string $a, string $b): int => [$when($a), $a] <=> [$when($b), $b]);

        return $order;
    }
}
