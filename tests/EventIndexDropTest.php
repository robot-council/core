<?php

declare(strict_types=1);

/**
 * That the migration removing the events table's two composite indexes finds them on a database
 * that already has them.
 *
 * **A fresh install never has them, so the migration is a no-op in this suite unless a test plants
 * them.** Without that, "the migration ran and the indexes are absent" is satisfied by a migration
 * that does nothing at all -- which is the shape it would have if `present()` never matched.
 *
 * The migration exists because robot-council/core#94 removed those indexes by editing the create
 * migration, which `CLAUDE.md` permitted only while no host had run it. One had.
 *
 * @command  vendor/bin/pest --compact tests/EventIndexDropTest.php
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    // The package's own tables, which is what this migration edits
    $this->migrateUsersTableWithPackageColumns();
});

/**
 * The events table's indexes, as lists of lower-cased column names.
 *
 * @return list<list<string>> The indexes.
 */
function eventIndexes(): array
{
    return array_values(array_map(
        fn (mixed $index): array => array_values(array_map(
            fn (mixed $column): string => strtolower(stringValue($column)),
            arrayValue(arrayValue($index)['columns'] ?? null),
        )),
        Schema::getIndexes('robot_council_events'),
    ));
}

/**
 * Run the drop migration against whatever the table currently looks like.
 */
function runTheDrop(): void
{
    $migration = require __DIR__.'/../database/migrations/2026_09_18_000007_drop_robot_council_event_indexes.php';

    // Called as a narrowed callable rather than as `$migration->up()`. A migration file returns
    // `mixed` to the analyzer, and `Migration` itself declares no `up()` -- the anonymous class the
    // file returns does. `is_callable` is true of both, and says so without a suppression.
    $up = [$migration, 'up'];

    if (! \is_callable($up)) {
        throw new RuntimeException('The migration file did not return something with an up().');
    }

    $up();
}

it('drops both composite indexes from a database that already carries them', function (): void {
    // Changes the schema, which MySQL commits implicitly under a test transaction (#473)
    $this->leaveTestTransaction();
    // Planted, because this is the only state where the migration has anything to do -- and the
    // state every host that installed before #94 is actually in
    Schema::table('robot_council_events', function (Blueprint $table): void {
        $table->index(['user_id', 'id']);
        $table->index(['type', 'id']);
    });

    expect(eventIndexes())->toContain(['user_id', 'id'])
        ->and(eventIndexes())->toContain(['type', 'id']);

    runTheDrop();

    expect(eventIndexes())->not->toContain(['user_id', 'id'])
        ->and(eventIndexes())->not->toContain(['type', 'id'])

        // And it took only what it was asked for: the primary key is still there
        ->and(eventIndexes())->toContain(['id']);
});

it('does nothing, rather than failing, on a database that never had them', function (): void {
    // Runs a migration, which MySQL commits implicitly under a test transaction (#473)
    $this->leaveTestTransaction();
    // The state of any host installing after #94. `dropIndex` on an index that is not there is an
    // error on every engine, so a migration that assumed presence would break exactly the installs
    // that need it least.
    expect(eventIndexes())->not->toContain(['user_id', 'id']);

    $before = eventIndexes();

    runTheDrop();

    expect(eventIndexes())->toBe($before);
});

it('drops one when only one is present', function (): void {
    // Changes the schema, which MySQL commits implicitly under a test transaction (#473)
    $this->leaveTestTransaction();
    // A half-migrated database, which is what a failed earlier run leaves behind
    Schema::table('robot_council_events', function (Blueprint $table): void {
        $table->index(['type', 'id']);
    });

    runTheDrop();

    expect(eventIndexes())->not->toContain(['type', 'id'])
        ->and(eventIndexes())->toContain(['id']);
});
