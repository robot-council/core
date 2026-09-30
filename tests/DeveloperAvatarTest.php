<?php

declare(strict_types=1);

/**
 * A developer's GitHub picture, as a circle beside their login (#410).
 *
 * The picture is the one sign-in stored, loaded by the browser: core asks GitHub nothing to show it,
 * and a stored value that is not on GitHub's avatar host is never put in a page. Each page-level
 * test pairs a developer with a picture and one without, so a page that dropped the fallback or
 * showed a picture it should not would fail one of the two.
 *
 * @command  vendor/bin/pest --compact tests/DeveloperAvatarTest.php
 */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RobotCouncil\Livewire\AccessLists;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Livewire\Agents;
use RobotCouncil\Livewire\ChangeFeed;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Livewire\Locks;
use RobotCouncil\Livewire\TaskBoard;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\GitHubAccounts;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\OwedItems;
use RobotCouncil\Support\Tasks;
use Symfony\Component\Finder\Finder;

const PICTURED = 'https://avatars.githubusercontent.com/u/4242?v=4';

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242, 4343, 77], admins: [4242]);

    // One developer with a picture, one without
    $this->pictured = $this->enrollDeveloper(4242, login: 'octodev');
    $this->plain = $this->enrollDeveloper(4343, login: 'hubot');
    GithubIdentity::query()->where('github_id', 4242)->update(['avatar_url' => PICTURED]);

    [$this->coordinatorSession] = $this->startCoordinatorSession(
        $this->approveInstallation($this->enrollDeveloper(77, login: 'coordinator'), machineLabel: 'coordinator-box')
    );

    foreach (['octodev' => $this->pictured, 'hubot' => $this->plain] as $login => $developer) {
        $session = $this->service(AgentSessions::class)->start($this->approveInstallation($developer), 'robot-council/core', 'a')->owner;
        $this->service(Tasks::class)->create($session, ['title' => 'Work for '.$login], false);
    }
});

/**
 * The avatars on a page, each as the login's initial and the picture it loads, if any.
 *
 * @param  string  $html  The page.
 * @return list<array{initial: string, src: string|null}> The avatars, in page order.
 */
function avatarsIn(string $html): array
{
    // Read by the component's exact shape, the markers Livewire writes round a condition included,
    // so an avatar whose markup changed is not counted rather than half-read
    $markers = '(?:<!--\[if [A-Z]+\]><!\[endif\]-->)*';

    preg_match_all(
        '#<span class="avatar [^"]*" aria-hidden="true" data-avatar><span[^>]*><span class="text-meta leading-none select-none">([^<]*)</span>'.$markers.'(?:<img src="([^"]*)" alt=""[^>]*>)?'.$markers.'</span></span>#',
        $html,
        $found,
        PREG_SET_ORDER
    );

    return array_map(static fn (array $avatar): array => [
        'initial' => $avatar[1],
        'src' => ($avatar[2] ?? '') !== '' ? html_entity_decode($avatar[2]) : null,
    ], $found);
}

it('shows the stored picture for a developer who has one, and the initial alone for one who has none', function (): void {
    $html = Blade::render('<x-robot-council::avatar login="octodev" />|<x-robot-council::avatar login="hubot" />');

    expect(avatarsIn($html))->toBe([['initial' => 'O', 'src' => PICTURED], ['initial' => 'H', 'src' => null]])
        // Decorative, since the login beside it names the developer
        ->and($html)->toContain('<img src="'.PICTURED.'" alt=""')
        ->and(substr_count($html, 'aria-hidden="true" data-avatar'))->toBe(2)
        // A failed load leaves the initial: the dashboard's script takes the picture away, which
        // the browser suite shows, and the markup carries no inline handler of its own
        ->and($html)->not->toContain('onerror')
        ->and($html)->not->toContain('x-on:');
});

it('renders nothing for an account with no login, since no developer is named', function (): void {
    expect(trim(Blade::render('<x-robot-council::avatar :login="null" />')))->toBeEmpty()
        ->and(trim(Blade::render('<x-robot-council::avatar login="" />')))->toBeEmpty();
});

it('never puts a stored picture that is not on GitHub avatar host in a page', function (string $stored): void {
    GithubIdentity::query()->where('github_id', 4242)->update(['avatar_url' => $stored]);

    $html = Blade::render('<x-robot-council::avatar login="octodev" />');

    expect(avatarsIn($html))->toBe([['initial' => 'O', 'src' => null]])
        ->and($html)->not->toContain('<img');
})->with([
    'another host' => ['https://evil.example/u/4242?v=4'],
    'a lookalike host' => ['https://avatars.githubusercontent.com.evil.example/u/4242'],
    'a script' => ['javascript:alert(1)'],
    'plain HTTP' => ['http://avatars.githubusercontent.com/u/4242?v=4'],
    'another path' => ['https://avatars.githubusercontent.com/u/4242/../../x'],
    'more query' => ['https://avatars.githubusercontent.com/u/4242?v=4&x=1'],
    'a trailing newline' => ["https://avatars.githubusercontent.com/u/4242?v=4\n"],
]);

it('matches a login in any case, since GitHub logins are not case-sensitive', function (): void {
    expect(GitHubAccounts::avatarOf('OctoDev'))->toBe(PICTURED)
        ->and(GitHubAccounts::avatarOf('hubot'))->toBeNull()
        ->and(GitHubAccounts::avatarOf('nobody-here'))->toBeNull()
        ->and(GitHubAccounts::avatarOf(null))->toBeNull();
});

