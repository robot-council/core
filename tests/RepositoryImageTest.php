<?php

declare(strict_types=1);

/**
 * A repository's picture, as a ringed circle beside its name (#416).
 *
 * The picture is its owner's avatar, as a webhook delivery last gave it: GitHub gives a repository
 * none of its own, and core asks GitHub nothing to show one. Each page-level test pairs a repository
 * whose owner has sent a delivery with one whose owner has not, so a page that dropped the fallback
 * or showed a picture it should not would fail one of the two.
 *
 * @command  vendor/bin/pest --compact tests/RepositoryImageTest.php
 */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RobotCouncil\Livewire\Administration;
use RobotCouncil\Livewire\Agents;
use RobotCouncil\Livewire\Lanes;
use RobotCouncil\Livewire\SeatSettings;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Backlog;
use RobotCouncil\Support\GitHubAccounts;
use RobotCouncil\Support\GitHubState;

const OWNER_PICTURE = 'https://avatars.githubusercontent.com/u/9919?v=4';

/**
 * A delivery from a repository, carrying its owner the way every webhook payload does.
 *
 * @param  string  $repository  `owner/name`.
 * @param  array<string, mixed>  $owner  The owner object, as GitHub sends it.
 * @param  string  $event  The event; `repository` is one the fleet otherwise ignores.
 * @return string What came of it.
 */
function deliverFrom(string $repository, array $owner, string $event = 'repository'): string
{
    return app(GitHubState::class)->receive(bin2hex(random_bytes(8)), $event, [
        'ref' => 'feature/pictures', 'ref_type' => 'branch',
        'repository' => ['full_name' => $repository, 'owner' => $owner],
    ]);
}

/**
 * The repository pictures on a page, each as its letter and the picture it loads, if any.
 *
 * @param  string  $html  The page.
 * @return list<array{initial: string, src: string|null}> The pictures, in page order.
 */
function repositoryImagesIn(string $html): array
{
    // Read by the component's exact shape, the markers Livewire writes round a condition included
    $markers = '(?:<!--\[if [A-Z]+\]><!\[endif\]-->)*';

    preg_match_all(
        '#<span class="avatar [^"]*" aria-hidden="true" data-avatar="repository"><span class="[^"]*\bborder-2\b[^"]*"><span class="text-meta leading-none select-none group-has-\[img\]:invisible">([^<]*)</span>'.$markers.'(?:<img src="([^"]*)" alt=""[^>]*>)?'.$markers.'</span></span>#',
        $html,
        $found,
        PREG_SET_ORDER
    );

    return array_map(static fn (array $image): array => [
        'initial' => $image[1],
        'src' => ($image[2] ?? '') !== '' ? html_entity_decode($image[2]) : null,
    ], $found);
}

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
    $this->setAccessLists(developers: [4242], admins: [4242]);

    $this->developer = $this->enrollDeveloper(4242, login: 'octodev');
    $this->installation = $this->approveInstallation($this->developer);
});

it("stores the owner's avatar from a delivery, and shows it beside each of that owner's repositories", function (): void {
    deliverFrom('robot-council/core', ['login' => 'robot-council', 'avatar_url' => OWNER_PICTURE]);

    $html = Blade::render('<x-robot-council::avatar repository="robot-council/core" />|<x-robot-council::avatar repository="Robot-Council/cli" />|<x-robot-council::avatar repository="UAMS-Web/uams-statamic" />');

    // A sibling that never sent a delivery shows its owner's picture too; another owner's does not
    expect(repositoryImagesIn($html))->toBe([
        ['initial' => 'C', 'src' => OWNER_PICTURE],
        ['initial' => 'C', 'src' => OWNER_PICTURE],
        ['initial' => 'U', 'src' => null],
    ])
        ->and($html)->toContain('<img src="'.OWNER_PICTURE.'" alt=""')
        ->and(substr_count($html, 'aria-hidden="true" data-avatar="repository"'))->toBe(3)
        ->and($html)->not->toContain('onerror');
});

it('draws a ring round a repository that a developer circle does not have', function (): void {
    $html = Blade::render('<x-robot-council::avatar repository="robot-council/core" />|<x-robot-council::avatar login="octodev" />');

    [$repository, $developer] = explode('|', $html);

    expect($repository)->toContain('border-2 border-base-content')
        ->and($developer)->toContain('border border-transparent')
        ->and($developer)->not->toContain('border-2');
});

it('renders nothing for no repository, since nothing is named', function (): void {
    expect(trim(Blade::render('<x-robot-council::avatar :repository="null" />')))->toBeEmpty()
        ->and(trim(Blade::render('<x-robot-council::avatar repository="" />')))->toBeEmpty();
});

