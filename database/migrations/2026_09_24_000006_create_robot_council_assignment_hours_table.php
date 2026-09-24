<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table holding each developer's assignment hours (#322).
 *
 * One row per developer, and no row means no hours are set: a developer who has said nothing is not
 * gated. The window is stored as the developer typed it -- `HH:MM` in their own timezone -- rather
 * than converted to UTC, because a window converted once is wrong for half the year wherever the
 * zone observes daylight saving.
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
        if (Schema::hasTable('robot_council_assignment_hours')) {
            return;
        }

        Schema::create('robot_council_assignment_hours', function (Blueprint $table): void {
            $owner = $table->string('user_id', self::KEY_LENGTH)->primary();

            // An IANA zone name. The longest PHP knows is 32 characters; 64 leaves room.
            $table->string('timezone', 64);

            // `HH:MM`, local to `timezone`. A window whose end is before its start runs overnight.
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);

            $table->boolean('skip_weekends')->default(true);

            $table->timestamps();

            // #54: a host user key compares byte-exactly, and MySQL's default collation does not
            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $owner->collation('utf8mb4_bin');
            }
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_assignment_hours');
    }
};
