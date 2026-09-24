<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RobotCouncil\Access\Guard;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Support\Contracts\SuppliesUserAttributes;
use RuntimeException;

/**
 * Reads and writes the records behind a developer: the host application's user row, and the
 * package's own GitHub identity for it. The package owns no users table, and takes the model from
 * the provider behind the configured guard.
 *
 * The host's users table has to accept a row carrying only `name` and `email`; the install
 * migration relaxes the two columns Laravel's own skeleton makes NOT NULL. A table with other
 * NOT NULL columns that have no default needs an extension point this package does not have yet.
 */
final class HostUsers
{
    /**
     * @param  Repository  $config  The host application's configuration repository.
     * @param  Guard  $guard  The configured guard's name.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly Guard $guard,
        private readonly SuppliesUserAttributes $attributes
    ) {}

    /**
     * Resolve the host application's user model.
     *
     * @return class-string<Model> The Eloquent model behind the `users` authentication provider.
     *
     * @throws RuntimeException When the configured model is not an authenticatable Eloquent model.
     */
    public function modelClass(): string
    {
        $provider = $this->providerName();

        $model = $this->config->get(sprintf('auth.providers.%s.model', $provider));

        // Refuse before any row is read or written, rather than failing after creating a user
        if (! \is_string($model) || ! is_subclass_of($model, Model::class) || ! is_a($model, Authenticatable::class, true)) {
            throw new RuntimeException(sprintf('Set `auth.providers.%s.model` to an Eloquent model that implements Authenticatable for robot-council.', $provider));
        }

        return $model;
    }

    /**
     * The authentication provider behind the package's configured guard.
     *
     * Derived from the guard rather than assumed to be `users`, because the guard is configurable
     * for exactly this reason: a host with several guards may keep its people under another
     * provider entirely, and reading one guard while resolving another's model is how a package
     * ends up creating rows in the wrong table.
     *
     * @return string The provider's name in `auth.providers`.
     */
    public function providerName(): string
    {
        $provider = $this->config->get(sprintf('auth.guards.%s.provider', $this->guard->name()));

        return \is_string($provider) && $provider !== '' ? $provider : 'users';
    }

    /**
     * Find the package's identity for a GitHub account.
     *
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @return GithubIdentity|null The identity, or null when the account has never signed in.
     */
    public function findIdentity(int $githubId): ?GithubIdentity
    {
        return GithubIdentity::query()->where('github_id', $githubId)->first();
    }

    /**
     * Find the host user an identity points at.
     *
     * @param  GithubIdentity  $identity  The identity to resolve.
     * @return Model|null The user, or null when the host has deleted or hidden the row.
     */
    public function findUserFor(GithubIdentity $identity): ?Model
    {
        $model = $this->modelClass();

        return $model::query()->whereKey($identity->user_id)->first();
    }

    /**
     * Find a host user by email address, without regard to case.
     *
     * @param  string  $email  The email address to look for.
     * @return Model|null The matching user, or null when no user holds that address.
     */
    public function findByEmail(string $email): ?Model
    {
        $model = $this->modelClass();

        // Compare case-insensitively, because collations differ by host database.
        //
        // **This cannot use a plain `users.email` index, and the cost is recorded rather than
        // guessed at** (#38). Wrapping the column in `lower()` makes the predicate non-sargable, so
        // a host with a b-tree index on `email` gets a sequential scan. Measured on PostgreSQL 17
        // against 200,000 users with a unique index on `email`:
        //
        // | predicate                | plan       | shared buffers |
        // | ------------------------ | ---------- | -------------- |
        // | `lower(email) = ?`       | Seq Scan   | 1,667          |
        // | `email = ?` (control)    | Index Scan | 4              |
        //
        // It is not fixed here because the table belongs to the **host**: this package adds no
        // index to it, and narrowing to an exact match would trade a correctness property -- two
        // addresses differing only in case are one account -- for a plan. A host that feels it adds
        // `create index on users (lower(email))`, which this predicate then uses.
        //
        // It runs once per sign-in, and on the create path of a first-time sign-in it runs where
        // there is nothing to find, which is the scan's worst case.
        return $model::query()
            ->whereRaw('lower(email) = ?', [Str::lower($email)])
            ->first();
    }

