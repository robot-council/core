<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the identity an approval supersedes on.
 *
 * `Support\Installations::createFrom()` now reads the live installations for a
 * `(user_id, harness, machine_label)` before creating their replacement, and takes them with
 * `lockForUpdate()` (#106, #146). That read happens on **every** approval, and the table carried
 * an index on `user_id` alone -- so it scanned every installation a developer had ever had, and
 * held whatever it scanned.
 *
 * **A new migration rather than an edit to the create migration**, because the era of editing those
 * is over: `robot-council/robot-council` has already run them, so an edit would leave a deployed
 * database and a fresh install with different schemas and nothing to say so (#94, #100).
 *
 * Guarded on what the schema reports rather than on an assumption, for the same reason: the three
 * populations -- installed before this, installed after it, and rolled back -- all run this file.
 */
return new class extends Migration
{
    /**
     * The table this indexes.
     */
    private const string TABLE = 'robot_council_installations';

    /**
     * The index's name, spelled out rather than left to the grammar, because `down()` has to name
     * the same thing `up()` created and a generated name is a guess in two places.
     */
    private const string INDEX = 'robot_council_installations_identity_index';

    /**
     * Add the composite index.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->indexExists()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(['user_id', 'harness', 'machine_label'], self::INDEX);
        });
    }

    /**
     * Drop it, leaving the `user_id` index the create migration added.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! $this->indexExists()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    /**
     * Whether the index is already on the table.
     *
     * Asked of the schema rather than inferred, so a database that has it -- from a rollback and
     * re-run, or from a future create migration that includes it -- is left alone instead of
     * erroring on a duplicate name.
     */
    private function indexExists(): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['name'] ?? null) === self::INDEX) {
                return true;
            }
        }

        return false;
    }
};
