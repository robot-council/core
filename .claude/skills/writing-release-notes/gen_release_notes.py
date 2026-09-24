#!/usr/bin/env python3
"""
Generate a GitHub Release note body for `robot-council/core` per the
`writing-release-notes` skill: em-dash-title-ready, milestone lead + optional
breaking-change callout, a closed heading vocabulary, and one linked bullet per
change (a `[#N]` PR link, or a backticked short SHA for a direct commit).

It reads first-parent git history for a ref range and resolves each change to a
clean, bucketed bullet -- pulling PR titles live from the GitHub API (via `gh`),
so it depends on nothing but `git`, `gh`, and Python 3.

Because PR titles are held to house style by the `writing-pull-requests`
skill, the live titles are already clean; this tool only strips residual noise
(Conventional-Commit prefixes, `[skip ci]` litter, merge-order hints, redundant
`(#NNN)` refs), drops `&`, and applies the Oxford comma. Acronym casing applies only to a
direct commit's subject, never to a PR title, which is used as written.
Changelog pull requests (`Update CHANGELOG for vX.Y.Z`) are skipped.

Usage:
  gen_release_notes.py [options] <prev-ref> <new-ref>

Example (cut v0.3.0 from the previous tag):
  python3 .claude/skills/writing-release-notes/gen_release_notes.py \\
      v0.2.0 v0.3.0 \\
      --lead "One-sentence milestone theme." \\
      --breaking "rename the \\`foo\\` config key to \\`bar\\` in a published config." \\
      --breaking-item "Rename the \\`foo\\` config key to \\`bar\\` [#12](...)." \\
      > body.md
  gh release create v0.3.0 --title 'v0.3.0 — Theme' --notes-file body.md --verify-tag

Options:
  --repo O/R          GitHub repo (default: derived from this checkout)
  --lead TEXT         one-sentence milestone lead (recommended; else a TODO placeholder)
  --breaking TEXT     impact/action for the "**Breaking change** —" callout paragraph
  --breaking-item T   an itemized "## Breaking changes" bullet (repeatable)
  --exclude N         PR number to omit from the auto-buckets (repeatable)
  --footer TEXT       trailing italic footer line (e.g. a retroactive-tag note)
"""
import argparse, json, re, subprocess, sys

def sh(args):
    return subprocess.run(args, capture_output=True, text=True).stdout


def run(args):
    """Run a command, returning (returncode, stdout-stripped). Errors are the caller's to read."""
    p = subprocess.run(args, capture_output=True, text=True)
    return p.returncode, p.stdout.strip()


REMOTE = re.compile(
    r"""^(?:https?://[^/]+/            # https://github.com/
         |(?:ssh://)?[^@]+@[^:/]+[:/]) # git@github.com: or ssh://git@host/
        (?P<owner>[^/]+)/(?P<repo>[^/]+?)(?:\.git)?/?$""",
    re.X,
)


def parse_remote(url):
    """`owner/repo` from a git remote URL, or None when it is not one.

    Kept pure and separate from the commands that produce a URL so the parsing is testable
    without a checkout, a remote, or a network.
    """
    m = REMOTE.match((url or "").strip())
    return f"{m.group('owner')}/{m.group('repo')}" if m else None


def derive_repo(runner=run):
    """The repository this checkout belongs to, or None.

    **Derived rather than defaulted, because a default is right in one repository and silently
    wrong in every other one** (cli#57). This script is copied between repositories, and a stale
    default emits links that RESOLVE -- to unrelated pull requests in the repository it came
    from -- which is what makes the failure hard to notice.

    `gh` first, because it knows the repository a checkout is configured against even when the
    remote is named something other than `origin`; the remote is the fallback for a checkout with
    no `gh` available.
    """
    code, out = runner(["gh", "repo", "view", "--json", "nameWithOwner", "-q", ".nameWithOwner"])

    if code == 0 and "/" in out:
        return out

    code, out = runner(["git", "remote", "get-url", "origin"])

    return parse_remote(out) if code == 0 else None

# ---- prose helpers -------------------------------------------------------

def noamp(s):
    """Replace '&' with 'and'; add an Oxford comma when it closes a comma-list."""
    def repl(m):
        return ", and " if "," in s[:m.start()] else " and "
    return re.sub(r"\s*&\s*", repl, s)


