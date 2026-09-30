<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The login GitHub last gave each allowlisted account, for the Access page (#484).
 *
 * A cache of public profiles, keyed by `github_id`, and nothing reads it to decide access: the ID
 * alone does that. One table for both sources of entry, because an entry from the environment has
 * no row of its own to carry a login. `login` and `account_type` are null when GitHub says no
 * account has the ID; `checked_at` is when the last attempt was made, successful or not, and
 * `resolved_at` when GitHub last answered with a profile.
 *
 * **`dateTime`, not `timestamp`**, per `tests/MigrationTimestampGuardTest.php`: `checked_at` is
 * never null, and MySQL would give a first NOT NULL `TIMESTAMP` an `ON UPDATE CURRENT_TIMESTAMP`.
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
        if (Schema::hasTable('robot_council_github_accounts')) {
            return;
        }

        Schema::create('robot_council_github_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('github_id')->unique();
            $table->string('login', 39)->nullable();
            $table->string('account_type', 16)->nullable();
            $table->dateTime('checked_at');
            $table->dateTime('resolved_at')->nullable();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_github_accounts');
    }
};
