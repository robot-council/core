---
name: upstream-workaround-lifecycle
description: >-
  The lifecycle of a workaround this repository carries for a defect in a dependency: write the
  workaround, report the defect upstream, offer the fix as a pull request, then remove the
  workaround once a fixed tag ships. Covers the four-ticket GitHub chain that tracks it, the
  `afk`/`hitl` mode of each ticket, the native `blocked_by` edges including the cross-repo REST
  recipe that makes an upstream issue a blocker on the removal ticket, where to file when the
  dependency's tracker has issues turned off (as `pestphp/*` plugins do), verifying the fix against
  this repository's own suite with a restored-vendor negative control, checking for the fix by tags
  and Packagist rather than GitHub Releases, and the request variant where nothing local is carried.
  Activate when a dependency release breaks something here and a workaround is being added (a step
  in CI or a `composer` script, a version constraint, a `conflict` entry), when filing or wiring the
  tickets for one, when reporting a dependency defect upstream or opening a pull request to a
  dependency, when checking whether an upstream fix has shipped, or when retiring a workaround.
---

# Carry and retire a workaround for a dependency defect

A workaround is a debt with a payoff schedule. This skill is that schedule: the four tickets that
track it, and what each one does. It is ported from `UAMS-Web/uams-statamic`'s
`upstream-patch-lifecycle` skill, which grew out of a full cycle there, and adapted to a repository
that carries no vendor patches.

**The one-line shape.** Work around it so we are not blocked, report it upstream with a tested fix
in hand, offer that fix as a pull request, and drop the workaround when a tag carries the fix.

**Why carry a workaround at all.** Waiting on a maintainer blocks the fleet; a workaround does not.
But a workaround resolves nothing. It stays in `ci.yml` or `composer.json` doing nothing useful
once upstream is fixed, and nobody notices the fixed release. **Only an upstream fix retires it,
and nothing starts that clock unless the defect is filed.**

## The first run: #542

`pestphp/pest-plugin-browser` v5.1.0 added a `Trace::cleanup()` whose `@rmdir()` warns when
`tests/Browser/Traces` is absent. PHPUnit records that warning at test-runner level despite the
`@`, so the `browser` job exited 1 with all 254 tests passing, on `main` and on every branch.

| # | Ticket | What happened |
| --- | --- | --- |
| 1 | #542 | The workaround: create the directory before the suite runs, in the `browser` job and in `composer test:browser`. Merged as #547. The operator chose this over a version constraint, recorded in #542's `## Decision`. |
| 2 | #544 | Filed as [`pestphp/pest#1944`](https://github.com/pestphp/pest/issues/1944) on Pest core, because the plugin has issues turned off. |
| 3 | #545 | [`pestphp/pest-plugin-browser#278`](https://github.com/pestphp/pest-plugin-browser/pull/278) against `5.x`, removing the directory only when it exists and is empty. |
| 4 | #546 | Blocked by `pestphp/pest#1944`. It removes the directory step once a 5.x tag carries the fix. |

The traps that run hit are listed under *What the first run hit*, below.

## The four-ticket chain

File all four **up front**, when the decision to carry a workaround is made. Each is blocked by
the one before it through GitHub's native `blocked_by` edges, so the tracker records what waits on
what. Every ticket carries `upstream`. Write each per [`writing-issues`](../writing-issues/SKILL.md),
and spend the API per [`github-api-budget`](../../rules/github-api-budget.md).

| # | Ticket | Mode | Blocked by |
| --- | --- | --- | --- |
| 1 | Write the workaround | `afk`, or `hitl` + `decision-fork` while the choice of workaround is open | — |
| 2 | File the defect upstream | `hitl` + `action-flavor` | 1 |
| 3 | Open the upstream pull request that fixes the upstream issue | `hitl` + `action-flavor` | 2 |
| 4 | Remove the workaround once a fixed tag ships | `afk` | 3, then the upstream issue |

**Tickets 2 and 3 are actions under the operator's own identity on a public tracker that is not
ours.** They are `hitl` for that reason, not because anything is undecided. Each needs the
operator's go-ahead. On #544 and #545 it came through coordinator placements quoting the operator (fleet events 6571 and 6576).

**Why the workaround comes first.** It unblocks the fleet now, and building it is where the
defect gets understood. Reporting after it, with a fix already tested against this repository's
suite, gives the maintainer a reproduction and a patch rather than a symptom.

**Ticket 1 names every line the workaround adds, documentation included**, so ticket 4 can remove exactly those. Where the file allows a comment, the workaround's comment cites ticket 4 by number. **The first run fell short of this:** #542 named the CI step and the `composer` script but not the `CLAUDE.md` row, and the `composer.json` entry carries no ticket number because JSON has no comments. So ticket 4 also finds the workaround by its content, `git grep -n 'Browser/Traces'` for #546, not only by ticket number.

### The two transitions that carry the subtlety

Both are easy to miss, and missing either leaves the chain silently broken.

- **Filing upstream closes ticket 2, and the upstream issue becomes a blocker on ticket 4.**
  Without that edge, ticket 4 reads as unblocked the moment ticket 3 closes, while nothing
  upstream has shipped.
