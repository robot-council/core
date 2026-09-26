<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RobotCouncil\Access\AccessList;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\AllowlistRemoval;
use RobotCouncil\Access\CurrentDeveloper;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Support\AllowlistEntries;
use RobotCouncil\Support\HostUsers;
use RuntimeException;

/**
 * Who may sign in and who may administer: the developer and administrator allowlists (#407).
 *
 * **Every entry point authorizes, including `render()`**, exactly as `Administration` does and for
 * its reasons: a Livewire action is an ordinary POST to `/livewire/update`, so a hidden control is
 * no boundary, and an administrator demoted while the page is open must stop seeing it on the next
 * render. `mount()` refuses first, so the route needs to know nothing about administrators.
 *
 * **Each list is two sources, and the page keeps them apart** (#312's decision). Entries from the
 * host's environment are listed and marked, and offer no remove control: they can only be changed
 * where they are set. Entries in the table are added and removed here, through
 * `Support\AllowlistEntries`, which holds every bound. A removal is reported by what access the
 * account still has afterwards, read from `Access\Allowlist`, because `Removed` is about one list:
 * an account taken off the developer list while it is an administrator still signs in.
 *
 * **Adding needs nothing from GitHub.** The administrator types the numeric ID and the login; the
 * page does not look either up, so it works whether or not GitHub is reachable. The ID is what
 * admits; the login is what the page shows beside it.
 */
#[Layout('robot-council::layouts.dashboard')]
#[Title('Access')]
final class AccessLists extends Component
{
    /**
     * The list a new entry goes on.
     */
    public string $list = 'developer';

    /**
     * The new entry's GitHub numeric ID, as typed.
     */
    public string $githubId = '';

    /**
     * The new entry's GitHub login, as typed.
     */
    public string $login = '';

    /**
     * What the last action did, or why it did nothing, in words.
     *
     * Locked, like every value a client could otherwise set: the page prints it as the server's own
     * account of what happened.
     */
    #[Locked]
    public ?string $said = null;

    /**
     * Where `said` belongs: `add`, or the list whose entry the last removal was about.
     */
    #[Locked]
    public ?string $saidAt = null;

    /**
     * Whether the last action was refused or changed nothing, which the page shows as an error.
     */
    #[Locked]
    public bool $refused = false;

    /**
     * Refuse anyone who is not an administrator.
     */
    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    /**
     * Add the typed account to the chosen list.
     */
    public function add(): void
    {
        $this->authorizeAdmin();

        $list = AccessList::tryFrom($this->list);

        if (! $list instanceof AccessList) {
            $this->say('add', 'Not added: choose the developer or the administrator list.', true);

            return;
        }

        try {
            $added = $this->service(AllowlistEntries::class)->add($list, $this->githubId, trim($this->login), $this->adminGithubId(), $this->service(CurrentDeveloper::class)->key());
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->say('add', 'Not added: '.$invalidArgumentException->getMessage(), true);

            return;
        }

        $id = Allowlist::githubId($this->githubId);

        if (! $added) {
            $this->say('add', sprintf('Already listed: GitHub user %d is on the %s list already. Nothing changed.', $id, self::named($list)), true);

            return;
        }

        $this->githubId = '';
        $this->login = '';
        $this->say('add', sprintf('Added: GitHub user %d is on the %s list from their next request.', $id, self::named($list)));
    }

    /**
     * Remove an account's table entry from a list.
     *
     * @param  string  $list  `developer` or `admin`.
     * @param  int  $githubId  The account's GitHub numeric ID.
     */
    public function remove(string $list, int $githubId): void
    {
        $this->authorizeAdmin();

        $access = AccessList::tryFrom($list);

        if (! $access instanceof AccessList) {
            // Shown above the lists, where the add form's words go: there is no list to show it by
            $this->say('add', 'Not removed: that is not a list.', true);

            return;
        }

        $outcome = $this->service(AllowlistEntries::class)->remove($access, $githubId, $this->service(CurrentDeveloper::class)->key());

        match ($outcome) {
            AllowlistRemoval::FromConfiguration => $this->say($access->value, 'Not removed: '.AllowlistEntries::fromConfiguration($access, $githubId), true),
            AllowlistRemoval::NotListed => $this->say($access->value, sprintf('Not removed: GitHub user %d is not on the %s list here. Nothing changed.', $githubId, self::named($access)), true),
            AllowlistRemoval::Removed => $this->say($access->value, sprintf('Removed: GitHub user %d is off the %s list. %s', $githubId, self::named($access), $this->accessRemaining($githubId))),
        };

        // **An administrator who removed their own administrator access has lost this page within
        // this request** (#407's review): the store forgot the read, so the `render()` that follows
        // would refuse them with a 403 in place of the words above. Sent to the dashboard instead,
        // which they may still use as a developer or which refuses them in the ordinary way.
        if ($outcome === AllowlistRemoval::Removed && ! $this->service(CurrentDeveloper::class)->isAdmin()) {
            $this->skipRender();
            $this->redirectRoute('robot-council.dashboard');
        }
    }

    /**
     * A list's name in the words the page uses.
     *
     * @param  AccessList  $list  The list.
     * @return string `developer` or `administrator`.
     */
    private static function named(AccessList $list): string
    {
        return match ($list) {
            AccessList::Developer => 'developer',
            AccessList::Admin => 'administrator',
        };
    }

