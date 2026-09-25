<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use OpenSSLAsymmetricKey;

/**
 * Doctor's two checks on the backlog fetch (#383): whether the GitHub App is usable, and how the
 * latest fetch went for each repository on the board.
 *
 * **Neither asks GitHub anything.** Doctor runs as a deploy gate and when somebody is worried, and a
 * check that made requests would slow the one and depend on the thing being diagnosed in the other.
 * Whether an owner has an installation is read from what the last fetch recorded, and the detail
 * says when that was.
 *
 * **Neither prints a credential**: whether the key parses, never what it is.
 */
final class GitHubAppDiagnosis
{
    /**
     * @param  GitHubAppKey  $key  The App's configuration.
     * @param  BacklogFetches  $fetches  What the last fetch recorded.
     * @param  LaneBoard  $board  Which repositories are on the board.
     * @param  Repository  $config  For how old a fetch may be and still count as recent.
     */
    public function __construct(
        private readonly GitHubAppKey $key,
        private readonly BacklogFetches $fetches,
        private readonly LaneBoard $board,
        private readonly Repository $config
    ) {}

    /**
     * Whether the App is configured, and its id and key are usable.
     *
     * **Unconfigured passes**, like an unset Slack webhook: a host that wants no fetched counts is
     * correctly configured. Half-configured fails, because that is a mistake rather than a choice.
     *
     * @return Diagnosis What the check concluded.
     */
    public function app(): Diagnosis
    {
        if (! $this->key->configured()) {
            return Diagnosis::passed(
                'github app',
                'Not configured, so no backlog count is fetched and the meters read only what sessions report. That is a choice, not a fault.'
            );
        }

        if (! $this->key->configuredId() || ! $this->key->configuredKey()) {
            return Diagnosis::failed(
                'github app',
                sprintf(
                    '%s is set and %s is not. Set both, or neither.',
                    $this->key->configuredId() ? 'ROBOT_COUNCIL_GITHUB_APP_ID' : 'ROBOT_COUNCIL_GITHUB_APP_PRIVATE_KEY',
                    $this->key->configuredId() ? 'ROBOT_COUNCIL_GITHUB_APP_PRIVATE_KEY' : 'ROBOT_COUNCIL_GITHUB_APP_ID'
                )
            );
        }

        $id = $this->key->appId();

        if ($id === null) {
            return Diagnosis::failed(
                'github app',
                "ROBOT_COUNCIL_GITHUB_APP_ID is not a numeric App id. It is the number on the App's settings page, not its client id."
            );
        }

        if (! $this->key->privateKey() instanceof OpenSSLAsymmetricKey) {
            return Diagnosis::failed(
                'github app',
                'ROBOT_COUNCIL_GITHUB_APP_PRIVATE_KEY does not parse as an RSA private key. Set it to the PEM GitHub generated for the App, base64-encoded onto one line.'
            );
        }

        return Diagnosis::passed('github app', sprintf('App %s is configured, and its key parses.', $id));
    }

    /**
     * Per owner on the board, whether the App is installed; per repository, how its latest fetch went.
     *
     * Fails when an owner has no installation or a repository's latest fetch failed, because each
     * is a meter reading unreadable for a reason somebody can fix. Undetermined when a repository has
     * not been fetched recently, which is what a scheduler that is not running looks like, and when
     * its latest fetch met something that passes on its own -- GitHub not answering, a rate limit,
     * an incomplete search, or a failure outside GitHub -- because failing a deploy gate on those
     * would fail it on GitHub's weather.
     *
     * Owners are grouped without case, as GitHub names them, and shown as the board spells them.
     *
     * @return Diagnosis What the check concluded.
     */
    public function fetch(): Diagnosis
    {
        if (! $this->key->configured()) {
            return Diagnosis::passed('backlog fetch', 'No GitHub App is configured, so nothing is fetched.');
        }

        $repositories = $this->board->repositories();

        if ($repositories === []) {
            return Diagnosis::passed('backlog fetch', 'No repository is on the lane board, so there is nothing to fetch.');
        }

        $latest = $this->fetches->latest($repositories);
        $recent = CarbonImmutable::now()->subMinutes($this->staleAfterMinutes());

        $owners = [];
        $shown = [];
        $lines = [];
        $failed = false;
        $unknown = false;

        // Describe each repository, and gather whether its owner's installation was found
        foreach ($repositories as $repository) {
            $spelled = explode('/', $repository, 2)[0];
            $owner = mb_strtolower($spelled);
            $shown[$owner] ??= $spelled;
            $fetch = $latest[$repository] ?? null;

            if ($fetch === null || $fetch['attempted_at']->lessThan($recent)) {
                $unknown = true;
                $lines[] = sprintf('%s: not fetched in the last %d minutes', $repository, $this->staleAfterMinutes());
                $owners[$owner] ??= null;

                continue;
            }

            // An owner is installed when any of its repositories was read, and has no installation
            // when the lookup said so. Any other failure leaves it unconfirmed, because a refusal
            // cannot say whether it came before the lookup or after it
            $owners[$owner] = match (true) {
                $fetch['outcome'] === BacklogFetchOutcome::Read => true,
                ($owners[$owner] ?? null) === true => true,
                $fetch['outcome'] === BacklogFetchOutcome::NoInstallation => false,
                default => $owners[$owner] ?? null,
            };

            // A transient outcome leaves the answer open; any other failure is one to fix
            if ($fetch['outcome']->transient()) {
                $unknown = true;
            } elseif ($fetch['outcome'] !== BacklogFetchOutcome::Read) {
                $failed = true;
            }

            $lines[] = sprintf(
                '%s: %s%s at %s UTC',
                $repository,
                $fetch['outcome']->value,
                $fetch['status'] === null ? '' : sprintf(' (HTTP %d)', $fetch['status']),
                $fetch['attempted_at']->utc()->format('Y-m-d H:i')
            );
        }

        ksort($owners, SORT_STRING);

        $ownerLines = array_map(
            // A key that looks like a number arrives as an int, so it is taken as either
            static fn (int|string $owner, ?bool $installed): string => sprintf('%s: %s', $shown[$owner] ?? (string) $owner, match ($installed) {
                true => 'installed',
                false => 'no installation',
                null => 'not confirmed by the latest fetch',
            }),
            array_keys($owners),
            array_values($owners)
        );

        $detail = sprintf('Owners -- %s. Repositories -- %s.', implode('; ', $ownerLines), implode('; ', $lines));

        if ($failed) {
            return Diagnosis::failed(
                'backlog fetch',
                $detail.' A repository not read shows "count unreadable" on the board. Install the App on an owner that has none, and check the App\'s key for a refusal.'
            );
        }

        if ($unknown) {
            return Diagnosis::undetermined(
                'backlog fetch',
                $detail.' A transient failure clears on a later fetch; a repository never fetched recently asks whether the scheduler is running `robot-council:backlog-fetch`.'
            );
        }

        return Diagnosis::passed('backlog fetch', $detail);
    }

    /**
     * How old a fetch may be and still be recent, the meters' own window.
     *
     * @return int Minutes, at least one.
     */
    private function staleAfterMinutes(): int
    {
        $minutes = $this->config->get('robot-council.backlog.stale_after_minutes', 60);

        return max(1, \is_int($minutes) ? $minutes : 60);
    }
}
