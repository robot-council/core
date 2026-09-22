<?php

declare(strict_types=1);

/**
 * Re-enrolling a harness ends the installation it replaces.
 *
 * Before #146 the previous installation stayed live, and its credential kept working for the whole
 * of `credentials.installation_max_age_days`. That is untidy for an ordinary re-enrollment and
 * wrong for the case that matters: a developer who re-enrolls **because they believe a credential
 * was exposed** has not invalidated it, and the act that felt like a remedy was not one (#106).
 *
 * **Every assertion about a credential here is made by using it**, never by reading `revoked_at`.
 * A column says what a row holds; only a request says what a credential can still do, and those are
 * different claims -- the token rows are deleted separately from the column being set, so a test
 * reading the column would pass on an implementation that revoked the installation and left its
 * tokens working.
 *
 * @command  vendor/bin/pest --compact tests/SupersededInstallationTest.php
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Installation;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $this->other = $this->enrollDeveloper(77, login: 'otherdev');
});

/**
 * Enroll a machine end to end and hand back the credential it was issued.
 *
 * Driven through the real endpoints rather than by writing rows, so the identity a supersede
 * matches on is the one the endpoints actually wrote.
 *
 * @param  TestCase  $case  The running test.
 * @param  User  $approver  The developer approving it.
 * @param  array<string, mixed>  $claims  What the requester says about itself.
 * @return string The installation credential.
 */
function enrollMachine(TestCase $case, User $approver, array $claims = []): string
{
    $enrollment = requestDeviceCode($case, overrides: $claims);

    $case->actingAs($approver, 'web')
        ->post(route('robot-council.enroll.approve'), [
            'user_code' => $enrollment['record']->user_code,
            'confirmed' => '1',
        ])
        ->assertRedirect();

    $response = $case->postJson(route('robot-council.device.token'), [
        'device_code' => $enrollment['device_code'],
        'code_verifier' => $enrollment['verifier'],
    ]);

    $response->assertCreated();

    return stringValue($response->json('token'));
}

/**
 * Whether a credential can still do the one thing an installation credential is for.
 *
 * @param  TestCase  $case  The running test.
 * @param  string  $credential  The installation credential to try.
 * @return int The status the service answered.
 */
function startsSession(TestCase $case, string $credential): int
{
    return $case->machine($credential)
        ->postJson(route('robot-council.sessions.start'), [])
        ->getStatusCode();
}

it('ends the credential it replaced, and leaves the replacement working', function (): void {
    $first = enrollMachine($this, $this->developer);

    // The control: before the re-enrollment this credential works. Without it, a test that finds it
    // refused afterwards cannot tell a supersede from a credential that never worked.
    expect(startsSession($this, $first))->toBe(201);

    $second = enrollMachine($this, $this->developer);

    // Both halves, in one test on purpose: an implementation that revoked everything would satisfy
    // the first expectation and fail the second, and one that revoked nothing does the reverse.
    expect(startsSession($this, $first))->toBe(401)
        ->and(startsSession($this, $second))->toBe(201);
});

it('leaves at most one live installation for the identity', function (): void {
    enrollMachine($this, $this->developer);
    enrollMachine($this, $this->developer);
    enrollMachine($this, $this->developer);

    expect(Installation::query()->count())->toBe(3)
        ->and(Installation::query()->whereNull('revoked_at')->count())->toBe(1);
});

it('records the supersede on the feed, as an ordinary revocation', function (): void {
    enrollMachine($this, $this->developer);
    enrollMachine($this, $this->developer);

    // The fleet learns about a superseded installation the same way it learns about one an admin
    // revoked. A supersede that wrote no event would be a credential going dead with nothing said.
    expect(FleetEvent::query()->where('type', FleetEventType::InstallationRevoked->value)->count())->toBe(1);
});

it('revokes nothing when the identity is new', function (): void {
    $credential = enrollMachine($this, $this->developer);

    expect(Installation::query()->count())->toBe(1)
        ->and(Installation::query()->whereNull('revoked_at')->count())->toBe(1)
        ->and(FleetEvent::query()->where('type', FleetEventType::InstallationRevoked->value)->count())->toBe(0)
        ->and(startsSession($this, $credential))->toBe(201);
});

it('leaves an installation alone when one part of the identity differs', function (string $axis, array $claims, ?string $approver): void {
    /** @var array<string, mixed> $claims */
    // **One case per axis, because a lookup matching on too little passes a single-axis test.**
    // Matching on `user_id` alone would pass the harness and label rows; matching on the claims
    // alone would pass the approver row. Only all three together survive every one.
    $first = enrollMachine($this, $this->developer);

    $second = enrollMachine(
        $this,
        $approver === 'other' ? $this->other : $this->developer,
        $claims
    );

    expect(startsSession($this, $first))->toBe(201, $axis.' should not have superseded the first')
        ->and(startsSession($this, $second))->toBe(201)
        ->and(Installation::query()->whereNull('revoked_at')->count())->toBe(2);
})->with([
    'a different harness' => ['a different harness', ['harness' => 'cursor'], null],
    'a different machine label' => ['a different machine label', ['machine_label' => 'workbench-02'], null],
    'a different approver' => ['a different approver', [], 'other'],
]);

it('names what an approval would end, and says nothing when there is nothing to end', function (): void {
    $enrollment = requestDeviceCode($this);

    // Nothing enrolled yet, so the page must not warn. Asserted first, so the positive case below
    // cannot pass by the page simply always warning.
    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]))
        ->assertOk()
        ->assertDontSee('Approving will end');

    enrollMachine($this, $this->developer);

    $next = requestDeviceCode($this);

    $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $next['record']->user_code]))
        ->assertOk()
        ->assertSee('Approving will end an existing installation')
        ->assertSee('--machine-label');
});

it('does not warn another developer about an installation that is not theirs', function (): void {
    enrollMachine($this, $this->developer);

    $enrollment = requestDeviceCode($this);

    // The identity is scoped to the approver, so the other developer approving the same claims is
    // creating their own first installation rather than replacing somebody else's.
    $this->actingAs($this->other, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]))
        ->assertOk()
        ->assertDontSee('Approving will end');
});

it('indexes the identity the supersede looks up', function (): void {
    // The lookup runs on every approval and holds what it reads. Asserted against the schema rather
    // than by reading the migration, so a migration that did not run reports as a failure here.
    $names = array_map(
        static fn (mixed $index): string => \is_array($index) ? stringValue($index['name'] ?? '') : '',
        Schema::getIndexes('robot_council_installations')
    );

    expect($names)->toContain('robot_council_installations_identity_index');
});
