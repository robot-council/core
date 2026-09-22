<?php

declare(strict_types=1);

/**
 * The feed's ordering guarantee, which only a second connection can show.
 *
 * An agent without a push connection pages this feed by an ID cursor, so the order rows become
 * visible has to match the order of their IDs. Both Postgres and InnoDB draw the key at INSERT
 * time, outside the transaction's ordering, so two writers can take 5 and 6 and commit 6 first --
 * and a reader that polls in between passes 6 and never sees 5 again, permanently, because paging
 * is `id > cursor`.
 *
 * `FleetEvents::record()` takes a row lock on a single sentinel row before inserting. These tests
 * are built to fail against the two changes that would quietly remove the guarantee:
 *
 * 1. **Taking the lock after the insert.** Caught by the sequence: a key drawn before the lock is
 *    a key already drawn, and a sequence is not rolled back. The first test asserts no key moved.
 * 2. **Downgrading to a shared lock.** Caught by holding a shared lock on the other connection and
 *    requiring the writer to block on it -- two shared locks would not conflict, and the write
 *    would succeed.
 *
 * The `cross-connection` group runs only in CI's `postgres` job: SQLite serializes writers and
 * gives each connection its own in-memory database, so neither test can run there.
 *
 * @command  DB_CONNECTION=pgsql vendor/bin/pest --compact --group=cross-connection
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\FleetEvents;

/**
 * How many keys the events table has handed out.
 *
 * Read from the sequence rather than from the table, because that is the number that survives a
 * rollback, and surviving a rollback is exactly what makes an early-drawn key dangerous.
 *
 * `is_called` is read alongside `last_value` and is not a detail: a sequence that has never been
 * drawn from reports `last_value = 1, is_called = false`, and the first draw takes that 1 and sets
 * `is_called`. Reading `last_value` alone therefore shows no movement across the very first insert
 * -- which is how the control in the first test caught this being written the naive way.
 *
 * @return int The number of keys drawn so far.
 */
function keysDrawn(): int
{
    $sequence = (array) DB::selectOne("select pg_get_serial_sequence('robot_council_events', 'id') as name");

    $name = \is_string($sequence['name'] ?? null) ? $sequence['name'] : '';

    // The name comes from the server, not from input
    $value = (array) DB::selectOne(sprintf('select last_value, is_called from %s', $name));

    if (($value['is_called'] ?? false) !== true) {
        return 0;
    }

    return \is_numeric($value['last_value'] ?? null) ? (int) $value['last_value'] : 0;
}

it('draws no key while another connection holds the feed', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    try {
        // The control. A normal write advances the sequence by exactly one, which proves the
        // sequence is the right thing to watch and that `record()` reaches an insert at all. If
        // this fails, every assertion below is meaningless rather than reassuring.
        $before = keysDrawn();

        $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'the control');

        $held = keysDrawn();

        expect($held)->toBe($before + 1);

        // A SHARED lock, deliberately. An exclusive writer conflicts with it and must wait; two
        // shared locks would not conflict, so a package that downgraded its own lock would sail
        // through and fail this test.
        $other->beginTransaction();
        $other->select(sprintf('select * from %s where id = ? for share', FleetEvents::LOCK_TABLE), [FleetEvents::LOCK_ROW]);

        // Bounded, so a writer that blocks reports it rather than hanging the suite
        DB::statement("set lock_timeout = '750ms'");

        expect(fn () => $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'the blocked writer'))
            ->toThrow(QueryException::class);

        // The assertion the whole file exists for. The writer never reached its insert, so no key
        // was drawn -- and had the lock been taken after the insert, a key would have been drawn,
        // and would have stayed drawn through the rollback.
        expect(keysDrawn())->toBe($held)
            ->and(FleetEvent::query()->where('body', 'the blocked writer')->exists())->toBeFalse();
    } finally {
        DB::statement('set lock_timeout = default');

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }

        DB::purge("{$default}_other");
    }
})->group('cross-connection')
    ->skip(notPostgres(...), 'Postgres only: this file sets `lock_timeout`, which MySQL spells differently, so elsewhere it stalls rather than failing.');

it('writes again as soon as the other connection lets go', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    try {
        $other->beginTransaction();
        $other->select(sprintf('select * from %s where id = ? for update', FleetEvents::LOCK_TABLE), [FleetEvents::LOCK_ROW]);

        DB::statement("set lock_timeout = '500ms'");

        // Held: the writer cannot get in
        expect(fn () => $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'blocked'))
            ->toThrow(QueryException::class);

        // Released by a rollback, with no hook to forget, which is why the lock is a row rather
        // than something the package has to remember to let go of
        $other->rollBack();

        $event = $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'unblocked');

        expect($event->body)->toBe('unblocked')
            ->and(FleetEvent::query()->where('body', 'blocked')->exists())->toBeFalse();
    } finally {
        DB::statement('set lock_timeout = default');

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }

        DB::purge("{$default}_other");
    }
})->group('cross-connection')
    ->skip(notPostgres(...), 'Postgres only: this file sets `lock_timeout`, which MySQL spells differently, so elsewhere it stalls rather than failing.');

it('refuses to write the feed when the sentinel row it orders by is missing', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // Not in the `cross-connection` group: this is about the lock being TAKEN, which one connection
    // can show, rather than about what it excludes, which needs two.
    //
    // `first()` on a row that is not there returns null and locks nothing, so without this guard a
    // host that truncated `robot_council_feed_lock` -- or restored it without its one row -- would
    // keep writing a feed whose ordering had silently stopped holding. Nothing at runtime tells the
    // two states apart, which is why it has to be loud here.
    DB::table(FleetEvents::LOCK_TABLE)->where('id', FleetEvents::LOCK_ROW)->delete();

    expect(fn (): FleetEvent => $this->service(FleetEvents::class)
        ->record(FleetEventType::Directive, null, 'Written with no lock to take.'))
        ->toThrow(RuntimeException::class);

    // And it refused rather than merely complaining: nothing reached the table
    expect(FleetEvent::query()->count())->toBe(0);
});

it('writes the feed when the sentinel row is present', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // The control for the test above. Same call, same fixture, one row different -- so the refusal
    // there is the missing sentinel and not something else about recording a directive.
    expect(DB::table(FleetEvents::LOCK_TABLE)->where('id', FleetEvents::LOCK_ROW)->count())->toBe(1);

    $event = $this->service(FleetEvents::class)
        ->record(FleetEventType::Directive, null, 'Written with the lock held.');

    expect($event->id)->toBeGreaterThan(0)
        ->and(FleetEvent::query()->count())->toBe(1);
});
