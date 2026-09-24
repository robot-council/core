<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the fleet events recorded by the two ability controls `robot-council/core#231` retired.
 *
 * **This is the load-bearing half of that retirement, not bookkeeping.** `robot_council_events.type`
 * is a `string(64)` holding the enum's backing value, and `Models\FleetEvent` casts it with
 * `'type' => FleetEventType::class`. Laravel's enum cast resolves through `from()`, which raises
 * `ValueError` on a value the enum no longer has. So without this, every row those controls ever
 * wrote becomes unreadable the moment the feed pages over it: on the dashboard, and in every
 * agent's feed read.
 *
 * **Those rows exist.** Measured against the deployment on 2026-09-24: two
 * `installation.ability_granted` and one `installation.ability_revoked`, the most recent at
 * 2026-09-23 17:20:44, all inside the window `robot-council:prune-events` keeps.
 *
 * **They are deleted rather than rewritten, and the reason is not brevity.** The obvious
 * alternative -- point them at some retained case, as
 * `2026_09_23_000002_rename_session_enrolled_events.php` does -- was rejected on two counts:
 *
 * - **Nothing is being lost.** `retention.events_days` defaults to 30 and
 *   `Console\PruneEventsCommand` runs daily, deleting by `created_at` alone whatever a row's type
 *   is. The feed is a rolling window, not a record, so preserving these is not a property this
 *   package offers anybody. This does what the prune would do, sooner.
 * - **A rewrite would launder a false sentence rather than remove it.** The body is already stored
 *   as `was granted tasks:create` or `lost tasks:create`, and the point of #231 is that four of the
 *   five abilities changed no session's authorization at all. Changing the type leaves that text in
 *   the feed under a vaguer heading, which is worse than removing the row.
 *
 * **`down()` is a no-op, and unlike a rename that is safe in both directions.** A rename needs its
 * inverse, because rolling the code back leaves migrated rows unreadable from the other side. A
 * delete does not: the previous release's enum still holds both cases, so it can read whatever rows
 * remain. It simply sees fewer of them -- the same thing it would see after the next prune. Nothing
 * can restore a deleted row, so pretending otherwise with a stub would be the lie.
 *
 * **Paging is unaffected.** `Support\FleetFeed` pages `id > cursor`, so a missing id is never
 * returned and a session's stored `feed_cursor` is a number rather than a reference.
 *
 * **Guarded on what the schema reports, because three populations run this file** -- installed
 * before the retirement, installed after it, and rolled back then migrated again. A fresh install
 * has no rows to delete and must not fail; a host that used the controls has some; a re-run has
 * none left. All three end in the same state, and none of them errors.
 *
 * The values are written as literals rather than read from `Models\FleetEventType`, deliberately,
 * and here they have to be: the enum no longer has these cases, so there is nothing to read.
 */
return new class extends Migration
{
    /**
     * The backing values of the two retired cases.
     *
     * @var list<string>
     */
    private const array RETIRED = ['installation.ability_granted', 'installation.ability_revoked'];

    /**
     * Delete the rows no version from here on can read.
     *
     * Unbatched, unlike `Support\FleetEvents::prune()`. That runs on a schedule against a table
     * that grows without bound; this runs once, against the rows two administrative controls wrote
     * by hand, and the deployment's count is three.
     */
    public function up(): void
    {
        if (! Schema::hasTable('robot_council_events')) {
            return;
        }

        DB::table('robot_council_events')->whereIn('type', self::RETIRED)->delete();
    }

    /**
     * Nothing. See the note above: a delete has no inverse, and needs none.
     */
    public function down(): void
    {
        //
    }
};