SKIPCI = re.compile(r"\s*\[\s*(?:skip[\s-]*ci|ci[\s-]*skip|no[\s-]*ci)\s*\]", re.I)


def scrub(s):
    """Strip build-directive litter like '[skip ci]' and collapse whitespace."""
    return re.sub(r"\s{2,}", " ", SKIPCI.sub("", s)).strip()


_ACRO = {"composer": "Composer", "json-ld": "JSON-LD", "cli": "CLI",
         "php": "PHP", "css": "CSS", "scss": "SCSS", "ci": "CI", "api": "API", "pcov": "PCOV",
         "mcp": "MCP", "laravel": "Laravel", "larastan": "Larastan",
         "phpstan": "PHPStan", "rector": "Rector", "vite": "Vite", "ssr": "SSR", "ssg": "SSG",
         "seo": "SEO"}
# A key counts only as a standalone word. One touching `.`, `/`, `-`, or `_` is part of a
# name -- `rector.php`, `composer.json`, `src/Rector`, `laravel-ray` -- and a name keeps its
# spelling. So does anything inside a backticked code span, which fix_acro() skips.
_ACRO_RE = re.compile(
    r"(?<![\w./-])(" + "|".join(re.escape(k) for k in _ACRO) + r")(?![\w/-]|\.\w)", re.I)


def fix_acro(s):
    # Even-indexed segments are outside backticks; odd-indexed ones are code spans.
    segments = s.split("`")
    for i in range(0, len(segments), 2):
        segments[i] = _ACRO_RE.sub(lambda m: _ACRO.get(m.group(0).lower(), m.group(0)), segments[i])
    return "`".join(segments)


def clean_title(t, recase=True):
    """Normalize a title into a house-style bullet title.

    `recase` is for a direct commit's subject only. A PR title is already held to house style
    by `writing-pull-requests`, and the skill requires it as written, so acronym casing would
    only damage it: a lowercase `php` preset name becomes `PHP`.
    """
    t = re.sub(r"^(feat|fix|perf|chore|docs|build|ci|test|style|refactor)(\([^)]*\))?:\s*", "", t)
    t = re.sub(r"\s*\((?:merge (?:after|before) #\d+)\)", "", t, flags=re.I)
    t = re.sub(r"\s*\(#\d+(?:\s*[,&–-]\s*#?\d+)*\)", "", t)
    t = fix_acro(t.strip()) if recase else t.strip()
    return (t[0].upper() + t[1:]) if t and t[0].islower() else t


# ---- routing (which bucket) ---------------------------------------------

# Terms that mean a security problem wherever they appear. None of these is ordinary
# vocabulary for a command line, so a title carrying one is making a security claim.
SEC = re.compile(
    r"\b(xss|ssrf|csp|hsts|xxe|redos|egress|nonce|"
    r"impersonat\w*|sanitiz\w*|clickjack\w*)\b", re.I)

# **`secret`, `credential`, `token` and `password` are this repository's SUBJECT, not a
# signal.** This file is shared with `robot-council/cli`, whose product IS a credential store, so
# these appear in the title of ordinary feature work there. Measured for `robot-council/core#264`:
# they misfire here too the moment this package grows a credential-shaped feature, and the
# two-tier split costs nothing in the meantime.
#
# Measured on `robot-council/cli#78` across all 35 merged subjects on `main`, routed with no
# paths and no labels: `credential` alone fired 8 times and `secret` once, and the nine
# included `Bridge MCP over stdio, holding the credential outside the agent (#10)` -- the
# headline feature of `v0.1.0` -- plus enrollment, the Windows credential store, and the
# per-harness keying. None of the unambiguous terms above fired even once. So this is the
# whole of the defect, and splitting the tiers is the whole of the fix.
#
# A **Security** heading claims something was wrong and is now fixed. Nine such claims in a
# release that had at most one real one does not merely mislabel bullets: it buries the
# genuine fix among eight that are not, which is the direction that costs a reader something.
SEC_AMBIGUOUS = re.compile(r"\b(secrets?|credentials?|tokens?|passwords?)\b", re.I)

# What turns one of those into a security claim: a word about the value ESCAPING, rather
# than about storing, choosing or reading it. Deliberately not `argv`, `transcript` or
# `plaintext`-adjacent phrasing about where a credential is *kept*, because keeping one out
# of argv is what several of this repository's features are FOR -- `Store the credential in
# Windows Credential Manager, without putting it in argv (#35)` is a feature, not a fix.
SEC_EXPOSURE = re.compile(
    r"\b(leak\w*|expos\w*|disclos\w*|exfiltrat\w*|world-readable|hard-?coded)\b"
    r"|\bin the clear\b|\bplain ?text\b", re.I)