it("shows each developer's avatar on every page that names them, and asks GitHub nothing to do it", function (string $component): void {
    Http::fake();
    Http::preventStrayRequests();

    $html = Livewire::actingAs($this->pictured)->test($component)->html();

    $avatars = avatarsIn($html);

    expect($avatars)->toContain(['initial' => 'O', 'src' => PICTURED])
        ->and($avatars)->toContain(['initial' => 'H', 'src' => null]);

    Http::assertNothingSent();
})->with([
    'lanes' => [Lanes::class],
    'agents' => [Agents::class],
    'queue' => [TaskBoard::class],
    'change feed' => [ChangeFeed::class],
    'administration' => [Administration::class],
]);

it("shows a lock holder's avatar", function (): void {
    Http::fake();

    $this->service(RobotCouncil\Support\Locks::class)->acquire(
        AgentSession::query()->where('user_id', HostKey::from($this->pictured->getAuthIdentifier()))->firstOrFail(),
        'branch:feature/avatars',
        600,
        false
    );

    expect(avatarsIn(Livewire::actingAs($this->pictured)->test(Locks::class)->html()))->toContain(['initial' => 'O', 'src' => PICTURED]);

    Http::assertNothingSent();
});

it("shows the avatar in each developer's section heading on the lane board, and none for General", function (): void {
    $owed = $this->service(OwedItems::class);
    $owed->record($this->coordinatorSession, 'octodev', 'robot-council/core#12', 'Which option?', 'Blocks two lanes.');
    $owed->record($this->coordinatorSession, 'hubot', 'robot-council/core#13', 'Which option?', 'Blocks a lane.');
    $owed->record($this->coordinatorSession, null, 'robot-council/core#14', 'Which option?', 'Blocks nothing yet.');

    $html = Livewire::actingAs($this->pictured)->test(Lanes::class)->html();

    // Each section's own heading, found by the section's marker
    preg_match_all('#data-owed-section="[^"]*">\s*<h3 class="text-lg font-semibold">(.*?)</h3>#s', $html, $headings);

    $byName = [];

    foreach ($headings[1] as $heading) {
        // The name alone, without the item count the heading carries after it (#390)
        $name = (string) preg_replace('#<span[^>]*data-owed-count>.*?</span>#s', '', withoutAvatars($heading));
        $byName[trim(strip_tags($name))] = avatarsIn($heading);
    }

    expect($byName)->toBe([
        'General' => [],
        'hubot' => [['initial' => 'H', 'src' => null]],
        'octodev' => [['initial' => 'O', 'src' => PICTURED]],
    ]);
});

it('shows the picture beside a signed-in account on the Access page, and only the initial for one not signed in', function (): void {
    // An account that has not signed in, whose login GitHub last gave as `octodev` -- the login a
    // signed-in developer with a picture still holds on their identity row, as after a rename.
    // Whose face that is, is not this account's to show.
    $this->setAccessLists(developers: [4242, 4343, 77, 9999], admins: [4242]);
    DB::table('robot_council_github_accounts')->insert([
        'github_id' => 9999, 'login' => 'octodev', 'account_type' => 'User', 'checked_at' => now(), 'resolved_at' => now(),
    ]);

    $html = Livewire::actingAs($this->pictured)->test(AccessLists::class)->html();

    preg_match('#<li[^>]*-9999"[^>]*>.*?</li>#s', $html, $unsigned);
    preg_match('#<li[^>]*-4242"[^>]*>.*?</li>#s', $html, $signed);

    expect(avatarsIn($unsigned[0] ?? ''))->toBe([['initial' => 'O', 'src' => null]])
        ->and(avatarsIn($signed[0] ?? ''))->toBe([['initial' => 'O', 'src' => PICTURED]]);
});

it("shows the signed-in developer's avatar in the header, beside their login", function (): void {
    Http::fake();

    $html = (string) $this->actingAs($this->pictured, 'web')->get(route('robot-council.dashboard'))->assertOk()->getContent();

    preg_match('#<header.*?</header>#s', $html, $header);

    expect(avatarsIn($header[0] ?? ''))->toBe([['initial' => 'O', 'src' => PICTURED]])
        ->and(withoutAvatars($header[0] ?? ''))->toMatch('#sm:inline">octodev</span>#');

    Http::assertNothingSent();
});

it('draws every avatar through the one component', function (): void {
    $components = 0;
    $images = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../resources/views')->name('*.blade.php') as $file) {
        $components += substr_count($file->getContents(), '<x-robot-council::avatar ');

        if (preg_match('/<img\b/i', $file->getContents()) === 1) {
            // Forward slashes on every platform, since Windows reads the path with backslashes
            $images[] = str_replace('\\', '/', $file->getRelativePathname());
        }
    }

    expect($components)->toBeGreaterThan(10)
        ->and($images)->toBe(['components/avatar.blade.php']);
});

it('costs no query per developer: at most one read of every picture, whatever a page names', function (): void {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    foreach (['octodev', 'hubot', 'nobody-here', 'OCTODEV'] as $login) {
        GitHubAccounts::avatarOf($login);
    }

    expect($queries)->toBe(1);
});

it('reads no picture of its own on a page whose logins are read through AgentLogins', function (): void {
    /** @var list<string> $read */
    $read = [];
    DB::listen(function (QueryExecuted $query) use (&$read): void {
        $read[] = $query->sql;
    });

    $avatars = avatarsIn(Livewire::actingAs($this->pictured)->test(Agents::class)->html());

    // Every identity read carried a `where`: the page's own, which noted the pictures, and never the
    // read of every developer that a login nobody noted would cost
    $identityReads = array_values(array_filter($read, static fn (string $sql): bool => str_contains($sql, 'robot_council_github_identities')));

    expect($avatars)->toContain(['initial' => 'O', 'src' => PICTURED])
        ->and($identityReads)->not->toBeEmpty()
        ->and(array_filter($identityReads, static fn (string $sql): bool => ! str_contains($sql, ' where ')))->toBeEmpty();
});
