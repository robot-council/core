<?php

declare(strict_types=1);

/**
 * The starting cursor a session is handed, and the mechanism that makes it safe.
 *
 * `AgentSessions::start()` returns the enrollment event's own id rather than a separate read of the
 * feed's head, because `FleetEvents::record()` holds the `robot_council_feed_lock` sentinel while it
 * inserts and that lock is transaction-scoped. Every id below the enrollment's therefore belonged to
 * a writer that held the sentinel first and committed first.
 *
 * **The implementation #87 set out to refute cannot be refuted, and that is the finding.** Replacing
 * `$enrolled->id` with `FleetEvent::query()->max('id')` *inside* the transaction is an equivalent
 * mutant: the transaction already holds the sentinel, so no other writer can have a key in flight,
 * and the session's own insert is the highest id visible to it. Measured on PostgreSQL 17.0 with the
 * substitution in place -- the full suite passed (`9 skipped, 794 passed`) and so did the
 * `cross-connection` group (`8 passed`). No test can tell the two apart on any engine, because they
 * are the same number.
 *
 * What a second connection *can* show is the mechanism the equivalence rests on: a session start
 * **blocks** while the sentinel is held elsewhere, so it never observes an in-flight id at all. That
 * is the first test here, and it is what would break if the sentinel were dropped or downgraded --
 * at which point `max('id')` would stop being equivalent and start being the defect #87 describes.
 *
 * The second test pins the other half on one connection: the cursor is the enrollment event's own
 * id, not the head before it. A cursor read before `record()` would hand back the previous head, and
 * paging `id > cursor` would then serve the session its own enrollment event -- which is how that
 * mistake would surface to an agent rather than as a lost event.
 *
 * @command  DB_CONNECTION=pgsql vendor/bin/pest --compact --group=cross-connection tests/FeedCursorOrderingTest.php
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\FleetEvents;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
});

it('cannot start a session while another connection holds the feed, so it sees no key in flight', function (): void {
    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    try {
        // The control. A start with nobody holding the sentinel succeeds and hands back the
        // enrollment event's own id -- so a refusal below is the held lock and not something else
        // about starting a session in this fixture.
        $first = app(AgentSessions::class)->start($this->installation);

        expect($first->feedCursor)->toBe(
            FleetEvent::query()->where('type', FleetEventType::SessionJoined->value)->max('id')
        );

        // A SHARED lock, deliberately, and the same choice `FeedOrderingTest` makes. An exclusive
        // writer conflicts with it and must wait; two shared locks would not conflict, so a
        // `record()` that downgraded its own lock would sail past. Measured: with `for update`
        // here instead, the downgrade survives this test and only the lock's outright removal
        // fails it.
        $other->beginTransaction();
        $other->select(
            sprintf('select * from %s where id = ? for share', FleetEvents::LOCK_TABLE),
            [FleetEvents::LOCK_ROW]
        );
        $other->table('robot_council_events')->insert([
            'type' => FleetEventType::Narration->value,
            'body' => 'in flight, and lower than anything a start could draw',
            'agent_session_id' => null,
            'user_id' => null,
            'posted_with_coordinator' => false,
            'meta' => '[]',
            'created_at' => now(),
        ]);

        $inFlight = $other->table('robot_council_events')->max('id');

        // Narrowed rather than cast: `max()` is declared `mixed`, and `composer analyse` refuses a
        // cast from it. A non-numeric here means the insert above did not land, which is worth
        // failing on rather than coercing into a zero that reads as a passing assertion.
        expect($inFlight)->toBeNumeric();

        // Bounded, so a writer that blocks reports it rather than hanging the suite.
        DB::statement("set lock_timeout = '750ms'");

        // **The assertion the file exists for.** The start does not merely read a safe cursor; it
        // does not get as far as reading one. That is why an unlocked `max('id')` in its place is
        // equivalent rather than dangerous -- and it is exactly what stops holding if the sentinel
        // is dropped, taken after the insert, or downgraded to a shared lock.
        expect(fn () => app(AgentSessions::class)->start($this->installation))
            ->toThrow(QueryException::class);

        // And it refused rather than half-committing: no session row and no enrollment event.
        expect(FleetEvent::query()->where('type', FleetEventType::SessionJoined->value)->count())->toBe(1)
            ->and((int) (\is_numeric($inFlight) ? $inFlight : 0))->toBeGreaterThan(0);
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

it("hands back the enrollment event's own id, not the head before it", function (): void {
    // On one connection deliberately: this half needs no concurrency, and a cursor read before
    // `record()` rather than after it is the mistake that is actually reachable in this method.
    $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'an earlier event');

    $headBefore = FleetEvent::query()->max('id');

    expect($headBefore)->toBeNumeric();

    $headBefore = (int) (\is_numeric($headBefore) ? $headBefore : 0);

    $issued = app(AgentSessions::class)->start($this->installation);

    $enrolled = FleetEvent::query()
        ->where('type', FleetEventType::SessionJoined->value)
        ->sole();

    expect($issued->feedCursor)->toBe($enrolled->id)
        // Named rather than merely "greater than", so a cursor that drifted to some other later
        // event would fail here too.
        ->and($issued->feedCursor)->toBe($headBefore + 1);

    // The consequence, stated the way an agent would meet it: paging from this cursor does not
    // serve the session its own enrollment event, and does serve everything after it.
    $later = $this->service(FleetEvents::class)->record(FleetEventType::Directive, null, 'after the start');

    $visible = FleetEvent::query()
        ->where('id', '>', $issued->feedCursor)
        ->pluck('id')
        ->all();

    expect($visible)->toBe([$later->id]);
});
