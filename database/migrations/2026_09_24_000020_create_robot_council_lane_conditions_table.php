<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the table of lane conditions the coordinator has been told about (#319).
 *
 * **Raised once, cleared when it stops holding, raised again if it recurs.** A condition is checked
 * on a schedule, so without a record of what was already said the coordinator would be told on
 * every run. An open row -- no `cleared_at` -- means the coordinator has been told and the
 * condition still holds; when it stops holding the row is cleared, and if it holds again a new row
 * and a new event follow.
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
        if (Schema::hasTable('robot_council_lane_conditions')) {
            return;
        }

        Schema::create('robot_council_lane_conditions', function (Blueprint $table): void {
            $table->id();
            $table->string('condition', 32);

            // What the condition is about: `session:12`, `task:40`, `robot-council/core#318`
            $table->string('subject', 191);
            $table->dateTime('raised_at');
            $table->dateTime('cleared_at')->nullable();

            $table->index(['condition', 'subject', 'cleared_at'], 'robot_council_lane_conditions_open');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_lane_conditions');
    }
};
