<?php

declare(strict_types=1);

/**
 * That what one seat IS does not depend on the database engine.
 *
 * A seat is keyed on its installation, repository and work location, and `WorkIdentity` keeps a
 * repository's case: `UAMS-Web` is a real owner. So `UAMS-Web/x` and `uams-web/x` are two places,
 * and SQLite and PostgreSQL 17.0 said so from the start.
 *
 * **MySQL folded them into one, in two places, and each fix alone left it folded.** Measured on
 * MySQL 9.4.0 while building #322:
 *
 * - The seat table's unique key compared `repository` under the default collation, so
 *   `insertOrIgnore` dropped the second spelling silently. The migration now gives that column a
 *   binary collation.
 * - With that in place MySQL still recorded one seat, because `Support\Seats::forDeveloper()` read
 *   the places with a SQL `DISTINCT` over the SESSION table's `repository`, which has the default
 *   collation too. It de-duplicates in PHP now, byte for byte.
 *
 * Removing either one fails this test on MySQL; on the other two engines it passes either way. That
 * is why it is in the `engine-semantics` group: the `mysql` job is the only one that can fail it.
 *
 * @command  vendor/bin/pest --compact tests/SeatIdentityTest.php
 */

use RobotCouncil\Models\Seat;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\Seats;

pest()->group('engine-semantics');

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [5201]);
});

it('keeps two spellings of a repository as two seats, on every engine', function (): void {
    $developer = $this->enrollDeveloper(5201, login: 'seat-owner');
    $installation = $this->approveInstallation($developer)->refresh();

    $this->service(AgentSessions::class)->start($installation, 'UAMS-Web/site', 'a');
    $this->service(AgentSessions::class)->start($installation, 'uams-web/site', 'a');

    $seats = $this->service(Seats::class)->forDeveloper(HostKey::from($developer->getAuthIdentifier()));

    $repositories = array_map(static fn (Seat $seat): string => $seat->repository, $seats);
    sort($repositories, SORT_STRING);

    expect($repositories)->toBe(['UAMS-Web/site', 'uams-web/site']);
});

it("finds a session's own seat and not the other spelling's", function (): void {
    $developer = $this->enrollDeveloper(5201, login: 'seat-owner');
    $installation = $this->approveInstallation($developer)->refresh();
    $key = HostKey::from($developer->getAuthIdentifier());

    $upper = $this->service(AgentSessions::class)->start($installation, 'UAMS-Web/site', 'a')->owner;
    $lower = $this->service(AgentSessions::class)->start($installation, 'uams-web/site', 'a')->owner;

    $seats = $this->service(Seats::class);
    $seats->forDeveloper($key);

    $parked = $seats->of($upper);
    expect($parked)->not->toBeNull();

    if ($parked !== null) {
        $seats->park($key, $parked->id);
    }

    expect($seats->of($upper)?->repository)->toBe('UAMS-Web/site')
        ->and($seats->of($upper)?->isParked())->toBeTrue()
        ->and($seats->of($lower)?->repository)->toBe('uams-web/site')
        ->and($seats->of($lower)?->isParked())->toBeFalse();
});
