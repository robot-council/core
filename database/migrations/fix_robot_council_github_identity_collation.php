<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes `robot_council_github_identities.user_id` compare byte-exactly, like the other six.
 *
 * **This file is named without a date prefix on purpose, and the reason is ordering.** Laravel runs
 * migrations in filename order, and `create_robot_council_github_identities_table.php` carries no
 * prefix either -- so it sorts after every `2026_*` file and runs **last**. The migration that
 * collates the other six key columns is dated, therefore runs before that table exists, and its
 * `Schema::hasTable()` guard skipped this column silently. A name beginning `f` sorts after one
 * beginning `c`, so this runs afterwards.
 *
 * That is a workaround for a trap rather than a thing to copy: an unprefixed migration always
 * sorts last, so nothing dated can ever alter what it creates. Renaming it is the real fix and is
 * filed separately -- it is not done here, because a rename makes a deployed host run the create a
 * second time, and that is not a risk worth folding into a security fix.
 *
 * The column decides which GitHub account a host user is, which is what the access lists are
 * checked against. Why a case-insensitive collation is an access-control problem rather than a
 * storage one is recorded on the migration that handles the other six (#54).
 */
return new class extends Migration
{
    /**
     * Give the identity's user key a binary collation on MySQL.
     */
    public function up(): void
    {
        $this->collate('utf8mb4_bin');
    }

    /**
     * Put it back on the server's default collation.
     */
    public function down(): void
    {
        $this->collate(null);
    }

    /**
     * Apply one collation to the column, where the engine has one to apply.
     *
     * @param  string|null  $collation  The collation to set, or null for the server's default.
     */
    private function collate(?string $collation): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('robot_council_github_identities')
            || ! Schema::hasColumn('robot_council_github_identities', 'user_id')) {
            return;
        }

        Schema::table('robot_council_github_identities', function (Blueprint $table) use ($collation): void {
            // Restated in full because `change()` redefines rather than amends. The column is
            // `unique()`, and MySQL rebuilds that index rather than dropping it.
            $changed = $table->string('user_id', 64);

            if ($collation !== null) {
                $changed->collation($collation);
            }

            $changed->change();
        });
    }
};
