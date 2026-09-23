<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits a session's one opaque `project_id` into the repository it belongs to and the work
 * location within it.
 *
 * **The split is measured rather than assumed.** `robot-council/core#220` assumed there was no
 * reliable way to divide the old value. There is: read from the deployed database on 2026-09-23,
 * every non-null `project_id` already carried the structure -- 54 rows of `owner/name` and one of
 * `owner/name/location`, out of 75. So this migration splits, and the counts are on that issue.
 *
 * **`project_id` is kept.** The epic (`robot-council/cli#125`) ships every slice backwards
 * compatible, so a client that has not been upgraded keeps sending one string and keeps working;
 * the final slice retires the column. A row whose value does not fit the shape therefore loses
 * nothing by leaving both new columns null -- the original is still on the row beside them.
 *
 * **The split rule is written out here rather than read from `Support\WorkIdentity`**, which holds
 * the same rule for the forward path. A migration describes a change between two fixed points in
 * time, and reading the class would make this file mean something different the next time that
 * class is edited -- which is the class of drift
 * `2026_09_23_000002_rename_session_enrolled_events.php` exists to repair. The two are kept
 * agreeing by a test that drives both over the same inputs, not by sharing code.
 *
 * Guarded on what the schema reports, because three populations run this file: installed before
 * these columns, installed after them, and rolled back then migrated again.
 */
return new class extends Migration
{
    /**
     * The table this adds to.
     */
    private const string TABLE = 'robot_council_agent_sessions';

    /**
     * The repository column.
     */
    private const string REPOSITORY = 'repository';

    /**
     * The work location column.
     */
    private const string WORK_LOCATION = 'work_location';

    /**
     * Add both columns, and split whatever the old one holds into them.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // **Read before the ALTER, and backfill whether or not the columns are new.** Only Postgres
        // and SQL Server wrap a migration in a transaction -- `Schema\Grammars\Grammar::$transactions`
        // is false and only `PostgresGrammar` overrides it -- so on SQLite and MySQL the ALTER and
        // the updates are independent statements. A crash between them leaves the columns added,
        // nothing backfilled and no `migrations` row, and a guard reading `hasColumn` would then
        // skip the backfill on the re-run and report success. Reading first also keeps the scan
        // outside the window where Postgres holds ACCESS EXCLUSIVE on this table, which every
        // session-token lookup in the fleet blocks behind.
        $split = $this->splitExistingRows();

        foreach ([self::REPOSITORY => 140, self::WORK_LOCATION => 32] as $column => $length) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($column, $length): void {
                // **Nullable, independently.** A session may name neither, or a repository with no
                // label for the checkout. The widths are written out rather than read from
                // `Support\WorkIdentity`, so that raising a constant later cannot leave a fresh
                // install and an upgraded host with different columns and nothing to say so --
                // which is what #94 cost. 140 is GitHub's own bound: an owner is at most 39
                // characters and a repository name at most 100.
                $table->string($column, $length)->nullable();
            });
        }

        // Grouped by the pair each row resolves to, so a fleet of any size costs one statement per
        // distinct repository and label rather than one per session. Idempotent: re-running writes
        // the same rows the same values from the same input.
        foreach ($split as $pair) {
            DB::table(self::TABLE)
                ->whereIn('id', $pair['ids'])
                ->update([self::REPOSITORY => $pair['repository'], self::WORK_LOCATION => $pair['location']]);
        }
    }

    /**
     * Drop both columns.
     *
     * **A rollback discards the split and a later `up()` performs it again from `project_id`**,
     * which is the same input, so the two directions round-trip for as long as that column exists.
     * What a rollback cannot restore is a repository or a location a client supplied DIRECTLY
     * rather than through `project_id`: there is nowhere else to read those from, and they come
     * back only when the session next starts. Sessions are short-lived by design, so that is
     * minutes rather than a lasting loss -- but it is a loss, and it is the reason this paragraph
     * is here rather than a claim that the rollback is clean.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ([self::REPOSITORY, self::WORK_LOCATION] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }

    /**
     * The rows to rewrite, grouped by the pair each one resolves to.
     *
     * Read and decided in PHP rather than matched in SQL, because the rule is a shape rather than a
     * pattern any one engine expresses the same way. Every value is narrowed on the way: the column
     * is `varchar` and the row decides what is in it, so anything that is not this shape contributes
     * nothing rather than raising from inside a migration a host is running.
     *
     * @return list<array{repository: string, location: string|null, ids: list<int>}> The groups.
     */
    private function splitExistingRows(): array
    {
        $groups = [];

        foreach (DB::table(self::TABLE)->select('id', 'project_id')->whereNotNull('project_id')->cursor() as $row) {
            if (! is_object($row) || ! property_exists($row, 'id') || ! property_exists($row, 'project_id')) {
                continue;
            }

            if (! is_string($row->project_id) || ! is_numeric($row->id)) {
                continue;
            }

            $parts = explode('/', $row->project_id);

            // Two segments is a repository with no label; three is a repository and its label.
            if (count($parts) !== 2 && count($parts) !== 3) {
                continue;
            }

            $repository = $parts[0].'/'.$parts[1];
            $location = count($parts) === 3 ? $parts[2] : null;

            // The same bounds the columns carry, spelled out for the reason the class docblock
            // gives. A value outside them is left for `project_id` to keep holding.
            if (mb_strlen($repository) > 140 || preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/D', $repository) !== 1) {
                continue;
            }

            if ($location !== null && (mb_strlen($location) > 32 || preg_match('/^[a-z0-9._-]+$/D', $location) !== 1)) {
                $location = null;
            }

            $key = $repository.'\0'.($location ?? '');

            $groups[$key] ??= ['repository' => $repository, 'location' => $location, 'ids' => []];
            $groups[$key]['ids'][] = (int) $row->id;
        }

        return array_values($groups);
    }
};