- **Opening the upstream pull request closes ticket 3.** Ticket 4 stays blocked until the fix is
  both **merged** and **tagged**, which are separate events.

Comment the upstream links on tickets 1, 2 and 3 as each item is posted, then close 2 and 3.

### The cross-repo `blocked_by` recipe

The endpoint takes the upstream issue's **numeric database `id`**, not its number. A number either
404s or silently links whatever unrelated issue carries that id.

```bash
id=$(gh api repos/pestphp/pest/issues/1944 --jq '.id')    # 5666778908, not 1944
gh api -X POST repos/robot-council/core/issues/546/dependencies/blocked_by -F issue_id=$id
gh api 'repos/robot-council/core/issues/546/dependencies/blocked_by?per_page=100' \
  --jq '.[] | "\(.repository.full_name)#\(.number) \(.state)"'
```

Always read the edge back. On #546 the read-back listed `robot-council/core#545 closed` and
`pestphp/pest#1944 open`. The closed in-repo blocker no longer holds the ticket, and the open
upstream one does.

**A pull request cannot be a blocker.** The endpoint refuses one with `Validation failed: Target issue may only be an issue` (HTTP 422, measured in `UAMS-Web/uams-statamic`). Put the edge on the issue the pull request fixes, so the
merge that closes that issue also clears the edge.

## Filing upstream

### Where to file when the tracker is not where you think

Check `has_issues` before drafting, and **only** that field:

```bash
gh api repos/pestphp/pest-plugin-browser --jq '{has_issues, default_branch}'
# {"default_branch":"5.x","has_issues":false}
```

`open_issues_count` is misleading on such a repository, because GitHub still counts pull requests
there. **`pest-plugin-browser` and `pest-plugin-mutate` both have issues turned off**, and Pest
core is the intake for its plugins: file on `pestphp/pest`. The pull request still goes to the
plugin. Check each repository rather than assuming.

### The report

- **Search for an existing report first**, on the tracker you will file on, with several phrasings
  (the class name, the file name, the symptom). Search is an index, so a miss means "not found as of
  this query", not "absent". Say in the report or the ticket what was searched.
