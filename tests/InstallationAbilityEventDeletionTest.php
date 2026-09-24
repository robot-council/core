<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Livewire\ChangeFeed;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;

/**
 * That rows written by the retired ability controls do not make the feed unreadable.
 *
 * **The removal is cheap; this is the part that could break a running fleet.**
 * `robot_council_events.type` is a `string(64)` holding the enum's backing value, and
 * `Models\FleetEvent` casts it with `'type' => FleetEventType::class`. Laravel's enum cast resolves
 * through `from()`, which raises `ValueError` on a value the enum no longer has. So every row the
 * two controls ever wrote becomes unreadable the moment the feed pages over it -- on the dashboard,
 * and in every agent's feed read.
 *
 * Those rows exist. Measured against the deployment on 2026-09-24: two
 * `installation.ability_granted` and one `installation.ability_revoked`, the most recent at
 * 2026-09-23 17:20:44 (`robot-council/core#231`).
 *
 * **They are deleted rather than rewritten**, for the reasons the migration records: the feed is a
 * 30-day rolling window that `Console\PruneEventsCommand` empties by `created_at` alone, so nothing
 * is being preserved that the package otherwise keeps; and the stored body already says
 * `was granted tasks:create`, which asserts an authorization change that never happened.
 *
 * @command  vendor/bin/pest tests/InstallationAbilityEventDeletionTest.php
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
 * thorough.
 *
 * Named apart from `SessionJoinedRenameTest`'s helpers deliberately: a function declared in a test
 * file is global once that file loads, so sharing a name would redeclare it on a full run.
 *
 * @return Migration The migration.
 */
function theAbilityEventDeletion(): Migration
{
    $migration = require __DIR__.'/../database/migrations/2026_09_23_000007_delete_installation_ability_events.php';

    // Narrowed with `if`/`throw` rather than `assert()`, which Pest's `security()` preset bans.
    if (! $migration instanceof Migration) {
        throw new RuntimeException('The deletion migration did not return a Migration.');
    }

    return $migration;
}

/**
 * Run one direction of it.
 *
 * Called dynamically because `Migration` declares neither `up()` nor `down()`: they are a
 * convention the migrator looks for rather than part of the base class.
 *
 * @param  string  $direction  `up` or `down`.
 */
function runTheAbilityEventDeletion(string $direction): void
{
    $migration = theAbilityEventDeletion();

    if (! method_exists($migration, $direction)) {
        throw new RuntimeException(sprintf('The deletion migration defines no `%s()`.', $direction));
    }

    $migration->{$direction}();
}

/**
 * Write a row the way a release before the retirement would have.
 *
 * Inserted through the query builder rather than the model, because the model is exactly what
 * cannot represent this value any more -- which is the situation being reproduced.
 *
 * @param  string  $type  The retired type to write.
 * @return int The row's id.
 */
function aRetiredAbilityEvent(string $type = 'installation.ability_granted'): int
{
    return (int) DB::table('robot_council_events')->insertGetId([
        'agent_session_id' => null,
        'user_id' => 'developer-1',
        'type' => $type,
        'body' => 'claude-code on workbench was granted tasks:create',
        'posted_with_coordinator' => false,
        'created_at' => now(),
    ]);
}

it('leaves no row holding either retired value once the migration has run', function (): void {
    $granted = aRetiredAbilityEvent();
    $revoked = aRetiredAbilityEvent('installation.ability_revoked');

    runTheAbilityEventDeletion('up');

    expect(DB::table('robot_council_events')->whereIn('id', [$granted, $revoked])->count())->toBe(0);
});

