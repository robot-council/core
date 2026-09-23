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
        $splits = $this->splitsByProjectId();

        $missing = array_values(array_filter(
            [self::REPOSITORY, self::WORK_LOCATION],
            static fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column)
        ));

        if ($missing !== []) {
            // **One `Schema::table()` for both columns, not one each.** On MySQL each is a separate
            // DDL statement with an implicit commit, so two calls make the unprotected window this
            // file is built around a three-state one instead of two -- and a `down()` that failed
            // between them would leave one column standing with the `migrations` row already gone.
            Schema::table(self::TABLE, function (Blueprint $table) use ($missing): void {
                foreach ($missing as $column) {
                    // **Nullable, independently.** A session may name neither, or a repository with
                    // no label for the checkout. The widths are written out rather than read from
                    // `Support\WorkIdentity`, so that raising a constant later cannot leave a fresh
                    // install and an upgraded host with different columns and nothing to say so --
                    // which is what #94 cost. 140 is GitHub's own bound: an owner is at most 39
                    // characters and a repository name at most 100.
                    $table->string($column, $column === self::REPOSITORY ? 140 : 32)->nullable();
                }
            });
        }

        // **One statement per distinct `project_id`, and no list of row ids.** The split is a pure
        // function of that value, so every row sharing one resolves to the same pair. An earlier
        // shape collected the ids per pair and passed them to `whereIn`, which is one bind per
        // SESSION: past 32,766 on SQLite and 65,535 on Postgres and MySQL the statement is refused,
        // and on Postgres the whole migration is in one transaction, so the ALTER rolls back with
        // it and every `POST api/sessions` 500s against a table the new code expects columns on.
        //
        // **Guarded on both columns being null, which is what makes a re-run safe.** A host that
        // crashed between the ALTER and these updates is already serving the new code, so a session
        // can have started in between and written a repository the client supplied directly.
        // Rewriting that from a `project_id` the client has stopped maintaining is exactly what
        // `Support\AgentSessions::start()` refuses to do, and a migration must not do it either.
        foreach ($splits as $projectId => $pair) {
            DB::table(self::TABLE)
                ->where('project_id', $projectId)
                ->whereNull(self::REPOSITORY)
                ->whereNull(self::WORK_LOCATION)
                ->update([self::REPOSITORY => $pair[0], self::WORK_LOCATION => $pair[1]]);
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
     *
     * Both columns go in one statement, so there is no state where one is dropped and the other is
     * not while the `migrations` row has already been deleted.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $present = array_values(array_filter(
            [self::REPOSITORY, self::WORK_LOCATION],
            static fn (string $column): bool => Schema::hasColumn(self::TABLE, $column)
        ));

        if ($present === []) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($present): void {
            $table->dropColumn($present);
        });
    }

    /**
     * What each distinct `project_id` on the table splits into.
     *
     * **Distinct values rather than rows**, so the work and the number of statements are bounded by
     * how many different labels the fleet has used rather than by how many sessions it has ever
     * started.
     *
     * Read and decided in PHP rather than matched in SQL, because the rule is a shape rather than a
     * pattern any one engine expresses the same way. Every value is narrowed on the way: the column
     * is `varchar` and the row decides what is in it, so anything that is not this shape contributes
     * nothing rather than raising from inside a migration a host is running.
     *
     * @return array<string, array{string, string|null}> The repository and location, by project id.
     */
    private function splitsByProjectId(): array
    {
        $splits = [];

        foreach (DB::table(self::TABLE)->distinct()->whereNotNull('project_id')->pluck('project_id') as $projectId) {
            if (! is_string($projectId)) {
                continue;
            }

            $parts = explode('/', $projectId);

            // Two segments is a repository with no label; three is a repository and its label.
            if (count($parts) !== 2 && count($parts) !== 3) {
                continue;
            }

            $repository = $parts[0].'/'.$parts[1];
            $location = count($parts) === 3 ? $parts[2] : null;

            // The same bounds the columns carry, spelled out for the reason the class docblock
            // gives. A value outside them is left for `project_id` to keep holding.
            if (mb_strlen($repository) > 140 || preg_match('/^(?!\.+\/)[A-Za-z0-9_.][A-Za-z0-9._-]*\/(?!\.+$)[A-Za-z0-9_.][A-Za-z0-9._-]*$/D', $repository) !== 1) {
                continue;
            }

            if ($location !== null && (mb_strlen($location) > 32 || preg_match('/^(?!\.+$)[a-z0-9_.][a-z0-9._-]*$/D', $location) !== 1)) {
                $location = null;
            }

            $splits[$projectId] = [$repository, $location];
        }

        return $splits;
    }
};
