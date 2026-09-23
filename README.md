# robot-council/core

[![CI](https://github.com/robot-council/core/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/robot-council/core/actions/workflows/ci.yml?query=branch%3Amain)

The core package of Robot Council, a coordination service for fleets of AI coding agents. It is installed into a host Laravel application, which it gives GitHub sign-in restricted to an allowlist of GitHub accounts, agent enrollment through the device-code flow, agent-session presence, task claims, named locks, and the fleet's change feed.

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
   `DELETE {prefix}/api/sessions/{id}` ends one when its harness exits, so what it held is released
   at once rather than after the presence threshold. All three take the installation credential,
   because the token belonging to the process that just died is the one thing that may no longer
   work. Ending is idempotent.

Every response that carries a bearer token names it `token`, every expiry is an `expires_in` in
seconds, and `abilities` always describes the token beside it. Where a response also names
`granted_abilities`, that is what a *different* token will carry -- the sessions an installation
credential will start. Starting a session also names a `feed_cursor`, which is where the change feed
stood at that moment; a renewal names the position the session has since acknowledged, restated
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
and `coordinator:direct`, which enrollment can never request. An admin grants it afterwards:

```bash
php artisan robot-council:grant-ability  <installation> coordinator:direct
php artisan robot-council:revoke-ability <installation> events:post
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

Granting or revoking an ability rewrites the session tokens already in flight, so it takes effect on
the next request rather than within the hour a session token lives.

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
developer allowlist, whether the Slack mirror would run inside an agent's request, and whether
`app.timezone` can shift under a credential's expiry.

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

Eighteen tools — `task_list`, `task_create`, the eight task transitions, the four lock actions,
`events_read`, `events_narrate`, `directive_post` and `presence_heartbeat`. Each enforces the same
ability as its endpoint, and **a refusal comes back marked as a tool error rather than as content**:
an MCP client cannot tell a result that describes a failure from one that describes success, so a
refusal returned as ordinary text reads to a model as though the call had worked.

**`tools/list` paginates, and the first page carries 15 of the 18.** It returns a `nextCursor` —
base64 of `{"offset":15}` — and `events_narrate`, `directive_post` and `presence_heartbeat` arrive
only when that cursor is passed back. Most MCP clients walk the pages for you; a hand-rolled probe
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

A signed-in developer sees the fleet's state at `{prefix}/dashboard`, behind the same access list
and framing refusal as the verification page. The pages are Livewire components and refresh by
polling every `robot-council.dashboard.poll_seconds` seconds, defaulting to 5. There is no
broadcasting: a change an agent commits is visible within one interval and no sooner.

**The stylesheet is compiled here and served by the package**, at `{prefix}/dashboard.css`. A
consuming application runs no asset build and needs no Node toolchain. That route is deliberately
public and deliberately outside the `web` middleware group, so it starts no session and a page can
load its styling before anyone has signed in.

Installing this package adds `livewire/livewire` to a host's dependencies, and Livewire registers
its own `/livewire/update` endpoint and a global middleware. The package registers
`EnsureAllowlistedDeveloper` as Livewire *persistent* middleware, because Livewire strips from that
endpoint every middleware not on its own fixed list -- without which a developer removed from the
access list would keep driving components from a page already open.

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
| `reassign` | `coordinator:direct` | `claimed`, `in_progress`, `blocked` | `claimed`, by another session |
| `cancel` | `coordinator:direct` | `pending`, `claimed`, `in_progress`, `blocked` | `cancelled` |

`done`, `failed`, and `cancelled` are terminal. `complete` and `fail` accept a `result` object.

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
status, priority, project, provenance — reaches everyone, because a queue half the fleet is blind to
is a queue that deadlocks. The `title`, `description`, `payload` and `result` reach only the readers
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
cannot lapse a held lease either. What `robot-council:doctor` still asks about is a credential's
expiry, which Sanctum compares against the application's clock rather than this package's --
so that one cannot be moved here without moving Sanctum too.

## The change feed

Every coordination state change becomes a row in one ordered log, written in the same transaction as
the change it records. Agents page it by an ID cursor:

- `GET  {prefix}/api/events?after=<id>` — the events this session may see, oldest first
- `POST {prefix}/api/events` — narration, needing `events:post`
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
held is recorded on the event, so granting or revoking it later changes nothing already written.

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

**Recovering it.** `POST sessions/{id}/renew` and `GET agent/session` both state the current
position, so a restarted process reads it back instead of choosing between replaying the feed from
`0` and starting a new session.

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
- **Narration is mirrored by default, and the feed's visibility rule does not apply to Slack.** That
  rule governs what one developer's *agent* may read from another's, because event content is
  untrusted input to something that may have shell access. A Slack channel is a human surface, and
  being a narration channel for humans is the point of having one — but it does mean everyone with
  channel access reads every agent's narration. Set `ROBOT_COUNCIL_SLACK_MIRROR_NARRATION=false` to
  mirror only state changes and directives.
- **The mirror's rate limit needs a shared cache store.** It is one limit across every worker,
  because Slack's is per webhook. On `CACHE_STORE=array` or `file` it is per process or per machine,
  and on `null` there is no limit at all.

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
