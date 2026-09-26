<?php

declare(strict_types=1);

/**
 * The running robot-council/core release, at the foot of every page (#405).
 *
 * @command  vendor/bin/pest --compact tests/CoreVersionTest.php
 */

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Http;
use RobotCouncil\Support\CoreVersion;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
});

it('describes a tagged install by its tag, and anything else by its branch and short commit', function (?string $version, ?string $reference, string $line): void {
    expect(CoreVersion::describe($version, $reference))->toBe($line);
})->with([
    'a tag' => ['v0.7.1', '0924157637890', 'robot-council/core v0.7.1'],
    'a tag without the v' => ['0.7.1', null, 'robot-council/core 0.7.1'],
    'a pre-release tag' => ['v1.0.0-beta.2', '0924157637890', 'robot-council/core v1.0.0-beta.2'],
    'a branch' => ['dev-main', '09241576378905c3016b44535994c447f46bc6a5', 'robot-council/core dev-main (0924157)'],
    'a numbered branch' => ['1.x-dev', 'abcdef0123456789', 'robot-council/core 1.x-dev (abcdef0)'],
    'a branch with no commit recorded' => ['dev-main', null, 'robot-council/core dev-main (commit unknown)'],
    'a branch with a reference that is not a commit' => ['dev-main', 'not-a-sha', 'robot-council/core dev-main (commit unknown)'],
    'a root package with no version' => ['1.0.0+no-version-set', null, 'robot-council/core 1.0.0+no-version-set (commit unknown)'],
    'nothing installed' => [null, null, 'robot-council/core, version unknown'],
]);

it('reads the running version from the install record, with no request made', function (): void {
    Http::preventStrayRequests();

    $expected = CoreVersion::describe(
        InstalledVersions::getPrettyVersion(CoreVersion::PACKAGE),
        InstalledVersions::getReference(CoreVersion::PACKAGE),
    );

    // The control: this suite runs from a checkout, which Composer records as a branch
    expect(CoreVersion::current())->toBe($expected)
        ->and($expected)->toMatch('/^robot-council\/core \S+( \((?:[0-9a-f]{7}|commit unknown)\))?$/');

    Http::assertNothingSent();
});

/**
 * The footer a page renders, as text.
 */
function versionFooter(string $html): ?string
{
    if (preg_match('/<footer [^>]*data-core-version>(.*?)<\/footer>/s', $html, $footer) !== 1) {
        return null;
    }

    return trim($footer[1]);
}

it('shows the version at the foot of an authenticated page and the standalone pages alike', function (string $route, bool $signedIn): void {
    if ($signedIn) {
        $this->actingAs($this->developer, 'web');
    }

    $html = (string) $this->get(route($route))->getContent();

    expect(versionFooter($html))->toBe(e(CoreVersion::current()))
        // Plain text in the page's own color, not a dimmed line
        ->and($html)->not->toMatch('/<footer [^>]*opacity-/');
})->with([
    'the overview' => ['robot-council.dashboard', true],
    'the queue' => ['robot-council.queue', true],
    'the enrollment page' => ['robot-council.enroll.show', true],
    'signed out' => ['robot-council.signed-out', false],
    'sign-in expired' => ['robot-council.auth.callback', false],
]);
