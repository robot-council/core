<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table of waivers a seat's developer grants against a placement refusal (#320).
 *
 * **One refusal, on one seat, for one placement**, as #320 decided: a waiver is granted by the
 * developer who owns the seat, recorded with who and when, and consumed by the placement it lets
 * through. A waiver that stood until withdrawn would be a setting under another name, and the
 * standing form that exists -- exempting a seat from assignment hours -- is #322's.
 *
 * A consumed waiver is kept, with the task it let through, as the record of who waived what.
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
        if (Schema::hasTable('robot_council_placement_waivers')) {
            return;
        }

        Schema::create('robot_council_placement_waivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seat_id')->constrained('robot_council_seats');
            $table->string('rule', 32);

            // The seat's developer, the only party who may grant it
            $grantor = $table->string('granted_by', 64);
            $table->dateTime('granted_at');

            // Set by the placement that used it, and the task it let through
            $table->dateTime('consumed_at')->nullable();
            $table->unsignedBigInteger('consumed_by_task')->nullable();

            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $grantor->collation('utf8mb4_bin');
            }

            $table->index(['seat_id', 'rule', 'consumed_at'], 'robot_council_placement_waivers_open_index');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_placement_waivers');
    }
};