it('reads the table back through the model that could not read those rows', function (): void {
    $id = aRetiredAbilityEvent();

    // A row of a type that survives, so the read below has something to return and cannot pass by
    // finding an empty table.
    DB::table('robot_council_events')->insert([
        'agent_session_id' => null,
        'user_id' => 'developer-1',
        'type' => FleetEventType::InstallationRevoked->value,
        'body' => 'claude-code on workbench was revoked.',
        'posted_with_coordinator' => false,
        'created_at' => now(),
    ]);

    // **The control, and the reason this test exists.** Before the migration the cast genuinely
    // refuses the row. Without this the assertion below would pass on a table that never had a
    // problem, and would be describing nothing.
    expect(fn (): mixed => FleetEvent::query()->findOrFail($id)->type)
        ->toThrow(ValueError::class, 'installation.ability_granted');

    runTheAbilityEventDeletion('up');

    // **Reading `->type` is the assertion.** The cast runs on access, so a row the enum cannot hold
    // raises here rather than returning something to compare -- which is why this maps the column
    // rather than asking whether each value `instanceof` the enum, a question PHPStan answers
    // statically from the model's docblock and reports as dead code.
    $types = FleetEvent::query()->orderBy('id')->get()
        ->map(static fn (FleetEvent $event): string => $event->type->value)
        ->all();

    expect($types)->toBe([FleetEventType::InstallationRevoked->value]);
});

it('deletes only the two retired types, and leaves every other row alone', function (): void {
    // **Without this a migration that emptied the table would pass every other test here.** The
    // surviving row is one of the types an administrative action still writes.
    $retired = aRetiredAbilityEvent();

    $kept = (int) DB::table('robot_council_events')->insertGetId([
        'agent_session_id' => null,
        'user_id' => 'developer-1',
        'type' => FleetEventType::InstallationRevoked->value,
        'body' => 'claude-code on workbench was revoked.',
        'posted_with_coordinator' => false,
        'created_at' => now(),
    ]);

    runTheAbilityEventDeletion('up');

    expect(DB::table('robot_council_events')->where('id', $retired)->count())->toBe(0)
        ->and(DB::table('robot_council_events')->where('id', $kept)->value('type'))
        ->toBe(FleetEventType::InstallationRevoked->value);
});

it('is safe to run again, and on a table with nothing to delete', function (): void {
    // The three populations the migration has to survive: an upgrade with rows, a re-run with none
    // left, and a fresh install that never had any. All three end in the same state.
    $id = aRetiredAbilityEvent();

    runTheAbilityEventDeletion('up');
    runTheAbilityEventDeletion('up');

    expect(DB::table('robot_council_events')->where('id', $id)->count())->toBe(0);

    // The fresh-install population: nothing to delete, and it must not error.
    DB::table('robot_council_events')->delete();

    runTheAbilityEventDeletion('up');

    expect(DB::table('robot_council_events')->count())->toBe(0);
});

it('rolls back without restoring anything, and leaves a readable table either way', function (): void {
    // **`down()` is deliberately empty, and this pins that rather than leaving it to a reader.** A
    // rename needs its inverse, because rolling the code back leaves migrated rows unreadable from
    // the other side. A delete does not: the previous release's enum still holds both cases, so it
    // reads whatever remains and simply sees fewer rows -- the same thing it would see after the
    // next prune. Nothing can restore a deleted row, and a stub pretending otherwise would be the
    // lie.
    $retired = aRetiredAbilityEvent();

    $kept = (int) DB::table('robot_council_events')->insertGetId([
        'agent_session_id' => null,
        'user_id' => 'developer-1',
        'type' => FleetEventType::InstallationRevoked->value,
        'body' => 'claude-code on workbench was revoked.',
        'posted_with_coordinator' => false,
        'created_at' => now(),
    ]);

    runTheAbilityEventDeletion('up');
    runTheAbilityEventDeletion('down');

    expect(DB::table('robot_council_events')->where('id', $retired)->count())->toBe(0)
        ->and(DB::table('robot_council_events')->where('id', $kept)->count())->toBe(1);
});

it('renders the change feed for a fleet that held those rows', function (): void {
    // The panel is where a developer meets these rows, and it reads them through the same cast. A
    // migration that deleted correctly but left the page erroring would satisfy every assertion
    // above.
    $developer = $this->enrollDeveloper(4242);

    aRetiredAbilityEvent();

    DB::table('robot_council_events')->insert([
        'agent_session_id' => null,
        'user_id' => 'developer-1',
        'type' => FleetEventType::InstallationRevoked->value,
        'body' => 'claude-code on workbench was revoked.',
        'posted_with_coordinator' => false,
        'created_at' => now(),
    ]);

    runTheAbilityEventDeletion('up');

    Livewire::actingAs($developer)
        ->test(ChangeFeed::class)
        ->assertOk()
        ->assertSee('was revoked')
        ->assertDontSee('was granted tasks:create');
});
