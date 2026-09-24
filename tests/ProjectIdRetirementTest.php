<?php

declare(strict_types=1);

/**
 * That a session's `project_id` is gone -- from the schema, from the model, and from the wire.
 *
 * **The column outlived its replacement by design, and `robot-council/cli#137` is what ended the
 * wait.** `robot-council/core#234` split the one label into `repository` and `work_location` and
 * kept it, because nothing had yet seen a deployed fleet store the two fields. Measured against the
 * production deployment on 2026-09-24, running `core` v0.4.0, fifteen sessions answered both fields
 * from the checkout, and `project_id` was null on every one a current client started from a
 * readable checkout.
 *
 * **The asymmetry that decided the order, stated because it is the reverse of `granted_abilities`'s.**
 * There, the risk was a column dropped too early: nothing derives what an administrator once
 * granted. Here the value is derivable in the direction that matters -- `#234`'s backfill has
 * already written the two fields from it -- so what a drop costs is the rows the split DECLINED to
 * guess at, which are the ones no reader could use either. `tests/SessionWorkIdentityTest.php`
 * holds that split and the legacy translation that survives it.
 *
 * **What this file does NOT assert, said so it is not over-read.** It does not scan `src/` for the
 * string, the way `tests/GrantedAbilitiesRetirementTest.php` can. `robot_council_tasks.project_id`
 * is a live column with live readers, and `Http\Controllers\SessionStartController` legitimately
 * reads the key off a legacy REQUEST, so a file-level scan would report four files doing their job
 * and could not express "a session's". The schema, the model's attribute bag, and the response body
 * are the three places where the property is exactly stateable.
 *
 * @command  vendor/bin/pest --compact tests/ProjectIdRetirementTest.php
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
});

/**
 * Run the drop migration in one direction.
 *
 * Narrowed rather than called as `$migration->up()`, for the reason `tests/EventIndexDropTest.php`
 * records: a migration file returns `mixed` to the analyzer, and `Migration` declares no `up()` --
 * the anonymous class the file returns does.
 *
 * @param  string  $direction  `up` or `down`.
 */
function runTheProjectIdMigration(string $direction): void
{
    $migration = require __DIR__.'/../database/migrations/2026_09_24_000002_drop_project_id_from_robot_council_agent_sessions.php';

    $run = [$migration, $direction];

    if (! \is_callable($run)) {
        throw new RuntimeException('The migration file did not return something with a '.$direction.'().');
    }

    $run();
}

/**
 * Whether the sessions table still carries the column.
 */
function sessionProjectIdPresent(): bool
{
    return Schema::hasColumn('robot_council_agent_sessions', 'project_id');
}

it('leaves all three populations in the same state', function (): void {
    // **The three a host can be in, driven through the migration's own `up()` and `down()`.** A
    // host that installed before `#234` had the column from the create migration; one that
    // installed after it still had it, because `#234` added two columns beside it rather than
    // replacing it; and one that rolled this back has it restored. All three run the same file,
    // and `CLAUDE.md` records what happens when a migration assumes one of them.
    expect(sessionProjectIdPresent())->toBeFalse();

    // Rolled back: a host that took this and then reversed it.
    runTheProjectIdMigration('down');

    expect(sessionProjectIdPresent())->toBeTrue();

    // Migrated again: the population that re-runs it. A guard reading `hasColumn` the wrong way
    // round would drop nothing here and report success.
    runTheProjectIdMigration('up');

    expect(sessionProjectIdPresent())->toBeFalse();

    // And `up()` against a database that has already taken it is a no-op rather than an error,
    // which is the population an unguarded `dropColumn` stops the whole batch on.
    runTheProjectIdMigration('up');

    expect(sessionProjectIdPresent())->toBeFalse();

    // As is `down()` against one that already has it back, which is the same guard read the other
    // way and the one an `ALTER` would fail on with a duplicate-column error.
    runTheProjectIdMigration('down');
    runTheProjectIdMigration('down');

    expect(sessionProjectIdPresent())->toBeTrue();

    runTheProjectIdMigration('up');
});

it('restores the column nullable and empty, which is where the old label ends', function (): void {
    // **`down()` restores the column and not its contents, and that is the half worth pinning.**
    // Every session predating the drop had its label split into the two fields before this file
    // ran, so the direction that matters survives; the label itself does not come back, and a
    // reader who expected a rollback to be lossless would find out here rather than on a host.
    [$session] = $this->startAgentSession($this->installation);

    runTheProjectIdMigration('down');

    $restored = DB::table('robot_council_agent_sessions')->where('id', $session->getKey())->value('project_id');

    expect($restored)->toBeNull();

    // Nullable, asserted by writing a null rather than by reading a schema field whose spelling
    // differs per engine. The create migration declares it nullable and `down()` restates that;
    // a rollback that changed it would leave a host in a state no migration describes.
    DB::table('robot_council_agent_sessions')->where('id', $session->getKey())->update(['project_id' => null]);

    // The control beside it: the column really is there and really does hold a value, so the null
    // above is the column accepting one rather than the write going nowhere.
    DB::table('robot_council_agent_sessions')->where('id', $session->getKey())->update(['project_id' => 'owner/name/a']);

    expect(DB::table('robot_council_agent_sessions')->where('id', $session->getKey())->value('project_id'))
        ->toBe('owner/name/a');

    runTheProjectIdMigration('up');

    expect(sessionProjectIdPresent())->toBeFalse();
});

it('carries no project_id on a session model, its casts, or its row', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    expect($session->getAttributes())->not->toHaveKey('project_id')
        ->and($session->getCasts())->not->toHaveKey('project_id')
        ->and($session->fresh()?->getAttributes())->not->toHaveKey('project_id')

        // Read off the row rather than the instance, which reports whatever PHP handed in. The
        // control for the read itself is the key beside it: `repository` IS in the row, so an
        // absent `project_id` is the column being gone rather than the query returning nothing.
        ->and((array) DB::table('robot_council_agent_sessions')->where('id', $session->getKey())->first())
        ->not->toHaveKey('project_id')
        ->toHaveKey('repository');
});

it('serves no project_id to the agent that asks what its own session is', function (): void {
    // **Absent, not null.** The two are different answers to a client, and
    // `assertJsonPath('project_id', null)` passes for both -- which is exactly the distinction
    // `robot-council/cli#137` leaned on when it read the production fleet, where a present-and-null
    // `repository` was told apart from a key the service no longer sends.
    [, $token] = $this->startAgentSession($this->installation);

    $response = $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonMissingPath('project_id')

        // The control: a key that IS served reads as present, so the assertion above is the key's
        // absence rather than a body that could not be read.
        ->assertJsonPath('repository', null);

    expect(arrayValue($response->json()))->not->toHaveKey('project_id')
        ->toHaveKey('repository')
        ->toHaveKey('work_location');
});
