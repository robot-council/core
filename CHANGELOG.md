# Changelog

All notable changes to `robot-council` will be documented in this file.

## v0.6.7 — Mirroring Restricted Events (2026-09-25)

`slack.mirror_restricted` is now read from `ROBOT_COUNCIL_SLACK_MIRROR_RESTRICTED`, with the old narration-only name still honored when it is unset.

### What's new
- Read `slack.mirror_restricted` from a variable named for every restricted type [#377](https://github.com/robot-council/core/pull/377)

## v0.6.6 — What a Page Holds (2026-09-25)

`events_read` now says that `limit` caps the events a read returns, and that one read looks at no more than 1,000 event ids.

### Maintenance and tooling
- Say that `events_read`'s `limit` caps the events returned, not the ids examined [#376](https://github.com/robot-council/core/pull/376)

## v0.6.5 — When a Session Ends (2026-09-25)

The README now says that ending a session frees its work at the next presence sweep, within about a minute, rather than at once.

### Maintenance and tooling
- Say that ending a session frees its work at the next sweep, not at once [#375](https://github.com/robot-council/core/pull/375)

## v0.6.4 — Lost Locks, Named (2026-09-25)

A session whose lock was force-released is now told it lost the lock rather than that it never held it.

### What's fixed
- Tell a force-released lock holder it lost the lock, not that it never held it [#374](https://github.com/robot-council/core/pull/374)

## v0.6.3 — An Agent's Own Cursor (2026-09-25)

A bridge can now read the change feed for its agent without moving the agent's resume position; pair it with `robot-council/cli` v0.4.20, which does.

### What's fixed
- Let a bridge read the feed without moving its agent's resume position [#372](https://github.com/robot-council/core/pull/372)

## v0.6.2 — The Fleet Guide (2026-09-25)

A fleet guide in the repository wiki, linked from the README, for the agents and people who coordinate on a fleet.

### Maintenance and tooling
- Seed the wiki with a fleet guide, and link it from the README [#370](https://github.com/robot-council/core/pull/370)

## v0.6.1 — Measuring Edge Order (2026-09-25)

A removal of a `blocked_by` edge that finds nothing stored is now logged, which measures how often GitHub delivers edge changes out of order.

### What's fixed
- Log a `blocked_by` removal that finds no stored edge [#363](https://github.com/robot-council/core/pull/363)

## v0.6.0 — The Lane Board (2026-09-25)

The lane board: the fleet now sees and tells the coordinator what each lane is doing, what it is waiting on, and what GitHub says has finished.

**Breaking change** — a placement's `directive` no longer carries the coordinator's text; it names the task, the lane, and a restricted `placement.instruction` event that does. Update bridges to `robot-council/cli` v0.4.16 or later before deploying, or a lane receives only the directive. Run the package migrations.

### Breaking changes
- Send a placement's instruction to its lane alone, and compose the directive in the package [#361](https://github.com/robot-council/core/pull/361)

### What's new
- Record the operating system and architecture a session runs on, reported by the bridge at session start [#358](https://github.com/robot-council/core/pull/358)
- Let an agent list the live sessions, with each one's last contact and the tasks it holds [#357](https://github.com/robot-council/core/pull/357)
- Raise the lane conditions that go quiet to the coordinator [#356](https://github.com/robot-council/core/pull/356)
- Warn when a ticket's branch already exists, and when documentation is placed ahead of functionality [#355](https://github.com/robot-council/core/pull/355)
- List placeable tickets per repository, unranked, with their blind spots [#353](https://github.com/robot-council/core/pull/353)
- Tell the coordinator when a build lane has authored nothing for an hour [#352](https://github.com/robot-council/core/pull/352)
- Record the bridge watcher's own heartbeat, and show it on the lane board [#350](https://github.com/robot-council/core/pull/350)
- Record what the fleet is waiting on each developer for, and show it on the lane board [#349](https://github.com/robot-council/core/pull/349)
- Record which pull request each gate is validating, and show it on the lane board [#348](https://github.com/robot-council/core/pull/348)
- Record session-reported backlog counts, a start-of-day baseline, and show both on the lane board [#347](https://github.com/robot-council/core/pull/347)
- Add the lane board at `dashboard/lanes`, and a lanes summary on the overview [#346](https://github.com/robot-council/core/pull/346)
- Refuse a placement that breaks a lane invariant, warn on the rest, and let a seat's developer waive [#345](https://github.com/robot-council/core/pull/345)
- Let a coordinator record why a lane is idle on purpose, in a closed vocabulary [#343](https://github.com/robot-council/core/pull/343)
- Let a placement insist the task is still unclaimed [#340](https://github.com/robot-council/core/pull/340)
- Let the lane holding a task report its branch after it starts [#338](https://github.com/robot-council/core/pull/338)
- Let each developer park their own seats and set their own assignment hours [#330](https://github.com/robot-council/core/pull/330)
- Record the issue a task is for, who placed it, and the branch its lane reports [#329](https://github.com/robot-council/core/pull/329)
- Receive GitHub webhooks and free lanes when GitHub reports their work finished [#342](https://github.com/robot-council/core/pull/342)
- Let a narration be addressed to named sessions, or to whoever holds a task [#324](https://github.com/robot-council/core/pull/324)

## v0.5.0 — Repository and Work Location (2026-09-24)

Sessions now carry a repository and a work location instead of one opaque project label, completing the fleet-topology epic.

**Breaking change** — run `php artisan migrate` after upgrading, and read `repository` and `work_location` where a client previously read `project_id`.

### Breaking changes
- Retire the session's `project_id`, keeping its split at the edge [#306](https://github.com/robot-council/core/pull/306). `robot_council_agent_sessions.project_id` is dropped, and the key is gone from `GET api/agent/session`, the administration panel's session rows, and the presence read shape. `POST api/sessions` still accepts `project_id` and splits it into `repository` and `work_location` when a client names neither, so a client that has not been upgraded keeps working. `robot_council_tasks.project_id` is untouched.

### Maintenance and tooling
- Add the `fleet-facing` and `housekeeping` priority labels to the issue-writing skill [#304](https://github.com/robot-council/core/pull/304)
- Put the `ci-passed` comment back on `ci-passed` [#303](https://github.com/robot-council/core/pull/303)
- Run the release generator's tests in CI [#301](https://github.com/robot-council/core/pull/301)
- Stop the issue skill and the impersonal-voice rule naming one repository [#300](https://github.com/robot-council/core/pull/300)
- Report a bullet that loses its link, where it loses it [#297](https://github.com/robot-council/core/pull/297)
- Stop the release generator's no-data warning raising on the response it reports [#296](https://github.com/robot-council/core/pull/296)

## v0.4.0 — Session Roles (2026-09-24)

Session roles replace the per-machine ability grants they made redundant, a session now records where it is working, and the dashboard splits into panels a developer chooses.

**Breaking change** — run `php artisan migrate` for seven new migrations, stop calling the removed ability-grant methods and commands, and match on `session.joined` where you matched on `session.enrolled`.

### Breaking changes
- Retire the `granted_abilities` column and every reader of it [#269](https://github.com/robot-council/core/pull/269). The column is dropped; `Installation`'s accessor and `Role::permittedBy()` are gone.
- Retire the per-ability grant and revoke controls the role preset replaced [#250](https://github.com/robot-council/core/pull/250). `robot-council:grant-ability` and `robot-council:revoke-ability` are removed, along with `grant()`, `revokeAbility()`, `setAbility()` and `grantableFrom()`.
- Rename the `session.enrolled` fleet event, first to `session.started` [#218](https://github.com/robot-council/core/pull/218) and then to `session.joined` [#227](https://github.com/robot-council/core/pull/227). A client matching the old name sees nothing; the net change from v0.3.2 is `session.enrolled` to `session.joined`, and a migration rewrites the recorded rows.

### What's new
- Return from the fleet-ability walk at the first admitted holder [#251](https://github.com/robot-council/core/pull/251)
- Answer `fleet_can_direct` from live coordinator sessions [#248](https://github.com/robot-council/core/pull/248)
- Let a session request a role, and an administrator decide it [#243](https://github.com/robot-council/core/pull/243)
- Record why no dashboard poll carries the visible modifier [#238](https://github.com/robot-council/core/pull/238)
- Split a session's project into a repository and a work location [#234](https://github.com/robot-council/core/pull/234)
- Give a session a role, and its abilities from that role's preset [#233](https://github.com/robot-council/core/pull/233)
- Raise the absent-value placeholders above the WCAG AA bar [#229](https://github.com/robot-council/core/pull/229)
- Nest the console sections under the page that holds them [#225](https://github.com/robot-council/core/pull/225)
- Give each dashboard panel a route of its own [#216](https://github.com/robot-council/core/pull/216)
- Read a session's last contact in words, not seconds [#213](https://github.com/robot-council/core/pull/213)
- Let a developer choose which panels the dashboard mounts [#209](https://github.com/robot-council/core/pull/209)

### What's fixed
- Bind every host user key as text, so MySQL cannot match the wrong developer [#254](https://github.com/robot-council/core/pull/254)
- Compare a session role byte for byte, whatever the server collates [#256](https://github.com/robot-council/core/pull/256)
- Treat MariaDB as MySQL where a collation has to be stated [#262](https://github.com/robot-council/core/pull/262)
- Send the package prefix root to the dashboard [#235](https://github.com/robot-council/core/pull/235)
- Register Livewire components even when a host has cached its routes [#224](https://github.com/robot-council/core/pull/224)

### Security
- Anchor the Livewire-expression guard and reach the Alpine shorthand [#252](https://github.com/robot-council/core/pull/252)
- Refuse a value concatenated into a server-built URL [#242](https://github.com/robot-council/core/pull/242)

### Maintenance and tooling
- Make the read-shape fixtures able to fail, and correct two recorded reasons [#289](https://github.com/robot-council/core/pull/289)
- Stop the release cascade filing a test-heavy feature as maintenance [#266](https://github.com/robot-council/core/pull/266)
- Keep merged head branches, so a pull request's file links keep working [#290](https://github.com/robot-council/core/pull/290)
- Work from two long-lived worktree slots instead of a worktree per ticket [#287](https://github.com/robot-council/core/pull/287)
- Stop the duplicate-check recipe hanging on a search term with a space [#286](https://github.com/robot-council/core/pull/286)
- State the `GH_REPO` bound without naming a repository [#284](https://github.com/robot-council/core/pull/284)
- Use the placeholders `gh` actually substitutes in every REST recipe [#282](https://github.com/robot-council/core/pull/282)
- Give the `postgres` job a timeout it cannot trip on a slow runner [#280](https://github.com/robot-council/core/pull/280)
- Route a release change on the type set on the issue it closes [#274](https://github.com/robot-council/core/pull/274)
- Take the repository from the checkout in the remaining `gh` recipes [#277](https://github.com/robot-council/core/pull/277)
- Take the repository from the checkout in the pull-request skill's `gh` recipes [#271](https://github.com/robot-council/core/pull/271)
- Bump actions/setup-node from 4 to 7 [#265](https://github.com/robot-council/core/pull/265)
- Record what the query-plan tests cost, and that their fixture is near its floor [#263](https://github.com/robot-council/core/pull/263)
- Give MySQL a narrow CI job for the tests whose subject is engine semantics [#260](https://github.com/robot-council/core/pull/260)
- Refuse the comma form for multiple closes, and make the scan refuse both directions [#259](https://github.com/robot-council/core/pull/259)
- Close the mutation survivors in the three read stores [#244](https://github.com/robot-council/core/pull/244)
- Record how Postgres answers the fleet totals counts [#237](https://github.com/robot-council/core/pull/237)

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
