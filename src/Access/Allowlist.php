<?php

declare(strict_types=1);

namespace RobotCouncil\Access;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Decides which GitHub accounts may use the service, from the `robot-council.access` lists and the
 * entries an administrator added (#406). It reads both on every call, so a change takes effect on
 * the next request — for the environment lists, unless the host application caches its
 * configuration, in which case it takes effect when the host re-runs `php artisan config:cache`.
 *
 * **Each list is the union of the two sources** (#312's decision). The table adds; it never
 * removes or replaces an environment entry, so an environment administrator is always an
 * administrator and the deployment's configuration is always the way back in.
 */
final class Allowlist
{
    /**
     * The table's entries, by list, read once for this instance.
     *
     * **Once per request, never across requests.** The provider binds this class `scoped`, which
     * Octane and the queue worker reset between requests and jobs, and `AllowlistEntries` forgets
     * the instance after every change, so a change takes effect on the next request. Within one, the
     * middleware, the admin gate, the layout and `FleetAbilities` -- which asks about every
     * coordinator holder -- share one query.
     *
     * @var array<string, list<int>>|null
     */
    private ?array $stored = null;

    /**
     * @param  Repository  $config  The host application's configuration repository.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * Determine whether a GitHub account may sign in at all.
     *
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @return bool True when the ID is listed as a developer or an admin.
     */
    public function admits(int $githubId): bool
    {
        return \in_array($githubId, $this->developers(), true) || $this->isAdmin($githubId);
    }

    /**
     * Determine whether a GitHub account holds admin rights.
     *
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @return bool True when the ID is listed as an admin.
     */
    public function isAdmin(int $githubId): bool
    {
        return \in_array($githubId, $this->admins(), true);
    }

    /**
     * The GitHub user IDs listed as developers.
     *
     * @return list<int> The configured developer IDs, without duplicates.
     */
    public function developers(): array
    {
        return $this->union(AccessList::Developer);
    }

    /**
     * The GitHub user IDs listed as admins.
     *
     * @return list<int> The configured admin IDs, without duplicates.
     */
    public function admins(): array
    {
        return $this->union(AccessList::Admin);
    }

    /**
     * The GitHub user IDs a list holds through the host's configuration alone.
     *
     * @param  AccessList  $list  The list.
     * @return list<int> The IDs, without duplicates.
     */
    public function configured(AccessList $list): array
    {
        return $this->ids($list->configKey());
    }

    /**
     * Read a GitHub user ID the way the lists are read: a whole number, or nothing.
     *
     * Shared with `Support\AllowlistEntries`, so the table can hold no ID the environment list
     * would have ignored.
     *
     * @param  mixed  $value  The candidate.
     * @return int|null The ID, or null when it is not a positive whole number that fits.
     */
    public static function githubId(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! \is_string($value)) {
            return null;
        }

        $candidate = trim($value);

        // Nineteen digits can exceed PHP_INT_MAX, and `(int)` would clamp rather than refuse
        if ($candidate === '' || ctype_digit($candidate) === false || \strlen(ltrim($candidate, '0')) > 18) {
            return null;
        }

        $id = (int) $candidate;

        return $id > 0 ? $id : null;
    }

    /**
     * A list's environment entries, plus its table entries.
     *
     * @param  AccessList  $list  The list.
     * @return list<int> The IDs, without duplicates, environment entries first.
     */
    private function union(AccessList $list): array
    {
        return array_values(array_unique([...$this->configured($list), ...$this->stored($list)]));
    }

    /**
     * A list's table entries.
     *
     * **A host that has not run the migration yet reads none**, rather than failing every request
     * the allowlist gates -- sign-in included -- until it does. The environment lists still apply.
     *
     * @param  AccessList  $list  The list.
     * @return list<int> The IDs.
     */
    private function stored(AccessList $list): array
    {
        if ($this->stored === null) {
            $this->stored = [];

            try {
                $rows = DB::table('robot_council_allowlist_entries')->orderBy('id')->get(['list', 'github_id']);
            } catch (QueryException) {
                $rows = [];
            }

            foreach ($rows as $row) {
                $id = self::githubId(\is_int($row->github_id) ? $row->github_id : (\is_scalar($row->github_id) ? (string) $row->github_id : null));

                if ($id !== null && \is_string($row->list)) {
                    $this->stored[$row->list][] = $id;
                }
            }
        }

        return $this->stored[$list->value] ?? [];
    }

    /**
     * Read one access list, accepting a comma-separated string or an array.
     *
     * @param  string  $key  The configuration key holding the list.
     * @return list<int> The numeric IDs in that list, without duplicates.
     */
    private function ids(string $key): array
    {
        $configured = $this->config->get($key, []);

        // Refuse anything else: `ROBOT_COUNCIL_ADMINS=true` becomes boolean true, whose string
        // form is `1`, which would otherwise admit GitHub user ID 1
        if (! \is_array($configured) && ! \is_string($configured)) {
            return [];
        }

        // Split an environment string into entries, and take an array as it stands
        $entries = \is_array($configured) ? $configured : explode(',', $configured);

        // Keep only the entries that are whole numbers, since GitHub user IDs are integers
        $ids = [];

        foreach ($entries as $entry) {
            if (! \is_scalar($entry)) {
                continue;
            }

            $candidate = trim((string) $entry);

            if ($candidate === '' || ctype_digit($candidate) === false) {
                continue;
            }

            $ids[] = (int) $candidate;
        }

        return array_values(array_unique($ids));
    }
}
