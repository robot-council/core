---
name: writing-release-notes
description: >-
  GitHub Release conventions for the `robot-council/core` Composer package: the
  em-dash release title (`vX.Y.Z — Theme`), a one-sentence milestone lead with an optional
  `**Breaking change**` callout, and a CLOSED, ordered heading vocabulary
  (`## Breaking changes`, `## What's new`, `## What's fixed`, `## Security`,
  `## Maintenance and tooling`) with one bullet per change formatted as
  `- <PR title> [#N](…/pull/N)` — using the API PR title (never a merge or squash commit
  subject), no `by @author`, inline code preserved. Covers the routing cascade and the bundled
  generator, semantic versioning for a library consumers resolve by tag (below 1.0 the
  minor is the Composer caret's breaking boundary), cutting a release (the `CHANGELOG.md` pull
  request, then the tag and a pre-release GitHub Release published through REST, then the wiki refresh), and the retroactive-tag footer. Activate whenever drafting,
  rewriting, or critiquing a GitHub Release title or body, generating release notes, or cutting
  a tag for this repo.
---

# Writing Release Notes

House style for GitHub Releases in `robot-council/core`. It applies to the **whole release
range** — retroactive tags and new ones alike — so the releases page and `CHANGELOG.md` read as one
consistent changelog. The audience is someone deciding whether to take this version of the package;
lead with the theme, then bucket the changes.

## Versioning — what the number promises

This repo is a **Composer library**: applications require `robot-council/core` and Composer
resolves the constraint against its **git tags**. The tag is therefore a compatibility promise, not a
milestone marker. Use **semantic versioning**: `MAJOR.MINOR.PATCH`, where a patch never breaks a
consumer, a minor adds without breaking, and a major is the breaking boundary.

**Below 1.0 the breaking boundary moves to the minor**, because that is how Composer's caret reads it:
`^0.3` means `>=0.3.0 <0.4.0` (and `^0.3.2` means `>=0.3.2 <0.4.0`), while `^1.2` means
`>=1.2.0 <2.0.0`. So on `0.x`, a breaking change bumps the **minor**, and a patch must not break
anyone already on that `^0.x` line; from `1.0.0` on, a breaking change bumps the **major**. When to
cut a release, and when `1.0.0` happens, are not decided here.

**A GitHub pre-release flag does not make Composer treat a version as unstable**, and reaching for
it when you meant a resolver to skip a version is the trap. The flag is GitHub metadata: it drives
the `Latest` badge and the release page, and Packagist never sees it. Composer reads stability from
the **version string** alone. Measured 2026-09-22 on this package's own `v0.1.0`, which is flagged
`prerelease=true` on GitHub and which Packagist serves as `version_normalized=0.1.0.0` — a stable
version, resolved by a default `^0.1` with no warning and no `minimum-stability` change.

So the two mechanisms have different audiences, and both are legitimate:

| to tell | use |
| --- | --- |
| a person reading the releases page that a version is provisional | the GitHub `prerelease` flag |
| a **resolver** to skip a version | a tag suffix — `v0.3.0-beta.1`, normalizing to `0.3.0.0-beta1` |

A suffixed tag is resolved only by a caller whose `minimum-stability` admits it, so a release meant
to be skipped carries the identifier on the tag from the start. #154 records this, along with the
decision to **accept** the releases already flagged this way rather than re-tag them: Packagist has
served them, clients cache what they resolved, and moving a served tag fails for somebody else long
after it looks fine here. `robot-council/cli#101` is the same change in the command line, whose copy
of this file is shared with this one by design.

## Title

`vX.Y.Z — <Theme>` — an **em dash** (`—`) with a space either side, never a hyphen, then a
concise Title-Case theme (no trailing period). Examples: `v0.2.0 — Council Configuration`,
`v0.3.0 — Config Publishing, Facade Helpers, and Migrations`.

The title is not cosmetic: it is also the version heading of the release's `CHANGELOG.md` entry
(see *Cutting a release* below).

## Body structure (in order)

1. **Milestone lead** — one sentence naming the release's theme.
2. **Breaking-change callout** (only when the release breaks something) — its **own paragraph**
   immediately after the lead, never appended to the lede sentence:
   `**Breaking change** — <impact and required action>.` State what a consumer must *do* on
   upgrade (republish or edit the config, run or adjust a migration, change a call site, update a
   published view), not the semver mechanics. The itemized detail with PR links goes in the
   `## Breaking changes` section below.
