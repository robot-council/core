<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a backlog reading be stored with no session behind it (#383).
 *
 * Core now fetches each board repository's open-issue count itself, through a GitHub App, and a
 * fetched reading has no session to name. **`reported_by` becomes nullable, and a null is what says
 * the package fetched the count.** A separate source column was weighed and not taken: it would say
 * the same thing twice, and two fields can disagree -- a `session` source with no session, or a
 * `fetched` one naming somebody -- where one field cannot. The alternative of keeping the column
 * NOT NULL and writing a stand-in id for a fetch was refused outright, because a number that names no
 * session is exactly the kind of stand-in #339 built the meters to avoid.
 *
 * Guarded on what the schema reports rather than on the migration having run, because three
 * populations run this file: a host that installed before it, one that installs after, and one
 * rolling back and forward again.
 */
return new class extends Migration
{
    /**
     * Make `reported_by` nullable, where it is not already.
     */
    public function up(): void
    {
        if (! $this->reportedByIs(nullable: false)) {
            return;
        }

        Schema::table('robot_council_backlog_readings', function (Blueprint $table): void {
            $table->unsignedBigInteger('reported_by')->nullable()->change();
        });
    }

    /**
     * Make it NOT NULL again, where it is nullable.
     *
     * **The fetched readings go first**, because a NOT NULL column cannot hold them. Readings are
     * kept for two days and a baseline copies its count rather than pointing at a reading, so what
     * a rollback loses is at most two days of meter history, and the meters then read what sessions
     * report, as they did before this migration.
     */
    public function down(): void
    {
        if (! $this->reportedByIs(nullable: true)) {
            return;
        }

        DB::table('robot_council_backlog_readings')->whereNull('reported_by')->delete();

        Schema::table('robot_council_backlog_readings', function (Blueprint $table): void {
            $table->unsignedBigInteger('reported_by')->nullable(false)->change();
        });
    }

    /**
     * Whether the table exists and its `reported_by` column's nullability is the one asked about.
     *
     * @param  bool  $nullable  The nullability to look for.
     * @return bool False when the table or the column is missing, so neither direction acts on it.
     */
    private function reportedByIs(bool $nullable): bool
    {
        if (! Schema::hasTable('robot_council_backlog_readings')) {
            return false;
        }

        foreach (Schema::getColumns('robot_council_backlog_readings') as $column) {
            if ($column['name'] === 'reported_by') {
                return $column['nullable'] === $nullable;
            }
        }

        return false;
    }
};
