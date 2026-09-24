<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a task what a lane board needs to say about it: which GitHub issue it is for, how it came to
 * be in a lane's hands, whether it is a hand-back, and the branch the lane reports.
 *
 * `robot-council/core#316`, the model half of the lane board #314 decided. A lane is a session and
 * holding work is a claimed task; these are the facts about that work the task could not yet carry.
 *
 * **Four nullable or defaulted columns on the task rather than a table of placements**, because a
 * placement has the lifetime of the claim it describes: a release clears it, and a finished task
 * keeps it as history. A separate table would need pruning and a place in the lock order for a row
 * that can never outlive the one it points at.
 *
 * Widths are written out rather than read from `Support\IssueReference`, `Support\BranchName` and
 * `Models\Placement`, so that a later change to one of those cannot leave a fresh install and an
 * upgraded host with different columns and nothing to say so -- the reason
 * `2026_09_23_000005_add_role_requests_to_robot_council_agent_sessions` gives for the same choice.
 * `tests/TaskPlacementTest.php` asserts each width still matches its constant.
 *
 * Guarded on what the schema reports, because three populations run this file: installed before
 * these columns, installed after them, and rolled back then migrated again.
 */
return new class extends Migration
{
    /**
     * The table this adds to.
     */
    private const string TABLE = 'robot_council_tasks';

    /**
     * The four columns, in the order they are added.
     */
    private const array COLUMNS = ['issue', 'branch', 'placed_by', 'hand_back'];

    /**
     * Add whichever of the four are missing.
     *
     * Nothing is backfilled. A task that existed before this names no issue, and nothing recorded
     * how its current holder came by it, so null is the true answer for both.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $missing = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column)
        ));

        if ($missing === []) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($missing): void {
            foreach ($missing as $column) {
                match ($column) {
                    // `owner/name#N`: a 140-character repository, the `#`, and up to ten digits
                    'issue' => $table->string($column, 151)->nullable(),
                    'branch' => $table->string($column, 200)->nullable(),

                    // The longest value is `coordinator`
                    'placed_by' => $table->string($column, 16)->nullable(),

                    // Not null, defaulting false: a task is not a hand-back until a placement
                    // says it is, and a nullable boolean would add a third answer nobody asked for
                    'hand_back' => $table->boolean($column)->default(false),
                };
            }
        });
    }

    /**
     * Drop whichever of the four are present.
     *
     * What they held is lost, which costs the board its placement detail until tasks are placed
     * again. Nothing about a task's own state is lost: status and claimant were never here.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $present = array_values(array_filter(
            self::COLUMNS,
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
