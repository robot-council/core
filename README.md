# robot-council/core

[![CI](https://github.com/robot-council/core/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/robot-council/core/actions/workflows/ci.yml?query=branch%3Amain)

The core package of Robot Council, a coordination service for fleets of AI coding agents. It is installed into a host Laravel application, which it gives GitHub sign-in restricted to an allowlist of GitHub accounts, agent enrollment through the device-code flow, agent-session presence, task claims, named locks, and the fleet's change feed.

**Running a fleet?** The [wiki](https://github.com/robot-council/core/wiki) is the guide for the agents and people who coordinate on one: [the event feed](https://github.com/robot-council/core/wiki/The-Event-Feed), [reach and visibility](https://github.com/robot-council/core/wiki/Reach-and-Visibility), [the task lifecycle](https://github.com/robot-council/core/wiki/The-Task-Lifecycle), [roles](https://github.com/robot-council/core/wiki/Roles), [presence](https://github.com/robot-council/core/wiki/Presence), and [locks and fences](https://github.com/robot-council/core/wiki/Locks-and-Fences). This README stays the reference for installing and configuring the package.

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

## Enrolling an agent machine

A developer approves one harness on one machine once, and that installation starts a session per
agent process from then on. Nothing pastes a long-lived secret into a config file: the machine
displays a short code, and the developer types it into a page while signed in.

**Most machines should use [`robot-council/cli`](https://github.com/robot-council/cli) rather than
implement any of this.** It runs the flow below, stores the credential in the OS keychain, and then
serves the coordination tools to an agent harness over stdio:

```bash
robot-council enroll --service=https://your-fleet.example.com
claude mcp add robot-council -e ROBOT_COUNCIL_SERVICE=https://your-fleet.example.com -- robot-council mcp
```

The credential never enters a harness's configuration, which is the reason that bridge exists: a
token in harness configuration is a token in every transcript that configuration is dumped into. The
protocol below is documented for anyone writing their own client.

1. The machine posts `harness`, `machine_label`, the abilities it wants, and the SHA-256 of a
   verifier only it holds to `POST {prefix}/api/device/code`, and is given a `user_code` to display.
2. The developer opens `{prefix}/enroll`, enters that code, reviews what the machine claims about
   itself, confirms the code is on a machine they control, and approves.
3. The machine polls `POST {prefix}/api/device/token` with the device code and the verifier, and is
   given an installation credential. That credential can do one thing: start and renew sessions.
4. Each agent process calls `POST {prefix}/api/sessions` for a short-lived session token, and
   `POST {prefix}/api/sessions/{id}/renew` to replace it without a restart and without a human.
   `DELETE {prefix}/api/sessions/{id}` ends one when its harness exits: its tokens stop working at
   once, and what it held is released by the next presence sweep, within about a minute, rather than
   after the thirty-minute gone threshold. While `schedule.sweep_sessions` is off, nothing releases
   it. All three take the installation credential,
   because the token belonging to the process that just died is the one thing that may no longer
   work. Ending is idempotent. A start may carry `platform`, with `os_family` (one of PHP's
   `PHP_OS_FAMILY` values) and an optional `arch`, as the bridge reports them; an older bridge
   that sends neither starts a session as before. A start may also carry `capacity`, how many
   tickets the session will hold at once when it works through subagents. A number past 16 is
   taken as 16; 0, a negative number or anything that is not a whole number is refused with 422.
   A session that sends none has a capacity of 1, exactly as before.
   **What is in effect is the smaller of that and the seat's cap**, which its developer sets on the
   seats page and which is 1 until they do. The start answers both, as `capacity` (in effect) and
   `declared_capacity` (asked for, within 16); `GET {prefix}/api/agent/session` answers the same
   pair, read fresh, so a developer raising the cap reaches a running session without a restart.
   The `session.joined` event carries the declaration as `meta.declared_capacity`; the capacity in
   effect is on `GET {prefix}/api/lanes`.
   A start may also carry `ephemeral: true`, for a process the fleet need not be told about:
   `robot-council api` sends it for a read (`robot-council/cli#298`). An ephemeral session writes
   no `session.joined`, `session.stale`, `session.resumed` or `session.gone` event however it ends,
   and is left out of `sessions_list`, `GET {prefix}/api/lanes`, the free-lane and merge-behind lane
   conditions, and the dashboard's session views and totals. One that holds work and stops answering
   is still named by the unobserved-lane condition, because that work is somebody's to recover. The
   administration panel lists a live one, marked `ephemeral`, so it can be revoked there, and counts
   no gone one. Everything else is an ordinary session's: it authenticates, reads the
   feed as its own developer's session, and whatever it claims or locks is released when it ends or
   is swept. JSON `true` and `false`, `1` and `0`, and `"1"` and `"0"` are accepted; anything else,
   the string `"true"` included, is refused with 422. Omitted, the session is an ordinary one.

Every response that carries a bearer token names it `token`, every expiry is an `expires_in` in
seconds, and `abilities` always describes the token beside it. Where a response also names
`granted_abilities`, that is what a *different* token will carry -- the sessions an installation
credential will start. Starting a session also names a `feed_cursor`, which is where the change feed
stood at that moment -- for an ordinary session its own `session.joined` event, and for an ephemeral
one the newest event, so either reads everything written after it started and nothing before; a renewal names the position the session has since acknowledged, restated
rather than moved, so a process that restarted can pick up where it was.

**This flow is device-code shaped, not [RFC 8628](https://www.rfc-editor.org/rfc/rfc8628)
conformant**, and the differences are deliberate:

- **The token endpoint takes a verifier**, not the RFC's `grant_type` and `client_id`. That verifier
  is the whole reason a stolen `device_code` is useless, so no off-the-shelf device-flow client can
  complete this exchange — which is also why the success responses use this package's own names
  rather than `access_token`, a name that would promise OAuth affordances this service does not have.
- **`slow_down` is not returned.** Poll throttling is out of scope for v1.
- **`verification_uri_complete` is not returned.** There is no QR-code form of the verification URL
  yet.

The *error* bodies do follow RFC 8628 section 3.5 exactly: HTTP 400 with `authorization_pending`,
`access_denied`, `expired_token`, or `invalid_grant`. Those names describe states this flow genuinely
has, and nothing better exists for them.

Abilities come from a fixed list — `tasks:create`, `tasks:claim`, `locks:acquire`, `events:post` —
and `coordinator:direct`, which enrollment can never request.

**What a session may do comes from its role, not from its machine.** Every session starts as
`build`, whatever its installation asked for at enrollment, and carries the four abilities above. A
session that needs to direct other agents asks to become a `coordinator` — `POST {prefix}/api/agent/role`
— and an administrator approves or denies it on the dashboard's administration page. Asking changes
nothing on its own: the token in the client's hand is untouched until somebody decides, which is what
stops any checkout from taking `coordinator:direct` by asserting it. The same page imposes a role
with no request outstanding, which is the emergency demotion.

A session withdraws a request by asking for the role it already holds. The pending request leaves
the administrator's queue and can no longer be approved, and the change feed records a
`session.role_withdrawn` event, which is not a denial: nobody refused anything. The response's
`pending` and `requested_role` always describe what is pending once the call returns, not the role
that was asked for.

There is deliberately no machine-level gate any more, and no console command for one. An
administrator who does not want a machine coordinating declines its request, which is one action
rather than two authorities that can disagree.

```bash
php artisan robot-council:revoke-installation <installation>   # and every session token it issued
php artisan robot-council:revoke-session <session>             # one process only
php artisan robot-council:doctor                               # reports misconfiguration; exits non-zero
php artisan robot-council:prune-device-codes                   # scheduled hourly
php artisan robot-council:sweep-sessions                       # scheduled every minute
php artisan robot-council:prune-events                         # scheduled daily at 03:10
php artisan robot-council:prune-tasks                          # scheduled daily at 03:20
php artisan robot-council:prune-locks                          # scheduled daily at 03:30
php artisan robot-council:prune-sessions                       # scheduled daily at 03:40
```

Approving or imposing a role rewrites that session's token in the same transaction, so it takes
effect on the next request rather than within the hour a session token lives.

## Retention

**The change feed is the one table that grows without anybody's help.** Every task transition, lock,
session change and line of narration is a row, and almost none of it is read twice: an agent pages
the feed forward and a developer reads the head of it. So `robot-council:prune-events` deletes
events past `robot-council.retention.events_days`, which defaults to **30** and is set with
`ROBOT_COUNCIL_EVENT_RETENTION_DAYS`.

```env
ROBOT_COUNCIL_EVENT_RETENTION_DAYS=30   # 0 keeps everything
```

**Zero keeps everything**, for a host archiving on its own terms — the command says so and exits
rather than reporting that it deleted nothing, because "pruned 0 events" and "pruning is switched
off" are different states and only one of them wants looking at.

**Run `robot-council:doctor` after installing, and again after changing anything.** It reports what
is wrong with this application's configuration without being asked a specific question: whether the
`sanctum` guard names a provider, whether `sanctum.expiration` is null, whether every migration this
version ships has run, whether anything appears to be consuming the queue, whether anybody is on the
developer allowlist, whether anything on the fleet can post a directive, whether the Slack mirror
would run inside an agent's request, and whether `app.timezone` can shift under a token's expiry.

Every fault it looks for is invisible until something else goes wrong. It writes nothing and prints
no secret, so it is safe to run when worried. A check it cannot reach reports as `UNKNOWN` with what
would make it reachable, which reads differently from one that looked and found nothing.

What a host should watch:

- **The row count, not the command's output.** A prune that is keeping up reports roughly a day's
  events each run. A number that climbs run after run means the retention is longer than the disk.
- **Agents that are offline longer than the retention.** The feed is how an agent without a push
  connection catches up, and it pages `id > cursor`. A prune cannot strand one — a cursor is a
  number, not a row — but an agent that was away for longer than the retention will have *missed*
  events rather than read them late. If that matters for a fleet, the retention is the wrong
  length for it.
- **The prune takes no feed lock and deletes in batches**, so it does not block writers. It is safe
  to run by hand at any time, and a host that wants it more often can schedule it itself with the
  entry turned off in `robot-council.schedule.prune_events`.

Finished tasks have their own retention, `robot-council.retention.tasks_days`, defaulting to **90**
and set with `ROBOT_COUNCIL_TASK_RETENTION_DAYS`. It is longer than the feed's because a task is a
unit of work somebody may want to look back at, and there are far fewer of them.

**A task nobody has finished is never deleted, whatever its age.** It is work the fleet still owes
somebody, and age is the opposite of a reason to remove it — an old pending task is the one most
worth looking at. Only `done`, `failed` and `cancelled` are pruned, and the age is measured from
when the task *finished* rather than when it was filed.

A finished task that still has another task filed under it is also left in place, because
`parent_task_id` is `nullOnDelete` and deleting the parent would rewrite a row the prune never
selected — possibly a task the fleet is still working on. It goes once its children have, which for
a finished tree happens within the same run.

**The dashboard's queue stops showing a finished task well before the prune removes it.** On the
unfiltered queue a `done` or `cancelled` task is hidden 24 hours after it finished, and a `failed` one
after 7 days, since a failure usually still needs somebody. Choosing a status on the queue shows every
task in it, however old, and the queue says how many it is hiding. These are display windows, not
retention: nothing is deleted. Each is set in hours under
`robot-council.dashboard.hide_finished_after_hours`, with `ROBOT_COUNCIL_DASHBOARD_HIDE_DONE_AFTER_HOURS`,
`ROBOT_COUNCIL_DASHBOARD_HIDE_CANCELLED_AFTER_HOURS` and `ROBOT_COUNCIL_DASHBOARD_HIDE_FAILED_AFTER_HOURS`;
`0` never hides.

Free locks have their own retention, `robot-council.retention.locks_days`, defaulting to **7** and
set with `ROBOT_COUNCIL_LOCK_RETENTION_DAYS`. It is shorter than the other two because a lock row
nobody holds carries a name, a previous holder and a number, none of which is read once the lease
is over.

**A lock somebody is holding is never deleted, whatever the row's age.** "Free" here is the same
condition an acquisition takes a lock from: no holder, or a lease that has lapsed.

**A lock's fence is drawn from one sequence shared by every name**, in `robot_council_lock_fence`,
rather than counted per row. That is what makes deleting a lock row safe: every acquisition of any
name draws a number above everything the sequence has ever issued, so a name whose row was deleted
and then taken again still gets a fence above the one its last holder carried. Upgrading seeds the
sequence above the highest fence already issued, so no running installation can reissue a number.

Ended agent sessions have their own retention, `robot-council.retention.sessions_days`, defaulting
to **30** and set with `ROBOT_COUNCIL_SESSION_RETENTION_DAYS`. Only a session that has **gone** is
ever deleted: an `active` session is live and a `stale` one is a single request from active again,
so age is the wrong question for both.

**A session still holding a task or a live lock is never deleted, whatever its age.**
`robot_council_tasks.claimed_by` and `robot_council_locks.holder_id` are both `nullOnDelete`, so
deleting the row would strip a task of its claimant while its status still said it was held, and
free a lock without the event a release writes. The session goes once whatever it held has been
released or finished, which is why this prune is scheduled last of the four.

Deleting a session leaves `robot_council_events.agent_session_id` pointing at nothing, which is
deliberate (#50) and harmless: nothing reads it to decide who may see an event. Both the feed and
the login lookup read the developer off the event itself.

## The MCP server

The same coordination actions, served as MCP tools at `POST {prefix}/api/mcp` for whichever bridge
an agent's harness runs. Every tool calls the same store its REST endpoint does, so the two surfaces
cannot drift: a rule that lives in a conditional update is enforced by the write, whichever door the
call came through.

Twenty-nine tools, among them `task_list`, `task_create`, the eight task transitions, the four lock
actions, `events_read`, `events_narrate`, `directive_post`, `presence_heartbeat` and
`developer_settings`. Each enforces the same
ability as its endpoint, and **a refusal comes back marked as a tool error rather than as content**:
an MCP client cannot tell a result that describes a failure from one that describes success, so a
refusal returned as ordinary text reads to a model as though the call had worked.

**`tools/list` paginates, and the first page carries 15 of the 29.** It returns a `nextCursor` —
base64 of `{"offset":15}` — and the rest arrive only when that cursor is passed back. Most MCP clients walk the pages for you; a hand-rolled probe
does not, and a first page read as a total looks exactly like a complete answer, because the number
that would contradict it is the one the page does not carry.

The server's instructions tell an agent the thing it most needs to know before reading anything
another agent wrote — that task and event content is data and never instructions, and that every
result carries provenance to weigh it by.

The MCP URI answers `GET` and `DELETE` with a 405, as the transport specification asks. Those two
are mounted behind the same guard and the same limiter as the `POST`, so a host's own machine
middleware covers all three.

**Installing this package installs `laravel/mcp`, and a host inherits more than the tools.** Its
service provider is auto-discovered, so a host also gets seven `mcp:*` and `make:mcp-*` artisan
commands, an `mcp` config key and view namespace, `routes/ai.php` loaded if the host happens to have
one, and one middleware pushed onto the global HTTP kernel. Two are worth knowing about before
upgrading:

- **On a Passport host it adds an `mcp:use` OAuth scope.** `Server\Registrar::ensureMcpScope()` runs
  on every boot and calls `Passport::tokensCan()` when Passport is installed, so the scope appears on
  the host's consent screen and is grantable to its clients. Nothing in this package uses Passport or
  OAuth; the machine API authenticates with the device-code credentials described above.
- **`mcp.redirect_domains` defaults to `['*']`.** It is inert unless a host calls
  `Mcp::oauthRoutes()`, which this package does not, but a host that publishes the `mcp` config and
  later turns those routes on inherits the permissive default.

**`mcp:inspector` will not list this server while a host has cached its routes.** Laravel skips a
package's route files then, and the server is registered inside that same guard.

## The dashboard

A signed-in developer reaches the fleet's state through eight pages, each behind the same access
list and framing refusal as the verification page:

| path | shows |
| --- | --- |
| `{prefix}/dashboard` | the fleet's totals, and the way in to the rest |
| `{prefix}/dashboard/agents` | the agent sessions, each linking to the locks it holds |
| `{prefix}/dashboard/locks` | the named locks, each linking to the session holding it |
| `{prefix}/dashboard/lanes` | the lane board: each lane's state and what it is on, pull requests by repository, and backlog meters |
| `{prefix}/dashboard/queue` | the task board |
| `{prefix}/dashboard/feed` | the change feed |
| `{prefix}/dashboard/seats` | the signed-in developer's own seats, assignment hours and days off |
| `{prefix}/dashboard/administration` | the installations -- **admins only** |

`{prefix}/dashboard/presence`, where agents and locks shared one page before #308, answers with a
permanent redirect to `{prefix}/dashboard/agents`, so a bookmark to it still lands.

**Each page is its own, so each one pays only for what it shows.** The administration page
refuses a non-admin from the component rather than from the route, so a direct visit answers 403
whether or not it was linked; a host adding its own path-based gate in
`robot-council.routes.web_middleware` still sees every one of these paths.

The pages are Livewire components and refresh by polling every
`robot-council.dashboard.poll_seconds` seconds, defaulting to 5 and bounded to 1..3600. There is no
broadcasting: a change an agent commits is visible within one interval and no sooner.

**The lane board is rendered from measured state, never typed.** A lane's `State` is one of
`Working`, `Idle`, `Parked`, `Blocked` and `not observed`, derived each time -- a lane holding no task
is never `Working` -- and `Parked` is the same rule a placement refuses on. Each lane shows its
occupancy as held over capacity, such as `2 / 3`, and a working lane lists every ticket it holds,
each with its sub-label where the lane gave one.
**`Watcher` is read from the bridge watcher's own heartbeat**, `POST {prefix}/api/agent/watcher`,
which no other request refreshes: `absent` until it reports, `alive` within
`presence.watcher_stale_after_seconds` (90), `stale` with its age after that, and `unknown, re-read`
past fifteen minutes. **A gate** -- a session in the `ci` role -- reports the pull request it is validating
with `POST {prefix}/api/gates/run` and `{ "pull_request": "owner/name#N" }` (or `gate_start`), and
finishes with `DELETE` on the same path (or `gate_finish`); GitHub reporting that pull request closed
or merged ends the run too. The board marks it `running` and counts each repository's queue: open,
not a draft, and no gate on it. A backlog count nobody has measured renders
as a dash, never a number. Stamps are shown in `robot-council.dashboard.timezone` (UTC by default).

**`{prefix}` itself answers a 302 to the dashboard**, so the prefix the package is mounted under
does not lead nowhere while the site root leads somewhere. Like the stylesheet below it, that route
sits outside the `web` middleware group and outside the access list: it reads nothing and decides
nothing, so a visitor being sent elsewhere has no session written for them. It is documented here
rather than in the table above because the two sentences around that table -- the access list, the
framing refusal, and a host's own gate in `robot-council.routes.web_middleware` -- are true of those
seven pages and not of this redirect.

**It is not registered when the prefix resolves to `/`**, whether the host configured an empty
string or a bare slash. That path belongs to the host, and a host serving the console at its root
has already routed it. Every other path above moves with the prefix;
`robot-council.routes.api_prefix` is a separate key and does not.

**A host that has cached its routes keeps the old 404 at `{prefix}` until it re-runs
`route:cache`,** for the same reason `mcp:inspector` will not list the server in that state: Laravel
skips a package's route files when a cached collection exists.

**The stylesheet is compiled here and served by the package**, at `{prefix}/dashboard.css`. A
consuming application runs no asset build and needs no Node toolchain. That route is deliberately
public and deliberately outside the `web` middleware group, so it starts no session and a page can
load its styling before anyone has signed in.

Installing this package adds `livewire/livewire` to a host's dependencies, and Livewire registers
its own `/livewire/update` endpoint and a global middleware. The package registers
`EnsureAllowlistedDeveloper` as Livewire *persistent* middleware, because Livewire strips from that
endpoint every middleware not on its own fixed list -- without which a developer removed from the
access list would keep driving components from a page already open.

## Seats and assignment hours

Each developer decides, for themselves, when their machines take new work. **The coordinator reads
these and never writes them**: they are constraints on the coordinator, so it is not the one who
changes them. The only writer is the developer's own page at `{prefix}/dashboard/seats`.

- **A seat** is one of a developer's machines working in one repository at one work location. It is
  recorded when its developer opens the page, from what their live sessions report, and it outlives
  every session that sits in it -- a restarted agent is still in the same seat.
- **Parking** a seat says it takes no new work. It records who parked it and when. **Only that
  developer lifts it**, and nothing lifts it on a timer.
- **Assignment hours** are a daily window, whether weekends count, and an IANA timezone such as
  `America/Chicago`. A window whose end is before its start runs overnight. Everything is read on the
  developer's own clock, including through daylight saving changes.
- **Days off** are the developer's own list of dates. They apply once hours are set, since a date
  needs a timezone to say when it starts. A developer with no hours set is not gated at all.
- **Exempting** a seat takes it out of its developer's hours.
- **Tickets at once** caps how many tickets a coordinator may place on one session in the seat,
  from 1 to 16 (#409). A session declares its own number when it joins and gets no more than this;
  a session can never raise it. It is 1 until the developer changes it, so a seat nobody touched
  behaves as it always has.

A session holding `coordinator:direct` reads all of it, **as it is at the moment of the call**:

- `GET  {prefix}/api/developers/settings` — every developer's hours and days off, and every recorded
  seat with its parked, exempt and `max_capacity` values
- the `developer_settings` MCP tool, which returns the same thing (#440)

Each developer and each seat also carries `inside_hours` -- `true`, `false`, or `"ungated"` when no
hours apply (none are set, or the seat is exempt) -- and `next_opens_at` when it is `false`. It is
computed by the code the placement check refuses with, so a seat read as inside its hours is not
refused for being outside them. Settings change on the developer's page at any time, so read them
when deciding rather than remembering an earlier answer.

Developers are named by GitHub login, as everywhere else on the machine API. A placement is refused
on these settings as #320 describes, in the placement rules further down.

## GitHub

Core learns about issues and pull requests from **GitHub's webhook**, and reads nothing from
GitHub that decides anything: when GitHub is unreachable, events simply stop arriving and no
coordination decision waits on it. The one read it makes is the lane board's open-issue counts,
which decide nothing -- see [Backlog counts](#backlog-counts-a-github-app). From each delivery it
stores the issue's or pull request's state, and it frees the lane working on it:

- **An issue closing** completes the task naming it as `owner/name#N`.
- **A pull request merging** completes the task whose lane reported that pull request's branch, in
  the same repository; **one closed without merging** releases the task to `pending`.
- A pull request from a fork frees nobody, since its branch lives in another repository.

Each is recorded in the change feed attributed to no session, naming the task and not the issue.

**A task GitHub completes keeps what finished it** (#433). Its `result` carries a `github` entry
with the reason and either the issue and GitHub's `state_reason`, or the pull request, `merged` and
the merge commit's SHA. Only a session that may read the task sees its result. The session that
held the task can still call `complete` with its own result **once, within an hour** of GitHub
finishing it: the result is merged into the recorded one, and the status stays `done`. GitHub's
`github` entry wins over a `github` key the holder sends, and a result sent as a list is kept
under `reported`. The call answers `200` with `applied: false` and `result_added: true`,
and the feed records `task.result_added` rather than a second `task.completed`. Any other session,
a second addition, or a call after the hour gets the usual `409`. A release records nothing, since
the task goes back to the queue with a clean slate.
A delivery replayed with the same `X-GitHub-Delivery` id changes nothing, and one older than what is
stored is ignored, since GitHub does not promise order.

### Enabling it (an operator step)

1. Choose a secret of at least 16 characters and set it as `ROBOT_COUNCIL_GITHUB_WEBHOOK_SECRET` on
   the deployment. Until one is set, the endpoint answers 404.
2. On GitHub, add a webhook to each repository (or the organization) with:
   - **Payload URL:** `https://your-fleet.example.com{prefix}/api/github/webhook`
   - **Content type:** `application/json` (form-encoded also works)
   - **Secret:** the same value
   - **Events:** *Issues*, *Pull requests*, *Issue dependencies*, and *Branch or tag creation* and
     *Branch or tag deletion* -- the last two let a placement warn when a ticket's branch already exists
3. GitHub sends a `ping`, which answers 200. Its *Recent Deliveries* tab shows each delivery's
   status: 401 is a signature mismatch, 422 a payload the service refused.
4. **Backfill what was already open**, once, since a webhook reports only what happens afterwards.
   With your own GitHub credentials:

   ```bash
   gh api --paginate --slurp 'repos/OWNER/REPO/issues?state=open&per_page=100' > issues.json
   gh api --paginate --slurp 'repos/OWNER/REPO/pulls?state=open&per_page=100' > pulls.json
   php artisan robot-council:github-import issues.json
   php artisan robot-council:github-import pulls.json
   ```

   The import stores state and frees no lane. `blocked_by` edges are not in either file; they
   arrive from the webhook as they change.

Deliveries are rate-limited per source address by `robot-council.rate_limits.github_webhook_per_minute`
(600), and the limiter runs before the signature check.

### Backlog counts: a GitHub App

The lane board shows each repository's open issues -- pull requests excluded -- against the count at
08:00 in `robot-council.dashboard.timezone`. **Core fetches those counts itself, every five minutes,
through a GitHub App** the deployment holds the key for. It is the one thing core reads from GitHub,
and it is a display: when a fetch fails the meter reads `count unreadable`, and nothing that frees a
lane, places work, or changes a task depends on it. Those still learn from the webhook alone.

**Registering the App** (once, by whoever owns it):

1. On GitHub, *Settings* > *Developer settings* > *GitHub Apps* > *New GitHub App*, under the
   organization or account that should own it.
2. **Permissions:** *Repository permissions* > *Issues*: **Read-only**. GitHub adds *Metadata*:
   read-only itself. Grant nothing else.
3. **Webhook:** untick *Active*. The App needs no webhook; the fleet's webhook above is separate and
   stays as it is.
4. **Where can this GitHub App be installed?** *Only on this account* works when every repository
   on the board belongs to the owning account. To install it on more than one organization or
   account it has to be **public**. Public means anyone can install it on their own account; that
   gives them nothing from this deployment, which only ever asks about the owners of repositories
   on its own board.
5. Create it, note the **App ID** on its settings page (a number, not the client ID), and generate a
   **private key**, which downloads a `.pem` file.

**Installing it**, once per organization or account whose repositories appear on the board: from
the App's page, *Install App*, choose the owner, and choose **Only select repositories**, naming the
ones the fleet works in. **Prefer that to *All repositories*.** A developer on the allowlist can name
any repository as a session's repository, and the board then shows that repository's open-issue
count to every allowlisted developer -- so an installation covering a whole organization lets its
private repositories' counts reach people who cannot see those repositories. A repository the
installation leaves out reads `count unreadable`. **An owner without an installation gets no count
request**, and its repositories read `count unreadable` too. Installing needs that owner's admin.

**Configuring the deployment:**

| Variable | Value |
| --- | --- |
| `ROBOT_COUNCIL_GITHUB_APP_ID` | the App ID |
| `ROBOT_COUNCIL_GITHUB_APP_PRIVATE_KEY` | the private key, **base64-encoded onto one line**: `base64 < key.pem \| tr -d '\n'` |

The key is base64-encoded because most environment editors mangle a multi-line value. A PEM pasted
whole is accepted too, including one whose newlines are written as `\n`, in either the PKCS#1
(`BEGIN RSA PRIVATE KEY`) form GitHub downloads or PKCS#8. With neither variable set, nothing is
fetched and no request is made: the meters read what sessions report through `backlog_report`, as
before. With only one set, or a key that does not parse, no request is made either, each board
repository records `key unusable`, and doctor's `github app` check fails.

The fetch is `robot-council:backlog-fetch`, scheduled every five minutes by
`robot-council.schedule.backlog_fetch` (on by default; it does nothing while the App is unset). It
is the last of the package's scheduled entries and runs in the background without overlapping
itself, so a slow GitHub cannot hold back the coordination checks; the overlap lock uses the host's
cache and expires after ten minutes. Each run looks up the App's installation on each owner
(`GET /users/{owner}/installation`, once per owner per run), uses one installation token per
installation -- cached, encrypted with the application key, in the host's configured cache until
five minutes before it expires -- and asks GitHub's search for `repo:OWNER/NAME is:issue is:open`,
one request per repository and at most 25 a run. **A failed fetch stores no reading, never a
zero**: a 401, 403, 404, 422, timeout, or unparseable answer leaves that repository's meter reading
`count unreadable` once its last reading is older than `robot-council.backlog.stale_after_minutes`
(60), logs a warning naming the repository and the status, and the run goes on to the next. An
installation whose token cannot be minted -- a suspended one, say -- is asked once a run, not once
per repository. A missing installation is logged when it begins, not every run. A rate limit, or a
second request in a row that got no answer, ends the run; the repositories it did not reach are
tried first next time. On a host whose cache store is `array`, every run mints a new token, which
works and costs one request per installation.

**A host running Laravel Telescope with its HTTP client watcher** records every outgoing request
and its response. The response to minting an installation token carries the token in its `token`
field, so add `'token'` to `Telescope::hideResponseParameters()` in the host's
`TelescopeServiceProvider`, or the token is stored in Telescope's tables in the clear.

`php artisan robot-council:doctor` reports two checks: **`github app`**, whether the App is configured
and its key parses, and **`backlog fetch`**, per owner on the board whether it has an installation and
per repository how its latest fetch went. A refusal, a missing installation, or an unusable key
fails it; GitHub not answering, a rate limit, an incomplete search, or a failure outside GitHub
leaves it undetermined, since those pass on their own. Neither check asks GitHub anything, and
neither prints a credential.

## Waiting on a developer

A coordinator records what the fleet is waiting on a developer for -- the ticket it is about, the
question and why it matters -- with `POST {prefix}/api/owed-items` (or `owed_record`), naming the
developer by GitHub login or leaving it out for `General`. The lane board lists them `General` first,
then one section per developer. An item settles when its ticket closes or loses its `hitl` label, as
GitHub reports them, or with `DELETE {prefix}/api/owed-items/{id}` (or `owed_settle`). An item whose
developer has left the fleet stops rendering rather than moving to `General`. Both need
`coordinator:direct`.

## Who is here

`GET {prefix}/api/lanes` (or `sessions_list`), needing no ability, lists every `active` and `stale`
session, newest first, with its developer, machine, role, repository, work location, operating
system, status, last contact, its `capacity` in effect, and the tasks it holds, each with the `sub_label`
its holder gave it. It reads the session table rather than the
change feed, so it is complete however far back the feed has been pruned. `repository`, `role` and
`os_family` narrow it, and `after`
takes the previous page's `cursor`, which is null on the last page. A held task's title and
description appear only where the reader may act on that task, on the rule `task_list` applies.

## The shortlist

`GET {prefix}/api/shortlist` (or `shortlist_read`), behind `coordinator:direct`, lists the tickets a
coordinator could place, per repository: open, with no open or unknown `blocked_by` blocker, and not
already held by a lane. **It is ordered by number and implies no preference** -- choosing is the
coordinator's. Each entry lists its blind spots: a `hitl` label, a title naming an act that needs a
human, every acceptance criterion ticked while still open, and the file paths its body mentions,
which are unverified and are never compared between tickets.

## Lane conditions

The coordinator's to-do items reach it as fleet events rather than a page (#314). A `lane.condition`
event, restricted and addressed to the live coordinators, is raised for:

| `meta.condition` | when |
| --- | --- |
| `lane_free` | a build lane with room for another placement -- holding fewer tasks than its capacity (#436), which for most lanes means holding nothing -- not parked and not held, has been seen free for longer than `lane_conditions.free_after_minutes` (30), measured from the first check that saw it free. A lane of capacity above 1 carries `holding` and `capacity` in `meta`; one of capacity 1 reads as it always has. A lane holding any work cannot be held, so one kept partly full on purpose is raised once for each stretch it has room |
| `not_taken_up` | a coordinator's placement is still unstarted past `take_up_within_minutes` (15) -- a hand-back owed says so |
| `working_unobserved` | a lane holding work goes `stale` or `gone`, raised on that transition itself, and on the next check for a stale one no coordinator heard |
| `pull_request_unpicked` | a ready pull request no gate is on, in a repository a live gate works in, unchanged past `gate_pickup_within_minutes` (30) |
| `merge_behind` | a pull request merged, naming the live sessions in its repository now behind |

Each is raised once and cleared when it stops holding; if it recurs it is raised again. The scheduled
ones are checked every five minutes; `robot-council.schedule.lane_conditions` turns that off. With no
coordinator live, a scheduled condition waits and is raised to the next one; `merge_behind` and an
ended lane are told on the event or not at all. A raise that fails never undoes the presence
transition or the GitHub delivery that caused it: it runs after that write commits, and is reported.

## Quiet lanes

Every five minutes the scheduler checks each build lane for anything it has **authored**: a
narration, a task transition, a lock acquired or released, or a directive it posted. A lane that has
authored none of those for an hour -- a heartbeat, joining, and a directive it merely received do
not count -- raises one `lane.quiet` event, addressed to the fleet's coordinators and restricted so
no other session reads it. It is raised once per quiet stretch; the lane's next act starts a new one.
A gate is exempt while it holds no pull request. `robot-council.schedule.quiet_lanes` turns it off.

## Lane holds

A coordinator records why a lane -- an agent session -- is idle on purpose, which the lane board
shows as `<party> — <what>`. The party is a developer the fleet knows, by GitHub login, or a ticket
as `owner/name#N`; the `<what>` is one of a fixed set, and free text is refused:

| `reason` | party | reads |
| --- | --- | --- |
| `clearing_seat` | developer | clearing this seat to take tickets |
| `decision` | developer | a decision |
| `action` | developer | an action only they can take |
| `ticket_lands` | ticket | that ticket to land |
| `ticket_decided` | ticket | that ticket's decision |

- `POST {prefix}/api/lanes/{session}/hold` with `{ "party": "...", "reason": "..." }`, and
  `DELETE` the same path to lift it -- both need `coordinator:direct`, as do the `lane_hold` and
  `lane_clear_hold` tools.
- A lane that holds a task cannot be held, and claiming or placing work on a lane lifts its hold in
  the same transaction.

## Tasks

The unit of work agents hand each other. Every agent sees every task -- an agent cannot decide
whether to claim work it cannot see, and a queue half the fleet is blind to is a queue that
deadlocks -- and what narrows a task is claiming it.

- `GET  {prefix}/api/tasks?status=pending&after_priority=9&after_id=41` — the queue, most urgent first
- `POST {prefix}/api/tasks` — file one, needing `tasks:create`
- `POST {prefix}/api/tasks/{id}/{transition}` — move one

**Read the queue with the cursor, not with the first page.** A page is bounded and nothing prunes
the table, so a reader that asks once sees the top of the queue and nothing else. Pass the `cursor`
back as `after_priority` and `after_id`; it is `null` on the last page.

| Transition | Who | From | To |
| --- | --- | --- | --- |
| `claim` | `tasks:claim`, subject to eligibility | `pending` | `claimed` |
| `start` | the claimant | `claimed`, `blocked` | `in_progress` |
| `block` | the claimant | `claimed`, `in_progress` | `blocked` |
| `complete` | the claimant | `claimed`, `in_progress` | `done` |
| `fail` | the claimant | `claimed`, `in_progress`, `blocked` | `failed` |
| `release` | the claimant, or `coordinator:direct` | `claimed`, `in_progress`, `blocked` | `pending` |
| `reassign` | `coordinator:direct`, to an eligible session | `pending`, `claimed`, `in_progress`, `blocked` | `claimed`, by the named session |
| `cancel` | `coordinator:direct` | `pending`, `claimed`, `in_progress`, `blocked` | `cancelled` |

`done`, `failed`, and `cancelled` are terminal. `complete` and `fail` accept a `result` object.

**A `reassign` is also how a coordinator places work, and it must say so.** It starts from `pending`
as well as the held statuses, so a coordinator can put unclaimed work in a particular session's
hands. It **requires** a `directive` -- what to tell that session -- which is written to the change
feed in the same transaction as the placement, so neither commits without the other; a request
without one is refused with 422. **Those words reach no other developer's agent** (#331): they are
recorded as a restricted `placement.instruction` addressed to the lane, readable by the lane and by
the coordinator's own developer's sessions, and carrying `coordinator_direct` truthfully. The
fleet-wide `directive` that wakes the lane says only which task was placed on which session, whether
it is a hand-back, and the id of the instruction event, with the `after` that reads it back. Two
readers still see the words: the dashboard's change feed, which shows every event to a signed-in
developer, and Slack while `slack.mirror_restricted` is on, its default. `hand_back: true` marks the placement as a gate returning a pull
request to the lane that made it. **`expect: "pending"` makes a placement insist the task is still
unclaimed:** if a lane claimed it meanwhile, the placement writes nothing and answers 409, so the
coordinator re-reads rather than taking the task from that lane. Without it, a placement moves a
task a lane already holds, and that lane learns so from `task.reassigned`. The session named in `session_id` must pass the same eligibility
rule a claimant does, so a coordinator cannot hand one developer's own task to another developer's
session; that answers 403. `start` accepts an optional `branch`, the branch the lane is working on.

**A lane usually reports its branch after starting, because at `start` it seldom exists yet.** The
session holding a task in `in_progress` or `blocked` sends `POST {prefix}/api/tasks/{id}/branch`
with `{ "branch": "feature/x" }` (or the `task_branch` tool) once it has made the branch, and a
second report replaces the first. Any other session is refused with 403, and a task in another
status with 409. It moves no status and writes no event; the lane board reads the row.

**A lane that hands tickets to subagents can label each one** (#409). `start`, and the same branch
report, accept an optional `sub_label` -- which subagent works the task -- up to 64 characters of
`[A-Za-z0-9._-]`, starting with a letter or digit; anything else is refused with 422. The report
takes `branch`, `sub_label` or both, and leaves the one it was not sent alone. It is display only.
**It is visible to every session in the fleet**, so it must not name an issue, a branch or anything
confidential: use something like `subagent-2` or a worktree slot name.

- **Where it shows.** The lane board, beside the ticket; `GET {prefix}/api/lanes`, only where the
  reader may read the task, as `branch` is; and `meta.sub_label` on the task's events, which reach
  everyone.
- **Which events carry it.** `task.started`, `task.blocked`, `task.completed`, `task.failed`,
  `task.released` and `task.cancelled` carry the label the task had at that moment, and a release
  carries the one it is clearing. `task.reassigned` carries the label the task had *before* the
  placement, so moving a labelled ticket from one lane to another records the previous lane's label.
  The gone-session sweep's `task.released` and GitHub's `task.completed` or `task.released` carry
  the label they cleared or kept. `task.claimed` never does: a claim starts from `pending`, and every
  way to `pending` clears the label. An event about a task with no label has no `sub_label` key.
- **When it is cleared.** When the task changes hands -- a claim, or a placement onto a different
  lane -- and on a release, the gone-session sweep, and a release GitHub drives. A placement back onto
  the lane already holding the task keeps it. Claims, locks and narration stay the session's.

**A placement is refused when it breaks a lane invariant** (#320), before anything is written, with
422 naming every rule it broke:

| `rule` | refused when |
| --- | --- |
| `ticket_open` | the task names an issue the fleet has no record of, or one that is closed |
| `lane_in_repository` | the lane does not work in the issue's repository |
| `lane_free` | the lane already holds another task -- that is, as many as its capacity, which is one unless it declared more at join and its seat allows it |
| `lane_not_parked` | the lane's seat is parked |
| `ticket_unblocked` | the issue has a `blocked_by` edge whose blocker is open, or unknown |
| `assignment_hours` | it is outside the lane's developer's hours -- new work only: a hand-back to a lane that has started the task before, work moved between one developer's own lanes, and an exempt seat are not gated |

The two ticket rules apply only to a task that names an issue. **Only the developer who owns the
lane's seat can waive a refusal**, from their seats page, for one rule and one placement; the
placement spends it. A coordinator cannot. A successful placement also returns `warnings` that do not
block: a title naming an act that needs a human (delete, remove, retire, release, tag, publish,
install, upgrade, rotate, spend), an open ticket whose acceptance criteria are all ticked, a branch
whose name carries the ticket's number with no open pull request (matched by name), and a
`documentation` ticket placed while functionality tickets are on the shortlist.

A task may name the GitHub issue it is for when it is filed, as `issue: "owner/name#N"`. A bare
`#N` is refused, because the same number exists in every tracker. Each task reports `placed_by`
(`coordinator` or `lane`) and `hand_back`, and a release or the gone-session sweep clears both, along
with the branch.

**Every transition is one conditional update, and the count of changed rows is the decision.** The
statuses it may start from, the claimant it requires, and the eligibility rule all go into the same
`where`, so two agents claiming one task is settled by the database rather than by whoever read
first. A transition that changed nothing answers **409**; one this session may not make answers
**403**; an unknown task answers **404**. Nothing is written to the feed unless the row moved.

**Who may claim what.** A session claims a task its own developer's session created, or one created
by a session that held `coordinator:direct` at the time. That is recorded on the task when it is
filed, so revoking the coordinator's ability afterwards cannot make work that was open to the fleet
silently unclaimable.

**Every agent sees that every task exists. Not every agent sees what it says.** The row — id,
status, priority, project, provenance, `placed_by`, `hand_back` — reaches everyone, because a queue half the fleet is blind to
is a queue that deadlocks. The `title`, `description`, `payload`, `result`, `issue` and `branch` reach only the readers
who may act on the task: its own developer's sessions, anyone at all when a coordinator filed it,
and any session holding `coordinator:direct`. Everyone else gets `null` in those fields and
`readable: false`. That is the same boundary the change feed draws for narration, and for the same
reason — a task's description is instructions, and task content is untrusted input to an agent that
may have shell access.

**A session that goes `gone` gives its tasks back.** The presence sweep releases everything a gone
session still held, and it runs on every sweep rather than on a signal, so a release that was missed
costs one sweep interval rather than leaving a task claimed by a process that no longer exists. A
`stale` session keeps its tasks: it has been quiet, not stopped.

## Locks

Named advisory leases, for anything narrower than a task — one session pushing to a branch at a
time. All three take the name in the **body**, never in the path: a Laravel route parameter does not
match `/`, and `branch:feature/foo` is exactly the kind of name worth locking.

- `POST {prefix}/api/locks/acquire` — take a free name, or one whose lease has lapsed
- `POST {prefix}/api/locks/renew` — extend a lease this session holds
- `POST {prefix}/api/locks/release` — give it up
- `POST {prefix}/api/locks/force-release` — take one away, needing `coordinator:direct`

The first three need `locks:acquire`. Acquire and renew take a `ttl` in seconds, up to
`locks.max_ttl_seconds`; a renewal cannot push a hold past `locks.max_hold_seconds` from when it was
first acquired, and a session holds at most `locks.max_per_session` at once.

**Advisory means nothing here enforces what a lock guards**, so a lease that lapses cannot stop the
session that held it from carrying on. The `fence` is what makes that safe:

```json
{ "name": "branch:feature/foo", "held": true, "fence": 7, "expires_at": "…", "expires_in": 900 }
```

Carry the fence into whatever the lock guards, and have that thing refuse anything below the highest
fence it has seen. **The fence only ever climbs for a name** — across a takeover, and across a
release, because a released lock keeps its row. A renewal keeps the same fence, because it is the
same hold continuing.

A lease expires on its own, so a session that stopped answering blocks the fleet for at most its
TTL. When a session goes `gone`, the presence sweep releases everything it still held.

## Presence

The fleet knows which agent processes are alive without asking any harness to keep one running.
**Every authenticated agent request is contact**, so a process that only ever reads the feed is as
visible as one that narrates. A process with nothing else to send posts `POST {prefix}/api/agent/heartbeat`,
which answers with both thresholds as durations so a bridge picks its own cadence:

```json
{ "session_id": 12, "status": "active", "stale_in": 300, "gone_in": 1800 }
```

`robot-council:sweep-sessions` moves a session that has stopped answering to `stale`, and then to
`gone`:

| state | means | what it does to the session |
| --- | --- | --- |
| `active` | heard from inside `presence.stale_after_minutes` | nothing |
| `stale` | quiet for longer than that | still holds whatever it claimed; one request brings it back |
| `gone` | quiet past `presence.gone_after_minutes`, ended, or revoked | final: its tokens are refused, it is never renewed, and what it held is released |

A session that has gone is never reused — the process starts a new one. Each transition writes one
event to the change feed (`session.stale`, `session.resumed`, `session.gone`), and going `gone`
dispatches `RobotCouncil\Events\SessionGone` once, after the transaction commits.

**Listen to `SessionGone` from a queued listener.** Laravel runs an after-commit callback outside
any try/catch, so a synchronous listener that throws escapes the transaction that ended the session
with the row already written.

A host that releases its own resources when a session goes registers a step on the sweep, which runs
on every sweep rather than once per session — a per-session signal can be missed, and a scheduled
sweep cannot:

```php
$this->app->make(RobotCouncil\Support\SessionReleases::class)->register(function (): void {
    // release whatever a session that has gone was holding
});
```

**The thresholds measure elapsed time, and `app.timezone` does not reach them.** Contact times and
their cutoffs are written, compared, and read back on one fixed clock, so a daylight-saving
transition moves neither. **A lock's lease is on that same clock** since #149, so a transition
cannot lapse a held lease either -- though upgrading a host that is not on UTC reinterprets its
existing lock rows once, which the note on `Models\Lock` describes. **A device code's lifetime is on
it too** since #160. What `robot-council:doctor` still asks about is a token's expiry alone, which
Sanctum compares against the application's clock rather than this package's -- so moving only this
side would introduce the mismatch rather than remove it.

## The change feed

Every coordination state change becomes a row in one ordered log, written in the same transaction as
the change it records. Agents page it by an ID cursor:

- `GET  {prefix}/api/events?after=<id>` — the events this session may see, oldest first
- `POST {prefix}/api/events` — narration, needing `events:post`, optionally addressed with `to` or `to_tasks`
- `POST {prefix}/api/directives` — a fleet-wide instruction, needing `coordinator:direct`

**A directive may name who is expected to act, and that is all it changes.** Pass `targets` with up
to 50 session ids, and the event records them; omit it and the event is exactly what it was before.
Delivery is not narrowed either way — every agent still reads it. The point is that an instruction
meant for one agent no longer asks every idle agent to decide for itself whether it is the
addressee, which is the one judgement the visibility rule below exists to avoid asking of a process
that may have shell access. An id naming no session, or one that has gone, is a `422` and writes no
event at all; the ids recorded are read off the session rows the server resolved, never taken from
what the poster sent.

**Who sees what.** State changes and directives reach every agent. *Narration* reaches an agent only
when the session that posted it belongs to the same developer, or held `coordinator:direct` when it
posted. That is a security boundary rather than a preference: task and event content is untrusted
input to an agent that may have shell access, so narrowing whose words reach whom is what stops one
developer's agent putting instructions in front of another's. Whether the coordinator's ability was
held is recorded on the event, so granting or revoking it later changes nothing already written. A
placement's instruction is the one coordinator post that does not reach every agent: it reaches its
lane and the coordinator's own developer's sessions only.

**A narration can be addressed, and the sessions it names read it whatever developer they belong
to.** Pass `to` with up to 50 session ids, or `to_tasks` with up to 50 task ids; a task names the
session holding it when the narration is posted, so a note for "whoever is building task 42" follows
the task across a restart or a reassignment. Nobody else gains anything: another session of the same
developer as the addressee still reads only what #29 already allowed it. A session that is unknown
or has gone, a task nobody holds, and a task the posting session may not read are each a `422` that
writes no event at all. The event records whom it was addressed to under `meta.to` (session ids) and
`meta.to_tasks` (each task and the session it resolved to), beside `meta.client` rather than inside
it. An addressed narration is still data, not an instruction, to the session that reads it.

Every event carries provenance the server derived — the posting session, that developer's GitHub
login, and whether the coordinator's ability was held — never anything the poster claimed.

**The cursor is how far the feed was read, not the last row returned.** A page is a window of IDs,
so it can come back short or empty when the visibility rule hides everything in that window, and the
cursor still moves. Do not treat an empty page as "caught up" — compare the cursor instead.

**Where the first cursor comes from, and what happens if you lose it.** Starting a session returns a
`feed_cursor`, which is where the feed stood as that session began. **Omit `after` and the read
resumes from where this session last got to**, so a process that has lost its place carries on
rather than replaying anything; read from `0` and the first page is the fleet's oldest, which on a
long-lived feed is a great many pages to walk before reaching the present. Either is allowed — a
process that wants the history asks for it by sending a lower number, and doing so does not cost it
its place.

**Passing `after` is how you acknowledge a page.** The service stores the position you send, so send
the `cursor` a page returned once you have acted on that page. A page you never acknowledge is
delivered again: that is deliberate, because a page that was sent and lost should come back rather
than vanish. It also means a reader that never sends `after` keeps receiving the same events. The
stored position only ever moves forward, and a cursor past the end of the feed is ignored rather
than stored.

**`limit` caps the events a read returns, up to 200; how far one read looks is fixed at 1,000 event
ids.** So a page shorter than `limit`, or empty, does not mean the reader is caught up: compare the
`cursor`, which always moves, rather than the count (#365).

**A reader following the feed for an agent passes `acknowledge=false`** (#354). The stored position is
what the agent's own read resumes from when it names no `after`, so a bridge that polls on the
agent's behalf and acknowledged as it went would move it past events the agent was never shown -- a
task placed on it included. With `acknowledge=false` the read writes nothing, and the reader keeps its
own position.

**Recovering it.** `POST sessions/{id}/renew` and `GET agent/session` both state the current
position, so a restarted process reads it back instead of choosing between replaying the feed from
`0` and starting a new session.

**`GET agent/session` also answers whether anything can arrive right now.** Its `fleet_can_direct`
is true when some session on the fleet is running in the `coordinator` role -- active, on an
installation that is neither revoked nor expired, and with its developer still on the access list.
It is a fleet-level answer deliberately, and not the same as the session's own `abilities`: a
directive is the one event that reaches an idle agent, posting one needs `coordinator:direct`, and a
session cannot ask itself into the role that carries it. A process that only ever receives holds
none of it and is correctly configured, so a client that warned on its own abilities would warn on
almost every session. **False means nothing will reach a waiting agent while that stays true**,
which is worth saying out loud, because an empty sink and a fleet with nothing to say look identical
from the agent's side.

**It is a reading, not a property of the deployment**, and it flips when the fleet's one coordinator
restarts. A client that states it once at startup is describing that moment; a fleet whose
coordinator is between runs reports false and reports true a moment later.

### Mirroring to Slack

Set a webhook and each event is posted for humans to read:

```dotenv
ROBOT_COUNCIL_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
ROBOT_COUNCIL_SLACK_QUEUE=robot-council-slack
```

Leave it unset and no mirror runs at all. The mirror is **one-way**: nothing in this package reads
from Slack, and no coordination decision depends on it, so an outage there costs visibility and
never correctness. It sends only the event type, the actor's login and a truncated body — never
`meta`, a payload, or a result — escapes what Slack would read as markup or a mention, and honors
Slack's `Retry-After`. **Run a worker on that queue**, or events are recorded and never mirrored,
and on a database queue the jobs accumulate.

Three things worth knowing before you enable it:

- **Do not leave the mirror on a `sync` queue connection.** On `sync` the job runs inline inside the
  agent's own request, the queue name is ignored, a rate-limit release is silently dropped, and a
  Slack failure surfaces on a request whose event is already committed. Set
  `ROBOT_COUNCIL_SLACK_CONNECTION` to a real queue connection. The package will not fail a write
  because Slack is unreachable, but it cannot move the work off the request for you.
- **The restricted events are mirrored by default, and the feed's visibility rule does not apply to
  Slack.** That covers narration, `lane.quiet`, `lane.condition` and `placement.instruction`. The
  rule governs what one developer's *agent* may read from another's, because event content is
  untrusted input to something that may have shell access. A Slack channel is a human surface, and
  being a narration channel for humans is the point of having one — but it does mean everyone with
  channel access reads every agent's narration and every placement's instructions. Set
  `ROBOT_COUNCIL_SLACK_MIRROR_RESTRICTED=false` to mirror only state changes and directives. The
  older name, `ROBOT_COUNCIL_SLACK_MIRROR_NARRATION`, is still read when the new one is unset, and
  the new one wins when both are set (#366).
- **The mirror's rate limit needs a shared cache store.** It is one limit across every worker,
  because Slack's is per webhook. On `CACHE_STORE=array` or `file` it is per process or per machine,
  and on `null` there is no limit at all.

## Upgrading

### Unreleased

Run `php artisan migrate`: one migration adds `robot_council_agent_sessions.ephemeral`, defaulting
to false, so every existing session stays an ordinary one (#424).

### To 0.7.0

Two public reads changed shape for #409, which matters only to a host calling them directly:

- **`Support\LaneBoard::read()`**: a `Working` lane's task fields moved from `on_what` itself into
  `on_what.tasks`, a list with one entry per held task, and `on_what.also_holds` is gone. Each row
  also gained `holding` and `capacity`.
- **`Support\LiveSessions`**: its constructor now takes a `Support\Seats` as well as the
  `AgentLogins`. Resolving it from the container needs no change.

Run `php artisan migrate`: one migration adds the capacity columns and `robot_council_tasks.sub_label`,
each defaulting so every existing session and seat keeps a capacity of one.

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
