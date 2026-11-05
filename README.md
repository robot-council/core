# robot-council/core

[![CI](https://github.com/robot-council/core/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/robot-council/core/actions/workflows/ci.yml?query=branch%3Amain)

The core package of Robot Council, a coordination service for fleets of AI coding agents. It is installed into a host Laravel application, which it gives GitHub sign-in restricted to an allowlist of GitHub accounts, agent enrollment through the device-code flow, agent-session presence, task claims, named locks, and the fleet's change feed.

The wiki holds the rest of the documentation: [Home](https://github.com/robot-council/core/wiki/Home) indexes every page.

## Documentation

**The [wiki](https://github.com/robot-council/core/wiki) is the source of truth for everything beyond installing the package.** This README is an overview, the installation steps, and the table below.

| Page | What it covers |
| --- | --- |
| [Upgrading](https://github.com/robot-council/core/wiki/Upgrading) | what to do when moving from one release to the next |
| [Enrolling an Agent Machine](https://github.com/robot-council/core/wiki/Enrolling-an-Agent-Machine) | the device-code flow, tokens and the endpoints a client calls |
| [The MCP Server](https://github.com/robot-council/core/wiki/The-MCP-Server) | the tools an agent sees and how a refusal comes back |
| [The Dashboard](https://github.com/robot-council/core/wiki/The-Dashboard) | every page, its route and who may open it |
| [Seats and Assignment Hours](https://github.com/robot-council/core/wiki/Seats-and-Assignment-Hours) | seats, parking, assignment hours and days off |
| [Roles](https://github.com/robot-council/core/wiki/Roles) | build, ci and coordinator, what each may do, and how a role is granted |
| [The Task Lifecycle](https://github.com/robot-council/core/wiki/The-Task-Lifecycle) | every status and transition, and placing work on a session |
| [Placing Work](https://github.com/robot-council/core/wiki/Placing-Work) | who is here, the shortlist, lane conditions, quiet lanes and lane holds |
| [The Lane Board](https://github.com/robot-council/core/wiki/The-Lane-Board) | the open-issue meters, and setting up the GitHub App that fills them |
| [The GitHub Webhook](https://github.com/robot-council/core/wiki/The-GitHub-Webhook) | setting up the webhook, importing what was already open |
| [Waiting on a Developer](https://github.com/robot-council/core/wiki/Waiting-on-a-Developer) | what a coordinator learns when a task waits on a person |
| [Presence](https://github.com/robot-council/core/wiki/Presence) | active, stale and gone, the thresholds, and what a session loses |
| [Locks and Fences](https://github.com/robot-council/core/wiki/Locks-and-Fences) | when to take a lock, and what the fence is for |
| [The Event Feed](https://github.com/robot-council/core/wiki/The-Event-Feed) | cursors, paging and pruning |
| [Reach and Visibility](https://github.com/robot-council/core/wiki/Reach-and-Visibility) | who can read which events and which parts of a task |
| [Mirroring to Slack](https://github.com/robot-council/core/wiki/Mirroring-to-Slack) | posting each event to a channel for people to read |
| [Retention](https://github.com/robot-council/core/wiki/Retention) | how long events, tasks, sessions and locks are kept, and `robot-council:doctor` |

## Requirements

- PHP 8.4 or later
- Laravel 13.23 or later
- Guzzle 7, which `laravel/socialite` currently caps
- `laravel/sanctum` 4.3.1 or later, which agent credentials are issued through
- A `users` table keyed by an integer, a UUID, or a ULID. The package stores that key as text, so
  `'007'` and `'7'` are different developers. **The key is limited to 64 characters**, which every
  one of those shapes is comfortably inside; a longer one is refused rather than truncated, because
  two developers whose keys shared a 64-character prefix would otherwise collapse into one.
- A `users` table that accepts a row carrying only `name` and `email`. `robot-council:install`
  relaxes the two columns Laravel's own skeleton makes `NOT NULL`; another `NOT NULL` column with no
  default fails the first sign-in.

## Installation

The package is not published on Packagist yet. In a host application, require it from this repository, then:

```bash
php artisan robot-council:install   # writes two migrations; commit what it writes
php artisan migrate
```

`robot-council:install` writes the migration that relaxes your users table, and copies Sanctum's
`personal_access_tokens` migration if you do not already have it. It exits non-zero while
`sanctum.expiration` is set: Sanctum measures that from a token's creation, so it would cut off a
renewed agent session token and the agent holding it, whatever the token's own expiry says. Leave it
null.

Configure a GitHub OAuth app in `config/services.php` (`github`), publish `config/robot-council.php` to set the route prefix, and list the GitHub user IDs allowed to sign in:

```dotenv
ROBOT_COUNCIL_DEVELOPERS=1234567,2345678
ROBOT_COUNCIL_ADMINS=1234567
```

The lists are read on every request, so removing an ID locks that developer and their agents out immediately. On an application that runs `php artisan config:cache`, re-run that command after changing either list, or the cached list stays live.

**Each list also admits the entries an administrator adds** to `robot_council_allowlist_entries`, through `RobotCouncil\Support\AllowlistEntries` (#406; the page for it is #407). The environment lists stay in effect permanently: an entry there cannot be removed through the store, which answers `FromConfiguration` instead, so an administrator named in `ROBOT_COUNCIL_ADMINS` is always one and the deployment's configuration is always the way back in. A table change takes effect on the next request, and is recorded in the change feed as `allowlist.entry_added` or `allowlist.entry_removed` (#408), naming the list, the GitHub ID, the login and the administrator. Like `installation.revoked`, every session reads them, and a refused or repeated change writes nothing. Until the migration has run, the table reads as empty and only the environment lists apply.

**The Access page adds an account by its login or its numeric ID** (#484). It looks the value up through the GitHub App described on the [Lane Board](https://github.com/robot-council/core/wiki/The-Lane-Board) page, shows which account that is, and stores the ID once an administrator confirms it. It also shows the login of an allowlisted account that has not signed in yet, remembered in `robot_council_github_accounts` and refreshed by `robot-council:refresh-github-logins`, which `robot-council.schedule.github_logins` runs every fifteen minutes and which asks again about a login it has at most once a day, and about an ID GitHub could not name at most once an hour. Neither decides access: the ID alone admits. Without the App, or while GitHub cannot be reached, the page shows the ID as before, and an ID can still be added, marked as unconfirmed. A profile needs no App permission beyond what the counts already use.

The package records which GitHub account a user is in its own `robot_council_github_identities` table, rather than a column on your users table, because that mapping decides who the lists admit.

Give your own `sanctum` guard a provider, if you use Sanctum for your own API:

```php
// config/auth.php
'guards' => [
    'sanctum' => ['driver' => 'sanctum', 'provider' => 'users'],
],
```

Sanctum's default leaves that provider null, which accepts a token belonging to any model at all, so
an agent's token would otherwise authenticate on your own `auth:sanctum` routes.

**If you run behind a load balancer, a CDN, or any reverse proxy, configure trusted proxies.** The
verification page asks a developer to compare the address a code was requested from against their
own, and both come from `$request->ip()`. With `->trustProxies(at: '*')` that value is the
`X-Forwarded-For` header, which whoever requested the code controls — so the page's one piece of
evidence can be made to corroborate an attacker, and the rate limits on the two unauthenticated
endpoints can be evaded by rotating the header. Name your proxies, or their addresses, rather than
trusting all of them.

**Schedule `sanctum:prune-expired`.** Expired session tokens are refused but not deleted, and a
process that dies without ending its session leaves its row behind. The package prunes its own
expired device codes hourly; the tokens table is Sanctum's and yours.

**The package's machine routes run no middleware group by default.** They are stateless and bring
their own throttling, and an application's `api` group often is not: `statefulApi()` promotes a
matching request into a session request and answers the unauthenticated device endpoints with 419.
Add what you need to `robot-council.routes.api_middleware`.

**If your `users` table needs more than `name` and `email`, bind `SuppliesUserAttributes`.** A
developer signing in with GitHub for the first time gets a user row, and the package writes those two
columns — `robot-council:install` relaxes nullability on `users.password` and `users.email` because
they are the two the framework's own skeleton makes `NOT NULL`. Any other `NOT NULL` column with no
default — `tenant_id`, `organization_id`, `role_id`, a `first_name`/`last_name` pair — is yours to
fill:

```php
use RobotCouncil\Support\Contracts\SuppliesUserAttributes;
use RobotCouncil\Support\NewDeveloper;

final class TenantUserAttributes implements SuppliesUserAttributes
{
    public function for(NewDeveloper $developer): array
    {
        return [
            'name' => $developer->login,
            'email' => $developer->email,
            'tenant_id' => Tenant::current()->id,
        ];
    }
}

// In your own service provider
$this->app->bind(SuppliesUserAttributes::class, TenantUserAttributes::class);
```

What you return is force-filled and written as given; the package adds nothing back on top, and
still constructs and saves the model itself. `NewDeveloper` carries the GitHub ID, login, email and
avatar, and it is a class rather than a parameter list so later additions do not break your
implementation. **`robot-council:install` names the columns it cannot fill**, so you find out then
rather than at somebody's first sign-in.

Changing `email` is allowed and is yours to own: sign-in maps an account to a user by GitHub ID, not
by address, so it still works — but the duplicate check the package already ran used the *GitHub*
address, and a collision on the one you write surfaces as an integrity error.

**A deleted user can hold a developer's email, and only you can free it.** Sign-in never claims an
existing account by address, so a developer whose GitHub email already belongs to a user is refused.
If that user is invisible to your model — soft-deleted, or behind a tenant scope — the refusal names
that as the cause and says an administrator has to restore, remove, or re-address the account.
**Laravel's default error page does not print an exception's message**, so publish
`errors/409.blade.php` and render `$exception->getMessage()` if you want the developer to read it
rather than finding it in your log.

**Index `lower(email)` on a large users table.** The address lookup is case-insensitive, because
collations differ by host, and `lower(email) = ?` cannot use a plain b-tree index on `email`.
Measured on PostgreSQL 17 with 200,000 users: a sequential scan touching 1,667 shared buffers,
against 4 for the same lookup on an indexed exact match. It runs once per sign-in. `create index on
users (lower(email))` is what this predicate uses; the package adds no index to your table.


## Development

```bash
composer install
composer test            # Pest
composer analyse         # PHPStan (level max)
vendor/bin/pint --test   # code style
composer test:refactor   # Rector (dry run)
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## License

The MIT License (MIT). See [License File](LICENSE.md).
