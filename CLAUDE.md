# robot-council

A **Laravel package** (`robot-council/core`), not an application. It is the core of a fleet coordination service for AI coding agents, designed in [robot-council/core#14](https://github.com/robot-council/core/issues/14) and installed into a host Laravel application. GitHub sign-in and agent enrollment are the slices that exist.

## Layout

- `src/` — namespace `RobotCouncil\`. `RobotCouncilServiceProvider` is built on `spatie/laravel-package-tools` and is auto-discovered by consuming apps through `extra.laravel` in `composer.json`. It registers the config file, the views, the console commands, the two Sanctum guards, the rate limits, the web and machine routes, the `robot-council-admin` ability, and the hourly device-code prune. Under it: `Access\` (the allowlist, the guard names, the fixed ability list, and what a token is), `Console\`, `Http\Controllers\`, `Http\Middleware\`, `Models\` (the package's own tables), and `Support\` (host user records, lifetimes, and the device-code, installation, and session stores).
- **The guard is configuration, never the host's default.** `robot-council.auth.guard` (default `web`) is what signs a developer in, checks them, and signs them out. A host whose default guard is another one would otherwise loop through sign-in forever.
- **A Sanctum guard returning somebody says nothing about what they are.** `Guard::__invoke()` tries the `web` guard before it reads a bearer token, so a signed-in human reaching a machine route arrives as that human, carrying a `TransientToken` whose `can()` answers true to every ability. `EnsureInstallation` and `EnsureAgentSession` therefore check the principal's class and its token's class, never just that one resolved. `Access\Tokens` holds that check, taking the token as `HasAbilities` because the type `currentAccessToken()` is declared to return makes the check read as dead code.
- **A route's limiter is declared BEFORE its principal middleware, and that declaration is what decides the order.** `Router::sortMiddleware()` reorders only middleware that are themselves in the framework's priority list, *relative to each other*. `ThrottleRequests` is in that list and neither `EnsureAgentSession` nor `EnsureInstallation` is, so with one member present nothing moves. Declared after the guard, the limiter never runs for a request the guard refuses: measured with `agent_per_session` at 2, six unauthenticated requests returned `401,401,401,401,401,401` and six authenticated ones `200,200,429,429,429,429`, so an unauthenticated flood was unlimited while the limiter demonstrably worked. Because the limiter now runs first, one keyed on the principal must resolve it through the guard rather than read what the middleware left on the request, and must key by `ip:` when none resolves.
- `config/robot-council.php` — the access lists, the route prefixes, the credential lifetimes, and the rate limits, published to the host application. It is the only place `env()` may be called, which `phpstan.neon.dist` tells Larastan through `configDirectories`.
- `routes/web.php` and `routes/api.php` — the human-facing and machine-facing routes, each mounted by the provider under its configured prefix and middleware group, both with the `robot-council.` name prefix. Approve and deny accept POST only.
- `resources/views/enroll.blade.php` — the verification page, registered by `hasViews()` under the `robot-council::` namespace. Everything a requester supplied is printed as a claim and escaped; there is no `{!! !!}` in it and there should never be.
- **The change feed is written through `Support\FleetEvents`, never by inserting a row.** Every writer locks one sentinel row in `robot_council_feed_lock` **before** inserting, because both Postgres and InnoDB draw the key at insert time: two writers can take IDs 5 and 6 and commit 6 first, and a reader paging `id > cursor` passes 6 and never sees 5 again. A row lock rather than `pg_advisory_xact_lock`, because the problem is not Postgres's alone, a row lock is transaction-scoped on every driver, and it collides with nothing a host owns. The order matters: a key drawn before the lock is a key already drawn, and a sequence is not rolled back. `holdTheFeed()` throws when that row is missing, because `first()` on an absent row locks nothing and the insert would otherwise go ahead unordered.
- **A cursor handed to a reader comes from `FleetEvents::record()`'s return value, never from a
  separate `MAX(id)`.** `AgentSessions::start()` returns the enrollment event's own id for this
  reason: a read taken outside the sentinel lock can observe a later id while an earlier one is
  still in flight, and the reader that pages past it never sees it again. **No test in this suite
  can fail on this** -- SQLite serializes writers, so `$enrolled->id`, a locked `MAX(id)`, and an
  unlocked one are the same number on one connection. Telling them apart needs the
  `cross-connection` group. A related consequence worth knowing before it reads as a bug: paging is
  `id > cursor`, so a session never sees its own `session.joined` event while every other session
  does.
- **A queued job dispatched from inside a transaction must not be allowed to throw.** `DatabaseTransactionRecord::executeCallbacks()` has no try/catch and Laravel runs it *after* the commit, so anything thrown there escapes `DB::transaction()` with the row already durably written. `SlackMirror` therefore queues from its own `DB::afterCommit()` callback with the try/catch inside it: an unreachable queue must not turn a committed enrollment into a 500.
- **Presence is written by `Support\SessionPresence`, and every write is conditional on the ROW.** A
  status is never decided from the model instance: Sanctum's guard materializes the session several
  queries before the middleware runs, so a sweep committing `stale` in between would be overwritten
  by a contact write that left the status alone -- and a `stale` row with a fresh contact time is
  unreachable by both sweep passes, so a live process would report `stale` to the whole fleet until
  it died. Each write names the statuses it accepts and, where a clock decides it, the contact time
  its read saw; the count of changed rows is the decision. That is also what makes `Events\SessionGone`
  fire exactly once however a session ended, and what makes two sweeps at once safe, so the sweep
  takes no overlap lock -- one would fail worse than the problem, holding for its whole expiry after
  a killed run and marking nothing gone meanwhile.
- **Lock order is `robot_council_installations`, then `robot_council_agent_sessions`, then
  `robot_council_locks`, then `robot_council_lock_fence`, then `robot_council_tasks`, then
  `robot_council_placement_waivers`, then `robot_council_lane_holds`, then the feed sentinel, then `robot_council_lane_conditions`, then `robot_council_event_addressees`, then
  `personal_access_tokens`.** Every path that touches more than one takes them in that
  order. `Support\LaneConditions` holds the feed before it reads its own table, and a raise from
  inside another write waits for that write to commit, so a presence transition or a GitHub
  delivery never holds its rows while it raises. A GitHub delivery takes its own `robot_council_github_*` rows first, then
  `robot_council_tasks`, then the feed sentinel; no other path takes those rows. Two paths taking the same two rows in opposite orders deadlock on every engine that locks
  rows, which is all of them but SQLite -- and SQLite serializes writers, so no test in this suite can
  show it. `AgentSessions::renew()` and `Installations::revoke()` both had to be reordered for this.
  **`robot_council_events` takes no session lock at all**, because #50 dropped the foreign key on
  `agent_session_id` rather than maintain an order every future author has to remember: on InnoDB the
  child insert takes a shared lock on the parent while the sentinel is already held, inverting the
  order the presence sweep takes the same two rows in, and the lock is implicit so nothing in the
  code says it is being taken. `robot_council_tasks` keeps its foreign keys, because its write rate
  is low and `Support\Tasks::transition()` already takes the session row explicitly and first -- the
  order is visible there rather than inherited.
  **`robot_council_lock_fence` is one row for the whole installation, and `Support\Locks::acquire()`
  is its only writer.** It holds the fence sequence, which #63 made shared across every lock name so
  that a free lock row could be deleted -- a per-row `fence + 1` made the row the only record of what
  its name had issued, so a pruned name restarted at 1 and re-blessed a stale holder's fence. Because
  it is global and its exclusive lock is held to commit, **it is drawn only on an acquisition that is
  going to win**: drawing on the losing path too would put every failed attempt on every contended
  name into one queue, so one hot lock would serialize acquisitions of every other name in the fleet.
  The takeability check that gates it mirrors the update's `where` and cannot go stale, because the
  lock row is already held with `lockForUpdate()`.
- **Whatever is displayed to agents is charset-limited at the edge.** `harness`, `machine_label`, and `project_id` all reach other developers' agents, and event content is untrusted input to something that may have shell access. `meta` is bounded by `Http\Rules\BoundedMeta` for the same reason an `array` rule bounds nothing.
- **Who sees which event is decided in `Support\FleetFeed`, and it is a security boundary.** Narration reaches only its own developer's sessions and sessions that held `coordinator:direct` when they posted; state changes and directives reach everyone. Whether the ability was held is recorded on the event at write time, so revoking it later is not retroactive.
- **Both halves of that rule are read from the event, never looked up from its session id.**
  `robot_council_events` carries `user_id`, denormalized at write time exactly as
  `robot_council_tasks.user_id` is. There is no foreign key on `agent_session_id` (#50), so nothing
  nulls it when a session row goes, and **session ids are reused**: Laravel's SQLite
  `compileTruncate` issues `delete from sqlite_sequence` beside the row delete, after which the next
  session takes id 1 again -- measured -- and Postgres's is `truncate ... restart identity`. A filter
  asking `agent_session_id IN (the reader's live sessions)` therefore re-points a dead session's
  narration at whoever holds its id now, and `AgentLogins` keyed by session id stamps that other
  developer's login onto it. Verified by planting both forms: each serves one developer another
  developer's restricted narration. `AgentLogins::forUsers()` exists for this; `forSessions()` is
  for callers reading live sessions, which is presence and tasks.
- **Nothing reads from Slack, and a test enforces it.** The package's only outbound HTTP call is one POST in `Jobs\MirrorEventToSlack`. A read would let a coordination decision depend on Slack being up and honest.
- **Read what Rector does to a queued job.** It renamed a private `retryAfter()` helper to `backoff()`, which is a framework hook, so `Illuminate\Queue\Queue` began calling it with a signature it does not have; it also rewrote `public int $tries` into `#[Tries(5)]`. Neither is announced. Do not name anything on a job `retryAfter` or `backoff`.
- **Every migration in `database/migrations/` carries a date prefix, and
  `tests/MigrationPrefixGuardTest.php` refuses one that does not.** Laravel runs migrations in
  filename order and digits sort before letters, so an unprefixed file runs **after every dated
  one** -- which means nothing dated can ever alter the table it creates. That is not style:
  `create_robot_council_github_identities_table.php` had no
  prefix, #54's dated collation migration therefore ran before that table existed, and its
  `Schema::hasTable()` guard **skipped the column silently**. An access-control change shipped
  covering six of seven key columns and reported success, caught only because it asserts the
  collation of every key column by name. The workaround was a second unprefixed file named `f...`
  so it would sort after `c...`; #132 dated the create instead and removed it. **A rename needs a
  guard and they land together**: a deployed host has a `migrations` row for the old name, so the
  new one is pending to it and an unguarded `Schema::create` stops the whole batch. That host ends
  with **three** rows about that table -- the old create, the new one, and the collation
  workaround this removed -- all inert: `Migrator::rollbackMigrations()` skips a recorded name
  whose file is absent with a `Migration not found` warning and never deletes the row. **The
  rename's `down()` needs a guard too**, and for a worse reason than `up()`: the re-run logs a row
  at the next batch number, so one `migrate:rollback` would otherwise drop a populated table.
  `database/stubs/` is out of scope, as it is for the timestamp guard: `robot-council:install`
  names those at copy time with `Carbon::now()->format('Y_m_d_His')`.
- **A schema change now ADDS a migration. The era of editing the create migrations is over.** It was safe only while nothing that had run them existed: `v0.1.0` ships no `database/` directory at all (`git ls-tree -r --name-only v0.1.0 -- database/` is empty), so no host could have run one from a release, and `4b1fdda` and `6afeb05` edited three create migrations on that basis. **That condition lapsed when `robot-council/robot-council` installed from `dev-main` and migrated**, exactly as this note warned it would, and silently: #94 then dropped two indexes by editing the events create migration, leaving the deployed database carrying indexes a fresh install no longer creates. Measured on the deployment afterwards -- `robot_council_events_user_id_id_index` and `robot_council_events_type_id_index` were both still there. `2026_09_18_000007_drop_robot_council_event_indexes.php` is the repair, and the shape to copy: guard on what the schema actually reports rather than assuming presence, because the three populations -- installed before the change, installed after it, and rolled back -- all run the same file.
- `database/migrations/` — the package's own tables, loaded by the provider so `php artisan migrate` picks them up. `robot_council_agent_sessions.last_seen_at` is a non-nullable `dateTime` rather than a `timestamp`: MySQL gives the first NOT NULL `TIMESTAMP` column an implicit `ON UPDATE CURRENT_TIMESTAMP` while `explicit_defaults_for_timestamp` is off, so marking a session stale would restart the clock deciding when it goes, and nullable would exempt a row from both cutoffs forever. `robot_council_github_identities` maps a host user to a GitHub account, and the package owns it because that mapping is what the access lists are checked against. `robot_council_installations`, `robot_council_agent_sessions`, and `robot_council_device_codes` hold enrollment.
- `database/stubs/` — migrations `robot-council:install` writes into the host application, because they change tables the host owns. Only nullability on `users.password` and `users.email`, skipped when already nullable. The suite runs the stub itself, so it is covered. The command also copies Sanctum's `personal_access_tokens` migration, guarding on the file-name suffix rather than the name, because a publish rewrites the timestamp.
- `resources/css/` and `resources/dist/` — the dashboard stylesheet's source and its compiled
  artifact. `package.json` drives the build; see the note below on why the artifact and its lockfile
  are committed.
- `src/Livewire/` — the dashboard's Livewire components, mounted by `routes/web.php` behind the
  allowlist gate. Testbench registers no provider it is not told about, so `tests/TestCase.php`
  lists Livewire's provider by hand exactly as it does Socialite's.
- `tests/` — Pest on Orchestra Testbench. `tests/Pest.php` binds `tests/TestCase.php`, which registers the service provider; `tests/ArchTest.php` applies Pest's `php()`, `security()`, and `strict()` arch presets to the package's namespaces. Tests whose subject is what an ENGINE does rather than what the package does belong to the `engine-semantics` group, which the `mysql` job runs and `tests/EngineSemanticsGroupGuardTest.php` keeps complete. Tests that read data a second database connection commits belong to the `cross-connection` group, which `phpunit.xml.dist` excludes from every run that does not name it; `tests/CrossConnectionTest.php` is the pattern. A test whose subject is a **query plan** skips unless the driver is `pgsql`, so it runs in the `postgres` job and nowhere else -- `tests/TaskQueuePlanTest.php` is the pattern, and it has to seed enough rows for the planner to prefer an index at all, because on a small table a sequential scan really is cheaper and a thinly seeded guard would pin the opposite plan. **Twenty thousand is near the floor rather than far above it, and is not to be trimmed** (#249): setting both files' constants together and running their real assertions, 200, 500, 2,000 and 5,000 rows all fail, and 10,000, 15,000 and 20,000 all pass -- so 10,000 sits one step above the cliff, on this machine, and the job runs somewhere else. A hand-rolled probe that checked only whether the index name appeared in one query's plan reported the fixture oversized by three orders of magnitude; the test file is the only instrument that discriminates.
- `.claude/rules/` loads into every session; `.claude/skills/` loads on demand.

## Commands

| Task | Command |
| --- | --- |
| Install | `composer install` |
| Tests | `composer test` (`vendor/bin/pest`); one file or test: `vendor/bin/pest --compact tests/ExampleTest.php --filter=...` |
| Tests on Postgres | `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=<db> DB_USERNAME=postgres DB_PASSWORD= vendor/bin/pest` |
| Tests on MySQL | `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=<db> DB_USERNAME=root DB_PASSWORD= vendor/bin/pest` |
| Cross-connection tests | Add `--group=cross-connection` to either line above. Never SQLite. All seven run on Postgres; on MySQL six skip themselves, because they set `lock_timeout` and would stall rather than fail |
| Coverage | `composer test-coverage` (needs PCOV or Xdebug; see the `pcov-setup` skill) |
| Mutation | `vendor/bin/pest --mutate --path=src --class="RobotCouncil\<Class>"` |
| Static analysis | `composer analyse` (PHPStan with Larastan and `pestphp/pest-plugin-phpstan`, level `max` with bleeding edge, no baseline); PHPStan and Rector both cover `config`, `routes`, `src`, `tests`, and `rector.php` |
| Format | `vendor/bin/pint --dirty`; check only: `vendor/bin/pint --test` |
| Refactor | `composer refactor` (Rector; see `rector.php`); check only: `composer test:refactor` |

**There is no `php artisan`.** Testbench supplies a throwaway Laravel skeleton under `vendor/`, driven by `vendor/bin/testbench`. Its `make:*` generators write into that skeleton, not into this package, so create files by hand.

## Things that are easy to get wrong

- **Every API must exist in the lowest supported Laravel version.** `composer.json` admits Laravel `^13.23.0` (`illuminate/contracts`), but the development install resolves the newest. The floor is the lowest release CI can test: its `prefer-lowest` cells resolve `laravel/framework` v13.23.0, because `orchestra/testbench ^11.2.0` requires it. Move the constraint whenever that tested floor moves, for example after raising the Testbench constraint.
- **Anything written into `vendor/orchestra/testbench-core/laravel/` changes what the tools see, and CI has none of it.** That skeleton is Testbench's throwaway application, and a `vendor/bin/testbench` run or a test that publishes into it leaves files behind that a fresh CI install does not have. Two costs found so far: a `.env` copied from `.env.example` supplied an `APP_KEY` the suite was relying on, and leftover `*_create_personal_access_tokens_table.php` files under its `database/migrations/` let Larastan infer `PersonalAccessToken`'s columns, so `composer analyse` passed locally and failed in CI on `Access to an undefined property`. To reproduce a CI-only analysis failure, empty that directory and delete `build/phpstan` before running. Do not write narrowing that only one side asks for: `Command::argument()` and package view strings are inferred differently depending on whether the analyzer could boot the application, so a check written for one side is reported as dead code by the other. Take `mixed` and narrow inside a helper, as `Access\Tokens` and `Console\Argument` do.
- **The dashboard stylesheet is a committed build artifact, and `package-lock.json` is committed
  with it.** `resources/dist/dashboard.css` is compiled by `npm run build` from
  `resources/css/dashboard.css`, and a consuming application runs no asset build -- the decision on
  #30. Tailwind emits only the classes it finds by scanning, so every directory holding markup this
  package renders must be named in an `@source`, and **`@import "tailwindcss" source(none)` is
  load-bearing**: without it Tailwind's automatic detection scans the whole project on top of what
  `@source` names, so anything anywhere that looks like a class name enters the shipped stylesheet. A
  test asserting `bg-red-500` was absent put `bg-red-500` into the artifact and failed on itself, and
  23 KB of the 74 KB build was classes scraped from tests and prose. The
  **`src/` is NOT a Tailwind source, and that is the decision on #111.** Scanning it put 30 KB of
  the 68 KB artifact there -- 44% -- because the extractor cannot tell a class name from an English
  word: `ordinal`, `transition`, `collapse`, `stack`, `step`, `range`, `progress`, `static`,
  `visible` and `inline` are all class names and all appear in this package's prose and method
  names. daisyUI multiplies it, one `step` in a sentence emitting nine `step-*` rules. Only
  `resources/views` is scanned now, so **a class name written in PHP reaches no stylesheet** and
  the element renders unstyled. `EscapingGuardTest` refuses a class-shaped token in a string
  literal under `src/` for that reason; comments are stripped first, because prose is exactly what
  must not be read. Comparing a build with `src/` added back cannot do the job -- prose always
  yields candidates, so the two always differ. The
  artifact goes stale silently: a view added without a rebuild renders with the previous build's
  classes and nothing reports it. Measured while building #72 -- the committed file was missing
  `.card-body`, `.card-title`, `.antialiased` and `.bg-base-200`, every one a class the new layout
  used, and the page would have rendered half-styled. `npm run check` rebuilds and compares; read
  its exit code, because piping it through `tail` discards the `cmp` status. **`composer.lock` is
  gitignored and `package-lock.json` is not**, and that asymmetry is deliberate: the first is a
  library's dependency resolution, which CI should re-resolve, and the second is a build toolchain,
  whose drift would change the bytes a consumer receives. **CI checks this**: the `stylesheet` job
  runs `npm run check` and `ci-passed` requires it, so a stale artifact fails the build rather than
  shipping (#66). What it does **not** catch is an artifact that matches its sources and should not
  -- a class name written in a Blade comment is part of those sources, so the rebuild agrees with it
  and the check passes. That is #230.
- **SQLite enforces no foreign key in this suite, so no test can observe one unless it says so.**
  Testbench's `Bootstrap\LoadConfiguration` sets `foreign_key_constraints` to `Env::get('DB_FOREIGN_KEYS', false)`,
  where Laravel's own skeleton config defaults the same key to `true`. Measured: `pragma foreign_keys`
  reads **0** on the default `testing` connection. So a cascade, a `nullOnDelete`, or a constraint
  violation is inert locally and enforced only in CI's `postgres` job -- and a test written to pin
  any of them passes identically whether the constraint is declared or not. Found while dropping the
  events table's foreign key for #59: the test written to show that a dangling session id survives a
  delete passed with the constraint put back, which made it a description of nothing. A test that
  genuinely depends on a constraint being enforced issues `DB::statement('pragma foreign_keys = ON')`
  and asserts it read back 1, rather than assuming the engine agrees -- **guarded on
  `DB::connection()->getDriverName() === 'sqlite'`**, because `pragma` is SQLite's alone and the
  `postgres` job runs the same files. Better still, write the test so it does not depend on
  enforcement at all: the one that shipped forces the state it is about rather than asking the engine
  to produce it.
- **A bound a validation rule states is not a bound the package holds.** Every store here is a
  public method on a `final` class a host can resolve and call, and the create paths spread what
  they are given straight into an insert -- so a rule in a controller protects the endpoint and
  nothing else. The column does not close the gap, because the column means something different on
  each engine: measured for #57, `unsignedTinyInteger` is `tinyint unsigned` on MySQL (0-255,
  an error in strict mode and a clamp otherwise), `smallint` on Postgres (signed, because it has no
  unsigned integers), and an unbounded `integer` on SQLite; `string()` is refused past its length by
  Postgres and MySQL and stored whole by SQLite. One call, three outcomes. **The settled pattern:
  an ordinal clamps and content refuses.** `Models\Task`'s `priority` mutator clamps, because 10
  and 9 both mean "as urgent as can be"; `Support\Tasks::create()` throws for an over-length
  `title` or `description`, because shortening content changes what it says, silently, in a field
  other developers' agents read -- and two engines already refuse it, so throwing makes the third
  agree rather than inventing a behavior. A test for either writes through the store, never through
  the endpoint, or it tests the validator instead of the guarantee, and it asserts on the ROW rather
  than on the instance the store returned, which reports whatever PHP handed in.
  **Every store now holds its own bounds**, through one narrow helper per value rather than a check
  per call site: `Support\HostKey` (64, the width of the fourteen columns holding a host user key, which `tests/HostKeyComparisonTest.php`'s `hostKeyColumns()` enumerates and keeps closed),
  `Support\ProjectId` (128 and a charset), and `Support\MachineIdentity` (`harness` 32,
  `machine_label` 64, each with a charset). `Models\FleetEvent::MAX_BODY` replaced four private
  copies of `4000`. **The unit is characters, everywhere**, because that is what Laravel's `max:`
  rule measures -- `ValidatesAttributes::getSize()` ends `return mb_strlen($value ?? '')` -- and what
  Postgres and MySQL count a `varchar` in. `Locks::acquire()` measured bytes and was aligned, which
  changed **no** input's fate: its charset regex is ASCII-only, so any string where the two functions
  disagree is refused by the regex either way. It is an equivalent mutant, and no test can tell the
  two versions apart -- worth knowing before someone "proves" the fix with a passing suite.
  **`HostKey` refuses rather than truncates**, because two developers whose keys share a 64-character
  prefix would collapse into one, which is an access-control failure rather than a storage one.
  Two related traps: a `string()` with no length takes `Schema::$defaultStringLength`, a public
  static the HOST may lower, so every column declares its own; and the values that leave a
  visibility rule -- `project_id`, `harness`, `machine_label` -- are charset-limited as well as
  length-limited, because `Tasks::create()` and `AgentSessions::start()` write them into the change
  feed, which every session in the fleet reads.
- **SQLite does not enforce a `varchar` length and Postgres does**, so a fixture that writes an
  overlong value passes every local run and fails only in the `postgres` job. `$table->string('x', 32)`
  is a hard limit there: Postgres answers `SQLSTATE[22001] value too long for type character
  varying(32)` where SQLite stores the value whole. Measured on a test that wrote a 34-character
  payload into `robot_council_device_codes.harness`, which is `varchar(32)`. A test that writes past
  validation on purpose -- which is how the escaping guards prove the page rather than the validator
  -- has no rule to keep it inside the column, so it has to assert the bound itself.

- **Never copy files aside into a flat scratch directory when two of them share a basename.** The
  package has `src/Support/Locks.php` and `src/Livewire/Locks.php` (and, until #308 split the
  presence panel, two `FleetPresence.php`), and `cp <both> "$SCRATCH/"` leaves one file holding the
  other's contents. Restoring then writes the wrong class back, `php -l` passes because both are
  valid PHP, and the tests keep passing because the surviving copy is the one they exercise.
  Measured while mutation-controlling #75: the Livewire component was overwritten by the store, and
  only a `grep` for a string unique to the component caught it. Name the copy after its path
  (`livewire-Locks.php`), and verify a restore by content rather than by the `cp` having exited 0.

- **Restoring a Blade view does not undo it: the compiled view wins on mtime.** Blade recompiles only
  when the source is newer than its cache under
  `vendor/orchestra/testbench-core/laravel/storage/framework/views/`, and a `cp` restore writes an
  mtime a second *older* than the compile that the planted run produced. Measured while
  mutation-controlling `resources/views/enroll.blade.php`: with the source byte-identical to `HEAD`
  and `cmp` confirming it, the cached compile still held `$code->machine_label` with no `e()`
  wrapper, so three rows of the new guard and the unrelated
  `DeviceVerificationTest > it escapes what the requester supplied` all failed against a view nobody
  had changed. The restore reads as complete and the next run tests the planted version. **Any
  mutation control that edits a Blade view has to clear that directory afterwards**, and a failure in
  a view test that `git diff` cannot explain is this until proven otherwise.

- **The local suite and CI run on different cache stores, and nothing records it.** A local
  checkout may have `vendor/orchestra/testbench-core/laravel/.env` with `CACHE_STORE=database`,
  copied there by a `vendor/bin/testbench` run; a fresh CI install has no `.env`, so `cache.default`
  falls back to `array`. Rate limiting is the visible difference: on a database store the limiter
  issues a dozen queries before the route's own first query, which changes where a `DB::listen`
  injection lands. Name the store alongside any result that depends on query order.
- **The suite's summary and its exit code are different answers, and CI reads the exit code.**
  `phpunit.xml.dist` sets `failOnWarning`, `failOnRisky`, `failOnEmptyTestSuite` and
  `beStrictAboutOutputDuringTests`, so a run can print `Tests: 388 passed` and still exit 1 with no
  failure shown anywhere -- not in the summary, and not in `build/report.junit.xml`, which records
  neither warnings nor risky tests. One `use SomeGlobalClass;` in a test file with no namespace does
  it: PHP warns that the statement has no effect, and the run fails. Read `$?`, and never take a
  green reading from a command whose output went through a pipe, which throws the status away. To
  find what a silent failure was, run with `--log-events-text` and grep for `Triggered`.
- **`Builder::update()` returns rows CHANGED, not rows matched, on MySQL.** Laravel sets no
  `MYSQL_ATTR_FOUND_ROWS` (zero occurrences in the framework) and reads `PDOStatement::rowCount()`,
  so a conditional update whose `where` matched a row that already says what was asked for reports
  **0** there and **1** on SQLite and Postgres. Every store in this package decides with
  `$changed !== 1`, so any write that can legitimately be a no-op needs a second look before it is
  read as a lost race: `Locks::renew()` inside one second is the case that bites, and its regression
  test cannot fail on SQLite for the same reason.
- **`composer.lock` is gitignored.** Every CI run and every fresh install resolves dependencies anew, so an unchanged branch can go red later. Compare resolved versions before blaming a diff (see `measurement-parity`).
- **CI is one workflow with one required check.** [`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every pull request and every push to `main`, with no path filters:
  - `tests` runs `vendor/bin/pest --ci` on ubuntu and windows × PHP 8.5 and 8.4 × Laravel 13 × `prefer-lowest` and `prefer-stable`, with `fail-fast: false`, on Pest 5 and PHPUnit 13.
  - `phpstan` runs PHPStan on PHP 8.5, and `pint` runs `vendor/bin/pint --test`, which **fails on a style problem instead of fixing it**. Run `vendor/bin/pint --dirty` before pushing.
  - `rector` runs `vendor/bin/rector --dry-run`, which fails when Rector would change a file. Run `composer refactor` before pushing, and review what it changed.
  - `stylesheet` runs `npm ci` and then `npm run check`, which rebuilds the dashboard stylesheet and `cmp`s it against the committed one, so a view added without a rebuild fails the build instead of shipping half-styled (#66). It reads the exit status rather than piping it. What it cannot catch is an artifact that matches its sources and should not, which is #230.
  - `postgres` runs on ubuntu with PHP 8.5 against a `postgres:17` service container. It runs `vendor/bin/pest --ci` with `DB_CONNECTION=pgsql`, then `vendor/bin/pest --ci --group=cross-connection`.

    **A `postgres` job reported as `cancelled` is this job's TIMEOUT, not a failing test** (#278). The checks panel says only `postgres -> cancelled`, the step reports no failure, and `ci-passed` goes red -- so it reads exactly like a real break on whatever diff happens to be open. Two have been recorded: 618s on `f9cdfd9` and 607s on `ab801f2`, both with every test that ran passing and the suite still working when the budget expired. To tell one from the other, open the job and read the last `PASS` line against the kill time; a timeout has no failing test anywhere in the log. **`gh api .../runs/<id>/jobs` will not show you this** -- it returns the LATEST attempt, so a cancelled run that was re-run reads as a clean success. Ask `.../runs/<id>/attempts/<n>/jobs` instead; measured, attempt 1 of run 35994405778 was the 618s cancellation while the run's own `conclusion` is `success`.

    **The budget is 22 minutes and the number is derived** (#278). Measured across 44 runs on 2026-09-24, every other job finishes inside 10 minutes with room to spare -- the tightest is `mysql` at **3.1x** its observed maximum, and the Windows test cells sit at 3.3x -- while `postgres` had **1.0x** and overran twice. Its worst *completed* run was 426s and its median 302s, so the same 3.1x multiple gives 1331s, which is 22 minutes. Leave the others at 10: they were checked in the same pass and none is close.

    **Its `Execute tests` step took 264s on 2026-09-24, and the step's run-to-run noise is far wider than any one test file's cost** (#249). Thirteen recent `postgres` jobs, almost all on unchanged content, ran that step in 170, 211, 222, 242, 258, 259, 260, 261, 261, 264, 264, 267 and 371 seconds. **371 is not the top of the range** -- re-measured for #278 over 40 successful jobs the step ran 165 to 371 with a median of 261, and the two killed jobs were still executing it at **547s and 574s** when they were cut off, so the true tail is unknown and at least 1.5x the worst completed reading. **So a single before-and-after pair cannot attribute a difference of tens of seconds to a change** -- a 170s reading exists on content carrying everything. Measure a file's cost by reading its own duration, not by differencing two job totals: the two query-plan files together take **3.45s**, read directly against PostgreSQL 17.0, and removing both from a local full-suite run moved 133.0s/129.2s to 132.3s, which is inside the noise. Neither file is a growing cost, and neither fixture should be shrunk -- the row count is near its floor, which #249 records.

    **Both engines run locally, and the claim that they do not has cost time twice.** Laravel Herd serves `mysql` and `postgresql` as services; on this machine they were already listening on 3306 and 5432, needing nothing started. Measured 2026-09-22: PostgreSQL 17.0 and MySQL 9.4.0, both reachable as `postgres` and `root` with an empty password, and the suite passes against each with a throwaway database. So a defect that only one engine can see is **not** CI-only, and reaching for CI to find one is a choice rather than a necessity -- #39's twenty failures were reproduced locally test-for-test and fixed without a single push.

    **A local run is not parity with the job, though, and what is listening changes under you.** Re-measured 2026-09-22 while taking #80: port 5432 served **PostgreSQL 18.0** and port 3306 served **MariaDB 12.3.2**, not the Postgres 17.0 and MySQL 9.4.0 recorded above hours earlier. A second Herd service, `robot-council-pgsql`, now serves **PostgreSQL 17.6** on **5433**, which is the one that matches CI's `postgres:17`; Herd refuses to create a service on a port another already holds, and the error names neither the port nor the holder. So read `select version()` off the connection you are about to measure and record it beside the result -- **the port does not identify the engine, let alone its major.** MariaDB is not MySQL: it is a separate optimizer, and a plan or a `NOT NULL TIMESTAMP` behavior read there is evidence about MariaDB only. Use local runs to find and fix, and the job to confirm.

    **A third service, `robot-council-mysql`, serves real MySQL 9.7.2 on 3307** (added 2026-09-23 while taking #247, which asked for a MySQL measurement that 3306 could not supply). `select version()` reads `9.7.2` with `version_comment` **MySQL Community Server - GPL**, against 3306's `12.3.2-MariaDB`; read the comment as well as the version, because the number alone does not say which product answered. It is `root` with an empty password, database `robot_council`, `collation_server` `utf8mb4_0900_ai_ci` and `explicit_defaults_for_timestamp` **ON** -- the job turned that off, so a `NOT NULL TIMESTAMP` reading here is not the job's. **`herd services:create` with `--no-interaction` and no `--service-version` crashes** on `Laravel\Prompts\select(): Return value must be of type string|int, null returned`, having created nothing; pass `--service-version` and read `herd services:list` rather than the exit code, which a pipe discards. **The full suite against it is far slower than against Postgres on this machine, and the gap is per test rather than a fixed startup cost** -- measured while taking #247 over the first 435 tests of a run: median **1.69s** per test and 3.45s at the 90th percentile, against **558s for 1,200 tests** on PostgreSQL 17.6, which is 0.47s each. It sits at about 5% CPU throughout, so it is waiting on the server rather than on PHP. Run one file against MySQL while working, and the whole suite only when something actually needs it.
  - **The `mysql` job runs the `engine-semantics` group and nothing else** (#253). The FULL MySQL
    job was dropped on 2026-09-22: the deployment runs Postgres on Laravel Cloud, and it cost 585s
    against 168s for `postgres`, rising with every test file added -- 328s, then 409s, then 585s,
    at which point it was cancelled at its timeout with every step reporting success. That number
    is about the whole suite and was never evidence about the few files whose subject is an engine.
    **Measured in CI on its first run: 21s for the test step and 65s for the whole job**, against
    312s for `postgres` on the same run, and 8.4s for the same 26 tests locally against MySQL 9.4.0.
    It sets `explicit_defaults_for_timestamp` OFF and `ROBOT_COUNCIL_EXPECT_MYSQL`, and `ci-passed`
    requires it.
    **The argument that made the full job's loss tolerable had a gap, and #247 walked into it.**
    #137 recorded that `HostKeyComparisonTest`'s behavioral tests run on every engine, so #54's
    access-control property stayed covered on Postgres. That holds only where the ANSWER is the
    same on every engine, and comparison semantics are exactly where it is not: `Support\HostUsers`
    bound an integer against a `varchar(64)`, which matched one row on SQLite and Postgres and
    every numerically equal row on MySQL and MariaDB. Measured by reverting that fix: the `mysql`
    job's selection fails **3** tests and the SQLite suite fails **1**, and two of the three are
    row-level properties no other engine can answer.
    **Membership is a Pest group, and `tests/EngineSemanticsGroupGuardTest.php` derives it from the
    gate each test already declares** -- `notMySql(...)` or `ROBOT_COUNCIL_EXPECT_MYSQL` -- so a new
    MySQL-gated test that forgets `pest()->group('engine-semantics');` fails that guard on every
    engine rather than silently dropping out of the only job that could answer it. Two files are
    exempt by name and the list is asserted: `tests/Pest.php`, which declares `notMySql()`, and the
    guard itself, whose probes quote both markers as fixture source.
    `tests/MySqlSchemaTest.php` is the promise-guard: it fails when a run that set
    `ROBOT_COUNCIL_EXPECT_MYSQL` is not actually on MySQL with `explicit_defaults_for_timestamp`
    off, which is what stops the job skipping every MySQL-gated test and reporting green. Herd
    defaults that variable ON, so a local run reproduces the job only after a `SET GLOBAL`.
  - **The `NOT NULL TIMESTAMP` rule is guarded at the source, not on one engine** (#136).
    `tests/MigrationTimestampGuardTest.php` scans `database/migrations/` for a non-nullable
    `timestamp()` and runs on every engine with no database, because the rule is about what the
    migrations **declare** rather than what MySQL does with the declaration -- so it fails in the
    pull request that adds the column instead of in whichever job happens to have MySQL. The
    `information_schema` version was removed rather than kept beside it: two guards with different
    reach is how one of them rots unnoticed. `timestamps()`, `timestampsTz()`, `nullableTimestamps()`
    and `softDeletes()` all create nullable columns and are not reported; **`->nullable(false)` is
    reported**, because it is an explicit NOT NULL and an exemption keyed on the method name alone
    would miss it. `database/stubs/` is out of scope -- those are the host's tables.
    `robot-council:doctor` still asks `information_schema` at runtime, which is the right place for
    it: it answers for a host's live schema, including drift no source scan can see.
  - `ci-passed` succeeds only when every other job succeeded. It is the one check the `main` ruleset requires.
  - Nothing writes `CHANGELOG.md` automatically. A release adds its entry through an `Update CHANGELOG for vX.Y.Z` pull request before the tag (the `writing-release-notes` skill).
  - Dependabot opens weekly Composer and GitHub Actions update pull requests labeled `dependencies`. Nothing merges them automatically: take each through `pre-merge-check` like any other change.
- **`main` is guarded by a ruleset**: a pull request, a successful `ci-passed`, and a branch that is up to date with `main`. Enforcement holds only while the ruleset is `active` (`gh api repos/robot-council/core/rulesets`); `pre-merge-check` covers what no check can.
- **Package classes are `final`, with no `protected` methods.** Pest's `strict()` preset enforces it, so consumers cannot extend them; extension points have to be designed in. A method a parent declares `protected` is widened to `public`, with a per-file Rector skip (see `php-coding-standards`).
- **`assert()` is unavailable in `src/`.** Pest's `security()` preset bans it, so narrow types with `if` / `throw` instead (see `php-coding-standards`).
- **Neither `Installation` nor `AgentSession` extends `Illuminate\Foundation\Auth\User`.** Each is a plain model with the `Authenticatable` trait. Sanctum decides whether a token belongs on a guard with `$tokenable instanceof $model`, reading the guard's provider model, so a host that names the framework's base user as its own users model would otherwise find an agent session to be an instance of it and admit the token on its own `auth:sanctum` routes. Testbench's default users model is exactly that class.
- **A test that makes two authenticated requests must forget the guards between them.** `Illuminate\Auth\RequestGuard::user()` caches the principal it resolved, and one test process keeps one application, so the second request is otherwise answered as whoever the first authenticated: a revoked token keeps working and another installation's credential arrives as this one's. `TestCase::machine()` does it; a real request boots its own application, and Octane flushes the same state.
- **`Model::preventLazyLoading()` only ever fires on a query that returned more than one row.**
  `Builder::hydrate()` sets the flag `if (count($items) > 1)`, so a model loaded with `first()` can
  never raise `LazyLoadingViolationException` whatever a host configured. A claim that some
  single-model path 500s under strict mode is wrong, and a test written to prove one passes for the
  wrong reason. The only queries here that hydrate several are the presence sweep's chunk reads.
- **A route constraint bounds the character set and not the magnitude.** `whereNumber` is `[0-9]+`,
  which admits a number no bigint can hold: Postgres answers `22003 value out of range` -- a 500 --
  where SQLite quietly matches no rows. Use `RobotCouncilServiceProvider::ROUTE_ID`, which is
  `[0-9]{1,18}`, wherever a route takes one of the package's own IDs.
- **`laravel/socialite` caps Guzzle at 7 for host applications.** Socialite v5.31.0 requires `league/oauth1-client ^1.11`, which allows only Guzzle 6 or 7, while Laravel 13 allows `^7.8.2 || ^8.0`. Installing this package therefore resolves Guzzle 7 in the host application, until Socialite allows `league/oauth1-client` 2.x.
- **Tests boot Socialite's provider by hand.** A host application discovers it through Composer, while Testbench registers only what `tests/TestCase.php` lists.
- **The repository belongs to the `robot-council` GitHub organization**, which enables the `Task`, `Bug`, and `Feature` issue types. It moved from `joshdaugherty/robot-council` on 2026-09-17, and old URLs redirect, so links in earlier issues, pull requests, and the `v0.1.0` release still resolve.
- **The package registers morph aliases for its token owners.** `robot-council-installation` and
  `robot-council-agent-session`, merged into `Relation::morphMap()` at register time. Without them a
  host that calls `Relation::enforceMorphMap()` cannot issue any credential, because `getMorphClass()`
  throws for a model outside the map; and the names are the package's own so that a host adding these
  classes to its map later cannot change what `tokenable_type` holds and orphan live tokens. A test
  asserting `tokenable_type` must use the alias, not the class name.
- **A service provider must not throw.** It runs for every request and every artisan command,
  including the `config:clear` that would fix a mistyped value, so `registerRoutes()` logs a warning
  and falls back to the documented default instead.
- **Never disclose an exploitable vulnerability in a public issue or PR.** Use a draft security advisory, per the `security-audit` skill.

## Where the conventions live

Rules (always loaded) — follow them; don't restate them:

- **Shipping:** `adversarial-review` (verify before a change ships or a claim is published), `pre-merge-check` (the judgment steps before merging), `sync-pr-branch` (bring a branch current, and the inputs its checks read), `closing-a-ticket` (what "done" means).
- **Evidence:** `an-empty-result-is-not-evidence`, `measurement-parity`.
- **Local processes and trees:** `long-running-commands`, `worktrees`.
- **GitHub:** `github-api-budget`, `filing-defects-across-repos`, `design-decision-forks`.
- **Prose:** `impersonal-voice-in-github-artifacts`, `no-emoji-in-durable-records`, `american-english-and-dictionary-overrides`.

Skills (activate when working in that area):

- **Code:** `laravel-best-practices`, `php-coding-standards`, `php-documentation`, `pest-testing`.
- **Tooling:** `pcov-setup`, `security-audit`, `wcag-contrast`.
- **Writing:** `writing-commits`, `writing-issues` (labels, templates, the `afk`/`hitl` convention), `writing-pull-requests`, `writing-release-notes`.
