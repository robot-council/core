<?php

declare(strict_types=1);

/**
 * The standing guarantee that no migration declares a date column MySQL will rewrite behind it.
 *
 * With `explicit_defaults_for_timestamp` off, MySQL and MariaDB give the first `NOT NULL`
 * `TIMESTAMP` column in a table an implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`,
 * so any UPDATE to the row silently rewrites it. `robot_council_device_codes.expires_at` was such a
 * column: approving a device code is an UPDATE, so approval reset the code's own expiry and made it
 * impossible to exchange. #22 changed the columns to `dateTime`.
 *
 * **This reads the declaration rather than one engine's consequence, and that is the point.** The
 * guard that caught the original defect read `information_schema`, so it could only run on MySQL --
 * and when the `mysql` job was dropped it stopped running anywhere. The rule is about what the
 * migrations declare, not about what MySQL does with the declaration, so a scan of the source runs
 * on every engine, fails in the pull request that introduces the column rather than in whichever job
 * happens to have a database, and costs no CI time (#136).
 *
 * `EscapingGuardTest` is the precedent, including the shape of the first test here: a detector is
 * run against a corpus whose right answer is known before it is trusted on the real tree, because a
 * scanner whose expression never matches reports a clean tree in exactly the same words as a clean
 * tree.
 *
 * `robot-council:doctor` still asks `information_schema` at runtime, and that is the right place for
 * it: it answers for a host's live schema, including drift no source scan can see.
 *
 * @command  vendor/bin/pest --compact tests/MigrationTimestampGuardTest.php
 */

/**
 * A migration exercising every shape the scan has to tell apart.
 *
 * Written as source rather than as a fixture file so the expected classification sits beside the
 * declaration it belongs to.
 */
function timestampProbe(): string
{
    return <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('probe', function (Blueprint $table): void {
                    // A comment naming $table->timestamp('commented_out') is not a declaration.
                    $table->timestamp('bare');
                    $table->timestamp('nullable_bare')->nullable();
                    $table->timestamp('nullable_true')->nullable(true);
                    $table->timestamp('nullable_false')->nullable(false);
                    $table->timestamp('nullable_then_indexed')->nullable()->index();
                    $table->timestamp('use_current')->useCurrent();
                    $table->timestamp('across_lines')
                        ->nullable();
                    $table->timestampTz('tz_bare');
                    $table->timestampTz('tz_nullable')->nullable();

                    // None of these declare a `timestamp()` column, and all of them create
                    // nullable ones, which is not the shape that carries the implicit ON UPDATE.
                    $table->timestamps();
                    $table->timestampsTz();
                    $table->nullableTimestamps();
                    $table->softDeletes();

                    // The remedy, which must never be reported.
                    $table->dateTime('correct');
                });
            }
        };
        PHP;
}

it('tells a non-nullable timestamp from every declaration that is exempt', function (): void {
    // **The detector's control, run on every invocation rather than by hand once.** Every row below
    // was chosen because it is a way the scan could be wrong in the reassuring direction: a plural
    // helper read as a declaration, an exemption that only matches at the end of a chain, a chain
    // broken across lines, prose about the rule read as a breach of it.
    $directory = $this->temporaryDirectory('timestamp-probe');

    file_put_contents($directory.'/2026_01_01_000000_probe.php', timestampProbe());

    $classified = [];

    foreach (timestampColumnsIn($directory) as $column) {
        $classified[$column['column']] = $column['nullable'];
    }

    expect($classified)->toBe([
        'bare' => false,
        'nullable_bare' => true,
        'nullable_true' => true,

        // An explicit NOT NULL. The exemption is the absence of a null value, not the presence of
        // the method name, and a check written the other way passes this row for the wrong reason.
        'nullable_false' => false,
        'nullable_then_indexed' => true,
        'use_current' => false,
        'across_lines' => true,
        'tz_bare' => false,
        'tz_nullable' => true,
    ]);

    // Named separately from the assertion above, because `toBe()` on the map would pass if these
    // were reported under a name that happened not to collide.
    expect(array_keys($classified))
        ->not->toContain('commented_out')
        ->not->toContain('created_at')
        ->not->toContain('updated_at')
        ->not->toContain('deleted_at')
        ->not->toContain('correct');
});

it('reports an offender with the file, the column, and the remedy', function (): void {
    // The planted half of #136's both-halves criterion, and the reason the description is returned
    // rather than only rendered into a failure message: a message only a failing run prints is a
    // message no passing run has ever checked.
    $directory = $this->temporaryDirectory('timestamp-plant');

    $plant = <<<'PHP'
        <?php

        Schema::create('planted', function (Blueprint $table): void {
            $table->timestamp('expires_at');
        });
        PHP;

    file_put_contents($directory.'/2026_01_01_000000_plant.php', $plant);

    expect(nonNullableTimestampsIn($directory))->toBe([
        '2026_01_01_000000_plant.php declares `expires_at` as a non-nullable timestamp; declare it dateTime instead.',
    ]);

    // Removing the plant makes it green. Without this the test shows the scan can find something
    // and says nothing about whether it can find nothing, which is the reading the real scan below
    // depends on.
    file_put_contents($directory.'/2026_01_01_000000_plant.php', str_replace(
        "timestamp('expires_at')",
        "dateTime('expires_at')",
        $plant
    ));

    expect(nonNullableTimestampsIn($directory))->toBeEmpty();
});

it('declares no non-nullable timestamp column in the package migrations', function (): void {
    $directory = __DIR__.'/../database/migrations';

    // **Two controls before the assertion, for two different ways of reading clean.** The first
    // fails when the directory moves or the walk stops finding files; the second when the
    // expression stops matching. Either one leaves `nonNullableTimestampsIn()` empty, which is
    // byte-identical to a tree with nothing wrong in it.
    expect(phpSourcesIn($directory))->not->toBeEmpty();
    expect(timestampColumnsIn($directory))->not->toBeEmpty()
        ->and(nonNullableTimestampsIn($directory))->toBeEmpty();
});
