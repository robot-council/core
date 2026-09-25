<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table of what the fleet is waiting on each developer for (#335).
 *
 * The lane board's *Waiting on a developer* section (#317). A coordinator records an item naming a
 * developer -- or nobody in particular, which the board shows as `General` -- the ticket it is about,
 * the question and why it matters. It settles when the ticket closes or loses its `hitl` label, as
 * GitHub reports them (#318), or when the coordinator settles it. A settled item is kept, with why.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        // Guarded, because three populations run this file: a host that installed before it, one
        // that installs after, and one rolling back and forward again.
        if (Schema::hasTable('robot_council_owed_items')) {
            return;
        }

        Schema::create('robot_council_owed_items', function (Blueprint $table): void {
            $table->id();

            // A GitHub login, 39 characters at most, as the fleet spells it; null for `General`
            $table->string('developer', 39)->nullable();

            // `IssueReference::MAX`, written out so this file means the same thing after that class
            // is edited
            $ticket = $table->string('ticket', 151);

            $table->string('question', 500);
            $table->string('why', 500);

            // The coordinator session that recorded it; history, so no foreign key (#50)
            $table->unsignedBigInteger('recorded_by');
            $table->dateTime('recorded_at');

            $table->dateTime('settled_at')->nullable();
            $table->string('settled_because', 32)->nullable();

            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $ticket->collation('utf8mb4_bin');
            }

            $table->index(['settled_at', 'developer'], 'robot_council_owed_items_open');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_owed_items');
    }
};