it('keeps what it has, and stores nothing, from a delivery it cannot trust', function (array $owner, string $repository): void {
    /** @var array<string, mixed> $owner */
    deliverFrom('robot-council/core', ['login' => 'robot-council', 'avatar_url' => OWNER_PICTURE]);
    deliverFrom($repository, $owner);

    expect(DB::table('robot_council_github_owners')->pluck('avatar_url', 'login')->all())->toBe(['robot-council' => OWNER_PICTURE]);
})->with([
    'a picture on another host' => [['login' => 'robot-council', 'avatar_url' => 'https://evil.example/u/9919'], 'robot-council/core'],
    'a lookalike host' => [['login' => 'robot-council', 'avatar_url' => 'https://avatars.githubusercontent.com.evil.example/u/1'], 'robot-council/core'],
    'a script' => [['login' => 'robot-council', 'avatar_url' => 'javascript:alert(1)'], 'robot-council/core'],
    'a trailing newline' => [['login' => 'robot-council', 'avatar_url' => OWNER_PICTURE."\n"], 'robot-council/core'],
    'no picture at all' => [['login' => 'robot-council'], 'robot-council/core'],
    "an owner that is not the repository's" => [['login' => 'someone-else', 'avatar_url' => 'https://avatars.githubusercontent.com/u/1?v=4'], 'robot-council/core'],
    'an owner that is only a prefix of it' => [['login' => 'robot', 'avatar_url' => 'https://avatars.githubusercontent.com/u/1?v=4'], 'robot-council/core'],
    'a login GitHub would refuse' => [['login' => '-robot-council', 'avatar_url' => 'https://avatars.githubusercontent.com/u/1?v=4'], '-robot-council/core'],
]);

it('writes only when the picture changed, and replaces it when it did', function (): void {
    deliverFrom('robot-council/core', ['login' => 'robot-council', 'avatar_url' => OWNER_PICTURE]);

    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (str_contains($query->sql, 'robot_council_github_owners') && ! str_starts_with(mb_strtolower(ltrim($query->sql)), 'select')) {
            $writes[] = $query->sql;
        }
    });

    deliverFrom('robot-council/cli', ['login' => 'Robot-Council', 'avatar_url' => OWNER_PICTURE]);

    expect($writes)->toBeEmpty();

    deliverFrom('robot-council/core', ['login' => 'robot-council', 'avatar_url' => 'https://avatars.githubusercontent.com/u/9919?v=5']);

    expect($writes)->toHaveCount(1)
        ->and(GitHubAccounts::repositoryImageOf('robot-council/core'))->toBe('https://avatars.githubusercontent.com/u/9919?v=5');
});

it('changes nothing from a delivery it has already seen', function (): void {
    $state = app(GitHubState::class);
    $payload = ['repository' => ['full_name' => 'robot-council/core', 'owner' => ['login' => 'robot-council', 'avatar_url' => OWNER_PICTURE]]];

    expect($state->receive('delivery-1', 'repository', $payload))->toBe('ignored');

    DB::table('robot_council_github_owners')->delete();

    expect($state->receive('delivery-1', 'repository', $payload))->toBe('duplicate')
        ->and(DB::table('robot_council_github_owners')->count())->toBe(0);
});

it('applies a delivery whose owner cannot be remembered, since a picture is only display', function (): void {
    // Drops a table, which a rolled-back test transaction would not undo on every engine (#473)
    $this->migrateFreshSchema();
    Schema::drop('robot_council_github_owners');

    expect(deliverFrom('robot-council/core', ['login' => 'robot-council', 'avatar_url' => OWNER_PICTURE], 'create'))->toBe('applied')
        ->and(DB::table('robot_council_github_branches')->where('name', 'feature/pictures')->exists())->toBeTrue();
});

it('shows the picture on every page that heads a row with a repository, and asks GitHub nothing to do it', function (string $component): void {
    deliverFrom('robot-council/core', ['login' => 'robot-council', 'avatar_url' => OWNER_PICTURE]);

    $sessions = app(AgentSessions::class);
    $sessions->start($this->installation, 'robot-council/core', 'a');
    $sessions->start($this->approveInstallation($this->developer, machineLabel: 'second-box'), 'UAMS-Web/uams-statamic', 'a');

    Http::fake();
    Http::preventStrayRequests();

    $images = repositoryImagesIn(Livewire::actingAs($this->developer)->test($component)->html());

    expect($images)->toContain(['initial' => 'C', 'src' => OWNER_PICTURE])
        ->and($images)->toContain(['initial' => 'U', 'src' => null]);

    Http::assertNothingSent();
})->with([
    'lanes' => [Lanes::class],
    'agents' => [Agents::class],
    'administration' => [Administration::class],
    'seat settings' => [SeatSettings::class],
]);

it("shows the picture in each repository's section heading and open-issue meter on the lane board", function (): void {
    deliverFrom('robot-council/core', ['login' => 'robot-council', 'avatar_url' => OWNER_PICTURE]);

    $lane = app(AgentSessions::class)->start($this->installation, 'robot-council/core', 'a')->owner;
    app(Backlog::class)->report($lane, 'robot-council/core', 10);

    $html = Livewire::actingAs($this->developer)->test(Lanes::class)->html();

    preg_match('#<h2 class="card-title">(.*?)</h2>#s', $html, $heading);
    preg_match('#wire:key="meter-robot-council/core".*?open issues</div>#s', $html, $meter);

    expect(repositoryImagesIn($heading[1] ?? ''))->toBe([['initial' => 'C', 'src' => OWNER_PICTURE]])
        ->and(trim(strip_tags(withoutAvatars($heading[1] ?? ''))))->toBe('robot-council/core')
        ->and(repositoryImagesIn($meter[0] ?? ''))->toBe([['initial' => 'C', 'src' => OWNER_PICTURE]]);
});

it('costs at most one query a request, however many repositories a page names', function (): void {
    deliverFrom('robot-council/core', ['login' => 'robot-council', 'avatar_url' => OWNER_PICTURE]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    foreach (['robot-council/core', 'robot-council/cli', 'UAMS-Web/uams-statamic', 'no-slash', null] as $repository) {
        GitHubAccounts::repositoryImageOf($repository);
    }

    expect($queries)->toBe(1);
});
