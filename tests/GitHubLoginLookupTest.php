<?php

declare(strict_types=1);

/**
 * Logins looked up on GitHub for the Access page, and adding an account by its login (#484).
 *
 * GitHub is faked by `fakeGitHubProfiles()`, which refuses every request it does not model, and
 * which records every request sent, so "no request" is a count read off the fake rather than an
 * absence of errors. Every "it was stored" assertion reads a table.
 *
 * @command  vendor/bin/pest --compact tests/GitHubLoginLookupTest.php
 */

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RobotCouncil\Access\AccessList;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Livewire\AccessLists;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\AllowlistEntries;
use RobotCouncil\Support\GitHubAccounts;
use RobotCouncil\Support\GitHubRefusal;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // An environment administrator who has signed in, and an environment developer who has not
    $this->setAccessLists(developers: [4242, 21082715], admins: [4242]);
    $this->admin = $this->enrollDeveloper(4242, login: 'octoadmin');

    configureProfileLookups();

    $this->accounts = [
        21082715 => ['login' => 'todd-uams'],
        5150 => ['login' => 'new-dev'],
        // An organization, and a person whose login is another account's ID
        6060 => ['login' => 'an-org', 'type' => 'Organization'],
        7070 => ['login' => '5150'],
        9191 => ['login' => 'solo-dev'],
        // An organization whose ID is a person's login
        8080 => ['login' => 'another-org', 'type' => 'Organization'],
        1111 => ['login' => '8080'],
    ];
    $this->github = fakeGitHubProfiles($this->accounts);
});

/**
 * The login remembered for an ID, read from the table.
 *
 * @param  int  $githubId  The ID.
 * @return string|null The login, or null when none is remembered.
 */
function rememberedLogin(int $githubId): ?string
{
    $login = DB::table('robot_council_github_accounts')->where('github_id', $githubId)->value('login');

    return is_string($login) ? $login : null;
}

/**
 * An override that fails only the profile requests, answering the installation and token ones.
 *
 * Aimed at the profile path on purpose: failing every path is refused at the installation lookup,
 * before any profile is asked for, and would leave `GitHubApp::account()`'s own handling untested.
 *
 * @param  Closure(): mixed  $failure  What a profile request meets.
 * @return Closure(string): mixed The override.
 */
function failProfiles(Closure $failure): Closure
{
    return static fn (string $path): mixed => preg_match('#^/users?/[^/]+$#', $path) === 1 ? $failure() : null;
}

/**
 * The requests the fake answered for profiles, leaving out the installation and token requests.
 *
 * @param  ArrayObject<string, mixed>  $github  The fake's state.
 * @return list<string> The profile requests.
 */
function profileRequests(ArrayObject $github): array
{
    return array_values(array_filter(
        array_map(stringValue(...), arrayValue($github['sent'])),
        static fn (string $request): bool => preg_match('#^GET /users?/[^/]+$#', $request) === 1
    ));
}

it('shows the login of an allowlisted ID that has not signed in, marked, linked, and read from the table', function (): void {
    // The control: before a lookup, today's words
    $before = Livewire::actingAs($this->admin)->test(AccessLists::class)->html();

    expect($before)->toContain('not signed in yet')
        ->and($before)->not->toContain('todd-uams')
        ->and(Artisan::call('robot-council:refresh-github-logins'))->toBe(0)
        ->and(rememberedLogin(21082715))->toBe('todd-uams');

    $html = Livewire::actingAs($this->admin)->test(AccessLists::class)->html();

    expect($html)->toContain('<a href="https://github.com/todd-uams" class="link" target="_blank" rel="noopener noreferrer">todd-uams<svg')
        ->and($html)->toMatch('#todd-uams<svg.*?</svg><span class="sr-only"> \(opens in a new tab\)</span></a>\s*\(not signed in yet\)#')
        // A signed-in account is named by its own sign-in, unmarked and unlinked
        ->and($html)->toContain('octoadmin')
        ->and($html)->not->toContain('https://github.com/octoadmin');
});

it('makes no GitHub request to render the page, however many logins are remembered', function (): void {
    expect(Artisan::call('robot-council:refresh-github-logins'))->toBe(0);

    // The control: the refresh itself did ask, so the counter sees requests when there are some
    expect(profileRequests($this->github))->toBe(['GET /user/21082715']);

    $this->github['sent'] = [];

    Livewire::actingAs($this->admin)->test(AccessLists::class)->assertSee('todd-uams')->call('$refresh');
    $this->actingAs($this->admin)->get(route('robot-council.access'))->assertOk()->assertSee('todd-uams');

    expect($this->github['sent'])->toBeEmpty();
});

