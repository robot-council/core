<?php

declare(strict_types=1);

/**
 * The administrator-only page for the developer and administrator allowlists (#407).
 *
 * The gate is on every entry point, as on `Administration`, and `Livewire::test()` calls an action
 * with no page and no HTTP middleware, which is what makes it the right instrument for that claim.
 * Every "it was stored" assertion reads the table, not the component.
 *
 * @command  vendor/bin/pest --compact tests/AccessListsTest.php
 */
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RobotCouncil\Access\AccessList;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Livewire\AccessLists;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Support\AllowlistEntries;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // One environment administrator and one plain developer, as in `AdministrationTest`: the page
    // must tell the dashboard's gate apart from the admin gate
    $this->setAccessLists(developers: [4242, 4243], admins: [4242]);

    $this->admin = $this->enrollDeveloper(4242, login: 'octoadmin');
    $this->developer = $this->enrollDeveloper(4243, login: 'octodev');
});

/**
 * How many table entries a list holds for an ID.
 *
 * @param  AccessList  $list  The list.
 * @param  int  $githubId  The account.
 * @return int The count.
 */
function tableEntries(AccessList $list, int $githubId): int
{
    return DB::table('robot_council_allowlist_entries')->where('list', $list->value)->where('github_id', $githubId)->count();
}

