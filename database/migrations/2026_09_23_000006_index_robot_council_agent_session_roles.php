<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the question `Support\FleetAbilities` asks on every agent request.
 *
 * `robot-council/core#223` moved `fleet_can_direct` onto live sessions, so `GET
 * {prefix}/api/agent/session` -- the route a bridge calls after every start and every renewal, up to
 * `rate_limits.agent_per_session` a minute -- now reads `robot_council_agent_sessions` filtered by
 * `role` and `status` and ordered by `id`. Nothing indexed that. `role` was added without one, and
 * the composite `(status, last_seen_at)` cannot serve it because **`active` is the common value**.
 *
 * **The normal state is the worst case**, which is what makes this worth a migration rather than a
 * note. `Support\Doctor`'s own text says a fleet whose agents only receive is a legitimate
 * configuration -- and no coordinator running is exactly the case where the filter matches nothing
 * and the scan has to exhaust the table before it can say so.
 *
 * Measured on PostgreSQL 17.0 (Laravel Herd, port 5432) on 2026-09-23, 20,000 `active` sessions and
 * no coordinator, `limit 50` as `lazyById()` issues it:
 *
 * | | plan | buffers | execution |
 * | --- | --- | --- | --- |
 * | before | `Limit -> Sort -> Nested Loop -> Seq Scan`, `Rows Removed by Filter: 20000` | 267 | 1.179 ms |
 * | after | `Limit -> Nested Loop -> Index Scan using this index` | 3 | 0.090 ms |
 *
 * **The `Sort` disappearing matters more than the buffer count.** With `id` trailing the index, the
 * rows arrive already ordered, so `Limit` can stop early; before, the limit sat above a sort of the
 * whole filtered set, and `FleetAbilities::CHUNK` bounded hydration without bounding the read.
 *
 * Column order is `(role, status, id)`: two equalities, most selective first, then the ordering the
 * walk pages on.
 *
 * **A new migration rather than an edit to the create migration**, because the era of editing those
 * is over: `robot-council/robot-council` has already run them, so an edit would leave a deployed
 * database and a fresh install with different schemas and nothing to say so (#94, #100).
 *
 * Guarded on what the schema reports rather than on an assumption, for the same reason: the three
 * populations -- installed before this, installed after it, and rolled back -- all run this file.
 */
return new class extends Migration
{
    /**
     * The table this indexes.
     */
    private const string TABLE = 'robot_council_agent_sessions';

    /**
     * The index's name, spelled out rather than left to the grammar, because `down()` has to name
     * the same thing `up()` created and a generated name is a guess in two places.
     */
    private const string INDEX = 'robot_council_agent_sessions_role_status_id_index';

    /**
     * Add the composite index.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'role')) {
            return;
        }

        if ($this->indexExists()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(['role', 'status', 'id'], self::INDEX);
        });
    }

    /**
     * Drop it, leaving the `(status, last_seen_at)` index the create migration added.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! $this->indexExists()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    /**
     * Whether the index is already on the table.
     *
     * Asked of the schema rather than inferred, so a database that has it -- from a rollback and
     * re-run, or from a future create migration that includes it -- is left alone instead of
     * erroring on a duplicate name.
     */
    private function indexExists(): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['name'] ?? null) === self::INDEX) {
                return true;
            }
        }

        return false;
    }
};
