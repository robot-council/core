<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table holding which open issues each repository's search qualifiers match (#530).
 *
 * **The mirror cannot evaluate a qualifier, so the fetch that counts them records what they
 * matched.** One row per repository, replaced whole by each successful fetch, holds the issue
 * numbers GitHub's search returned and the qualifiers it was asked with, so the shortlist can keep
 * only those tickets and say when the set was taken with qualifiers no longer configured.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        if (Schema::hasTable('robot_council_backlog_members')) {
            return;
        }

        Schema::create('robot_council_backlog_members', function (Blueprint $table): void {
            // `WorkIdentity::MAX_REPOSITORY`, written out so this file means the same thing after
            // that class is edited; stored lower-cased, as GitHub compares names
            $repository = $table->string('repository', 140);

            // `BacklogQualifiers::MAX_LENGTH`, written out for the same reason
            $table->string('qualifiers', 90);

            // The matching issue numbers, a JSON list of at most `GitHubApp::MAX_MATCHING` integers;
            // null when the qualifiers matched more than that, so the set could not be listed whole
            $table->text('numbers')->nullable();
            $table->dateTime('fetched_at');

            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $repository->collation('utf8mb4_bin');
            }

            $table->primary('repository', 'robot_council_backlog_members_repository');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_backlog_members');
    }
};