# A Conventional-Commit prefix is STRIPPED, never routed on. The title conventions in
# `writing-pull-requests` forbid these outright, so a prefix here is legacy litter -- and
# routing on it would mean no title the conventions produce could ever match.
CC_PREFIX = re.compile(
    r"^(?:feat|fix|docs|build|ci|test|chore|style|refactor|perf|revert)(?:\([^)]*\))?!?:\s*", re.I)

# Labels that decide a category on their own, read from the issue a pull request closes.
# `development` is deliberately absent: it is the default for all code work and spans every
# bucket, so it discriminates nothing.
LABEL_MAINT = {"build", "documentation"}
LABEL_SEC = {"security"}

# A change confined to these is tooling or prose whatever its title says. Top-level
# dotfiles (`.editorconfig`, `.gitattributes`, `.gitignore`) count too; see _is_maint.
MAINT_PREFIXES = (".github/", ".claude/", "tests/", "workbench/")
MAINT_FILES = {"composer.json", "phpstan.neon.dist", "phpstan-baseline.neon", "phpunit.xml.dist", "rector.php",
               "CHANGELOG.md", "CLAUDE.md", "README.md", "LICENSE.md"}

# Published, consumer-visible surfaces of the package: what an application installing it
# receives through config publishing, migrations, factories, views, and routes. A change
# touching one is a product change, not maintenance. `src/` is deliberately absent: it
# holds internals as well as the public API, so it flows through the fix-verb and
# test-dominance rules below instead.
USER_FACING_PREFIXES = ("config/", "database/", "resources/", "routes/")

# Where each repository keeps the code it ships. **Not a user-facing surface** -- these hold
# internals as well as public API, so they must not route to `new` on their own -- but a change
# touching one is not maintenance either, which is what rule 6 below was deciding by accident.
SOURCE_PREFIXES = ("src/", "app/")

FIX_VERBS = re.compile(r"^(Fix|Resolve|Repair|Prevent|Guard|Restore|Correct|Harden|Stop|Avoid)\b")
MAINT_VERBS = re.compile(
    r"^(Migrate|Document|Adopt|Refactor|Refresh|Rework|Bump|Reformat|Consolidate|Deduplicate)\b")
MAINT_WORDS = re.compile(
    r"\btests?\b|paratest|test hygiene|test isolation|"
    r"ci parity|\bcoverage\b|mutation|\bmutant\b|pcov|coverage driver|\bskill\b|worktree|"
    # Dependency work, by the thing it is about rather than by its diff shape. Before #264 a
    # hand-written `Raise dependency floors ...` reached Maintenance only because its test diff
    # happened to be the larger one, which is the accident that also hid nine features. Dependabot's
    # own titles route on `Bump` in MAINT_VERBS and never needed this.
    r"\bdependenc(?:y|ies)\b", re.I)


def strip_cc_prefix(s):
    """Remove a legacy Conventional-Commit prefix so it cannot affect routing."""
    return CC_PREFIX.sub("", s.strip(), count=1)


def _is_maint(path):
    return (path.startswith(MAINT_PREFIXES) or path in MAINT_FILES
            or ("/" not in path and path.startswith(".")))


def _all_maint(paths):
    return bool(paths) and all(_is_maint(p) for p in paths)


