<?php

declare(strict_types=1);

/**
 * Proves the database under test isolates connections the way cross-connection tests rely on: a
 * row another connection has not committed is invisible, and it becomes visible once committed.
 * The `cross-connection` group never runs on SQLite, because each connection to its in-memory
 * `testing` database opens a separate, empty one. **This file is the only member that is engine
 * neutral**, so it is the only one the `mysql` job executes; the other three set `lock_timeout`,
 * which is Postgres's spelling, and skip themselves elsewhere rather than stalling (#39).
 *
 * @command  DB_CONNECTION=pgsql vendor/bin/pest --compact --group=cross-connection
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('sees a row from another connection only after that connection commits', function (): void {
    // A second, independently connected copy of the default connection
    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    Schema::create('robot_council_ci_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('value');
    });

    try {
        $other->beginTransaction();
        $other->table('robot_council_ci_probe')->insert(['value' => 'from the other connection']);

        expect(DB::table('robot_council_ci_probe')->count())->toBe(0);

        $other->commit();

        expect(DB::table('robot_council_ci_probe')->value('value'))->toBe('from the other connection');
    } finally {
        // Leave nothing behind for the next test, whether or not the assertions passed
        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }

        Schema::dropIfExists('robot_council_ci_probe');
        DB::purge("{$default}_other");
    }
})->group('cross-connection');
