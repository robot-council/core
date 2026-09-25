<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * Which rows in a host's `migrations` table belong to this package.
 *
 * **The manifest is the point, and it exists because no pattern can answer that.** The table holds
 * the host's rows and every other package's beside this one's, so a check that speaks about "this
 * package's migrations" has to know which they are. A substring test on `robot_council` is the
 * obvious narrowing and is **already wrong**: `2026_09_22_000002_compare_host_user_keys_byte_exactly`
 * is one of this package's own and contains no such substring. Naming them is exact, and the
 * guard in `tests/MigrationManifestGuardTest.php` is what keeps the naming honest (#169).
 *
 * **A recorded name whose file is gone survives every rollback.** `Migrator::rollbackMigrations()`
 * prints `Migration not found` and `continue`s for exactly that case and never deletes the row, so
 * `migrate:rollback`, `migrate:reset` and `migrate:refresh` all leave it. `migrate:fresh` is the
 * one exception, and it drops the table with everything else rather than tidying the row. `robot-council/core#132` produced two of them by dating a
 * create migration and removing the sort workaround beside it, and every host that migrated from
 * `dev-main` before that carries both. They are inert, and until now nothing said so.
 *
 * **What this cannot do, stated so nobody builds on it.** It cannot detect a database that is
 * ahead of its code -- a host that ran a newer version's migrations and then installed an older
 * package. The manifest ships *with* the package, so a name from a later version is not in it, and
 * no comparison against it can see one. That needs a mechanism this package does not have.
 */
final class PackageMigrations
{
    /**
     * Every migration name this package has ever shipped, retired ones included.
     *
     * **Appended to in the same change that adds a migration, and never edited otherwise.** A
     * rename is two entries, not one changed entry: the old name stays because a host that ran it
     * still has the row, and removing it here would make that row unidentifiable again.
     *
     * Derived from history rather than memory:
     * `git log --all --no-renames --name-only --format='' -- database/migrations/`.
     *
     * **Two things that command cannot see**, so a re-derivation's silence is not completeness: a
     * file added and deleted within one commit appears in no diff, and a ref absent from the local
     * clone is not searched. Neither affects the list below; both would affect a future one.
     *
     * @var list<string>
     */
    public const array EVER_SHIPPED = [
        '2026_09_18_000000_create_robot_council_github_identities_table',
        '2026_09_18_000001_create_robot_council_installations_table',
        '2026_09_18_000002_create_robot_council_agent_sessions_table',
        '2026_09_18_000003_create_robot_council_device_codes_table',
        '2026_09_18_000004_create_robot_council_events_table',
        '2026_09_18_000005_create_robot_council_tasks_table',
        '2026_09_18_000006_create_robot_council_locks_table',
        '2026_09_18_000007_drop_robot_council_event_indexes',
        '2026_09_22_000001_create_robot_council_lock_fence_table',
        '2026_09_22_000002_compare_host_user_keys_byte_exactly',
        '2026_09_22_000003_index_robot_council_installation_identity',
        '2026_09_22_000004_add_feed_cursor_to_robot_council_agent_sessions',
        '2026_09_23_000001_add_actor_to_robot_council_events',
        '2026_09_23_000002_rename_session_enrolled_events',
        '2026_09_23_000003_add_role_to_robot_council_agent_sessions',
        '2026_09_23_000004_add_work_identity_to_robot_council_agent_sessions',
        '2026_09_23_000005_add_role_requests_to_robot_council_agent_sessions',
        '2026_09_23_000006_index_robot_council_agent_session_roles',
        '2026_09_23_000007_compare_session_roles_byte_exactly',
        '2026_09_24_000001_drop_granted_abilities_columns',
        '2026_09_24_000002_drop_project_id_from_robot_council_agent_sessions',
        '2026_09_24_000003_create_robot_council_event_addressees_table',
        '2026_09_24_000004_add_placement_to_robot_council_tasks',
        '2026_09_24_000005_create_robot_council_seats_table',
        '2026_09_24_000006_create_robot_council_assignment_hours_table',
        '2026_09_24_000007_create_robot_council_holidays_table',
        '2026_09_24_000008_create_robot_council_github_deliveries_table',
        '2026_09_24_000009_create_robot_council_github_items_table',
        '2026_09_24_000010_create_robot_council_github_blockers_table',
        '2026_09_24_000011_create_robot_council_lane_holds_table',
        '2026_09_24_000012_create_robot_council_placement_waivers_table',
        '2026_09_24_000013_create_robot_council_backlog_tables',
        '2026_09_24_000014_create_robot_council_gate_runs_table',
        '2026_09_24_000015_create_robot_council_owed_items_table',
        '2026_09_24_000016_add_watcher_seen_at_to_robot_council_agent_sessions',
        '2026_09_24_000017_add_quiet_noticed_at_to_robot_council_agent_sessions',
        '2026_09_24_000018_add_mentioned_paths_to_robot_council_github_items',
        '2026_09_24_000019_create_robot_council_github_branches_table',

        // Retired by #132, which dated the create so something dated could alter its table. Both
        // names stay here forever: a host that migrated before that change has a row for each.
        'create_robot_council_github_identities_table',
        'fix_robot_council_github_identity_collation',
    ];

    /**
     * The migration names this version ships, as the migrator will record them.
     *
     * @param  string|null  $directory  Where to look, for a caller that is not this package's own
     *                                  tree. The seam exists so a test can point the check at a
     *                                  temporary directory instead of writing a probe file into
     *                                  the tracked one, which a parallel run or a second session
     *                                  in the same checkout would see.
     * @return list<string> The base names, without the `.php`, in filename order.
     */
    public static function shipped(?string $directory = null): array
    {
        // **The migrator's own glob, not a wider one.** `Migrator::getMigrationFiles()` globs
        // `*_*.php` and derives the name with `str_replace('.php', '', basename($path))`. A file
        // this matched and the migrator did not would be reported permanently pending by
        // `Doctor::migrations()` and as an unlisted migration by `retiredMigrations()`, for a file
        // that can never run. `glob()` already returns a sorted list, so nothing sorts here.
        $paths = glob(($directory ?? self::directory()).'/*_*.php');

        // No input reaches this, so `FalseToTrue` on it cannot be killed. `glob()` returns an empty
        // ARRAY for a directory that does not exist -- measured, `glob('/definitely/not/a/dir/*.php')`
        // is `array(0) {}` -- and reserves `false` for a filesystem error this package cannot
        // provoke. The guard stays because the signature says `array|false` and `array_map()` would
        // fatal on the other branch; the empty case is handled by the callers, which is where the
        // real failure mode lives.
        // @pest-mutate-ignore: FalseToTrue
        if ($paths === false) {
            return [];
        }

        return array_map(
            static fn (string $path): string => str_replace('.php', '', basename($path)),
            $paths
        );
    }

    /**
     * The names this package once shipped and no longer does.
     *
     * @param  string|null  $directory  Where to look; see `shipped()`.
     * @return list<string> The retired names, in the order the manifest lists them.
     */
    public static function retired(?string $directory = null): array
    {
        return array_values(array_diff(self::EVER_SHIPPED, self::shipped($directory)));
    }

    /**
     * The names this version ships that the manifest does not list.
     *
     * **Non-empty means the manifest is stale, and that is a fault rather than a curiosity.**
     * Every other answer here is computed against the manifest, so an incomplete one makes the
     * retired set wrong in the direction that hides a row rather than invents one.
     *
     * @param  string|null  $directory  Where to look; see `shipped()`.
     * @return list<string> The unlisted names, in filename order.
     */
    public static function unlisted(?string $directory = null): array
    {
        return array_values(array_diff(self::shipped($directory), self::EVER_SHIPPED));
    }

    /**
     * Which of the recorded names belong to this package and are no longer shipped.
     *
     * @param  list<string>  $recorded  The `migration` column, as the host's table holds it.
     * @param  string|null  $directory  Where to look; see `shipped()`.
     * @return list<string> The retired names this host has run, in manifest order.
     */
    public static function retiredAmong(array $recorded, ?string $directory = null): array
    {
        // `retired()` first, so the result is in MANIFEST order rather than the host's row order.
        return array_values(array_intersect(self::retired($directory), $recorded));
    }

    /**
     * Where this package's migrations live.
     *
     * @return string The absolute path to the migration directory.
     */
    public static function directory(): string
    {
        return \dirname(__DIR__, 2).'/database/migrations';
    }
}
