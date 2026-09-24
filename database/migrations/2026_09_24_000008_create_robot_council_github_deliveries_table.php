<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table of GitHub webhook deliveries already applied (#318).
 *
 * **GitHub redelivers, and a replay must change nothing.** A delivery's `X-GitHub-Delivery` id is
 * inserted in the same transaction as everything the delivery writes, against this primary key, so
 * a second delivery of the same id finds it taken and applies nothing -- including freeing a lane
 * a second time. Rows older than GitHub's redelivery window are pruned as deliveries arrive.
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
        if (Schema::hasTable('robot_council_github_deliveries')) {
            return;
        }

        Schema::create('robot_council_github_deliveries', function (Blueprint $table): void {
            // A GUID from GitHub, 36 characters; 64 leaves room for a format change
            $id = $table->string('delivery_id', 64)->primary();
            $table->string('event', 64);
            $table->dateTime('received_at')->index();

            // Compared byte for byte: two ids differing only in case are two deliveries
            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $id->collation('utf8mb4_bin');
            }
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_github_deliveries');
    }
};
