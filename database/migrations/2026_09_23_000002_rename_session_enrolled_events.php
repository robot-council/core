<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rewrites the fleet event a starting session records, from the installation's verb to its own.
 *
 * **This is the load-bearing half of #217, not bookkeeping.** `robot_council_events.type` is a
 * `string(64)` holding the enum's backing value, and `Models\FleetEvent` casts it with
 * `'type' => FleetEventType::class`. Laravel's enum cast resolves through `from()`, which raises
 * `ValueError` on a value the enum no longer has -- measured, not assumed. So without this, every
 * row written before the rename becomes unreadable the moment the feed pages over it: on the
 * dashboard, and in every agent's feed read.
 *
 * Those rows exist on the deployed fleet. Sessions started on 2026-09-23 moved the feed cursor from
 * 313 to 368, and they sit inside the window `robot-council:prune-events` keeps.
 *
 * **Guarded on what the schema reports, because three populations run this file** -- installed
 * before the rename, installed after it, and rolled back then migrated again. A fresh install has
 * no rows to rewrite and must not fail; an upgrade has some; a re-run has none left. All three end
 * in the same state, and none of them errors.
 *
 * The values are written as literals rather than read from `FleetEventType`, deliberately. A
 * migration describes a change between two fixed points in time, and reading the enum would make
 * this file mean something different the next time a case is renamed -- which is exactly the class
 * of drift it exists to repair.
 */
return new class extends Migration
{
    /**
     * Point existing rows at the new value.
     */
    public function up(): void
    {
        if (! Schema::hasTable('robot_council_events')) {
            return;
        }

        DB::table('robot_council_events')
            ->where('type', 'session.enrolled')
            ->update(['type' => 'session.started']);
    }

    /**
     * Point them back, so a rollback leaves rows the previous release can read.
     *
     * Without this, rolling back the code would leave every migrated row unreadable in the other
     * direction -- the same `ValueError`, reached from the other side.
     */
    public function down(): void
    {
        if (! Schema::hasTable('robot_council_events')) {
            return;
        }

        DB::table('robot_council_events')
            ->where('type', 'session.started')
            ->update(['type' => 'session.enrolled']);
    }
};
