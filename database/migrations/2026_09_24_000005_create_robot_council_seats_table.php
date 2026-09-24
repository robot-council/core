<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table recording what a developer has said about one of their own seats (#322).
 *
 * **A seat is a machine, a repository and a work location, not a session.** #314 settled that a
 * lane is a session, and a session ends: parking the session would lift itself the moment its
 * process restarted, which is exactly the elapsed-time lift #314 rules out. So the row is keyed on
 * what outlives a session -- the installation it runs under, which is one machine's harness owned by
 * one developer, and the `repository` and `work_location` every session reports -- which is also
 * the order #314 gives the lane board: by developer, then machine, then slot.
 *
 * **`work_location` is `''` for a seat that names none, never null.** A unique index does not
 * treat two nulls as equal on Postgres or SQLite, so a nullable column would let one seat hold two
 * rows. `''` is the one value `WorkIdentity::LOCATION` can never produce, so it cannot collide with
 * a real location.
 */
return new class extends Migration
{
    /**
     * The width of a host user key, matching every other column that holds one.
     */
    private const int KEY_LENGTH = 64;

    /**
     * Create the table.
     */
    public function up(): void
    {
        // Guarded, because three populations run this file: a host that installed before it, one
        // that installs after, and one rolling back and forward again.
        if (Schema::hasTable('robot_council_seats')) {
            return;
        }

        Schema::create('robot_council_seats', function (Blueprint $table): void {
            $table->id();

            // A foreign key: an installation is revoked rather than deleted, so this never has to
            // choose what happens to a seat whose machine went away
            $table->foreignId('installation_id')->constrained('robot_council_installations');

            // The developer the installation belongs to, recorded at write time as
            // `robot_council_tasks.user_id` is, so reading one developer's seats is a key lookup
            $owner = $table->string('user_id', self::KEY_LENGTH);

            // `WorkIdentity::MAX_REPOSITORY` and `WorkIdentity::MAX_LOCATION`, written out rather
            // than read from the class, for the reason every migration here gives: this file has to
            // mean the same thing after that class is edited
            $table->string('repository', 140);
            $table->string('work_location', 32)->default('');

            // Who parked it and when, null while the seat takes work. Only this developer lifts it.
            $parker = $table->string('parked_by', self::KEY_LENGTH)->nullable();
            $table->dateTime('parked_at')->nullable();

            // Whether the developer exempted this seat from their assignment hours
            $table->boolean('hours_exempt')->default(false);

            $table->timestamps();

            // #54: a host user key compares byte-exactly, and MySQL's default collation does not.
            // Set at creation rather than by a later `change()`, since this table is new.
            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $owner->collation('utf8mb4_bin');
                $parker->collation('utf8mb4_bin');
            }

            $table->unique(['installation_id', 'repository', 'work_location']);
            $table->index('user_id');
        });
    }

    /**
     * Drop the table.
     *
     * A rollback lifts every parked seat, which is the state the fleet was in before this existed.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_seats');
    }
};
