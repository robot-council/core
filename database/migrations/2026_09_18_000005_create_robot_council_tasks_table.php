<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Models\Task;
use RobotCouncil\Support\ProjectId;

/**
 * Creates the table holding the unit of work agents hand each other.
 *
 * Every transition is one conditional update against this table, so the columns a transition tests
 * -- `status`, `claimed_by`, and the two that decide claim eligibility -- all live on the row rather
 * than behind a join. That is what lets the write be the whole decision.
 */
return new class extends Migration
{
    /**
     * Create the tasks table.
     */
    public function up(): void
    {
        Schema::create('robot_council_tasks', function (Blueprint $table): void {
            $table->id();

            // Stored and nothing more, per #25. Self-referential, so a parent that is deleted
            // leaves its children rather than taking them.
            $table->foreignId('parent_task_id')
                ->nullable()
                ->index()
                ->constrained('robot_council_tasks')
                ->nullOnDelete();

            // **The length here is not what enforces the length.** Postgres and MySQL refuse to
            // overrun a `varchar`, and SQLite stores the value whole -- so one over-length write is
            // a 500 on two engines and a silently too-long title on the third, in a field other
            // developers' agents read. `Support\Tasks::create()` refuses it before any engine sees
            // it, which is what makes the bound mean the same thing everywhere. Both endpoints
            // validate it too; the store is what a host reaches directly. (#57)
            $table->string('title', Task::MAX_TITLE);
            // A `text` column, which holds far more than `Task::MAX_DESCRIPTION`. That bound is
            // policy rather than capacity -- like a title, a description reaches other developers'
            // agents -- and `Support\Tasks::create()` is what holds it.
            $table->text('description')->nullable();

            $table->string('status', 16);

            // Higher is more urgent. A small range rather than an open integer, because it is
            // sorted on and a client that sends the largest integer it can would otherwise pin
            // itself to the top of every other developer's queue.
            //
            // **What holds that range is `Models\Task`'s mutator, not this column.** Measured for
            // #57: `unsignedTinyInteger` is `tinyint unsigned` on MySQL (0-255, an error in strict
            // mode and a clamp otherwise), `smallint` on Postgres (-32768 to 32767, because it has
            // no unsigned integers), and an unbounded `integer` on SQLite. One out-of-range call
            // therefore had three outcomes. The mutator clamps to 0..MAX_PRIORITY in PHP, which is
            // the only place all three agree.
            $table->unsignedTinyInteger('priority')->default(0);

            // The same urgency, ascending, so one index serves both the ordering and the cursor.
            // `order by priority desc, id asc` matches no single-direction b-tree in either scan
            // direction, and `Blueprint::index()` cannot declare a direction -- so the index this
            // table used to carry could never serve the ordering it was added for.
            //
            // Written only by `Models\Task`'s `priority` mutator, which sets both columns in one
            // assignment, so they cannot be given disagreeing values through the model. The default
            // is the rank of priority 0 for the same reason: a row created without naming a
            // priority takes the column default for both, and the two defaults agree.
            //
            // **`Task::MAX_PRIORITY` is baked into every row and frozen into this default.** Raising
            // it needs a backfill: rows already written hold `old_max - priority`, this default
            // stays at the old value in an already-migrated database because nothing issues an
            // `ALTER`, and `TaskList` would convert an incoming cursor with the new constant. All
            // three would disagree silently, and the ordering would be wrong rather than broken.
            $table->unsignedTinyInteger('queue_rank')->default(Task::MAX_PRIORITY);

            $table->json('payload')->nullable();
            $table->json('result')->nullable();

            // The session holding it now. Indexed explicitly: `constrained()` emits a foreign key
            // and no index on Postgres and SQLite, and the release step queries this column.
            $table->foreignId('claimed_by')
                ->nullable()
                ->index()
                ->constrained('robot_council_agent_sessions')
                ->nullOnDelete();

            $table->dateTime('claimed_at')->nullable();

            // Provenance, recorded when the task is created and never rewritten
            $table->foreignId('created_by')
                ->nullable()
                ->index()
                ->constrained('robot_council_agent_sessions')
                ->nullOnDelete();

            // The creating session's developer, denormalized so the claim's conditional update can
            // carry #16's eligibility rule without a join -- and so it survives the creating
            // session's row being deleted, which would otherwise take the rule's other half with it
            $table->string('user_id', 64)->index();

            // Whether the creating session held `coordinator:direct` at the time. Read at creation
            // and stored, so revoking it later cannot retroactively narrow who may claim the task.
            $table->boolean('created_with_coordinator')->default(false);

            // Which repository or workspace the task belongs to, when the creator says
            // Pinned, like every other string column here: an unpinned `string()` takes its length
            // from `Schema::$defaultStringLength`, which the host owns. `Support\ProjectId` holds
            // the bound and the charset, because this value reaches the whole fleet through the
            // feed's `meta` rather than staying behind `TaskList`'s visibility rule.
            $table->string('project_id', ProjectId::MAX)->nullable();

            $table->timestamps();

            // What listing reads, in its two shapes. Every column ascends, so both are walked
            // rather than sorted -- which `priority desc, id asc` could not be, in either scan
            // direction, whatever index it was given.
            //
            // **Two indexes, because `status` is optional on every surface.** `ListTasksController`
            // and `ListTasksTool` both take it `sometimes`, and `Livewire\TaskBoard` renders with
            // `status` null, so the unfiltered listing is the DEFAULT rather than an edge. Measured
            // on a 20,000-row SQLite fixture after `ANALYZE`: with only the composite below, the
            // unfiltered listing plans `SCAN | USE TEMP B-TREE FOR ORDER BY` and the unfiltered
            // cursor `SEARCH (ANY(status) AND queue_rank>?) | USE TEMP B-TREE FOR ORDER BY`, because
            // a leading column nothing constrains cannot be seeked. With `(queue_rank, id)` beside
            // it they become `SCAN USING INDEX` and `SEARCH (queue_rank>?)`, no sort -- and the
            // filtered plan is unchanged, so the second index does not displace the first.
            //
            // **Postgres agrees, and that is now measured rather than assumed** (#80). Figures
            // below are PostgreSQL 17.6, which is CI's major, on a 20,000-row fixture aged the way
            // this table ages -- nothing prunes it, so `done` is 91% and `pending` 1% -- after
            // `ANALYZE`. 18.0 produced the same plans.
            //
            // The board's default read is `Index Scan using robot_council_tasks_queue_rank_id_index`,
            // 5 shared buffers, no sort. With only the composite it is a `Seq Scan` over all 20,000
            // rows plus a `top-N heapsort`, 228 buffers -- the same defect SQLite showed, 45 times
            // the pages, on a query the dashboard repeats every `poll_seconds`.
            //
            // **The composite still earns its place, and the margin grows.** Dropping it does not
            // make the filtered read fall back to a scan, which is why "an index was used" is the
            // wrong question to ask: it falls back to the queue index and filters, at 308 buffers
            // and 2,574 rows discarded to return 26, against 28 buffers and none. Every completed
            // task widens it.
            //
            // The filtered plans are identical with and without the queue index, so it displaces
            // nothing an agent reads.
            //
            // MySQL is not measured, and no longer runs in CI; the deployment is Postgres on
            // Laravel Cloud. `tests/TaskQueuePlanTest.php` holds all four plans and fails if any
            // of them stops being true.
            $table->index(['status', 'queue_rank', 'id']);
            $table->index(['queue_rank', 'id']);
        });
    }

    /**
     * Drop the tasks table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_tasks');
    }
};