def bucket(subject, title, labels=(), paths=(), test_lines=0, other_lines=0, issue_types=()):
    """Route a change to one of: sec | maint | fix | new.

    A cascade, and the ORDER carries the correctness. A maintenance change whose title
    opens with a fix-verb (`Correct the ...`, `Stop two ...`) is routed correctly only
    because its label is consulted first.
    """
    labels = {l.lower() for l in labels}
    t = strip_cc_prefix(title)
    combo = strip_cc_prefix(subject) + " || " + t
    low = combo.lower()

    # 1. Security, by label or vocabulary.
    if labels & LABEL_SEC:
        return "sec"
    if (SEC.search(combo) or "ssl verif" in low or "security header" in low or "x-powered-by" in low
            or "password protection" in low or "internal-network" in low or "internal network" in low
            or (("escap" in low) and re.search(r"script|json-ld|xss|html", low))
            or (SEC_AMBIGUOUS.search(combo) and SEC_EXPOSURE.search(combo))):
        return "sec"

    # 2. A category-bearing label on the linked issue. Pull requests usually carry no labels
    #    of their own, so these come from the issue the PR closes.
    if labels & LABEL_MAINT:
        return "maint"

    # 3. A consumer-visible surface makes it a product change regardless of how test-heavy it is.
    if any(p.startswith(USER_FACING_PREFIXES) for p in paths):
        return "new"

    # 4. A fix, by its opening verb.
    if FIX_VERBS.match(t):
        return "fix"

    # 5. Confined to tooling, prose, or the dependency manifest.
    if _all_maint(paths):
        return "maint"

    # 6. Test-dominant diff with no source edit: the change is coverage, not product.
    #
    #    **The source exclusion is what makes this rule mean anything** (#264). Rule 5 already
    #    routes a diff confined to `.claude/`, `tests/`, `README.md` or the manifests, so by the
    #    time control arrives here every remaining change has touched `src/` or `app/` -- and
    #    comparing its line counts then decides a product change on the size of its test suite.
    #    Measured over `robot-council/cli`'s `v0.2.0..main`: nine user-visible changes landed in
    #    Maintenance this way, including both the release was named for. A threshold on the
    #    non-test lines cannot fix it either, because product changes there run as low as 10 added
    #    lines while genuine maintenance reaches 82 -- the two ranges overlap completely, and only
    #    the paths separate them.
    #
    #    `robot-council/cli` has none of rule 3's directories, so before this every one of its pull
    #    requests fell through to here.
    if test_lines > other_lines and not any(p.startswith(SOURCE_PREFIXES) for p in paths):
        return "maint"

    # 7. A maintenance verb or noun in the title, whatever the diff looks like (#264).
    if MAINT_VERBS.match(t) or "update dependencies" in t.lower() or MAINT_WORDS.search(t):
        return "maint"

    # 8. The type a human set on the issue this closes, as a LAST-RESORT tiebreaker.
    #    `Bug` is a fix; `Feature` is new.
    #
    #    **Both bounds on this position are load-bearing, and the ticket pinned only the upper
    #    one** (#268).
    #
    #    *Upper bound -- it must sit below every rule above it, 1 through 5.* It reads as belonging beside the other
    #    human-set signal, up with the labels, and there it would be wrong: `robot-council/cli#154`
    #    is typed `Bug` and is confined to `.claude/`, so rule 5 routes it to Maintenance, which is
    #    right, because a change to a skill file is maintenance whatever the ticket it closes is
    #    typed. That position is what makes `Bug` agree 2 of 2 rather than 1 of 2.
    #
    #    *Lower bound -- it must also sit below rules 6 and 7, which is NOT what a first reading of
    #    #268 gives you.* Placed directly after rule 5 it silently overrides two earlier decisions.
    #    It would take a test-only diff that changed no behavior and call it a fix; and it would
    #    take `Raise dependency floors to their latest stable releases`, which #264 deliberately
    #    routes to Maintenance by its title, and call it a fix the moment somebody typed that
    #    ticket `Bug`. The principle the rest of this cascade already follows is that an explicit
    #    signal beats an inferred one -- a path that says maintenance, a title that says
    #    maintenance -- and the issue type is the coarsest signal here, not the finest. So it
    #    decides only what nothing else could.
    #
    #    **What that costs, stated rather than discovered later.** Rule 7 is `MAINT_VERBS` OR
    #    `MAINT_WORDS`, and the second matches `test`, `coverage`, `skill`, `worktree` or
    #    `dependency` ANYWHERE in a title, which is not the same as a title that says maintenance.
    #    So a `Bug`-typed fix phrased as an outcome and carrying one of those words incidentally --
    #    `Register the skill loader even when a host has cached its routes` -- stays Maintenance.
    #    That is a narrowing of this rule's reach, never a regression: sitting immediately above
    #    the terminal default, this rule can only turn a would-be `new` into `fix`, so no input
    #    is worse off than before it existed. Widening `MAINT_WORDS`'s precision is its own
    #    question, not this one's.
    #
    #    **`Task` is deliberately not encoded.** #268 records it agreeing with `fix` six times out
    #    of six on the range the decision was measured against; that is an editorial reading of
    #    those six titles rather than a cascade output, and it is not re-derivable by running this
    #    file, so it is attributed rather than asserted. The reason the rule is refused does not
    #    rest on it: the correlation is an artifact of how those tickets happened to be typed, `writing-issues` assigns `Task` to a research spike, a decision fork,
    #    a follow-up cleanup or an epic, never to a bug. A rule built on it breaks the first time
    #    somebody types a ticket correctly, and it breaks toward calling a cleanup a fix.
    #
    #    **A change closing issues of differing types is a FIX.** Nothing forces one answer, so the
    #    tie is broken deliberately: a pull request that closes a bug and a feature has repaired
    #    something, and a reader scanning What's fixed for a regression they actually hit is worse
    #    served by its absence than a reader of What's new is by its absence there. Under-claiming
    #    novelty is the cheaper error.
    if 'Bug' in issue_types:
        return "fix"

    #    **`Feature` here is an EQUIVALENT MUTANT, and that is stated rather than hidden.** Nothing
    #    follows it but `return "new"`, so deleting these two lines changes no input's fate and no
    #    test can tell the two versions apart -- the same situation `CLAUDE.md` records for
    #    `Locks::acquire()`'s bytes-versus-characters fix. It is kept, not deleted, because it
    #    states the half of #268's decision that the default only happens to agree with: a rule
    #    added after this one would otherwise silently take every `Feature` with it. A test asserts
    #    the behavior; no test can assert the branch, and claiming otherwise would be a false green.
    if 'Feature' in issue_types:
        return "new"

    return "new"