it('decides access by the ID alone, whatever login is remembered for it', function (): void {
    $allowlist = static fn (): Allowlist => app(Allowlist::class);

    expect($allowlist()->admits(21082715))->toBeTrue()
        ->and($allowlist()->admits(5150))->toBeFalse();

    // Remember another account's login against each ID, and a login for an ID nobody listed
    DB::table('robot_council_github_accounts')->insert([
        ['github_id' => 21082715, 'login' => 'octoadmin', 'account_type' => 'User', 'checked_at' => Carbon::now(), 'resolved_at' => Carbon::now()],
        ['github_id' => 5150, 'login' => 'todd-uams', 'account_type' => 'User', 'checked_at' => Carbon::now(), 'resolved_at' => Carbon::now()],
    ]);
    app()->forgetInstance(Allowlist::class);

    expect($allowlist()->admits(21082715))->toBeTrue()
        ->and($allowlist()->isAdmin(21082715))->toBeFalse()
        ->and($allowlist()->admits(5150))->toBeFalse();
});

it("falls back to today's words when GitHub cannot name the ID, and keeps a login it already had", function (): void {
    // Unresolvable: GitHub has no account with the ID
    $this->setAccessLists(developers: [4242, 99999], admins: [4242]);

    expect(Artisan::call('robot-council:refresh-github-logins'))->toBe(0)
        ->and(rememberedLogin(99999))->toBeNull()
        ->and(DB::table('robot_council_github_accounts')->where('github_id', 99999)->exists())->toBeTrue();

    $html = Livewire::actingAs($this->admin)->test(AccessLists::class)->html();

    expect($html)->toContain('not signed in yet')
        ->and($html)->toContain('<code>99999</code>');

    // A login GitHub gave earlier survives a request that fails, and the page keeps showing it
    $this->setAccessLists(developers: [4242, 21082715], admins: [4242]);
    expect(Artisan::call('robot-council:refresh-github-logins'))->toBe(0)
        ->and(rememberedLogin(21082715))->toBe('todd-uams');

    DB::table('robot_council_github_accounts')->where('github_id', 21082715)->update(['checked_at' => Carbon::now()->subDays(2)]);
    $this->github['override'] = failProfiles(static fn (): never => throw new ConnectionException('down'));

    expect(Artisan::call('robot-council:refresh-github-logins'))->toBe(0)
        ->and(rememberedLogin(21082715))->toBe('todd-uams')
        ->and(Livewire::actingAs($this->admin)->test(AccessLists::class)->html())->toContain('todd-uams');
});

it('asks about an ID again only once its answer has aged, and never about one that signed in', function (): void {
    expect(Artisan::call('robot-council:refresh-github-logins'))->toBe(0)
        ->and(profileRequests($this->github))->toBe(['GET /user/21082715']);

    // Fresh: a second run asks nothing
    $this->github['sent'] = [];
    expect(Artisan::call('robot-council:refresh-github-logins'))->toBe(0)
        ->and(profileRequests($this->github))->toBeEmpty();

    // Aged past a day: asked again
    Carbon::setTestNow(Carbon::now()->addHours(GitHubAccounts::FRESH_FOR_HOURS)->addMinute());
    expect(Artisan::call('robot-council:refresh-github-logins'))->toBe(0)
        ->and(profileRequests($this->github))->toBe(['GET /user/21082715']);

    // Signed in since: its own sign-in names it, and it is not asked about
    Carbon::setTestNow(Carbon::now()->addHours(GitHubAccounts::FRESH_FOR_HOURS)->addMinute());
    $this->github['sent'] = [];
    $this->enrollDeveloper(21082715, login: 'todd-uams');

    expect(Artisan::call('robot-council:refresh-github-logins'))->toBe(0)
        ->and(profileRequests($this->github))->toBeEmpty();
});

it('stops a refresh at a failure every later request would meet', function (): void {
    $this->setAccessLists(developers: [4242, 21082715, 9191], admins: [4242]);
    $this->github['override'] = failProfiles(static fn () => Http::response([], 500));

    expect(Artisan::call('robot-council:refresh-github-logins'))->toBe(0)
        ->and(profileRequests($this->github))->toHaveCount(1);

    // The control: reachable, both are asked about in one run
    $this->github['override'] = null;
    $this->github['sent'] = [];
    DB::table('robot_council_github_accounts')->update(['checked_at' => Carbon::now()->subDays(2)]);

    expect(Artisan::call('robot-council:refresh-github-logins'))->toBe(0)
        ->and(profileRequests($this->github))->toHaveCount(2);
});

it('makes no request at all with no App configured', function (): void {
    config()->set('robot-council.github.app.id');
    config()->set('robot-council.github.app.private_key');

    expect(Artisan::call('robot-council:refresh-github-logins'))->toBe(0)
        ->and($this->github['sent'])->toBeEmpty()
        ->and(DB::table('robot_council_github_accounts')->count())->toBe(0);
});