    /**
     * Whether any row holds this email, including one the host's model cannot see.
     *
     * **`findByEmail()` and the `users.email` unique index disagree, and that gap is a lockout.**
     * `$model::query()` applies the host model's global scopes, so a host using `SoftDeletes`
     * cannot see a trashed user -- while the unique index still can. A developer whose GitHub email
     * belongs to a previously deleted account therefore reads as "no such user", is sent down the
     * create path, and hits an integrity error that used to be reported as a bare 409 with nothing
     * naming the cause, on every attempt forever (#38).
     *
     * This is the same question asked of the table rather than of the model, so the answer matches
     * what the index will do. `withoutGlobalScopes()` rather than `withTrashed()`, because the
     * host's model may not use `SoftDeletes` at all and this package must not assume which scopes a
     * host has added -- a tenant scope hides a row from `findByEmail()` exactly as a soft delete
     * does, and the index does not care which one it was.
     *
     * @param  string  $email  The address to look for.
     * @return bool Whether any row holds it, visible or not.
     */
    public function emailIsHeld(string $email): bool
    {
        $model = $this->modelClass();

        return $model::query()
            ->withoutGlobalScopes()
            ->whereRaw('lower(email) = ?', [Str::lower($email)])
            ->exists();
    }

    /**
     * Create a host user for a developer signing in for the first time.
     *
     * @param  array<string, mixed>  $attributes  The columns to set on the new user.
     * @return Model The saved user.
     */
    public function create(array $attributes): Model
    {
        $model = $this->modelClass();

        $user = new $model;

        // Force-fill, because the host model's `$fillable` is not the package's to assume
        $user->forceFill($attributes)->save();

        return $user;
    }

    /**
     * Create the user row a developer signing in for the first time gets.
     *
     * **The attributes come from `Contracts\SuppliesUserAttributes`, which a host may rebind**, so
     * a users table with a `NOT NULL` column the package knows nothing about -- `tenant_id`,
     * `role_id`, a `first_name`/`last_name` pair -- can be filled without extending anything (#36).
     * The package still constructs and saves the model, so what a developer's row is remains
     * something it can state.
     *
     * @param  NewDeveloper  $developer  The account signing in, already admitted by the allowlist.
     * @return Model The saved user.
     */
    public function createFor(NewDeveloper $developer): Model
    {
        return $this->create($this->attributes->for($developer));
    }

    /**
     * Record, or refresh, the GitHub identity for a host user.
     *
     * @param  Model  $user  The host user the account belongs to.
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @param  string  $login  The account's login.
     * @param  string|null  $avatarUrl  The account's avatar URL, when GitHub exposes one.
     * @return GithubIdentity The saved identity.
     */
    public function recordIdentity(Model $user, int $githubId, string $login, ?string $avatarUrl): GithubIdentity
    {
        return GithubIdentity::query()->updateOrCreate(
            ['github_id' => $githubId],
            [
                'user_id' => HostKey::from($user->getKey()),
                'github_login' => $login,
                'avatar_url' => $avatarUrl,
            ]
        );
    }

    /**
     * Read the GitHub user ID recorded for a signed-in user.
     *
     * @param  Authenticatable  $user  The user resolved by the host application's guard.
     * @return int|null The account's GitHub user ID, or null when the user has no identity.
     */
    public function githubId(Authenticatable $user): ?int
    {
        return $this->githubIdForKey($user->getAuthIdentifier());
    }

    /**
     * Read the GitHub login recorded against a key in the host's users table.
     *
     * For display only, as the identities table's own comment says: a login can be renamed and then
     * claimed by somebody else, so nothing is ever decided on one.
     *
     * @param  mixed  $key  The user's primary key, as the host's model types it.
     * @return string|null The login last seen at sign-in, or null when no identity points at that key.
     */
    public function githubLoginForKey(mixed $key): ?string
    {
        $userId = HostKey::tryFrom($key);

        if ($userId === null) {
            return null;
        }

        return GithubIdentity::query()->where('user_id', $userId)->first()?->github_login;
    }

