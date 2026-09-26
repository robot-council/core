<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds what lets a task GitHub finished keep its holder's result (#433).
 *
 * - `github_finished_at`: when a webhook delivery finished the task, and null for every task a
 *   session finished itself. It is what marks the task as one its displaced holder may still add a
 *   result to, and what the window for doing so is measured from.
 * - `result_added_at`: when that holder added its result, so it can do so once.
 *
 * Both are nullable `dateTime`s rather than `timestamp`s, for the reason
 * `robot_council_agent_sessions.last_seen_at` is: MySQL's implicit `ON UPDATE CURRENT_TIMESTAMP` on
 * a NOT NULL `TIMESTAMP` would restamp them on every write to the row.
 */
return new class extends Migration
{
    /**
     * The columns this adds.
     *
     * @var list<string>
     */
    private const array COLUMNS = ['github_finished_at', 'result_added_at'];

    /**
     * Add each column, guarded on its own existence, as `2026_09_25_000004` is: MySQL adds them in
     * separate statements outside any transaction, so a deploy killed between them leaves one.
     */
    public function up(): void
    {
        if (! Schema::hasTable('robot_council_tasks')) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('robot_council_tasks', $column)) {
                Schema::table('robot_council_tasks', static function (Blueprint $table) use ($column): void {
                    $table->dateTime($column)->nullable();
                });
            }
        }
    }

    /**
     * Drop each column, only where it exists, for the same reason.
     */
    public function down(): void
    {
        if (! Schema::hasTable('robot_council_tasks')) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('robot_council_tasks', $column)) {
                Schema::table('robot_council_tasks', static function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
