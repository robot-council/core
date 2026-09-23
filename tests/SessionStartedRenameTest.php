<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Livewire\ChangeFeed;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;

/**
 * That rows written before the event was renamed are still readable afterwards.
 *
 * **The rename is cheap; this is the part that could break a running fleet.** `robot_council_events.type`
 * is a `string(64)` holding the enum's backing value, and `Models\FleetEvent` casts it with
 * `'type' => FleetEventType::class`. Laravel's enum cast resolves through `from()`, which raises
 * `ValueError` on a value the enum no longer has. So a historical row becomes unreadable the moment
 * the feed pages over it -- on the dashboard, and in every agent's feed read.
 *
 * Those rows exist. Sessions started on the deployed fleet on 2026-09-23 moved the cursor from 313
 * to 368, inside the window `robot-council:prune-events` keeps (#217).
 *
 * @command  vendor/bin/pest tests/SessionStartedRenameTest.php
 */
beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
});

/**
 * The migration under test, as an object.
 *
 * **Invoked directly rather than through `artisan migrate`.** The suite migrates everything before
 * each test, so the migration is already recorded and `migrate` is a no-op -- a row planted
 * afterwards would never be touched, and a test written that way asserts nothing while looking
 * thorough. Calling `up()` is also what makes the re-run and rollback cases expressible at all.
 *
 * @return Migration The migration.
 */
function theRenameMigration(): Migration
{
    $migration = require __DIR__.'/../database/migrations/2026_09_23_000002_rename_session_enrolled_events.php';

    // Narrowed with `if`/`throw` rather than `assert()`, which Pest's `security()` preset bans.
    if (! $migration instanceof Migration) {
        throw new RuntimeException('The rename migration did not return a Migration.');
    }

    return $migration;
}

/**
 * Run one direction of the migration.
 *
 * **Called dynamically because `Migration` declares neither `up()` nor `down()`.** They are a
 * convention the migrator looks for, not part of the base class, so a static call on the declared
 * type is an undefined method to the analyzer. Guarded rather than assumed, so a migration that
 * stopped defining one fails here saying so instead of somewhere less obvious.
 *
 * @param  Migration  $migration  The migration to run.
 * @param  string  $direction  `up` or `down`.
 */
function runMigration(Migration $migration, string $direction): void
{
    if (! method_exists($migration, $direction)) {
        throw new RuntimeException(sprintf('The rename migration defines no `%s()`.', $direction));
    }

    $migration->{$direction}();
}

/**
 * Write a row the way a release before the rename would have.
 *
 * Inserted through the query builder rather than the model, because the model is exactly what
 * cannot represent this value any more -- which is the situation being reproduced.
 *
 * @return int The row's id.
 */
function anEventRowFromBeforeTheRename(): int
{
    return (int) DB::table('robot_council_events')->insertGetId([
        'agent_session_id' => null,
        'user_id' => 'developer-1',
        'type' => 'session.enrolled',
        'body' => 'octodev on a-machine started a session.',
        'posted_with_coordinator' => false,
        'created_at' => now(),
    ]);
}

it('leaves no row holding the old value once the migration has run', function (): void {
    $id = anEventRowFromBeforeTheRename();

    runMigration(theRenameMigration(), 'up');

    expect(DB::table('robot_council_events')->where('id', $id)->value('type'))
        ->toBe('session.started');
});

it('reads a migrated row back through the model that could not read the old one', function (): void {
    $id = anEventRowFromBeforeTheRename();

    // The control, and the reason this test exists: before the migration the cast genuinely refuses
    // the row. Without this, the assertion below would pass on a value the model never struggled
    // with, and would be describing nothing.
    expect(fn (): mixed => FleetEvent::query()->findOrFail($id)->type)
        ->toThrow(ValueError::class, 'session.enrolled');

    runMigration(theRenameMigration(), 'up');

    expect(FleetEvent::query()->findOrFail($id)->type)->toBe(FleetEventType::SessionStarted);
});

it('is safe to run again, and on a table with nothing to rewrite', function (): void {
    // The three populations the migration has to survive: an upgrade with rows, a re-run with none
    // left, and a fresh install that never had any. All three end in the same state.
    $id = anEventRowFromBeforeTheRename();

    runMigration(theRenameMigration(), 'up');
    runMigration(theRenameMigration(), 'up');

    expect(DB::table('robot_council_events')->where('id', $id)->value('type'))->toBe('session.started')
        ->and(DB::table('robot_council_events')->where('type', 'session.enrolled')->count())->toBe(0);

    // The fresh-install population: nothing to rewrite, and it must not error.
    DB::table('robot_council_events')->delete();

    runMigration(theRenameMigration(), 'up');

    expect(DB::table('robot_council_events')->count())->toBe(0);
});

it('points a rolled-back row back, so the previous release can still read it', function (): void {
    // A rollback that left rows holding the new value would hit the same `ValueError` from the
    // other side, which is why `down()` is not empty.
    $id = anEventRowFromBeforeTheRename();

    runMigration(theRenameMigration(), 'up');

    expect(DB::table('robot_council_events')->where('id', $id)->value('type'))->toBe('session.started');

    runMigration(theRenameMigration(), 'down');

    expect(DB::table('robot_council_events')->where('id', $id)->value('type'))
        ->toBe('session.enrolled');
});

it('renders a migrated row in the dashboard change feed', function (): void {
    // The panel is where a developer meets these rows, and it reads them through the same cast. A
    // row that migrated correctly but rendered as an error would satisfy every assertion above.
    $developer = $this->enrollDeveloper(4242);

    $id = anEventRowFromBeforeTheRename();

    runMigration(theRenameMigration(), 'up');

    Livewire::actingAs($developer)
        ->test(ChangeFeed::class)
        ->assertOk()
        ->assertSee('started a session');
});