it('refuses to mount for a developer who is not an administrator, and mounts for one who is', function (): void {
    Livewire::actingAs($this->developer)->test(AccessLists::class)->assertForbidden();
    Livewire::actingAs($this->admin)->test(AccessLists::class)->assertOk();

    // And on the route itself, which has no gate of its own
    $this->actingAs($this->developer)->get(route('robot-council.access'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('robot-council.access'))->assertOk();
});

it('refuses every action to an administrator demoted while the page is open, and writes nothing', function (string $action, array $arguments): void {
    app(AllowlistEntries::class)->add(AccessList::Developer, 5150, 'someone');

    $component = Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('githubId', '6060')->set('login', 'newcomer');

    $this->setAccessLists(developers: [4242, 4243], admins: []);

    $component->call($action, ...$arguments)->assertForbidden();

    expect(tableEntries(AccessList::Developer, 5150))->toBe(1)
        ->and(tableEntries(AccessList::Developer, 6060))->toBe(0);
})->with([
    'add' => ['add', []],
    'remove' => ['remove', ['developer', 5150]],
    'render' => ['$refresh', []],
]);

it('offers the link in the sidebar to an administrator only', function (): void {
    $this->actingAs($this->developer)->get(route('robot-council.dashboard'))->assertOk()->assertDontSeeHtml(route('robot-council.access'));

    $this->actingAs($this->admin)->get(route('robot-council.dashboard'))->assertOk()->assertSeeHtml(route('robot-council.access'));
});

it('lists environment entries, marked, with no remove control', function (): void {
    $html = Livewire::actingAs($this->admin)->test(AccessLists::class)->html();

    expect($html)->toContain('from configuration')
        ->and($html)->toContain('octoadmin')
        ->and($html)->toContain('octodev')
        ->and($html)->not->toContain("remove('developer', 4243)")
        ->and($html)->not->toContain("remove('admin', 4242)");
});

it('adds a table entry that admits on the next request, and removes it again', function (): void {
    $component = Livewire::actingAs($this->admin)->test(AccessLists::class);

    expect(app(Allowlist::class)->admits(5150))->toBeFalse();

    $component->set('list', 'developer')->set('githubId', '5150')->set('login', 'new-dev')->call('add')
        ->assertSet('said', 'Added: GitHub user 5150 is on the developer list from their next request.')
        ->assertSet('refused', false);

    expect(tableEntries(AccessList::Developer, 5150))->toBe(1)
        ->and(DB::table('robot_council_allowlist_entries')->where('github_id', 5150)->value('added_by'))->toBe(4242)
        ->and(app(Allowlist::class)->admits(5150))->toBeTrue()
        ->and($component->html())->toContain("remove('developer', 5150)");

    $component->call('remove', 'developer', 5150)
        ->assertSet('said', 'Removed: GitHub user 5150 is off the developer list. They can no longer sign in.');

    expect(tableEntries(AccessList::Developer, 5150))->toBe(0)
        ->and(app(Allowlist::class)->admits(5150))->toBeFalse();
});

it('says what access remains after a removal, rather than calling the account out', function (): void {
    $entries = app(AllowlistEntries::class);
    $entries->add(AccessList::Developer, 6060, 'both-lists');
    $entries->add(AccessList::Admin, 6060, 'both-lists');

    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->call('remove', 'developer', 6060)
        ->assertSet('said', 'Removed: GitHub user 6060 is off the developer list. They can still sign in and administer, as an administrator.');

    expect(app(Allowlist::class)->admits(6060))->toBeTrue();
});

it('refuses to remove an environment entry, in its own words, and changes nothing', function (): void {
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->call('remove', 'developer', 4243)
        ->assertSet('refused', true)
        ->assertSet('said', 'Not removed: GitHub user 4243 is on the developer list through the host configuration (ROBOT_COUNCIL_DEVELOPERS), so it can only be removed there.');

    expect(app(Allowlist::class)->admits(4243))->toBeTrue();
});

it('reports a refused add in words and stores nothing', function (string $list, string $id, string $login, string $said): void {
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('list', $list)->set('githubId', $id)->set('login', $login)->call('add')
        ->assertSet('refused', true)
        ->assertSet('said', $said);

    expect(DB::table('robot_council_allowlist_entries')->count())->toBe(0);
})->with([
    'not a number' => ['developer', 'abc', 'someone', 'Not added: A GitHub user ID is a positive whole number.'],
    'not a login' => ['developer', '5150', '<b>x</b>', 'Not added: A GitHub login is 1 to 39 letters, digits and single hyphens, not leading or trailing.'],
    'not a list' => ['owner', '5150', 'someone', 'Not added: choose the developer or the administrator list.'],
]);

it('warns before an administrator removes themselves', function (): void {
    // An administrator through the table alone, so their own entry has a remove control
    $this->setAccessLists(developers: [4243], admins: [99]);
    app(AllowlistEntries::class)->add(AccessList::Admin, 4242, 'octoadmin');

    $html = Livewire::actingAs($this->admin)->test(AccessLists::class)->html();

    expect($html)->toContain('(you)')
        ->and($html)->toContain('wire:confirm="Remove yourself from this list? If this is your only way onto it, you lose that access immediately, and an administrator from the server configuration will have to add you back."');
});

it('warns before the last administrator added here is removed', function (): void {
    app(AllowlistEntries::class)->add(AccessList::Admin, 6060, 'second-admin');

    $html = Livewire::actingAs($this->admin)->test(AccessLists::class)->html();

    expect($html)->toContain('wire:confirm="Remove the last administrator added here? Only administrators from the server configuration will remain."');

    // With two, neither is the last, and each gets the ordinary warning
    app(AllowlistEntries::class)->add(AccessList::Admin, 7070, 'third-admin');

    expect(Livewire::actingAs($this->admin)->test(AccessLists::class)->html())
        ->not->toContain('Remove the last administrator added here?');
});

it('renders a login carrying markup as text, from the table and from a signed-in identity', function (string $payload): void {
    // The login column is 39 characters on every engine that enforces it
    expect(mb_strlen($payload))->toBeLessThanOrEqual(39);

    // Written past the store, which refuses markup, so the escaping is the page's own guarantee
    DB::table('robot_council_allowlist_entries')->insert([
        'github_id' => 5150, 'list' => 'developer', 'login' => $payload, 'added_by' => null, 'created_at' => Carbon::now(),
    ]);
    GithubIdentity::query()->where('github_id', 4243)->update(['github_login' => $payload]);

    $html = Livewire::actingAs($this->admin)->test(AccessLists::class)->html();

    expect($html)->not->toContain($payload)
        ->and($html)->toContain(e($payload));
})->with([
    'a script' => ['<script>alert(1)</script>'],
    'an attribute break' => ['"><img src=x onerror=alert(1)>'],
    'a Livewire directive' => ['<a wire:click="add">x</a>'],
]);

it('sends an administrator who removes their own administrator access to the dashboard, rather than a 403', function (): void {
    // An administrator through the table alone
    $this->setAccessLists(developers: [4242, 4243], admins: [99]);
    app(AllowlistEntries::class)->add(AccessList::Admin, 4242, 'octoadmin');

    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->call('remove', 'admin', 4242)
        ->assertRedirect(route('robot-council.dashboard'));

    expect(tableEntries(AccessList::Admin, 4242))->toBe(0)
        ->and(app(Allowlist::class)->isAdmin(4242))->toBeFalse()
        // Still a developer, so the dashboard it is sent to admits it
        ->and(app(Allowlist::class)->admits(4242))->toBeTrue();
});

it('refuses on the page to add an account the configuration already lists', function (): void {
    Livewire::actingAs($this->admin)->test(AccessLists::class)
        ->set('list', 'developer')->set('githubId', '4243')->set('login', 'octodev')->call('add')
        ->assertSet('refused', true)
        ->assertSet('said', 'Not added: GitHub user 4243 is already on the developer list through the host configuration (ROBOT_COUNCIL_DEVELOPERS). Nothing changed.');

    expect(DB::table('robot_council_allowlist_entries')->count())->toBe(0);
});

it("puts the self-removal warning on the administrator's own row and no other", function (): void {
    $this->setAccessLists(developers: [4243], admins: [99]);
    app(AllowlistEntries::class)->add(AccessList::Admin, 4242, 'octoadmin');
    app(AllowlistEntries::class)->add(AccessList::Admin, 6060, 'other-admin');

    $html = Livewire::actingAs($this->admin)->test(AccessLists::class)->html();

    // Each row's button, read in order: the warning belongs to the button naming 4242
    preg_match_all('/<button[^>]*wire:confirm="([^"]+)"[^>]*aria-label="([^"]+)"/', $html, $found, PREG_SET_ORDER);
    $byLabel = array_column($found, 1, 2);

    expect($byLabel['Remove yourself, GitHub user 4242, from the administrator list'] ?? null)->toStartWith('Remove yourself from this list?')
        ->and($byLabel['Remove GitHub user 6060 from the administrator list'] ?? null)->toBe('Remove this account from the list? It takes effect on their next request.');
});
