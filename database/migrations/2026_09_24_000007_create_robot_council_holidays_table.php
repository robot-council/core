<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\Engines;

/**
 * Creates the table of days each developer takes no new placements (#322).
 *
 * **The developer's own list, not a calendar the package ships.** Which days are public holidays
 * depends on the country, often the region, and sometimes the employer, and the package knows
 * none of those. A developer who lists their days gets exactly the days they meant; a bundled
 * calendar would be right for some developers and silently wrong for the rest.
 *
 * A day is a calendar date in the developer's own timezone, so nothing here is converted.
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
        if (Schema::hasTable('robot_council_holidays')) {
            return;
        }

        Schema::create('robot_council_holidays', function (Blueprint $table): void {
            $owner = $table->string('user_id', self::KEY_LENGTH);
            $table->date('day');

            // #54: a host user key compares byte-exactly, and MySQL's default collation does not
            if (Engines::needsBinaryCollation(DB::getDriverName())) {
                $owner->collation('utf8mb4_bin');
            }

            $table->primary(['user_id', 'day']);
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_holidays');
    }
};
