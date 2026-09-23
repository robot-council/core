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
 * **The collation is not optional.** #54 gave all six columns holding a host user key a binary
 * collation on MySQL, because the default `utf8mb4_0900_ai_ci` compares two keys differing only in
 * case as equal, which is an access-control failure rather than a storage one. A seventh such column
 * added without it would compare case-insensitively while its six siblings do not -- and the
 * asymmetry would be invisible on Postgres and SQLite, which compare bytes already.
 */
return new class extends Migration
{
    /**
     * The width of a host user key, matching the six columns that already hold one.
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
        if (Schema::hasColumn('robot_council_events', 'actor_user_id')) {
            return;
        }

        Schema::table('robot_council_events', function (Blueprint $table): void {
            $table->string('actor_user_id', self::KEY_LENGTH)->nullable()->after('user_id');
        });

        $this->collate('utf8mb4_bin');
    }

    /**
     * Drop it again.
     *
     * A rollback loses which admin did what, and cannot do otherwise: the information was never in
     * another column to fall back to. That is the honest direction, and it is why `up()` does not
     * try to reconstruct it either -- the rows written before this migration recorded the actor in
     * `user_id`, and telling those apart from a session's event after the fact is guesswork.
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
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Raw rather than `change()`, for the reason
        // `2026_09_22_000002_compare_host_user_keys_byte_exactly.php` records: `change()` redefines
        // a column from the Blueprint's idea of it and would need every attribute restated.
        DB::statement(sprintf(
            'alter table `robot_council_events` modify `actor_user_id` varchar(%d) collate %s null',
            self::KEY_LENGTH,
            $collation
        ));
    }
};
