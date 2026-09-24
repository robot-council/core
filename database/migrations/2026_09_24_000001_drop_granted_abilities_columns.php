<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the two `granted_abilities` columns, which no longer decide anything.
 *
 * `robot-council/core#221` gave a session token its `Access\Role` preset, and `#222` removed
 * `Role::permittedBy()` -- this column's one authorization reader -- so machine-level eligibility
 * stopped existing rather than moving somewhere else. `#231` retired the controls that wrote it.
 * What kept it alive after that was a released `robot-council/cli` reading `granted_abilities` out
 * of the enrollment response; that read went in `robot-council/cli#152` and shipped in
 * **v0.3.0**, which Packagist serves.
 *
 * **Two columns, and the second is dead only because the first is.** `robot_council_device_codes`
 * carries its own `granted_abilities`, written on approval and read by exactly one caller --
 * `Support\Installations::approve()`, copying it into the installation. With the installation
 * column gone that read goes with it, leaving the device-code column with no reader at all. It is
 * dropped in the same change rather than left as residue, and it carries no recovery concern of
 * its own: device codes are short-lived and pruned hourly, so nothing here outlives the
 * enrollment it belongs to. **`requested_abilities` is a different column and stays** -- the
 * verification page renders it, which is what a developer approves against.
 *
 * **Guarded on what the schema reports, because three populations run this file.** A host that
 * installed before the epic has both columns; one that installed after `#231` still has them,
 * because `#231` retired the controls and not the schema; and one that rolled this back and
 * migrated again has them restored by `down()`. `Schema::hasColumn()` is the only thing that can
 * tell those apart, and an unguarded `dropColumn` stops the whole batch on the one that has
 * already run.
 *
 * **`down()` restores the columns and not their contents, and that asymmetry is the point.** The
 * installation column is NOT NULL with no default in the create migration, so a rollback on a
 * populated table has to put something there; an empty JSON list is the only honest answer,
 * because nothing derives what an administrator once granted. `robot-council/core#239` weighed
 * that and chose it deliberately: a column kept one release too long costs a dead accessor, and
 * one dropped too early cannot be reconstructed -- which is why this waited for the client release
 * rather than shipping beside `#231`.
 */
return new class extends Migration
{
    /**
     * The columns this drops, by the table that holds each.
     *
     * @var array<string, string>
     */
    private const array COLUMNS = [
        'robot_council_installations' => 'granted_abilities',
        'robot_council_device_codes' => 'granted_abilities',
    ];

    /**
     * Drop each column that is still there.
     */
    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropColumn($column);
            });
        }
    }

    /**
     * Put the columns back, with the nullability each create migration declared.
     *
     * The installation's is NOT NULL and the device code's is nullable, which is how they were
     * created. Restated here rather than inferred, because a rollback that changed a column's
     * nullability would leave a host in a state neither migration describes.
     */
    public function down(): void
    {
        if (Schema::hasTable('robot_council_installations')
            && ! Schema::hasColumn('robot_council_installations', 'granted_abilities')) {
            Schema::table('robot_council_installations', function (Blueprint $blueprint): void {
                // **A default, where the create migration has none.** `json` columns cannot take a
                // default on MySQL, so this is filled after the fact rather than declared -- see
                // below. Added nullable first for the same reason: a NOT NULL column cannot be
                // added to a populated table without one.
                $blueprint->json('granted_abilities')->nullable();
            });

            // Every row gets an empty list, because nothing can recover what was there. Then the
            // column returns to NOT NULL, which is what the create migration declares.
            DB::table('robot_council_installations')
                ->whereNull('granted_abilities')
                ->update(['granted_abilities' => json_encode([], JSON_THROW_ON_ERROR)]);

            Schema::table('robot_council_installations', function (Blueprint $blueprint): void {
                $blueprint->json('granted_abilities')->nullable(false)->change();
            });
        }

        if (Schema::hasTable('robot_council_device_codes')
            && ! Schema::hasColumn('robot_council_device_codes', 'granted_abilities')) {
            Schema::table('robot_council_device_codes', function (Blueprint $blueprint): void {
                $blueprint->json('granted_abilities')->nullable();
            });
        }
    }
};
