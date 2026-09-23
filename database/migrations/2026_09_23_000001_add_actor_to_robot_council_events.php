<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give `robot_council_events` a column for who ACTED, so `user_id` can go back to one meaning.
 *
 * **`user_id` came to mean two things, and they are different people by construction.** For a
 * session's event it is the developer the event is ABOUT; for an administrative event -- a granted
 * ability, a revoked installation -- `Support\FleetEvents::record()` had no session to read and put
 * the acting admin there instead. An admin administers *other* developers' installations, so the
 * two never coincide.
 *
 * Nothing read the difference, because #29's visibility rule consults `user_id` only for a
 * restricted type and `Models\FleetEventType::isRestricted()` answers true for narration alone. The
 * cost was latent rather than absent: marking any `installation.*` type restricted would have served
 * the event to the **admin's** sessions and hidden it from the **owner's**, which is backwards, and
 * the enum presents that as a one-line change (#115).
 *
 * **A migration rather than an edit of the create file.** `CLAUDE.md` records that the era of
 * editing create migrations ended when `robot-council/robot-council` installed from `dev-main` and
 * migrated, and #94 is the instance that proved it -- a deployed database left carrying indexes a
 * fresh install no longer creates.
 *
 * **The collation is not optional.** #54 gave every column holding a host user key a binary
 * collation on MySQL, because the default `utf8mb4_0900_ai_ci` compares two keys differing only in
 * case as equal, which is an access-control failure rather than a storage one. There are seven of
 * them today, enumerated in `tests/HostKeyComparisonTest.php`'s `hostKeyColumns()`, and this is the
 * eighth -- so it is added to that list in the same change. That list is the registry whose whole
 * purpose is to catch a new key column, and its docblock says so: "a column added later that holds
 * a key and is not on this list is the defect coming back." One added without the collation would
 * compare case-insensitively while its siblings do not, and the asymmetry is invisible on Postgres
 * and SQLite, which compare bytes already.
 */
return new class extends Migration
{
    /**
     * The width of a host user key, matching the seven columns that already hold one.
     */
    private const KEY_LENGTH = 64;

    /**
     * Add the column, and give it the same comparison its siblings have.
     *
     * Nullable, because most events have no actor at all: an agent's narration, a task transition,
     * a presence sweep. Null means "nobody signed in did this", which is what a console command and
     * a background sweep both are.
     *
     * No index. Nothing filters by it -- #29's rule reads `user_id`, and the feed renders this
     * column rather than searching on it -- and #94 measured two composite indexes on this table as
     * buying nothing on a million-event feed while costing an index write per event.
     */
    public function up(): void
    {
        // Guarded, because three populations run this file: a host that installed before it, one
        // that installs after, and one rolling back and forward again.
        if (! Schema::hasColumn('robot_council_events', 'actor_user_id')) {
            Schema::table('robot_council_events', function (Blueprint $table): void {
                $table->string('actor_user_id', self::KEY_LENGTH)->nullable()->after('user_id');
            });
        }

        // **Outside the guard, deliberately.** MySQL does not wrap a migration in a transaction --
        // only `PostgresGrammar` sets `$transactions` -- and `alter table` commits implicitly. So a
        // failure between adding the column and collating it leaves the column durably present and
        // the `migrations` row unwritten; a re-run that returned early on the column's presence
        // would record the migration as done and leave the collation wrong forever. Re-applying a
        // collation the column already has costs one statement.
        $this->collate('utf8mb4_bin');
    }

    /**
     * Drop it again.
     *
     * A rollback loses which admin did what, and cannot do otherwise: the information was never in
     * another column to fall back to.
     *
     * **`up()` does not backfill either, and the reason is a judgment rather than an
     * impossibility.** An administrative row written before this is identifiable -- a null
     * `agent_session_id` with an `installation.*` type, and `meta.installation_id` names the owner
     * -- so a backfill could move the admin's key across and put the owner in `user_id`. It is not
     * done because a backfill rewrites history on the strength of a join against rows an
     * installation may since have lost, and the feed is an append-only record. The cost is named
     * where it lands: `actor.github_login` means the admin on rows written before this migration
     * and the owner on rows written after.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('robot_council_events', 'actor_user_id')) {
            return;
        }

        Schema::table('robot_council_events', function (Blueprint $table): void {
            $table->dropColumn('actor_user_id');
        });
    }

    /**
     * Put the new column on a binary collation, on the one engine whose default is not.
     *
     * @param  string  $collation  The collation to set.
     */
    private function collate(string $collation): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasColumn('robot_council_events', 'actor_user_id')) {
            return;
        }

        // **Through `change()`, exactly as
        // `2026_09_22_000002_compare_host_user_keys_byte_exactly.php` does it.** A raw
        // `alter table` was the first draft and was wrong twice: it hardcoded an unprefixed table
        // name, so a host with a table prefix would have got `Table ... doesn't exist`, and the
        // reason given for avoiding `change()` was that it needs every attribute restated -- which
        // is true, costs two lines here, and is what that sibling already does for a column of this
        // exact shape.
        Schema::table('robot_council_events', function (Blueprint $table) use ($collation): void {
            $table->string('actor_user_id', self::KEY_LENGTH)
                ->collation($collation)
                ->nullable()
                ->change();
        });
    }
};
