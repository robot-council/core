<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a session somewhere to record the role it has asked to be.
 *
 * **Two columns on the session rather than a table of requests, because a request has exactly the
 * lifetime of the session that made it.** `robot-council/core#222` says it "expires with it", and a
 * separate table would need its own pruning, its own foreign key, and a place in the lock order --
 * for a row that can never outlive the one it points at. A session holds at most one pending
 * request; asking again replaces it, which is what a client retrying after a restart does anyway.
 *
 * **`requested_at` is not decoration.** It is what lets the panel show an administrator which
 * request has been waiting, and the two columns move together: both are written or both are
 * cleared, so a row can never say a role was asked for at no time or at a time for no role.
 *
 * `dateTime` rather than `timestamp`, and nullable, for the reason the create migration records:
 * MySQL gives the first NOT NULL `TIMESTAMP` column an implicit `ON UPDATE CURRENT_TIMESTAMP`
 * while `explicit_defaults_for_timestamp` is off. `tests/MigrationTimestampGuardTest.php` refuses a
 * non-nullable `timestamp()` in this directory, and this is nullable regardless.
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
     * The role a session has asked to be.
     */
    private const string REQUESTED_ROLE = 'requested_role';

    /**
     * When it asked.
     */
    private const string REQUESTED_AT = 'requested_at';

    /**
     * Add both columns.
     *
     * Nothing is backfilled: a request is something a running session makes, and no session that
     * existed before this ran has made one.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $missing = array_values(array_filter(
            [self::REQUESTED_ROLE, self::REQUESTED_AT],
            static fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column)
        ));

        if ($missing === []) {
            return;
        }

        // One `Schema::table()` for both, so there is no state where one exists and the other does
        // not -- which for these two would be a row that can say a role was asked for at no time.
        Schema::table(self::TABLE, function (Blueprint $table) use ($missing): void {
            foreach ($missing as $column) {
                if ($column === self::REQUESTED_ROLE) {
                    // 16 characters, matching `role`: the column holds the same enum's backing
                    // value and the longest is `coordinator`. Written out rather than read from
                    // `Access\Role`, so that a rename cannot leave a fresh install and an upgraded
                    // host with different columns and nothing to say so.
                    $table->string($column, 16)->nullable();

                    continue;
                }

                $table->dateTime($column)->nullable();
            }
        });
    }

    /**
     * Drop both columns.
     *
     * A pending request is lost, which costs a session one further call: the client asks again on
     * its next start. Nothing that was approved is lost, because an approval writes `role` and
     * clears these two rather than living here.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $present = array_values(array_filter(
            [self::REQUESTED_ROLE, self::REQUESTED_AT],
            static fn (string $column): bool => Schema::hasColumn(self::TABLE, $column)
        ));

        if ($present === []) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($present): void {
            $table->dropColumn($present);
        });
    }
};