it('schedules the refresh every fifteen minutes, in the background and without overlapping', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'robot-council:refresh-github-logins')
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/15 * * * *')
        ->and($events[0]->runInBackground)->toBeTrue()
        ->and($events[0]->withoutOverlapping)->toBeTrue();
});

it('adds an account by its login, storing the ID GitHub gave and the login for display', function (): void {
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('list', 'admin')->set('account', ' New-Dev ')->call('lookUp')
        ->assertSet('refused', false)
        ->assertSet('said', "Found: new-dev is GitHub user 5150, a person's account. Check that it is who you mean, then add them.")
        ->assertSeeHtml('Add new-dev to the administrator list')
        ->call('add', 5150)
        ->assertSet('said', 'Added: new-dev (GitHub user 5150) is on the administrator list from their next request.');

    $row = DB::table('robot_council_allowlist_entries')->where('github_id', 5150)->sole();

    expect($row->list)->toBe('admin')
        ->and($row->login)->toBe('new-dev')
        ->and(rememberedLogin(5150))->toBe('new-dev')
        ->and(app(Allowlist::class)->isAdmin(5150))->toBeTrue();
});

it("offers both accounts when an all-digit value is one account's ID and another's login", function (): void {
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', '5150')->call('lookUp')
        // `5150` is also a login -- account 7070's -- so both are offered
        ->assertSet('candidates', [['github_id' => 5150, 'login' => 'new-dev'], ['github_id' => 7070, 'login' => '5150']])
        ->assertSet('said', 'Two accounts match 5150: new-dev is GitHub user 5150, and 5150 is GitHub user 7070. Add the one you mean.');

    expect(DB::table('robot_council_allowlist_entries')->count())->toBe(0);
});

it('offers one account for an ID that is no login', function (): void {
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', '21082715')->call('lookUp')
        ->assertSet('candidates', [['github_id' => 21082715, 'login' => 'todd-uams']])
        ->assertSet('said', "Found: todd-uams is GitHub user 21082715, a person's account. Check that it is who you mean, then add them.");
});

it('adds an account by its ID, confirmed against GitHub', function (): void {
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', '9191')->call('lookUp')
        ->assertSet('candidates', [['github_id' => 9191, 'login' => 'solo-dev']])
        ->call('add', 9191)
        ->assertSet('said', 'Added: solo-dev (GitHub user 9191) is on the developer list from their next request.');

    expect(DB::table('robot_council_allowlist_entries')->where('github_id', 9191)->value('login'))->toBe('solo-dev')
        ->and(profileRequests($this->github))->toBe(['GET /user/9191', 'GET /users/9191']);
});

it('names an organization an all-digit value also matched, rather than offering one account silently', function (): void {
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', '8080')->call('lookUp')
        ->assertSet('candidates', [['github_id' => 1111, 'login' => '8080']])
        ->assertSet('said', "Found: 8080 is GitHub user 1111, a person's account. Check that it is who you mean, then add them. another-org (GitHub user 8080) also matches, but it is an organization account, which cannot sign in.");
});

it('offers nothing when the ID answered but the login check failed, rather than half an answer', function (): void {
    $this->github['override'] = static fn (string $path): mixed => $path === '/users/6060' ? Http::response([], 429) : null;

    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', '6060')->call('lookUp')
        ->assertSet('refused', true)
        ->assertSet('candidates', [])
        ->assertSet('said', 'Not added: GitHub is limiting requests just now, so it could not be checked whether 6060 is also a login. Try again later.');
});

it('saves nothing for an all-digit value naming two accounts until one is chosen, and then only that one', function (): void {
    $component = Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', '5150')->call('lookUp');

    expect(DB::table('robot_council_allowlist_entries')->count())->toBe(0);

    $component->call('add', 7070)
        ->assertSet('said', 'Added: 5150 (GitHub user 7070) is on the developer list from their next request.');

    expect(DB::table('robot_council_allowlist_entries')->pluck('github_id')->map(intValue(...))->all())->toBe([7070]);
});

it('refuses an organization account, and stores nothing', function (): void {
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', 'an-org')->call('lookUp')
        ->assertSet('refused', true)
        ->assertSet('candidates', [])
        ->assertSet('said', "Not added: an-org is an organization account, and only a person's account can sign in.");

    // And by its ID
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', '6060')->call('lookUp')
        ->assertSet('refused', true)
        ->assertSet('candidates', []);

    expect(DB::table('robot_council_allowlist_entries')->count())->toBe(0);
});

