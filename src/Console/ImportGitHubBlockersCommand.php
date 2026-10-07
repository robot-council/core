<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RobotCouncil\Support\GitHubState;

/**
 * Store open issues' `blocked_by` edges from a file an operator produced with `gh api graphql` (#569).
 *
 * **The edges' half of `robot-council:github-import`.** That command backfills issues and pull
 * requests but no edges, and the webhook records an edge only when it changes, so a ticket blocked
 * since before the webhook was configured read as unblocked: the shortlist offered it, and the
 * documentation-ahead warning counted it. The operator reads one repository's open issues with their
 * blockers in a single paginated query, with their own credentials, and this imports the file. The
 * package still makes no request to GitHub.
 *
 * GraphQL rather than REST because the read is graph-shaped: REST answers one issue's blockers per
 * request, and this needs every open issue's.
 *
 * **It refuses what it cannot trust rather than storing part of it quietly.** A GraphQL refusal --
 * a rate limit among them -- arrives as HTTP 200 with an `errors` key and no data, a `blockedBy` list
 * can be cut short at the page size asked for, and a file whose last page still has a next one was
 * not read to the end. Each of those fails the run and says which, so an import that missed edges
 * never reads as one that found none.
 */
#[Description("Import open issues' blocked_by edges from a `gh api graphql` JSON file")]
#[Signature('robot-council:github-import-blockers {file : A JSON array of pages, as `gh api graphql --paginate --slurp` writes it}')]
final class ImportGitHubBlockersCommand extends Command
{
    /**
     * Import the file.
     *
     * @param  GitHubState  $state  The store.
     * @return int The command's exit code.
     */
    public function handle(GitHubState $state): int
    {
        $path = Argument::text($this->argument('file'));
        $contents = is_file($path) ? file_get_contents($path) : null;

        if (! \is_string($contents)) {
            $this->components->error(sprintf('Cannot read `%s`.', $path));

            return self::FAILURE;
        }

        $decoded = json_decode($contents, true);

        if (! \is_array($decoded)) {
            $this->components->error('The file is not JSON.');

            return self::FAILURE;
        }

        // `--slurp` writes a list of pages; a single page, read without it, is accepted as one
        $pages = array_is_list($decoded) ? $decoded : [$decoded];

        $issues = 0;
        $added = 0;
        $known = 0;
        $refused = 0;
        $unfinished = null;

        foreach ($pages as $index => $page) {
            $repository = self::at($page, 'data', 'repository');

            if (! \is_array($repository) || self::at($page, 'errors') !== null) {
                $this->components->warn(sprintf('Page %d carries no repository, or carries errors: %s', $index + 1, json_encode(self::at($page, 'errors'), JSON_THROW_ON_ERROR)));
                $refused++;

                continue;
            }

            $name = self::at($repository, 'nameWithOwner');
            $unfinished = self::at($repository, 'issues', 'pageInfo', 'hasNextPage') === true ? $name : null;
            $nodes = self::at($repository, 'issues', 'nodes');

            foreach (\is_array($nodes) ? $nodes : [] as $issue) {
                $issues++;
                $number = self::at($issue, 'number');
                $blockers = self::at($issue, 'blockedBy', 'nodes');
                $blockers = \is_array($blockers) ? $blockers : [];

                // Cut short at the page size asked for: store what it does list, which can only
                // block more, and fail the run so the rest is not taken for absent
                if (self::at($issue, 'blockedBy', 'totalCount') !== \count($blockers)) {
                    $this->components->warn(sprintf('#%s lists %d of its blockers; ask for more with `blockedBy(first:)`.', json_encode($number), \count($blockers)));
                    $refused++;
                }

                foreach ($blockers as $blocker) {
                    try {
                        if ($state->importBlocker($name, $number, self::at($blocker, 'repository', 'nameWithOwner'), self::at($blocker, 'number'))) {
                            $added++;
                        } else {
                            $known++;
                        }
                    } catch (InvalidArgumentException $invalid) {
                        $this->components->warn($invalid->getMessage());
                        $refused++;
                    }
                }
            }
        }

        if ($unfinished !== null) {
            $this->components->warn(sprintf('The last page of `%s` has a next page, so the file was not read to the end; pass `--paginate`.', \is_string($unfinished) ? $unfinished : 'the repository'));
            $refused++;
        }

        // Counted rather than summarized, so a file that imported nothing reads as that
        $this->components->info(sprintf('Read %d open issue(s) on %d page(s). Stored %d new edge(s); %d already recorded; %d refused.', $issues, \count($pages), $added, $known, $refused));

        return $refused === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A value inside decoded JSON, or null where any step of the path is missing.
     *
     * @param  mixed  $value  Where to start.
     * @param  string  ...$keys  The path.
     * @return mixed What is there.
     */
    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (! \is_array($value) || ! \array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}
