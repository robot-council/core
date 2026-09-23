# Changelog

All notable changes to `robot-council` will be documented in this file.

## v0.3.2 — Fleet Totals (2026-09-23)

Fleet totals above the dashboard panels, fewer queries behind them, and a doctor that can answer one question so a deploy can gate on it.

### What's new
- Show the fleet's totals above the dashboard panels [#204](https://github.com/robot-council/core/pull/204)
- Let `robot-council:doctor` run a subset of its checks [#208](https://github.com/robot-council/core/pull/208)

### What's fixed
- Count the installation table once, not twice [#203](https://github.com/robot-council/core/pull/203)
- Cut the fleet presence panel from nine queries to six [#201](https://github.com/robot-council/core/pull/201)

### Maintenance and tooling
- Tell a released lock from a held one [`650273a`](https://github.com/robot-council/core/commit/650273a288ea3a6134741e7db1d9bbf887b70a4b)

## v0.3.1 — Console Shell (2026-09-23)

The dashboard becomes a console: a sidebar shell, a branded light and dark theme, sign-out, and the enrollment page rendered inside it.

### What's new
- Ship a branded light and dark theme [#198](https://github.com/robot-council/core/pull/198)
- Render the enrollment page inside the dashboard shell [#196](https://github.com/robot-council/core/pull/196)
- Give the dashboard a sidebar and header shell [#195](https://github.com/robot-council/core/pull/195)
- Name which recorded migrations belong to this package [#179](https://github.com/robot-council/core/pull/179)

### Maintenance and tooling
- Remove the mutation surface from `Doctor`'s diagnosis messages [#188](https://github.com/robot-council/core/pull/188)
- Build the presence view's lock data without a duplicated key [#182](https://github.com/robot-council/core/pull/182)

## v0.3.0 — A Clock That Does Not Shift (2026-09-23)

Presence, lock leases, and enrollment codes move onto a clock that cannot shift under them; an event now says both who it is about and who acted; and a session can ask whether anything on the fleet can reach it.

**Breaking change** — run `php artisan migrate`. On a host whose `app.timezone` is not UTC, outstanding presence, lock, and device-code rows are reinterpreted once at the upgrade -- east of UTC that briefly extends a device code's life, west of it expires outstanding codes at once. And `robot_council_events.user_id` now always means the developer an event is **about**, with the developer who acted in the new `actor_user_id` column.

### Breaking changes
- Measure presence on a clock that does not shift [#150](https://github.com/robot-council/core/pull/150).
- Measure a lock's lease on a clock that does not shift [#161](https://github.com/robot-council/core/pull/161).
- Measure a device code's expiry on a clock that does not shift [#164](https://github.com/robot-council/core/pull/164).
- Give an event one meaning for who it is about, and a column for who did it [#165](https://github.com/robot-council/core/pull/165).
- Date the identities migration, so something dated can alter its table [#166](https://github.com/robot-council/core/pull/166).

### What's new
- Make a session's feed position recoverable [`46ec620`](https://github.com/robot-council/core/commit/46ec620f69e4b3f97c3fbc1c24f1fc23a88be518)
- Let a host supply a developer's user attributes [#152](https://github.com/robot-council/core/pull/152)
- Name the installation whose stored abilities cannot be read [#176](https://github.com/robot-council/core/pull/176)
- Tell a session whether anything on the fleet can post a directive [#162](https://github.com/robot-council/core/pull/162)

### What's fixed
- Bound the abilities every device-code path writes [#174](https://github.com/robot-council/core/pull/174)
- Drop a malformed abilities value instead of raising a `TypeError` [#173](https://github.com/robot-council/core/pull/173)
- Name the collision a hidden user causes [#151](https://github.com/robot-council/core/pull/151)

### Security
- End the installation a re-enrollment replaces [#147](https://github.com/robot-council/core/pull/147)

### Maintenance and tooling
- Close the surviving mutants in the device-code classes [#177](https://github.com/robot-council/core/pull/177)
- Close every surviving mutant in the installation store [#168](https://github.com/robot-council/core/pull/168)
- Cover the feed cursor's ordering guarantee on a second connection [#163](https://github.com/robot-council/core/pull/163)
- Derive the repository the release notes are about [#158](https://github.com/robot-council/core/pull/158)
- Say what a GitHub pre-release flag does not do [#155](https://github.com/robot-council/core/pull/155)
- Measure the task queue's plans on Postgres [`e84879f`](https://github.com/robot-council/core/commit/e84879f5247955f1a1da81e3a6e2a038bece4f60)

## v0.2.0 — The Coordination Service (2026-09-22)

The first release with features: GitHub sign-in restricted to an allowlist, agent enrollment through the device-code flow, session presence, task claims, named locks with fence values, an ordered change feed, an MCP server, and a Livewire dashboard.

**Breaking change** — `v0.1.0` was a package shell with no database and no features. Upgrading adds four required dependencies (`laravel/mcp`, `laravel/sanctum`, `laravel/socialite`, and `livewire/livewire`) and nine tables. Publish `config/robot-council.php`, run `robot-council:install` and then `php artisan migrate`, and set the GitHub OAuth credentials and the access lists before any route will serve.

### What's new
- Let a directive name the sessions expected to act [#139](https://github.com/robot-council/core/pull/139)
- Report a host application's misconfiguration with robot-council:doctor [#134](https://github.com/robot-council/core/pull/134)
- Prune agent sessions, the last table nothing deleted from [#130](https://github.com/robot-council/core/pull/130)
- Bound the lock table, and share one fence sequence to make that safe [#129](https://github.com/robot-council/core/pull/129)
- Prune finished tasks, which nothing deleted from [#127](https://github.com/robot-council/core/pull/127)
- Prune the event feed, which nothing deleted from [#126](https://github.com/robot-council/core/pull/126)
- Page and scope the admin panel's installation list [#122](https://github.com/robot-council/core/pull/122)
- Page and filter the presence and lock lists [#121](https://github.com/robot-council/core/pull/121)
- Administer installations and agent sessions from the dashboard [#116](https://github.com/robot-council/core/pull/116)
- Show which checkout a session belongs to on the Agents list [#107](https://github.com/robot-council/core/pull/107)
- Start a session at the head of the change feed, and index what the visibility filter reads [#88](https://github.com/robot-council/core/pull/88)
- Show the fleet change feed on the dashboard [#85](https://github.com/robot-council/core/pull/85)
- Show agent presence and held locks on the dashboard [#82](https://github.com/robot-council/core/pull/82)
- Show the task queue on the dashboard [#79](https://github.com/robot-council/core/pull/79)
- Mount the dashboard shell with Livewire, Mary UI and a compiled stylesheet [#78](https://github.com/robot-council/core/pull/78)
- Serve the coordination tools over MCP with `laravel/mcp` [#65](https://github.com/robot-council/core/pull/65)
- Acquire and release named locks with fence values [#64](https://github.com/robot-council/core/pull/64)
- Claim and transition tasks atomically through the API [#58](https://github.com/robot-council/core/pull/58)
- Track agent session presence [#53](https://github.com/robot-council/core/pull/53)
- Record fleet events as an ordered change feed, and mirror them to Slack [#49](https://github.com/robot-council/core/pull/49)
- Store the host application's user key as a string [#44](https://github.com/robot-council/core/pull/44)
- Enroll agent machines through the device-code flow [#41](https://github.com/robot-council/core/pull/41)
- Sign in allowlisted developers with GitHub [#35](https://github.com/robot-council/core/pull/35)

### What's fixed
- Guard the NOT NULL timestamp rule in the migrations, not on MySQL [#138](https://github.com/robot-council/core/pull/138)
- Answer a stale GitHub callback with a page, not a 500 [#105](https://github.com/robot-council/core/pull/105)
- Drop the events indexes by migration, not by editing the create [#100](https://github.com/robot-council/core/pull/100)
- Reshape the feed read into a capped single-pass query [#99](https://github.com/robot-council/core/pull/99)
- Order the task queue by an ascending key [#90](https://github.com/robot-council/core/pull/90)
- Drop the foreign key on the event feed's session column [#89](https://github.com/robot-council/core/pull/89)

### Security
- Compare a host user key byte-exactly, whatever collation a host gives it [#133](https://github.com/robot-council/core/pull/133)
- Guard what may be written into a Livewire expression [#120](https://github.com/robot-council/core/pull/120)
- Hold every store's bounds in the store, not in a controller [#93](https://github.com/robot-council/core/pull/93)
- Hold a task's bounds in the store, not in a validation rule [#92](https://github.com/robot-council/core/pull/92)
- Refuse agent-supplied text in URL attributes [#71](https://github.com/robot-council/core/pull/71)
- Guard agent-supplied text against rendering as markup [#68](https://github.com/robot-council/core/pull/68)

### Maintenance and tooling
- Ready the package metadata for publication [#144](https://github.com/robot-council/core/pull/144)
- Drop the mysql CI job, keeping the SQLite matrix [#137](https://github.com/robot-council/core/pull/137)
- Run CI against MySQL with explicit_defaults_for_timestamp off [#131](https://github.com/robot-council/core/pull/131)
- Pin the clock where an expiry is asserted to the second [#128](https://github.com/robot-council/core/pull/128)
- Store and check out every text file as LF [#125](https://github.com/robot-council/core/pull/125)
- Ask cp1252 whether it can encode the character [#124](https://github.com/robot-council/core/pull/124)
- Stop scanning `src/` for stylesheet classes [#123](https://github.com/robot-council/core/pull/123)
- Close two gaps the blind-instrument rules do not cover [#119](https://github.com/robot-council/core/pull/119)
- Cover the two dashboard guarantees #30 claimed and nothing asserted [#110](https://github.com/robot-council/core/pull/110)
- Fail CI when the committed stylesheet is stale [#108](https://github.com/robot-council/core/pull/108)
- Point the README at the CLI, and record that tools/list paginates [#97](https://github.com/robot-council/core/pull/97)
- Pin that a throttled agent session is not marked gone [#96](https://github.com/robot-council/core/pull/96)
- Say that the closing-keyword check reads the PR body, and run it every time [#95](https://github.com/robot-council/core/pull/95)
- Make the closing-keyword check fail rather than print [#84](https://github.com/robot-council/core/pull/84)
- Give the machine API one vocabulary [#45](https://github.com/robot-council/core/pull/45)
- Run the test suite against Postgres in CI [#34](https://github.com/robot-council/core/pull/34)
- Rename the package to robot-council/core after the organization move [#13](https://github.com/robot-council/core/pull/13)

## v0.1.0 — Composer Package Shell (2026-09-17)

A pre-release shell of the Composer package: it requires PHP 8.4 or later and Laravel 13.23 or later, registers its service provider, and has no features yet.

### What's new
- Scaffold Laravel package from Spatie skeleton [`40ea724`](https://github.com/joshdaugherty/robot-council/commit/40ea724ee3061df309c29279ad338e525e5484e6)

### Maintenance and tooling
- Fix release-notes routing of CLAUDE.md edits and PR title recasing [#12](https://github.com/joshdaugherty/robot-council/pull/12)
- Remove unused skeleton leftovers and dev dependencies [#9](https://github.com/joshdaugherty/robot-council/pull/9)
- Adopt Pest's php, security, and strict arch presets and its Rector rules [#8](https://github.com/joshdaugherty/robot-council/pull/8)
- Run PHPStan and Rector against tests and rector.php [#7](https://github.com/joshdaugherty/robot-council/pull/7)
- Raise dependency floors to their latest stable releases [#6](https://github.com/joshdaugherty/robot-council/pull/6)
- Raise PHPStan to level max with bleeding edge [#5](https://github.com/joshdaugherty/robot-council/pull/5)
- Add Rector with the Laravel rule sets to the required check [#4](https://github.com/joshdaugherty/robot-council/pull/4)
- Upgrade to Pest 5 and PHPUnit 13, and require Laravel 13 [#3](https://github.com/joshdaugherty/robot-council/pull/3)
- Require one CI check on every pull request and cut releases through a changelog PR [#2](https://github.com/joshdaugherty/robot-council/pull/2)
- Add Claude Code conventions; drop PHP 8.3 and Laravel 11 support [#1](https://github.com/joshdaugherty/robot-council/pull/1)
