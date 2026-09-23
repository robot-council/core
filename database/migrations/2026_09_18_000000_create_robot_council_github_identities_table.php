<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the table mapping host users to GitHub accounts. The package owns this table, because
 * the mapping decides who the access lists admit: a column on the host's own users table could be
 * written by the host's mass assignment, its own GitHub linking, a seeder, or an admin form, and
 * whoever wrote it would then hold an allowlisted identity.
 *
 * **This file carried no date prefix until #132, and that was a trap rather than a style.** Laravel
 * runs migrations in filename order and digits sort before letters, so an unprefixed name runs
 * **after every dated one** -- which means nothing dated can ever alter the table it creates. It
 * cost a real fix: #54's collation migration is dated, ran before this table existed, and its
 * `Schema::hasTable()` guard skipped this column **silently**. An access-control change shipped
 * covering six of seven columns and reported success. The workaround was a second unprefixed file
 * named to sort after this one; the fix is the prefix, and the workaround is gone with it.
 *
 * `000000` rather than a later number, so it sorts before the six creates that follow and a dated
 * migration can alter this table like any other.
 */
return new class extends Migration
{
    /**
     * Create the identities table.
     *
     * **Guarded, because the rename makes a deployed host meet this file under a name it has no
     * row for.** A host that migrated before #132 recorded `create_robot_council_github_identities_table`;
     * the new name is pending to it, so Laravel runs this a second time, and an unguarded
     * `Schema::create` on an existing table is an error that stops the whole `migrate`. The guard
     * makes the second run a no-op and lets the rest of the batch through.
     *
     * That host ends with **three** `migrations` rows about this table's history: the old create,
     * this one, and `fix_robot_council_github_identity_collation`, whose file #132 also removed.
     * The two fileless rows are inert -- `Migrator::rollbackMigrations()` prints
     * `Migration not found` and `continue`s past a recorded name whose file is absent, so they are
     * never resolved, never rolled back, and never deleted -- and `robot-council:doctor` compares
     * shipped names against run ones, so an extra run name is not reported as pending.
     *
     * **`migrate --pretend` misreports on that host.** `Schema::hasTable()` issues a select, which
     * returns nothing under `--pretend`, so the guard reads false and the plan prints the
     * `create table` the guard exists to prevent. Cosmetic, and worth knowing before somebody
     * reads it as the deploy's intent.
     */
    public function up(): void
    {
        if (Schema::hasTable('robot_council_github_identities')) {
            return;
        }

        Schema::create('robot_council_github_identities', function (Blueprint $table): void {
            $table->id();

            // One identity per user, and per GitHub account. No foreign key: the host owns its
            // users table, including its name and the type of its key, and a user that has gone
            // is refused at sign-in rather than by the database.
            // The host's key as text, not a bigint. A host keyed by UUID or ULID is an ordinary
            // multi-tenant application, and this table holds one row per developer, so the usual
            // argument for a narrow integer index has nothing to weigh against here.
            $table->string('user_id', 64)->unique();
            $table->unsignedBigInteger('github_id')->unique();

            // What the account looked like at the last sign-in, for display only
            $table->string('github_login', 255);
            $table->string('avatar_url', 255)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Drop the identities table.
     */
    public function down(): void
    {
        // **A host that already had this table under the old name must not lose it here.** Its
        // re-run logged a row at the next batch number -- possibly alone in that batch -- so the
        // most ordinary action after a bad deploy, `php artisan migrate:rollback`, reaches this
        // `down()`. `Migrator::runDown()` resolves by file and takes no `shouldRun()` hook, so a
        // guard on `up()` alone does nothing here: an unguarded drop would take the identity
        // rows every developer's sign-in resolves through, and `GitHubCallbackController` would
        // then send each of them down the enrollment path and refuse on a held email.
        //
        // The old row is the only record that says the table predates this file, which is why it
        // is what this reads. `2026_09_22_000003_index_robot_council_installation_identity.php`
        // guards both directions for the same reason.
        //
        // The trade, stated rather than discovered: once that batch has been rolled back this
        // row is gone, so a later `migrate:reset` on that host leaves the table standing -- the
        // old row's file is absent and is skipped. Leaving a table behind on a reset is the safe
        // direction, and `migrate:fresh` still drops it.
        if (DB::table($this->migrationsTable())
            ->where('migration', 'create_robot_council_github_identities_table')
            ->exists()) {
            return;
        }

        Schema::dropIfExists('robot_council_github_identities');
    }

    /**
     * The table Laravel records migrations in, which a host may rename.
     *
     * @return string The configured table name.
     */
    private function migrationsTable(): string
    {
        $configured = config('database.migrations');

        if (\is_array($configured)) {
            $table = $configured['table'] ?? null;

            return \is_string($table) ? $table : 'migrations';
        }

        return \is_string($configured) ? $configured : 'migrations';
    }
};
