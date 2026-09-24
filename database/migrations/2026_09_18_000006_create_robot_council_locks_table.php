<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table holding the fleet's named locks.
 *
 * A lock is an advisory lease over anything narrower than a task -- one session pushing to a branch
 * at a time. Acquiring is atomic, a holder that vanished cannot block the fleet past its lease, and
 * a fence value lets a guarded action notice that its own lease lapsed while it was working.
 *
 * **A released lock keeps its row, with no holder.** #26 describes release as deleting it, and
 * keeping it is what makes the fence's guarantee cheap: every acquisition must return a value
 * greater than any previously issued for that name, *including across a release*, and a deleted row
 * takes the only record of that name's last fence with it. The alternative is a second table that
 * is never deleted from, which is the same growth with an extra join. What the row holds when it is
 * free is nothing: a null holder, and the number.
 */
return new class extends Migration
{
    /**
     * Create the locks table.
     */
    public function up(): void
    {
        Schema::create('robot_council_locks', function (Blueprint $table): void {
            $table->id();

            // 191 rather than 255: this is the unique key, and 191 is the longest a `varchar` can
            // be under MySQL's utf8mb4 with the older 767-byte index limit.
            //
            // The collation is not decoration. This column IS the mutual exclusion, and MySQL's
            // default `utf8mb4_0900_ai_ci` compares case- and accent-insensitively -- so
            // `branch:Main` and `branch:main` would be two locks on Postgres and SQLite and one on
            // MySQL, and the supported databases would disagree about whether two agents are
            // guarding the same thing. Binary on MySQL makes all three agree; the other two
            // compare bytes already, and neither accepts MySQL's collation names.
            $name = $table->string('name', 191);

            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $name->collation('utf8mb4_bin');
            }

            $table->unique('name');

            // Null while the lock is free. Indexed explicitly, because `constrained()` emits no
            // index on Postgres or SQLite and the release step queries this column.
            $table->foreignId('holder_id')
                ->nullable()
                ->index()
                ->constrained('robot_council_agent_sessions')
                ->nullOnDelete();

            // Monotonic per name, and never reset. A guarded action carries the fence it was given
            // and can be refused by whatever it is guarding once a higher one exists.
            $table->unsignedBigInteger('fence')->default(0);

            // Who the lock was taken from, set by the takeover's own write. It is what lets a
            // displaced holder be told its lease is gone rather than that it was never entitled.
            $table->foreignId('previous_holder_id')
                ->nullable()
                ->constrained('robot_council_agent_sessions')
                ->nullOnDelete();

            // `dateTime` rather than `timestamp`, for the reason the sessions table records: MySQL
            // gives the first NOT NULL `TIMESTAMP` column an implicit `ON UPDATE CURRENT_TIMESTAMP`
            $table->dateTime('acquired_at')->nullable();
            $table->dateTime('expires_at')->nullable();

            $table->timestamps();

            // What the release step reads: the locks a given set of sessions still holds
            $table->index(['holder_id', 'expires_at']);
        });
    }

    /**
     * Drop the locks table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_locks');
    }
};