- **Match the tracker's issue form.** Pest core's `bug_report.yml` has What Happened, How to Reproduce, Sample Repository, Pest Version, PHP Version, Operation System (the form's spelling), and Notes. Filing through REST cannot fill a form, so write each field as a `### <label>` heading in that order. `_No response_` is what the form itself writes for an empty optional field. The form also adds a `[Bug]: ` title prefix, which REST does not, so type it, and a `bug` label, which only a triager can apply, so #1944 has none.
- **Write it in plain terms for a maintainer who has never seen this repository.** State the
  mechanism, give a minimal reproduction, a small table of what was measured, and the fix you
  propose. Say a pull request follows.
- **Name the bounds.** Versions of the package, Pest, PHPUnit and PHP, the OS, and where it was
  measured: locally, in CI, or both.
- **Read the posted body back** and compare it with the draft, per
  [`long-running-commands`](../../rules/long-running-commands.md).

### The pull request

- **Fork under the operator's account, and check for an existing fork first.**
  `gh api repos/<login>/<repo> --jq '.fork, .parent.full_name'`; on #545 one already existed.
  `POST repos/<owner>/<repo>/forks` returns the existing fork rather than failing.
- **Branch from the upstream default branch, not the fork's**, which may be stale:
  `git fetch upstream 5.x && git switch --no-track -c <branch> upstream/5.x`. Confirm the file you are fixing on that tip is byte-identical to the tag you reproduced against, by comparing the file rather than the branch: `git diff --quiet <tag> upstream/5.x -- src/Support/Trace.php`. A whole-branch compare answers a different question; v5.1.1 landed on 5.x a minute after #545 opened, and the branch compare then read `ahead` while `Trace.php` was unchanged.
- **Target the major line this repository runs.** A fix on another line cannot reach us even when
  merged.
- **Run the upstream repository's own gates before pushing**: its lint, static analysis and type
  coverage, from its `composer.json` scripts. Its gates are not ours, and they run only in its tree: its Rector rewrote `(new FilesystemIterator($dir))->` to the PHP 8.4 `new FilesystemIterator($dir)->` before the pull request opened.
- **Match its commit style.** `pestphp/*` uses Conventional Commit prefixes (`fix:`, `feat:`, `chore:`).
- **Open it with `Fixes owner/repo#N.`** so the merge closes the upstream issue on the tracker
  where it was filed.
- **Say what the upstream suite can and cannot show.** On #545 the plugin's own suite gave the
  same result with and without the fix: 370 passed and the same 3 failed both times, all three
  loading external pages. A new test would have raced `TracingTest`, which writes to the same
  directory under `--parallel`. The pull request said so, and gave this repository's measurement
  instead.

## Verifying the fix against this repository

The upstream fix is proven here, against the suite that found the defect, with a negative control.

1. **Apply the fix to the installed copy in `vendor/`**, after copying the original aside:
   `cp vendor/<pkg>/src/File.php "$SCRATCH/vendor-File.php.orig"`. **Never the stash**, which every
   worktree shares ([`worktrees`](../../rules/worktrees.md)).
2. **Remove the workaround's effect** for the run, so the fix alone is under test. For #542 that
   meant the bare `vendor/bin/pest --ci --testsuite=Browser` with no `tests/Browser/Traces`.
3. **Run it, then restore the original, confirm the restore with `cmp -s`, and run again.** The fix
   must turn the result green and the restored original must turn it red for the stated reason.
   On #542: patched, exit 0 and no warning recorded; restored, exit 1 and one warning recorded. A
   third run with the directory present but holding a non-zip file confirmed the fix keeps it
   rather than warning.
4. **Leave `vendor/` as the lock has it** when done, and say so.

## Checking whether the fix shipped

**Do not consult the Releases feed.** Pest's plugins publish tags; a GitHub Release may never
appear. The tag is the release, and Packagist serves it.

```bash
curl -sS https://repo.packagist.org/p2/pestphp/pest-plugin-browser.json \
  | jq -r '[.packages["pestphp/pest-plugin-browser"][].version] | .[0:5][]'
# The tag contains the fix when the fix's merge commit (the PR's `merge_commit_sha`) is an ancestor of
# it: `identical` or `ahead`. A fix cherry-picked onto another line reads `diverged` though it carries
# the change, so read the file there instead.
gh api repos/pestphp/pest-plugin-browser/compare/<fix-merge-sha>...<tag> --jq '.status'
```

A merge is not a release, and a release on another major line is not a release for us. Nor is the newest tag: v5.1.1 shipped a minute after #545 opened, and it does not carry the fix. The fix
must be in a tag that `composer.json`'s constraint resolves.

## Removing the workaround

This repository carries no `cweagans/composer-patches`, so a workaround here is one of:

| Workaround | Removal |
| --- | --- |
| A step in a CI job or a `composer` script | Delete the step, its comment, and any documentation of it; `git grep` for the workaround's content and for the ticket numbers it cites finds nothing it added. |
| A version constraint or a `conflict` entry | Restore the constraint, then `composer update <vendor/package>` targeted, never a bare `composer update`. |

`composer.lock` is gitignored here, so a constraint change relocks only the tree it is made in.
CI resolves afresh on every run, which is exactly how a dependency release reaches every branch at
once. Then run the gate that exercised the defect, with the workaround gone, and **see it green**.

## The request variant: nothing to retire

Not every `upstream` ticket carries a workaround. A feature request, a documentation request, or
a pull request offered with nothing local behind it has no removal ticket. What it usually has left
is one criterion only a maintainer can satisfy: "if upstream answers X, do Y". Left in place, that
criterion holds the ticket open indefinitely.

1. **The filing ticket closes once the item is posted.** Its own work is done then.
2. **"Act on the answer" moves to a follow-up** filed per [`writing-issues`](../writing-issues/SKILL.md),
   with a cross-repo `blocked_by` edge on the upstream issue, read back. On the filing ticket,
   strike the moved criterion rather than ticking it, and close with a comment naming the
   follow-up.

When the upstream item is a pull request, the edge goes on the issue it fixes, as above.

## What the first run hit

- **The failure was silent.** The summary printed `Tests: 254 passed` and the step exited 1, with
  nothing in the summary or the log saying why. `--log-events-text <file>` and a `grep Triggered`
  found it: `Test Runner Triggered PHP Warning (suppressed using operator)`. **`@` does not stop
  PHPUnit recording a warning**, and `failOnWarning` fails the run on it.
- **A green local gate hid it.** The slot's `composer.lock` already pinned v5.1.0, but an earlier
  `composer update <other-package> --no-install` had written the lock without touching `vendor/`,
  which still held v5.0.1. Compare what CI installed (its `composer install` log, or the
  `List Installed Dependencies` step) with `composer show` locally, per
  [`measurement-parity`](../../rules/measurement-parity.md), before believing a local green.
- **`main` was the deciding control.** Running `main` with the same dependencies failed the same
  way, which separated a dependency release from the branch under review. Pinning only the
  suspect package back (`--with=pestphp/pest-plugin-browser:5.0.1`) then named it.
- **Re-running the failed job once** told a flake from a deterministic failure before any local
  digging, with `POST repos/<owner>/<repo>/actions/jobs/<id>/rerun`. Read the attempt's own jobs
  (`…/runs/<id>/attempts/<n>/jobs`), not the run's latest.
- **The obvious workaround did not work.** A committed placeholder in the directory would have made
  `rmdir()` warn on a non-empty directory instead. Read the failing call before choosing.

## The DRY line

This skill owns the **lifecycle** and the **ticket chain** of a dependency workaround. Issue and
pull-request style stay in [`writing-issues`](../writing-issues/SKILL.md) and
[`writing-pull-requests`](../writing-pull-requests/SKILL.md); which API surface to spend stays in
[`github-api-budget`](../../rules/github-api-budget.md); where a defect is reported, and what the
report must pin down, stays in [`filing-defects-across-repos`](../../rules/filing-defects-across-repos.md);
the negative-control discipline stays in [`adversarial-review`](../../rules/adversarial-review.md).
