<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a session declare no capacity at all, so it takes its seat's Tasks at once setting (#564).
 *
 * `2026_09_25_000004` made `declared_capacity` NOT NULL with a default of one, so a session that said
 * nothing at join was recorded as having declared one, and `Support\Capacity::effective()` held it to
 * one however far its developer raised the seat. Decided on #564 (option B): null means "whatever the
 * seat allows", and a number still lowers it.
 *
 * **Every stored one becomes null, because a stored one cannot say which it was.** A client sends a
 * capacity only when one was declared (`robot-council/cli` `Session::start()`, since #302), and the
 * column recorded an omitted one as one. Read from the deployment on 2026-10-07 before this was
 * written: all 37 live sessions held one, and of 50 seats, 49 capped at one and one at two. So the
 * only lanes whose capacity in effect moves are the ones in a seat its developer raised -- which is
 * the case #564 was filed about -- and a session that really did declare one there now takes the
 * seat's number, as its developer asked. Under a seat at one, null and one are the same lane.
 *
 * **Guarded on what the schema reports**, as `2026_09_18_000007` is, because three populations run
 * this file: a host that ran `000004`, one that never did, and one that rolled this back.
 */
return new class extends Migration
{
    /**
     * Make the column nullable with no default, then forget every one it holds.
     *
     * **The backfill runs whenever this does, not only after the change.** MySQL commits a column
     * change at once and Laravel runs its migrations outside a transaction, so a deploy killed
     * between the two statements leaves the column nullable and every one still stored. Gated on the
     * change, a re-run would skip the backfill forever and leave those sessions held to one, which is
     * #564 itself. Ungated it erases nothing it should not: a recorded migration does not run again,
     * so the only ones it can meet are those the old default wrote.
     */
    public function up(): void
    {
        if (! $this->hasColumn()) {
            return;
        }

        if (! $this->isNullable()) {
            Schema::table('robot_council_agent_sessions', static function (Blueprint $table): void {
                $table->unsignedSmallInteger('declared_capacity')->nullable()->default(null)->change();
            });
        }

        DB::table('robot_council_agent_sessions')->where('declared_capacity', 1)->update(['declared_capacity' => null]);
    }

    /**
     * Put the one back where nothing was declared, then the NOT NULL and the default.
     *
     * Every null becomes one, which is what each of those sessions was recorded as before `up()`.
     */
    public function down(): void
    {
        if (! $this->hasColumn() || ! $this->isNullable()) {
            return;
        }

        DB::table('robot_council_agent_sessions')->whereNull('declared_capacity')->update(['declared_capacity' => 1]);

        Schema::table('robot_council_agent_sessions', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('declared_capacity')->nullable(false)->default(1)->change();
        });
    }

    /**
     * Whether the column exists at all.
     */
    private function hasColumn(): bool
    {
        return Schema::hasTable('robot_council_agent_sessions')
            && Schema::hasColumn('robot_council_agent_sessions', 'declared_capacity');
    }

    /**
     * Whether the column already admits null, read from the engine rather than assumed.
     */
    private function isNullable(): bool
    {
        foreach (Schema::getColumns('robot_council_agent_sessions') as $column) {
            if ($column['name'] === 'declared_capacity') {
                return $column['nullable'];
            }
        }

        return false;
    }
};
