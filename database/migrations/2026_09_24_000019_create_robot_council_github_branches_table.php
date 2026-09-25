<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table of branches GitHub has reported, from `create` and `delete` deliveries (#344).
 *
 * A placement warns when a branch for the ticket already exists with no open pull request -- the
 * work may already have started. Core reads nothing from GitHub, so it learns branches the way it
 * learns everything else: from the webhook, inbound. A branch that existed before the webhook was
 * configured is not known until something happens to it.
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
        if (Schema::hasTable('robot_council_github_branches')) {
            return;
        }

        Schema::create('robot_council_github_branches', function (Blueprint $table): void {
            // `WorkIdentity::MAX_REPOSITORY` and `BranchName::MAX`, written out so this file means
            // the same thing after those classes are edited
            $repository = $table->string('repository', 140);
            $name = $table->string('name', 200);
            $table->dateTime('created_at');

            // Byte for byte: git branch names are case-sensitive
            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $repository->collation('utf8mb4_bin');
                $name->collation('utf8mb4_bin');
            }

            $table->primary(['repository', 'name'], 'robot_council_github_branches_ref');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_github_branches');
    }
};
