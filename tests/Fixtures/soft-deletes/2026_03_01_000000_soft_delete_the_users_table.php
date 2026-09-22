<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the users table the `deleted_at` column `SoftDeletes` reads.
 *
 * Kept apart from the package's own migrations because this is the **host's** table and the host's
 * decision. `robot-council:install` relaxes nullability on `users.password` and `users.email` and
 * nothing else; whether a host soft-deletes is not something this package sets or should.
 *
 * Run alongside the package's column stub rather than instead of it, so the schema under test is
 * what a real host would have: the framework's users table, plus what `robot-council:install`
 * writes, plus the host's own choices.
 */
return new class extends Migration
{
    /**
     * Add the column.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'deleted_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    /**
     * Drop it.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'deleted_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
