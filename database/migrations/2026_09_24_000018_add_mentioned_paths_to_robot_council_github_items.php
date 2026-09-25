<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the file paths an issue's body mentions (#321).
 *
 * **Shown as unverified mentions, never as a disjointness verdict.** #314 recorded three false
 * collisions in one afternoon from paths like these -- a README both tickets only mentioned, a file
 * in a different repository, a path listed under out-of-scope -- and a false collision holds a ticket
 * with nothing ever reporting it. So the shortlist shows them for a coordinator to read, and nothing
 * compares them. They are extracted at receipt because the body itself is not kept.
 */
return new class extends Migration
{
    /**
     * Add the column.
     */
    public function up(): void
    {
        if (! Schema::hasTable('robot_council_github_items') || Schema::hasColumn('robot_council_github_items', 'mentioned_paths')) {
            return;
        }

        Schema::table('robot_council_github_items', function (Blueprint $table): void {
            $table->json('mentioned_paths')->nullable();
        });
    }

    /**
     * Drop the column.
     */
    public function down(): void
    {
        if (! Schema::hasTable('robot_council_github_items') || ! Schema::hasColumn('robot_council_github_items', 'mentioned_paths')) {
            return;
        }

        Schema::table('robot_council_github_items', function (Blueprint $table): void {
            $table->dropColumn('mentioned_paths');
        });
    }
};
