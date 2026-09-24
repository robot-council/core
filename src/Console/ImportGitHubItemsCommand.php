<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RobotCouncil\Support\GitHubState;

/**
 * Store issue and pull-request state from a file an operator produced with `gh api` (#318).
 *
 * **The backfill a webhook needs, without core reading GitHub.** A webhook delivers only what
 * happens after it is configured, so work already open has to be told once. The operator reads it
 * with their own credentials -- `gh api --paginate --slurp 'repos/{owner}/{repo}/issues?state=open'`
 * and the same for `pulls` -- and this imports the file. The package still makes no request to
 * GitHub, so no coordination decision depends on GitHub being reachable.
 *
 * It stores state and moves no lane: an issue that is already closed when imported frees nobody,
 * because the import is a snapshot and not an event.
 */
#[Description('Import open GitHub issues and pull requests from a `gh api` JSON file')]
#[Signature('robot-council:github-import {file : A JSON array of issues or pull requests, as `gh api --paginate --slurp` writes it}')]
final class ImportGitHubItemsCommand extends Command
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
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! \is_string($contents)) {
            $this->components->error(sprintf('Cannot read `%s`.', $path));

            return self::FAILURE;
        }

        $decoded = json_decode($contents, true);

        if (! \is_array($decoded)) {
            $this->components->error('The file is not a JSON array.');

            return self::FAILURE;
        }

        $stored = 0;
        $refused = 0;

        foreach ($this->items($decoded) as $item) {
            try {
                $stored += $state->import($item) ? 1 : 0;
            } catch (InvalidArgumentException $invalid) {
                $refused++;
                $this->components->warn($invalid->getMessage());
            }
        }

        // Counted rather than summarized, so a file that imported nothing reads as that
        $this->components->info(sprintf('Stored %d item(s); %d refused; %d already newer.', $stored, $refused, $this->counted - $stored - $refused));

        return $refused === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * How many items the file held.
     */
    private int $counted = 0;

    /**
     * The items in the file, one page deep: `--slurp` writes an array of pages.
     *
     * @param  array<array-key, mixed>  $decoded  The file.
     * @return list<array<array-key, mixed>> The items.
     */
    private function items(array $decoded): array
    {
        $items = [];

        foreach ($decoded as $entry) {
            if (\is_array($entry) && array_is_list($entry)) {
                foreach ($entry as $item) {
                    if (\is_array($item)) {
                        $items[] = $item;
                    }
                }
            } elseif (\is_array($entry)) {
                $items[] = $entry;
            }
        }

        $this->counted = \count($items);

        return $items;
    }
}