    /**
     * The GitHub account IDs behind several host user keys, in one query.
     *
     * **The bulk form exists so a fleet-wide question is not N queries.** `githubIdForKey()` is
     * right for one principal on one request; `Support\FleetAbilities` asks about every developer
     * holding a live session in the role under question, on a route an agent may call twice a
     * second.
     *
     * A key the identity table does not know is absent from the result rather than null in it,
     * because every caller reads absence as "not admitted" and a null would have to be checked
     * for separately to mean the same thing.
     *
     * **`array<string, int>` is the intent, and PHP cannot quite hold it.** A canonical numeric
     * key arrives back as an `int` key, because that coercion is the language's and no expression
     * prevents it -- so `'5'` goes in and `5` comes out. Reading a key back out and binding it into
     * a query is therefore the defect the body below records, arriving through the other door;
     * `Support\FleetAbilities`, the only caller, reads the VALUES. A caller that needs the key
     * itself should keep the one it asked with rather than take it from this map.
     *
     * @param  list<mixed>  $keys  The host user keys to look up.
     * @return array<string, int> The GitHub ID per key, for the keys that have one.
     */
    public function githubIdsForKeys(array $keys): array
    {
        $narrowed = [];

        foreach ($keys as $key) {
            $userId = HostKey::tryFrom($key);

            if ($userId !== null) {
                // **Held as the VALUE as well as the key, and read back as the value.** The key is
                // only here to deduplicate. Writing `= true` and reading `array_keys()` back was an
                // access-control defect on MySQL, for the reason the query below records.
                $narrowed[$userId] = $userId;
            }
        }

        if ($narrowed === []) {
            return [];
        }

        $found = [];

        // **Every binding leaves here as text, and on MySQL that is an access-control property
        // rather than a tidiness one** (#247).
        //
        // A PHP array key coerces a canonical numeric string to an integer, so the earlier
        // `array_keys()` handed `5` where the row said `'5'` -- and MySQL compares a text column
        // against an integer by casting the COLUMN to a number, so every key that is numerically
        // five matches. Measured on MySQL 9.7.2 and MariaDB 12.3.2 with rows `'5'`, `'5x'`, `'05'`,
        // `' 5'` and `'5.0'`: `githubIdsForKeys(['5'])` returned all five developers' GitHub IDs.
        //
        // **That is not a display bug.** `Support\FleetAbilities` reads this map's VALUES and asks
        // the allowlist about each, so one unrelated developer who happened to be listed made the
        // whole fleet report able to direct. `Support\FleetAbilities` was given this same treatment
        // by `robot-council/core#223`; this is the other half of it.
        //
        // **`2026_09_22_000002`'s byte-exact collation does not cover this**, which is the part
        // worth knowing before someone concludes it does. Measured on MySQL 9.7.2: the column is
        // `utf8mb4_bin` and the coercion happens anyway, because a numeric comparison casts the
        // column before any collation is consulted.
        //
        // Postgres and SQLite are unaffected -- measured on PostgreSQL 17.6 and 18.0 and SQLite
        // 3.51.3, an integer binding matched `'5'` alone -- and there is no `mysql` CI job, so
        // nothing in CI can fail on the rows. `it('binds every host key as text, on every engine')`
        // asserts the BINDINGS instead, which every engine can answer.
        foreach (GithubIdentity::query()->whereIn('user_id', array_values($narrowed))->get() as $identity) {
            $found[$identity->user_id] = $identity->github_id;
        }

        return $found;
    }

    /**
     * Read the GitHub user ID recorded against a key in the host's users table.
     *
     * Used where the developer is known by key rather than as a signed-in user: an installation
     * and an agent session each record the developer they belong to, and every request they make
     * is checked against the access lists again.
     *
     * @param  mixed  $key  The user's primary key, as the host's model types it.
     * @return int|null The account's GitHub user ID, or null when no identity points at that key.
     */
    public function githubIdForKey(mixed $key): ?int
    {
        $userId = HostKey::tryFrom($key);

        if ($userId === null) {
            return null;
        }

        // Compared as text, because `HostKey::tryFrom()` has already made it text and nothing
        // between there and here turns it back into a number.
        //
        // **The reason this comment used to give was wrong, and the correction is worth keeping**
        // (#247). It said that binding an integer against a text column "is a type error on
        // Postgres rather than a miss". Measured through this package's own connection on
        // PostgreSQL 17.6 and 18.0: `where('user_id', 5)` against `varchar(64)` returns the `'5'`
        // row and nothing else, with no error at all.
        //
        // The type error is real, but it belongs to an inlined LITERAL rather than to a binding.
        // `where user_id = 5` written into the SQL answers `SQLSTATE[42883] operator does not
        // exist: character varying = integer`, while `where user_id = ?` reaches Postgres with no
        // declared type and is inferred from the column, so the comparison is text against text.
        // Laravel never inlines, so this code could not reach the error it was warned about.
        //
        // The engine that really does coerce is MySQL, it coerces the other way -- the column to a
        // number -- and `githubIdsForKeys()` above is where that mattered.
        $identity = GithubIdentity::query()->where('user_id', $userId)->first();

        return $identity?->github_id;
    }
}