3. **Buckets** — a **CLOSED** set of `##` headings, each included **only when it has items**,
   always in this order:
   - `## Breaking changes` — anything a consumer must act on to upgrade: a removed or changed
     public class, method, or facade signature in `src/`; a renamed or removed config key; a
     migration or factory change; a published view change. Name the impact, not just the change.
   - `## What's new` — new features, commands, config options, facade methods, migrations, views,
     and integrations.
   - `## What's fixed` — bug fixes, regressions, correctness and performance fixes.
   - `## Security` — vulnerability fixes and hardening (XSS, SSRF, CSP/security headers, auth,
     egress, injection, sanitization).
   - `## Maintenance and tooling` — docs, CI, tests, refactors, dependency bumps, chores, and
     developer-experience / skills work.
4. **Footer** — retroactively-tagged releases only: `_Retroactively tagged at \`<sha>\` (<date>)._`
   Real-time releases omit it.

Do **not** invent headings outside this closed set. If something doesn't obviously fit, it is
*New*, *Fixed*, or *Maintenance and tooling* — decide by dominant intent.

## Line format

- One bullet per change: `- <PR title> [#N](https://github.com/robot-council/core/pull/N)`.
- **Use the PR title from the GitHub API** (`gh pr view N --json title`), **never the commit
  subject on `main`.** A merge commit's subject is a branch slug (`Merge pull request #N from
  robot-council/<branch>`), and this repo's squash setting (`COMMIT_OR_PR_TITLE`) takes the
  *commit's* title when a PR has a single commit, so neither is reliably the PR title. For a change
  on `main` with no PR number in its subject — a direct commit, or each commit of a rebase merge —
  use the commit subject (strip any Conventional-Commit prefix and `[skip ci]` litter) and link the
  **short commit SHA**:
  `` - <title> [`a1b2c3d`](https://github.com/robot-council/core/commit/<sha>) ``.
  Every bullet is linked — `[#N]` for a PR, a backticked short SHA for a direct commit.
- **No `by @author`.** On a single-maintainer repository attribution is noise. GitHub's
  auto-generated notes add it, which is one reason not to use them.
- Preserve inline code in titles (class names, config keys, paths, package names).
- Don't restate the lead inside a bucket; don't add an `H1`.

## Prose style (titles, leads, and bullets)

- **Never use an ampersand (`&`)** — write "and". This applies to release titles, the lead, and
  every bullet.
- **Use the Oxford comma** — `config, migrations, and a facade helper`, not
  `config, migrations and a facade helper`.

## Routing (which bucket) — by title, closing-issue label and type, and diff shape

A change routes on its resolved title, the labels and the **GitHub issue type** of the issue its PR
closes (read from that issue, not from the PR), and which paths its diff touches. A cascade, first
match wins — the order is what makes it correct:

1. **Breaking changes** — editorial call, per the bucket definition above; the generator cannot
   infer it, so it is passed in by flag.
2. **Security** — a `security` label, or a title mentioning `XSS`, `SSRF`, `CSP`, `HSTS`, `XXE`,
   `ReDoS`, egress, nonce, impersonation, sanitize, SSL verification, security header,
   `X-Powered-By`, password protection, internal-network, or "escape" of a script/HTML/JSON-LD sink.
3. **Maintenance and tooling** — a `documentation` (or `build`) label on the closing issue. Checked
   before the fix verbs, because a maintenance title can open with `Correct` or `Stop`.
4. **What's new** — the diff touches a published surface (`config/`, `database/`, `resources/`,
   `routes/`), whatever its title says. `src/` is deliberately not on that list: a change there
   routes on its title and on how test-heavy the diff is.
5. **What's fixed** — the title opens with `Fix`/`Resolve`/`Repair`/`Prevent`/`Guard`/`Restore`/
   `Correct`/`Harden`/`Stop`/`Avoid`.
6. **Maintenance and tooling** — any one of three, checked in this order and all returning the same
   bucket: the diff is confined to tooling (`.github/`, `.claude/`, `tests/`, `workbench/`,
   `composer.json`, `phpstan.neon.dist`, `phpstan-baseline.neon`, `phpunit.xml.dist`, `rector.php`,
   top-level dotfiles, `CHANGELOG.md`, `CLAUDE.md`, `README.md`, `LICENSE.md`); or it adds more
   lines under `tests/` than elsewhere **and edits nothing under `src/` or `app/`**; or the title
   opens with a maintenance verb (`Refactor`, `Bump`, `Document`, …) or names tests, coverage,
   mutation, a skill, a worktree, or dependencies.
