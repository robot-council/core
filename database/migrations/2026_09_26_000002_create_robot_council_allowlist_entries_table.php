<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allowlist entries an administrator adds, beside the environment lists (#406, #312's decision).
 *
 * `github_id` is the identifier, as it is everywhere else; `login` is only what the account was
 * called when it was added, 39 characters being GitHub's own limit. `added_by` is the adding
 * administrator's GitHub ID, null for an entry added by a host calling the store directly.
 *
 * **Guarded on the table's existence**, so a re-run after a partial deploy is inert.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        if (Schema::hasTable('robot_council_allowlist_entries')) {
            return;
        }

        Schema::create('robot_council_allowlist_entries', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('github_id');
            $table->string('list', 16);
            $table->string('login', 39);
            $table->unsignedBigInteger('added_by')->nullable();
            $table->dateTime('created_at');

            $table->unique(['list', 'github_id']);
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_allowlist_entries');
    }
};
