<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table of GitHub issue and pull-request state, as the webhook reports it (#318).
 *
 * **Core reads nothing from GitHub; this is what GitHub has told it.** #314 decided core learns
 * GitHub state from inbound deliveries, so a coordination decision never waits on GitHub being up.
 * The lane board's pull-request queue, a placement's "the ticket is open" check and the placeable
 * ticket list all read this table. Work open before the webhook existed arrives through the
 * operator's `robot-council:github-import`, which reads a file rather than GitHub.
 *
 * **`github_updated_at` decides which report wins.** GitHub does not promise delivery order, so a
 * delivery older than the row it would replace changes nothing.
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
        if (Schema::hasTable('robot_council_github_items')) {
            return;
        }

        Schema::create('robot_council_github_items', function (Blueprint $table): void {
            $table->id();

            // `WorkIdentity::MAX_REPOSITORY`, written out rather than read from the class, so this
            // file means the same thing after that class is edited
            $repository = $table->string('repository', 140);
            $table->unsignedBigInteger('number');
            $table->boolean('is_pull_request');

            // `open` or `closed`, and for a pull request whether it merged and whether it is a draft
            $table->string('state', 16);
            $table->boolean('merged')->default(false);
            $table->boolean('draft')->default(false);

            // GitHub caps a title at 256 characters. Stored for the placeable-ticket list; escaped
            // wherever it is rendered, because it is anybody's text.
            $table->string('title', 256);

            // The branch a pull request comes from, when it comes from this same repository and
            // is a name `BranchName` admits -- null otherwise, so a fork's branch can never match
            // a lane's
            $headRef = $table->string('head_ref', 200)->nullable();

            // Label names, GitHub caps each at 50 characters
            $table->json('labels');

            // Task-list checkboxes in the body, counted at receipt so the body itself is not kept
            $table->unsignedInteger('checkboxes')->default(0);
            $table->unsignedInteger('checkboxes_ticked')->default(0);

            $table->dateTime('github_updated_at');
            $table->timestamps();

            // Byte for byte, like the seat table's: `WorkIdentity` keeps a repository's case, and a
            // branch comparison against a lane's must not fold case on MySQL
            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $repository->collation('utf8mb4_bin');
                $headRef->collation('utf8mb4_bin');
            }

            // Issues and pull requests share one number space per repository
            $table->unique(['repository', 'number'], 'robot_council_github_items_ref_unique');
            $table->index(['repository', 'head_ref'], 'robot_council_github_items_head_index');
        });
    }

    /**
     * Drop the table.
     *
     * A rollback forgets what GitHub reported. Nothing is lost that a redelivery or an import cannot
     * restore.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_github_items');
    }
};