GLOBAL_SKIP = [
    re.compile(r"^Merge branch ", re.I), re.compile(r"^Merge remote-tracking", re.I),
    # A changelog pull request (`Update CHANGELOG for vX.Y.Z`) records a release rather than
    # belonging to one. Matched against the raw subject and the resolved PR title alike.
    re.compile(r"^Update CHANGELOG\b"),
]

_pr_cache = {}
_PR_BATCH = 50


def prime_pr_cache(nums, repo):
    """Fetch every pull request's title and its CLOSING ISSUE's labels in ONE query.

    Batched rather than one call per pull request for two reasons: it replaces N round
    trips with one, and the labels are usually not on the pull request at all. Measured
    in `UAMS-Web/uams-statamic` over a 2026-09-14 range, 24 of 24 pull requests carried no
    labels of their own. So the category signal has to come from the issue each pull
    request closes, which `closingIssuesReferences` answers directly rather than by
    pattern-matching a body for `Closes #N`.
    """
    nums = [n for n in dict.fromkeys(nums) if n not in _pr_cache]
    if not nums:
        return
    owner, name = repo.split("/", 1)

    # Chunked, and the partial-data handling below is the load-bearing half. A subject's
    # `#N` is not always a pull request -- an issue reference resolves to nothing -- and
    # ONE such alias makes the whole query exit non-zero. GraphQL still returns `data`
    # with that alias null and the rest populated, so the response is parsed regardless
    # of the exit code. Gating on `returncode == 0` would discard every good title over
    # one bad reference and emit a full set of bullets with no links, which looks like a
    # complete release note.
    for start in range(0, len(nums), _PR_BATCH):
        batch = nums[start:start + _PR_BATCH]
        fields = " ".join(
            f'p{n}: pullRequest(number:{n}){{title '
            f'closingIssuesReferences(first:20){{totalCount nodes{{issueType{{name}} '
            f'labels(first:20){{nodes{{name}}}}}}}}}}'
            for n in batch)
        q = f'query {{repository(owner:"{owner}",name:"{name}"){{{fields}}}}}'
        r = subprocess.run(["gh", "api", "graphql", "-f", f"query={q}"],
                           capture_output=True, text=True)
        try:
            payload = json.loads(r.stdout)
            data = (payload.get("data") or {}).get("repository") or {}
        except (json.JSONDecodeError, AttributeError):
            payload, data = {}, {}

        # **A field error costs one alias; a VALIDATION error costs the whole batch**, and the two
        # are told apart only here. `issueType` is a newer schema field than everything else this
        # query asks for, so an endpoint whose schema predates it rejects the entire document:
        # `data` comes back null, every alias in the batch caches as a miss, and the run emits a
        # full set of bullets with no `[#N]` links -- the exact outcome the comment above says the
        # partial-data handling exists to prevent, arriving through a door that handling does not
        # cover. Reported on stderr rather than raised, because a release note with plain subjects
        # still beats no release note; what must not happen is that it looks complete.
        # **Gated on `not data`, NOT on an `errors` array being present**, because the shapes that
        # carry no `errors` are the ones most likely to happen: `gh` exiting non-zero with empty
        # stdout (no credential, no network, an HTTP 403 from a proxy), an HTML error page from a
        # gateway, and `{"data":{"repository":null}}`. Every one of those reaches here with the
        # whole batch uncached, and an earlier draft of this guard stayed silent for all three --
        # it asked whether GraphQL had complained rather than whether anything had come back.
        if not data:
            errs = payload.get("errors") or []
            why = (f"{errs[0].get('type') or 'error'}: {errs[0].get('message', '')[:160]}"
                   if errs else f"no data, gh exit {r.returncode}, {len(r.stdout)} bytes of stdout")
            print(f"warning: the pull-request query returned no data for #{batch[0]}-#{batch[-1]} "
                  f"({why}). Those bullets will fall back to commit subjects and carry no links.",
                  file=sys.stderr)
        for n in batch:
            node = data.get(f"p{n}")
            if not node:
                # Not a pull request, or unreachable: resolve() falls back to the subject.
                _pr_cache[n] = (None, (), ())
                continue
            cir = node.get("closingIssuesReferences") or {}
            issues = cir.get("nodes") or []
            # The page is 20 and nothing orders it, so a pull request closing more than that would
            # have its types decided by an ordering nobody pinned -- and since #268 the type can
            # decide the bucket, where before it could only lose a label. Reported, never guessed.
            if (cir.get("totalCount") or 0) > len(issues):
                print(f"warning: #{n} closes {cir['totalCount']} issues; only {len(issues)} were "
                      f"read, so its labels and type may be incomplete.", file=sys.stderr)
            labels = tuple(
                l["name"]
                for iss in issues
                for l in (iss.get("labels") or {}).get("nodes", []))

            # `issueType` is null on an issue nobody typed, which is most of them: on the range
            # #268 measured, 14 of 25 PULL REQUESTS end up with an empty tuple here (the unit is
            # pull requests, not issues -- those 25 close 19 issues, of which 11 are typed).
            # Dropped rather than carried as None, so `bucket()` sees an empty tuple and falls
            # through exactly as it did before this existed.
            types = tuple(
                (iss.get("issueType") or {}).get("name")
                for iss in issues
                if (iss.get("issueType") or {}).get("name"))

            _pr_cache[n] = (node.get("title") or None, labels, types)


