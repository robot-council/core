<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds what a session with subagents needs (#409, from #386's decision).
 *
 * - `robot_council_agent_sessions.declared_capacity`: how many tickets the session said it will hold
 *   at once when it joined. Not the capacity in effect, which `Support\Capacity` computes on every
 *   read against the seat's cap, so the column is named for what it holds.
 * - `robot_council_seats.max_capacity`: the developer's cap for every session in that seat.
 * - `robot_council_tasks.sub_label`: the optional display-only label a lane gives one held task,
 *   such as the worktree of the subagent working it.
 *
 * **Both numbers are NOT NULL with a default of one**, which is how every existing session and seat
 * keeps behaving exactly as before: one held task out of one is a full lane. `smallint` rather than
 * `tinyint`, because the bound lives in `Support\Capacity` and a column's meaning differs by engine
 * (#57); nothing here depends on the column refusing a value.
 */
return new class extends Migration
{
    /**
     * The columns, per table: name and how to add it.
     *
     * `sub_label` is 64 characters, `Support\SubLabel::MAX`, written out rather than read from the
     * class so this file means the same thing after that class is edited.
     *
     * @return array<string, array<string, callable(Blueprint): void>> By table, then column.
     */
    private function columns(): array
    {
        return [
            'robot_council_agent_sessions' => [
                'declared_capacity' => static function (Blueprint $table): void {
                    $table->unsignedSmallInteger('declared_capacity')->default(1);
                },
            ],
            'robot_council_seats' => [
                'max_capacity' => static function (Blueprint $table): void {
                    $table->unsignedSmallInteger('max_capacity')->default(1);
                },
            ],
            'robot_council_tasks' => [
                'sub_label' => static function (Blueprint $table): void {
                    $table->string('sub_label', 64)->nullable();
                },
            ],
        ];
    }

    /**
     * Add each column.
     *
     * **Each guarded on its own existence**, as `2026_09_25_000001` is: MySQL adds them in separate
     * statements outside any transaction, so a deploy killed between them leaves some, and a guard on
     * the first alone would skip the rest forever.
     */
    public function up(): void
    {
        foreach ($this->columns() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $add) {
                if (! Schema::hasColumn($table, $column)) {
                    Schema::table($table, static function (Blueprint $blueprint) use ($add): void {
                        $add($blueprint);
                    });
                }
            }
        }
    }

    /**
     * Drop each column, only where it exists, for the same reason.
     *
     * A rollback returns every lane to one task and every seat to a cap of one, which is the fleet
     * before this existed.
     */
    public function down(): void
    {
        foreach ($this->columns() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($columns) as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, static function (Blueprint $blueprint) use ($column): void {
                        $blueprint->dropColumn($column);
                    });
                }
            }
        }
    }
};