it('adds only an account the last lookup offered', function (): void {
    // Never looked up
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->call('add', 5150)
        ->assertSet('refused', true)
        ->assertSet('said', 'Not added: look the account up first, then add it.');

    // Looked up, then the value changed: the earlier offer is gone
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', 'new-dev')->call('lookUp')
        ->set('account', 'todd-uams')
        ->assertSet('candidates', [])
        ->call('add', 5150)
        ->assertSet('refused', true);

    expect(DB::table('robot_council_allowlist_entries')->count())->toBe(0);
});

it('lets an all-digit value be added unconfirmed while GitHub is unreachable, after saying so', function (): void {
    $this->github['override'] = failProfiles(static fn (): never => throw new ConnectionException('down'));

    $component = Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', '5150')->call('lookUp')
        ->assertSet('refused', true)
        ->assertSet('said', 'Not confirmed: GitHub could not be reached, so GitHub user 5150 could not be checked. You can still add it by its ID, and the page shows its login once GitHub answers.')
        ->assertSet('candidates', [['github_id' => 5150, 'login' => null]])
        ->assertSeeHtml('Add GitHub user 5150 to the developer list');

    expect(DB::table('robot_council_allowlist_entries')->count())->toBe(0);

    $component->call('add', 5150)
        ->assertSet('said', 'Added: GitHub user 5150 is on the developer list from their next request.');

    $event = FleetEvent::query()->where('type', FleetEventType::AllowlistEntryAdded)->sole();

    expect(DB::table('robot_council_allowlist_entries')->where('github_id', 5150)->value('login'))->toBe('')
        ->and(app(Allowlist::class)->admits(5150))->toBeTrue()
        ->and($event->body)->toBe('GitHub user 5150 was added to the developer list.')
        ->and(Livewire::actingAs($this->admin)->test(AccessLists::class)->html())->toMatch('#not signed in yet\s*(?:<!--.*?-->\s*)*</span>\s*<span class="opacity-90">&middot; GitHub user <code>5150</code>#');
});

it('refuses a login while GitHub is unreachable, since there is no ID to store', function (): void {
    // The control: the same login, reachable, is offered
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', 'new-dev')->call('lookUp')
        ->assertSet('candidates', [['github_id' => 5150, 'login' => 'new-dev']]);

    $this->github['override'] = failProfiles(static fn (): never => throw new ConnectionException('down'));

    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', 'new-dev')->call('lookUp')
        ->assertSet('refused', true)
        ->assertSet('candidates', [])
        ->assertSet('said', 'Not added: GitHub could not be reached, so new-dev could not be looked up. Try again later, or add them by their numeric user ID.');
});

it('says which failure it was', function (Closure $failure, string $reason): void {
    $this->github['override'] = failProfiles($failure);

    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', 'new-dev')->call('lookUp')
        ->assertSet('said', sprintf('Not added: %s, so new-dev could not be looked up. Try again later, or add them by their numeric user ID.', $reason));
})->with([
    'unreachable' => [static fn () => throw new ConnectionException('down'), 'GitHub could not be reached'],
    'rate limited' => [static fn () => Http::response([], 429), 'GitHub is limiting requests just now'],
    'refused' => [static fn () => Http::response([], 500), 'GitHub did not answer the lookup'],
]);

it('says the App is not set up when there is none, and asks nothing', function (): void {
    config()->set('robot-council.github.app.id');

    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('account', 'new-dev')->call('lookUp')
        ->assertSet('said', 'Not added: the GitHub App is not set up for looking accounts up, so new-dev could not be looked up. Try again later, or add them by their numeric user ID.');

    expect($this->github['sent'])->toBeEmpty();
});

it('refuses a profile whose login is not a GitHub login, rather than remembering it', function (string $login): void {
    $this->github['accounts'] = [5150 => ['login' => $login]];

    expect(fn () => app(GitHubAccounts::class)->byId(5150))->toThrow(GitHubRefusal::class, 'GitHub: unparseable (HTTP 200).')
        ->and(DB::table('robot_council_github_accounts')->count())->toBe(0);
})->with([
    'a bot' => ['renovate[bot]'],
    'markup' => ['<b>x</b>'],
    'too long' => [str_repeat('a', 40)],
]);

it('keeps adding through the store working for a host that calls it with an ID and a login', function (): void {
    expect(app(AllowlistEntries::class)->add(AccessList::Developer, 5150, 'new-dev'))->toBeTrue()
        ->and(DB::table('robot_council_allowlist_entries')->where('github_id', 5150)->value('login'))->toBe('new-dev');

    // And still refuses a login that is not one
    expect(fn () => app(AllowlistEntries::class)->add(AccessList::Developer, 6161, '<b>x</b>'))->toThrow(InvalidArgumentException::class);
});