    /**
     * Draw both lists.
     *
     * @return View The page.
     */
    public function render(): View
    {
        $this->authorizeAdmin();

        $allowlist = $this->service(Allowlist::class);
        $entries = $this->service(AllowlistEntries::class);
        $self = $this->adminGithubId();

        $lists = [];

        foreach (AccessList::cases() as $access) {
            $configured = $allowlist->configured($access);
            $stored = $entries->entries($access);

            $lists[$access->value] = [
                'configured' => array_map(static fn (int $id): array => ['github_id' => $id], $configured),
                'stored' => array_map(static fn (array $entry): array => [
                    ...$entry,
                    // Also named by the environment: removing it here would change nothing
                    'also_configured' => \in_array($entry['github_id'], $configured, true),
                    'is_self' => $entry['github_id'] === $self,
                ], $stored),
            ];
        }

        // The last administrator the table holds, which removing warns about, since only an
        // environment administrator would then remain to add one back
        $tableAdmins = array_filter(
            $lists[AccessList::Admin->value]['stored'],
            static fn (array $entry): bool => ! $entry['also_configured']
        );

        // Pinned, as every panel pins it: whether the analyzer resolves a package view depends on
        // whether it could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.access-lists';

        return view($template, [
            'lists' => $lists,
            'logins' => $this->knownLogins($lists),
            'lastTableAdmin' => \count($tableAdmins) === 1 ? array_values($tableAdmins)[0]['github_id'] : null,
        ]);
    }

    /**
     * What access an account still has, in words, after a removal.
     *
     * Read from `Access\Allowlist` rather than inferred from the removal, because the account may
     * still hold the other list, from either source (#406's review).
     *
     * @param  int  $githubId  The account.
     * @return string The sentence.
     */
    private function accessRemaining(int $githubId): string
    {
        // A fresh read: the store forgot this request's copy when it wrote
        $allowlist = $this->service(Allowlist::class);

        return match (true) {
            $allowlist->isAdmin($githubId) => 'They can still sign in and administer, as an administrator.',
            $allowlist->admits($githubId) => 'They can still sign in, as a developer.',
            default => 'They can no longer sign in.',
        };
    }

    /**
     * The GitHub logins the package already knows, for entries that came from the environment.
     *
     * An environment entry carries no login, so the page shows the one the account signed in with,
     * when it has. Keyed with a prefix, since PHP turns a numeric string key into an integer.
     *
     * @param  array<string, array{configured: list<array{github_id: int}>, stored: list<array<string, mixed>>}>  $lists  The lists.
     * @return array<string, string> Logins, keyed `id:<github id>`.
     */
    private function knownLogins(array $lists): array
    {
        $ids = [];
        $logins = [];

        foreach ($lists as $list) {
            foreach ($list['configured'] as $entry) {
                $ids[] = $entry['github_id'];
            }

            // A table row the configuration also names, from before the store refused one: its
            // login is shown on the configuration row, and a signed-in identity overrides it below
            foreach ($list['stored'] as $entry) {
                if (($entry['also_configured'] ?? false) === true && \is_int($entry['github_id'] ?? null) && \is_string($entry['login'] ?? null)) {
                    $logins['id:'.$entry['github_id']] = $entry['login'];
                }
            }
        }

        if ($ids === []) {
            return $logins;
        }

        foreach (GithubIdentity::query()->whereIn('github_id', array_values(array_unique($ids)))->get(['github_id', 'github_login']) as $identity) {
            $logins['id:'.$identity->github_id] = $identity->github_login;
        }

        return $logins;
    }

    /**
     * The signed-in administrator's GitHub ID, for the entry they add and for spotting themselves.
     *
     * @return int|null The ID, or null when it cannot be read.
     */
    private function adminGithubId(): ?int
    {
        $user = $this->service(CurrentDeveloper::class)->user();

        return $user === null ? null : $this->service(HostUsers::class)->githubId($user);
    }

    /**
     * Record what the last action did.
     *
     * @param  string|null  $at  Where it belongs on the page.
     * @param  string  $words  What happened, leading with the word that sums it up.
     * @param  bool  $refused  Whether it was refused or changed nothing.
     */
    private function say(?string $at, string $words, bool $refused = false): void
    {
        $this->said = $words;
        $this->saidAt = $at;
        $this->refused = $refused;
    }

    /**
     * Refuse anyone who is not an administrator.
     *
     * Through `Access\CurrentDeveloper`, for the reason `Administration::authorizeAdmin()` records:
     * a bare `Gate::authorize()` reads the host's default guard rather than the package's.
     */
    private function authorizeAdmin(): void
    {
        $this->service(CurrentDeveloper::class)->authorizeAdmin();
    }

    /**
     * One service, resolved out of the application.
     *
     * @template TService of object
     *
     * @param  class-string<TService>  $abstract  What to resolve.
     * @return TService The service.
     *
     * @throws RuntimeException When the application answers with something else.
     */
    private function service(string $abstract): object
    {
        $service = app($abstract);

        if (! $service instanceof $abstract) {
            throw new RuntimeException(sprintf('robot-council resolved something other than %s.', $abstract));
        }

        return $service;
    }
}