def pr_title(num, repo):
    if num not in _pr_cache:
        prime_pr_cache([num], repo)
    return _pr_cache.get(num, (None, (), ()))[0]


def pr_labels(num):
    return _pr_cache.get(num, (None, (), ()))[1]


def pr_issue_types(num):
    """The GitHub issue types of the issues this pull request closes, with the untyped dropped."""
    return _pr_cache.get(num, (None, (), ()))[2]


def diff_signals(sha):
    """(paths, test_lines, other_lines) for a commit -- from git, costing no API call."""
    out = sh(["git", "show", "--numstat", "--pretty=format:", sha])
    paths, test_lines, other_lines = [], 0, 0
    for line in out.splitlines():
        parts = line.split("\t")
        if len(parts) != 3:
            continue
        added, _removed, path = parts
        paths.append(path)
        n = int(added) if added.isdigit() else 0
        if path.startswith("tests/"):
            test_lines += n
        else:
            other_lines += n
    return paths, test_lines, other_lines


def resolve(subject, repo):
    """Return (pr_number_or_None, title, link)."""
    m = re.match(r"Merge pull request #(\d+)", subject)
    nums = re.findall(r"#(\d+)", subject)
    pr = int(m.group(1)) if m else (int(nums[-1]) if nums else None)
    if pr:
        t = pr_title(pr, repo)
        if t:
            return pr, t, f"[#{pr}](https://github.com/{repo}/pull/{pr})"
    t = re.sub(r"\s*\(#\d+[^)]*\)\s*$", "", subject).strip()
    t = re.sub(r"\s*\(#\d+\)\s*$", "", t).strip()
    return None, t, ""