7. **The issue type a human set** — `Bug` routes to *What's fixed*, `Feature` to *What's new*, read
   from `closingIssuesReferences` at the cost of one field on a query the generator already makes.
   **It is a last-resort tiebreaker, and both bounds on that position are load-bearing.** Above
   rules 4 to 6 it would call a `Bug`-typed change confined to `.claude/` a fix, where a change to a
   skill file is maintenance whatever its ticket is typed; it would call a test-only diff that
   changed no behavior a fix; and it would take `Raise dependency floors …`, which rule 6
   deliberately routes to Maintenance by its title, and file it under *What's fixed* the moment
   somebody typed that ticket `Bug`. The rest of this cascade already holds that an explicit signal
   beats an inferred one, and the issue type is the coarsest signal here — so it decides only what
   nothing else could. **`Task` is not consulted**, although it correlated with *fixed* six times
   out of six on the range this was measured on: that is an artifact of this skill set assigning
   `Task` to spikes, forks, cleanups, and epics, so a rule built on it would break the first time
   somebody typed a ticket correctly. A change closing issues of several types is a **fix** —
   under-claiming novelty is the cheaper error.
8. **What's new** — everything else. Note that `Feature` at rule 7 reaches the same answer as this
   default, deliberately: the branch is kept so that a rule added after it cannot silently take
   every `Feature` with it, and it is annotated in the source as an equivalent mutant no test can
   distinguish.

## Generating the body — [`gen_release_notes.py`](gen_release_notes.py)

Don't hand-assemble the buckets — run the bundled generator. It reads first-parent git history
for a ref range, pulls each PR's title **live from the GitHub API** (`gh`), and applies every
rule above: prefix/`[skip ci]`/merge-hint stripping, acronym casing of a direct commit's subject
(never of a PR title, which is used as written, and never of a dotted name, a path, or a code
span), the routing cascade, `&` rewritten to `and` with the Oxford comma, `[#N]` PR links, and
backticked-short-SHA links for direct commits. It skips changelog pull requests titled
`Update CHANGELOG for vX.Y.Z`. It depends only on `git`, `gh`, and Python 3 (3.9 or later) — no
other setup. Its title cleanup and routing are tested offline by
[`test_gen_release_notes.py`](test_gen_release_notes.py):
`python3 -m unittest discover -s .claude/skills/writing-release-notes`.

