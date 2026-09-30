<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The avatar GitHub last gave each repository owner, for the picture beside a repository (#416).
 *
 * **Per owner rather than per repository**, because a repository has no picture of its own that a
 * circle can show -- only its owner, a user or an organization, has one -- so every repository of
 * one owner shows the same picture, and a repository that has never sent a delivery still gets it
 * once any sibling has. Written from webhook deliveries, which carry `repository.owner`, so core asks
 * GitHub nothing for it. Display only: nothing reads this table to decide anything.
 *
 * `login` is stored lower-cased, as GitHub compares logins. **`dateTime`, not `timestamp`**, per
 * `tests/MigrationTimestampGuardTest.php`: `noted_at` is never null.
 *
 * **Guarded on the table's existence**, so a re-run after a partial deploy is inert.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        if (Schema::hasTable('robot_council_github_owners')) {
            return;
        }

        Schema::create('robot_council_github_owners', static function (Blueprint $table): void {
            $table->id();
            $table->string('login', 39)->unique();
            $table->string('avatar_url', 255);
            $table->dateTime('noted_at');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_github_owners');
    }
};