def main():
    # Guarantee UTF-8 on stdout before anything writes to it, including argparse's
    # own --help. The generator interpolates contributor-supplied text (--lead,
    # --breaking, --footer) and live PR titles, so a character absent from the
    # locale's encoding would otherwise raise UnicodeEncodeError -- after every
    # section is assembled, into a redirect, on the release path.
    #
    # The getattr guard is load-bearing: a test harness that swaps sys.stdout for
    # io.StringIO has no reconfigure and needs none, since it holds str and never
    # encodes.
    reconfigure = getattr(sys.stdout, "reconfigure", None)
    if reconfigure is not None:
        reconfigure(encoding="utf-8", errors="strict")

    ap = argparse.ArgumentParser(description="Generate a release-note body (writing-release-notes skill).")
    ap.add_argument("prev", help="previous ref/tag (use '-' for repo root)")
    ap.add_argument("new", help="new ref/tag being released")
    ap.add_argument("--repo", default=None)
    ap.add_argument("--lead", default=None)
    ap.add_argument("--breaking", default=None, help="impact/action for the breaking-change callout")
    ap.add_argument("--breaking-item", action="append", default=[], help="a '## Breaking changes' bullet")
    ap.add_argument("--exclude", action="append", default=[], type=int,
                    help="PR number to omit from the auto-buckets (already covered elsewhere; repeatable)")
    ap.add_argument("--footer", default=None)
    a = ap.parse_args()

    # **Derived, and a failure to derive is fatal rather than a fallback** (cli#57). Falling back
    # to a repository that happens to exist produces links that resolve, to unrelated pull requests
    # somewhere else, which is the reassuring kind of wrong: the output is well-formed and
    # plausible, and nothing about it invites a second look.
    if a.repo is None:
        a.repo = derive_repo()

    if not a.repo:
        sys.exit(
            "Could not tell which GitHub repository this checkout belongs to: `gh repo view` "
            "answered nothing usable and `origin` is not a GitHub remote. Pass --repo OWNER/NAME."
        )


    # PRs itemized in a breaking bullet (or explicitly excluded) must not also auto-list in a bucket.
    excl = set(a.exclude) | {int(n) for item in a.breaking_item for n in re.findall(r"#(\d+)", item)}

    rng = a.new if a.prev in ("-", "", "root") else f"{a.prev}..{a.new}"
    log = sh(["git", "log", rng, "--first-parent", "--format=%H%x1f%s"]).splitlines()

    # One batched query for every pull request in the range, before any bullet is built.
    prime_pr_cache(
        [int(n) for line in log if "\x1f" in line
         for n in re.findall(r"#(\d+)", line.split("\x1f", 1)[1])],
        a.repo)

    buckets = {"new": [], "fix": [], "sec": [], "maint": []}
    for line in log:
        if "\x1f" not in line:
            continue
        sha, s = line.split("\x1f", 1)
        s = s.strip()
        if not s or any(r.search(s) for r in GLOBAL_SKIP):
            continue
        pr, title, link = resolve(s, a.repo)
        if not title or (pr is not None and pr in excl) or any(r.search(title) for r in GLOBAL_SKIP):
            continue
        if pr is None:  # direct commit -> link the short SHA
            link = f"[`{sha[:7]}`](https://github.com/{a.repo}/commit/{sha})"
        disp = scrub(clean_title(title, recase=pr is None))
        if not disp:
            continue
        paths, test_lines, other_lines = diff_signals(sha)
        b = bucket(s, title, pr_labels(pr) if pr else (), paths, test_lines, other_lines,
                   pr_issue_types(pr) if pr else ())
        bullet = f"- {noamp(disp)} {link}".rstrip()
        if bullet not in buckets[b]:
            buckets[b].append(bullet)

    parts = [noamp(a.lead) if a.lead else "TODO: one-sentence milestone lead."]
    if a.breaking:
        parts += ["", noamp(f"**Breaking change** — {a.breaking}")]

    def section(title, items):
        if items:
            parts.extend(["", f"## {title}", *items])

    if a.breaking_item:
        parts += ["", "## Breaking changes", *[f"- {noamp(b)}" for b in a.breaking_item]]
    section("What's new", buckets["new"])
    section("What's fixed", buckets["fix"])
    section("Security", buckets["sec"])
    section("Maintenance and tooling", buckets["maint"])
    if a.footer:
        parts += ["", f"_{a.footer}_"]

    sys.stdout.write("\n".join(parts).rstrip() + "\n")


if __name__ == "__main__":
    main()