The **editorial** parts it can't infer are passed as flags: the one-sentence `--lead`, the
`--breaking` callout impact, and any `--breaking-item` bullets (`--exclude` a PR itemized there
so it doesn't also auto-list in a bucket).

```
python3 .claude/skills/writing-release-notes/gen_release_notes.py <prev-tag> origin/main \
    --lead "One-sentence milestone theme." \
    --breaking "republish the config file and rename \`seats\` to \`members\`." \
    --breaking-item "Rename the \`seats\` config key to \`members\` [#12](https://github.com/robot-council/core/pull/12)." \
    --exclude 12 \
    > body.md
```

The new tag does not exist yet when the body is generated, so the range ends at `origin/main`
(fetch first). Then review `body.md` and use it as below. `--help` lists all flags; `<prev-tag>`
may be `-` for the repo root (first release). The routing is a heuristic: read every
bucket before publishing and move a bullet the cascade misfiled.

## Cutting a release

**`CHANGELOG.md` changes through a pull request like everything else, before the tag.** Nothing
writes it automatically: the `main` ruleset requires a pull request and a successful `ci-passed` for
every change, so the tag must point at a commit that already carries the entry.

1. **Generate and review the body** against `origin/main`, as above.

2. **Open the changelog pull request** from a branch such as `changelog-vX.Y.Z`, titled exactly
   `Update CHANGELOG for vX.Y.Z` (the generator skips that title, so the pull request never lists
   itself in a later release). Add the entry at the top of `CHANGELOG.md`, directly under the intro
   paragraph: a `## vX.Y.Z — <Theme> (YYYY-MM-DD)` heading, then the body with each `##` heading
   demoted to `###` so the buckets nest under the version:

   ```
   sed 's/^## /### /' body.md
   ```

   Merge it once `ci-passed` succeeds and [`pre-merge-check`](../../rules/pre-merge-check.md) is
   done.

3. **Tag the merge and publish the release**, after confirming that `origin/main` is the changelog
   merge and nothing else landed after it, **and that `ci-passed` succeeded on that exact commit**:

   ```
   git fetch origin
   git log --oneline -1 origin/main
   SHA=$(git rev-parse origin/main)
   gh api "repos/{owner}/{repo}/commits/$SHA/check-runs?check_name=ci-passed" \
     --jq '.total_count, (.check_runs[] | "\(.status) \(.conclusion)")'
   git tag -a vX.Y.Z "$SHA" -m 'vX.Y.Z — <Theme>'
   git push origin vX.Y.Z
   jq -n --rawfile body body.md --arg tag vX.Y.Z --arg name 'vX.Y.Z — <Theme>' \
     '{tag_name: $tag, name: $name, body: $body, prerelease: true}' |
     gh api -X POST repos/{owner}/{repo}/releases --input - --jq .html_url
   ```

   **The check that gates the tag is `ci-passed` on the commit being tagged, not on the pull
   request.** The ruleset requires `ci-passed` on the pull request's head, but merges are squashes,
   so the commit that lands on `main` is a new commit that no pull-request run has validated.
   `ci.yml` runs again on every push to `main`, so a result for that exact commit arrives a few
   minutes after the merge. Wait for it: tag only when the read above reports a `ci-passed` run
   that is `completed success`. **A `total_count` of `0` is not a pass**: while the push run is
   still in progress its `ci-passed` job has not been created yet, so the read comes back empty
   (observed on `8974dd0` on 2026-09-26, while `v0.7.0`'s tagged commit read `completed success`).
   If it concludes anything but `success`, do not tag; treat it like any red `main`.

   **Publish through REST, not `gh release create`,** per
   [`github-api-budget`](../../rules/github-api-budget.md): `gh release create` spends the GraphQL
   quota every session shares, and on 2026-09-25, with that quota exhausted, it created no
   release while the tag was already public -- the release had to be created by hand afterwards. **Every
   release is a pre-release until `v1.0.0`** (the maintainer's standing instruction): the tag stays
   plain, and `prerelease: true` is the GitHub flag, which, as the versioning section explains,
   Composer never sees. Read the release back
   (`gh api repos/{owner}/{repo}/releases/tags/vX.Y.Z --jq '.tag_name, .prerelease'`) before moving
   on. The body goes in from the file with `--rawfile`, never inline, because the bodies are dense
   with backticks, `#`, and `—` that the shell mangles. The release body is `body.md` as generated,
   with `##` headings; only the `CHANGELOG.md` copy is demoted.

4. **Refresh the wiki for this version.** A release is a changelog entry **and** a wiki that
   describes it; without this step a release ships while the wiki still describes an older one.
   Clone or pull `<repo>.wiki.git`, then:

   - **Set the version line** each page opens with (`Describes robot-council/<repo> vX.Y.Z.`),
     Home's included.
   - **Correct every page this release's changes make wrong.** Walk the release body's entries and
     check each against the pages it touches, reading the code at the new tag rather than the
     entry's wording; link the issue or pull request behind each changed claim, and the source at
     `blob/vX.Y.Z`.
   - **A release that changes nothing a page describes still moves the version line**, and its
     release note says the wiki was checked.

   **A wiki push publishes immediately; there is no pull request and no check between the push and
   every reader.** So the change is reviewed *before* it is pushed, by whoever the maintainer has
   delegated wiki approval to -- send them the diff, and push only what they approved. Then read
   the push back from a fresh clone of `<repo>.wiki.git` and compare it with what was approved,
   file for file.

**Keep the release and the entry in step.** Editing a published release
(`gh api -X PATCH repos/{owner}/{repo}/releases/<id> -F body=@notes.md`, with the id from
`releases/tags/vX.Y.Z`) changes only the release, so a correction to one is a pull request to the
other.

Retroactive tags: create an **annotated** tag stamped with the target commit's date so
`git tag --sort=creatordate` orders correctly —
`GIT_COMMITTER_DATE="$(git log -1 --format=%cI <sha>)" git tag -a vX.Y.Z <sha> -m '…'`. GitHub's
`published_at` is still the publish time (not backdatable via any API); the API's `created_at`
already reflects the tagged commit's date.

## The DRY line

This is the standing statement of Release conventions. It composes with
[`writing-commits`](../writing-commits/SKILL.md) and
[`writing-pull-requests`](../writing-pull-requests/SKILL.md) (the PR titles this skill renders
come from those) and with [`pre-merge-check`](../../rules/pre-merge-check.md), which the changelog
pull request goes through like any other. The `gh` flags live in that tool; don't restate them.
