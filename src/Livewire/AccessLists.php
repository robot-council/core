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
use RobotCouncil\Support\GitHubAccount;
use RobotCouncil\Support\GitHubAccounts;
use RobotCouncil\Support\GitHubRefusal;
use RobotCouncil\Support\HostUsers;
use RobotCouncil\Support\LaneHolds;
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
 * **Adding is a lookup, then a confirmation** (#484). The administrator types a login or a numeric
 * ID into one field; `lookUp()` asks GitHub which account that is and shows it, and `add()` stores
 * only an account the lookup offered. The ID is what admits, and the login is what the page shows
 * beside it. An all-digit value is asked about as an ID and as a login, since GitHub allows a login
 * of digits alone, and when the two name different accounts both are offered. **With GitHub
 * unreachable, an all-digit value can still be added, as an ID the page says it could not
 * confirm**, so adding someone never depends on GitHub being up; a login cannot, because without
 * GitHub there is no ID to store.
 *
 * **Rendering makes no request.** The login shown for an account that has not signed in is the one
 * `Support\GitHubAccounts` remembered from a lookup or a scheduled refresh.
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
     * The account to add, as typed: a GitHub login or a numeric user ID.
     */
    public string $account = '';

    /**
     * The accounts the last lookup offered, which are the only ones `add()` will store.
     *
     * Locked, so a client cannot offer itself an account the lookup did not find. A login is null
     * for an ID offered unconfirmed, because GitHub could not be asked.
     *
     * @var list<array{github_id: int, login: string|null}>
     */
    #[Locked]
    public array $candidates = [];

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
     * Look the typed account up on GitHub, and offer what it names.
     */
    public function lookUp(): void
    {
        $this->authorizeAdmin();
        $this->candidates = [];

        if (! AccessList::tryFrom($this->list) instanceof AccessList) {
            $this->say('add', 'Not added: choose the developer or the administrator list.', true);

            return;
        }

        $value = trim($this->account);
        $id = ctype_digit($value) ? Allowlist::githubId($value) : null;
        $asLogin = preg_match(LaneHolds::LOGIN, $value) === 1;

        if ($id === null && ! $asLogin) {
            $this->say('add', 'Not added: type a GitHub login, such as octocat, or a numeric user ID, such as 583231.', true);

            return;
        }

        $accounts = $this->service(GitHubAccounts::class);
        $found = [];

        // Each half is caught on its own, so a failure of one never discards the other's answer
        if ($id !== null) {
            try {
                $account = $accounts->byId($id);
            } catch (GitHubRefusal $gitHubRefusal) {
                // An ID needs nothing from GitHub to be stored, so it is offered, marked unconfirmed
                $this->candidates = [['github_id' => $id, 'login' => null]];
                $this->say('add', sprintf('Not confirmed: %s, so GitHub user %d could not be checked. You can still add it by its ID, and the page shows its login once GitHub answers.', GitHubAccounts::reason($gitHubRefusal), $id), true);

                return;
            }

            if ($account instanceof GitHubAccount) {
                $found[$account->id] = $account;
            }
        }

        if ($asLogin) {
            try {
                $account = $accounts->byLogin($value);
            } catch (GitHubRefusal $gitHubRefusal) {
                // With the ID answered, an unasked login could still be another account, so
                // nothing is offered rather than half an answer
                $this->say('add', $id === null
                    ? sprintf('Not added: %s, so %s could not be looked up. Try again later, or add them by their numeric user ID.', GitHubAccounts::reason($gitHubRefusal), $value)
                    : sprintf('Not added: %s, so it could not be checked whether %s is also a login. Try again later.', GitHubAccounts::reason($gitHubRefusal), $value), true);

                return;
            }

            if ($account instanceof GitHubAccount) {
                $found[$account->id] = $account;
            }
        }

        $people = array_values(array_filter($found, static fn (GitHubAccount $account): bool => $account->isPerson()));

        if ($found === []) {
            $this->say('add', sprintf('Not added: no GitHub account has the login or user ID %s.', $value), true);

            return;
        }

        if ($people === []) {
            $account = array_values($found)[0];
            $this->say('add', sprintf("Not added: %s is %s account, and only a person's account can sign in.", $account->login, $account->type === 'Organization' ? 'an organization' : 'a bot'), true);

            return;
        }

        $this->candidates = array_map(static fn (GitHubAccount $account): array => ['github_id' => $account->id, 'login' => $account->login], $people);

        // An account left out is named, so a value that matched two never reads as matching one
        $leftOut = array_map(
            static fn (GitHubAccount $account): string => sprintf(' %s (GitHub user %d) also matches, but it is %s account, which cannot sign in.', $account->login, $account->id, $account->type === 'Organization' ? 'an organization' : 'a bot'),
            array_values(array_filter($found, static fn (GitHubAccount $account): bool => ! $account->isPerson()))
        );

        $this->say('add', (\count($people) === 1
            ? sprintf("Found: %s is GitHub user %d, a person's account. Check that it is who you mean, then add them.", $people[0]->login, $people[0]->id)
            : sprintf('Two accounts match %s: %s is GitHub user %d, and %s is GitHub user %d. Add the one you mean.', $value, $people[0]->login, $people[0]->id, $people[1]->login, $people[1]->id)).implode('', $leftOut));
    }

    /**
     * Forget the last lookup's accounts once the typed value changes, so a confirmation can only
     * ever add the account the words beside it describe.
     */
    public function updatedAccount(): void
    {
        $this->candidates = [];
    }

    /**
     * Add an account the last lookup offered to the chosen list.
     *
     * @param  int  $githubId  The account's numeric ID, which must be one the lookup offered.
     */
    public function add(int $githubId): void
    {
        $this->authorizeAdmin();

        $list = AccessList::tryFrom($this->list);

        if (! $list instanceof AccessList) {
            $this->say('add', 'Not added: choose the developer or the administrator list.', true);

            return;
        }

        $candidate = array_values(array_filter($this->candidates, static fn (array $candidate): bool => $candidate['github_id'] === $githubId))[0] ?? null;

        if ($candidate === null) {
            $this->say('add', 'Not added: look the account up first, then add it.', true);

            return;
        }

        try {
            $added = $this->service(AllowlistEntries::class)->add($list, $githubId, $candidate['login'], $this->adminGithubId(), $this->service(CurrentDeveloper::class)->key());
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->say('add', 'Not added: '.$invalidArgumentException->getMessage(), true);

            return;
        }

        $name = $candidate['login'] === null ? sprintf('GitHub user %d', $githubId) : sprintf('%s (GitHub user %d)', $candidate['login'], $githubId);

        if (! $added) {
            $this->say('add', sprintf('Already listed: %s is on the %s list already. Nothing changed.', $name, self::named($list)), true);

            return;
        }

        $this->account = '';
        $this->candidates = [];
        $this->say('add', sprintf('Added: %s is on the %s list from their next request.', $name, self::named($list)));
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
            'names' => $this->names($lists),
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
     * What each listed account is called, and whether that is from its own sign-in.
     *
     * The login an account signed in with, when it has; otherwise the one `GitHubAccounts`
     * remembered, which is fresher than a table entry's; otherwise the table entry's own. Read from
     * the database alone, so rendering makes no request (#484). Keyed with a prefix, since PHP turns
     * a numeric string key into an integer.
     *
     * @param  array<string, array{configured: list<array{github_id: int}>, stored: list<array<string, mixed>>}>  $lists  The lists.
     * @return array<string, array{login: string|null, signed_in: bool}> Names, keyed `id:<github id>`.
     */
    private function names(array $lists): array
    {
        $ids = [];
        $entered = [];

        foreach ($lists as $list) {
            foreach ($list['configured'] as $entry) {
                $ids[] = $entry['github_id'];
            }

            foreach ($list['stored'] as $entry) {
                if (\is_int($entry['github_id'] ?? null)) {
                    $ids[] = $entry['github_id'];

                    // Checked as a login, since a host may write the table directly
                    if (\is_string($entry['login'] ?? null) && preg_match(LaneHolds::LOGIN, $entry['login']) === 1) {
                        $entered[$entry['github_id']] = $entry['login'];
                    }
                }
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $signedIn = [];

        foreach (GithubIdentity::query()->whereIn('github_id', $ids)->get(['github_id', 'github_login']) as $identity) {
            $signedIn[$identity->github_id] = $identity->github_login;
        }

        $remembered = $this->service(GitHubAccounts::class)->logins($ids);
        $names = [];

        foreach ($ids as $id) {
            $names['id:'.$id] = isset($signedIn[$id])
                ? ['login' => $signedIn[$id], 'signed_in' => true]
                : ['login' => $remembered[$id] ?? $entered[$id] ?? null, 'signed_in' => false];
        }

        return $names;
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
