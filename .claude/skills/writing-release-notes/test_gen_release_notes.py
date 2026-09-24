#!/usr/bin/env python3
"""
Offline tests for gen_release_notes.py: title cleanup and bucket routing. Neither calls
`git` or `gh`, so these run without a network or a checkout history.

  python3 -m unittest discover -s .claude/skills/writing-release-notes
"""
import os
import sys
import contextlib
import io
import json
import types
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import gen_release_notes as g  # noqa: E402


class AcronymCasing(unittest.TestCase):
    def test_recases_a_standalone_word(self):
        self.assertEqual(g.fix_acro("raise phpstan to level max"), "raise PHPStan to level max")
        self.assertEqual(g.fix_acro("drop php 8.3 support"), "drop PHP 8.3 support")
        self.assertEqual(g.fix_acro("add a ci check."), "add a CI check.")
        self.assertEqual(g.fix_acro("render json-ld"), "render JSON-LD")

    def test_leaves_dotted_names_and_paths_alone(self):
        self.assertEqual(g.fix_acro("run rector against rector.php"), "run Rector against rector.php")
        self.assertEqual(g.fix_acro("bump composer.json floors"), "bump composer.json floors")
        self.assertEqual(g.fix_acro("move src/rector/php files"), "move src/rector/php files")
        self.assertEqual(g.fix_acro("drop laravel-ray"), "drop laravel-ray")
        self.assertEqual(g.fix_acro("rename ci_passed"), "rename ci_passed")

    def test_leaves_code_spans_alone(self):
        self.assertEqual(g.fix_acro("apply the `php` preset in php"), "apply the `php` preset in PHP")


class TitleCleanup(unittest.TestCase):
    def test_pr_title_is_used_as_written(self):
        for title in (
            "Run PHPStan and Rector against tests and rector.php",
            "Adopt Pest's php, security, and strict arch presets and its Rector rules",
        ):
            self.assertEqual(g.clean_title(title, recase=False), title)

    def test_pr_title_still_loses_a_conventional_commit_prefix(self):
        self.assertEqual(g.clean_title("docs: correct the php preset name", recase=False),
                         "Correct the php preset name")

    def test_commit_subject_is_recased(self):
        self.assertEqual(g.clean_title("build: bump phpstan in composer.json"),
                         "Bump PHPStan in composer.json")


class Routing(unittest.TestCase):
    TITLE = "Raise dependency floors to their latest stable releases"

    def test_claude_md_counts_as_tooling(self):
        self.assertEqual(g.bucket("s", self.TITLE, paths=["CLAUDE.md", "composer.json"]), "maint")
        self.assertEqual(g.bucket("s", self.TITLE, paths=["CLAUDE.md"]), "maint")

    def test_a_source_change_routes_by_title_not_by_how_many_tests_it_ships(self):
        """The correction #264 made, and the assertion it had to invert.

        The version before it required a test-dominant `src/` change to be Maintenance. That is the
        mechanism that hid nine user-visible changes in `robot-council/cli`'s v0.3.0 range,
        including both the release was named for, because rule 5 already routes genuine maintenance
        by path -- so by the time the test-dominance rule is reached, every change left has touched
        source.
        """
        paths = ["CLAUDE.md", "src/RobotCouncilServiceProvider.php"]
        neutral = "Report the fleet's roles on the dashboard"

        # A title claiming nothing in particular, touching source: a product change.
        self.assertEqual(g.bucket("s", neutral, paths=paths, other_lines=10), "new")
        self.assertEqual(g.bucket("s", "Fix the provider name", paths=paths, other_lines=10), "fix")

        # **And still a product change when the tests outweigh it.** Taken from `robot-council/cli#149`
        # with its real line counts; before #264 this returned "maint".
        self.assertEqual(
            g.bucket("s", "Renew when this session's role changes",
                     paths=["app/Support/Bridge.php", "tests/Feature/SessionRoleChangeTest.php"],
                     test_lines=648, other_lines=160),
            "new")

        # Dependency work is Maintenance because it SAYS so, whatever its diff shape. Before #264
        # this title reached Maintenance only when its test diff happened to be the larger one.
        floors = "Raise dependency floors to their latest stable releases"

        self.assertEqual(g.bucket("s", floors, paths=paths, other_lines=10), "maint")
        self.assertEqual(g.bucket("s", floors, paths=paths, test_lines=20, other_lines=10), "maint")

    def test_a_domain_noun_does_not_assert_a_vulnerability(self):
        """`credential` and `secret` are what this project is about, not markers of a fix to it.

        Both titles are real, from `robot-council/cli#109` and `#110`. Each routed to Security on
        the word `credential` alone, which would have told readers of a release that they had been
        exposed.
        """
        source = ["app/Support/Credentials/KeychainStore.php", "tests/Feature/KeychainStoreTest.php"]

        self.assertEqual(
            g.bucket("s", "Answer a multi-key credential read in one `powershell.exe` invocation",
                     paths=source, test_lines=301, other_lines=206),
            "new")
        self.assertEqual(
            g.bucket("s", "Read the legacy credential once per refusal, not twice",
                     paths=source, test_lines=108, other_lines=32),
            "new")
        self.assertEqual(g.bucket("s", "Store the secret where the keychain wants it", paths=source), "new")

        # **But the domain noun still routes when a second word says the value ESCAPED.** Removing
        # the nouns outright was the first attempt and was worse: it would have missed a genuine
        # credential-disclosure fix entirely. The two tiers come from `robot-council/cli#78`, which
        # measured them across 35 subjects.
        for claim in ("Stop a credential leaking into the transcript",
                      "Prevent a token being exposed in argv",
                      "Fix a password stored in the clear"):
            self.assertEqual(g.bucket("s", claim, paths=source), "sec", claim)

        # The label a human set still routes, which is how a genuine credential fix gets there --
        # `robot-council/cli#105` reached Security exactly this way.
        self.assertEqual(g.bucket("s", "Read the legacy credential once per refusal, not twice",
                                  labels=["security"], paths=source), "sec")

        # And the vocabulary that is not domain-specific still routes on its own.
        for word in ("Prevent an XSS in the enrollment page", "Stop an SSRF in the callback",
                     "Add a CSP nonce to the layout"):
            self.assertEqual(g.bucket("s", word, paths=source), "sec", word)

    def test_published_surface_still_wins(self):
        self.assertEqual(g.bucket("s", self.TITLE, paths=["CLAUDE.md", "config/robot-council.php"]), "new")


class IssueType(unittest.TestCase):
    """The type a human set on the linked issue, read as a LAST-RESORT tiebreaker (#268).

    **Two kinds of fixture, and which is which is stated rather than left to be discovered.** The
    ones named by a pull-request number -- #235, #224, #243, #259 -- carry that pull request's real
    path list and added-line counts, read from its own files through the API on 2026-09-24;
    `diff_signals()` counts **added** lines only, so these are additions, never
    additions-plus-deletions. The rest are deliberately synthetic (`src/X.php`, `"Anything at
    all"`), because they exist to isolate ONE rule and a real diff drags in whichever other rules
    its paths happen to trip.

    **What no test in this class can do**, stated here rather than implied away: the `Feature`
    branch is an equivalent mutant. Nothing follows it but the cascade's `return "new"`, so deleting
    it changes no input's fate. The tests below pin `Feature`'s BEHAVIOR; none of them can pin the
    branch, and a test claiming to would be a false green.
    """

    def test_bug_routes_to_fixed(self):
        """`#235` and `#224`, the whole measured reach of this rule on this repository.

        Both open with an outcome verb -- `Send`, `Register` -- so rule 4's verb list cannot see
        them; both edit `src/`, so #264's source exclusion disarms rule 6; and neither title carries
        a maintenance word for rule 7. They reach rule 8 and nothing else could have classified
        them.
        """
        self.assertEqual(
            g.bucket("s", "Send the package prefix root to the dashboard",
                     paths=["README.md", "src/Http/Controllers/PrefixRootController.php",
                            "src/RobotCouncilServiceProvider.php", "tests/WebPrefixTest.php"],
                     test_lines=173, other_lines=99, issue_types=("Bug",)),
            "fix")
        self.assertEqual(
            g.bucket("s", "Register Livewire components even when a host has cached its routes",
                     paths=["src/RobotCouncilServiceProvider.php",
                            "tests/CachedRoutesRegistrationTest.php",
                            "tests/RouteRegistrationTest.php", "tests/TestCase.php"],
                     test_lines=157, other_lines=39, issue_types=("Bug",)),
            "fix")

    def test_feature_routes_to_new(self):
        """Two fixtures, because the real one never reaches the rule being named.

        **`#243` is decided by rule 3**, five rules earlier: it touches `config/` and `routes/`, so
        a published surface makes it `new` whatever its type is. It is kept because it is the real
        change, and it proves the answer; it proves nothing about the branch, and it answers `new`
        for `Bug`, `Task` and a nonsense type alike. The synthetic case below is the one that
        actually reaches rule 8.

        Even there the branch cannot be pinned -- see the class docstring. What both assert is the
        behavior, which is what #268 specified.
        """
        self.assertEqual(
            g.bucket("s", "Let a session request a role, and an administrator decide it",
                     paths=["config/robot-council.php", "src/Access/Role.php", "routes/api.php"],
                     test_lines=824, other_lines=797, issue_types=("Feature",)),
            "new")
        # Reaches rule 8: no published surface, no fix verb, not all-maintenance paths, source
        # edited so the test-dominance rule is disarmed, and no maintenance word in the title.
        self.assertEqual(
            g.bucket("s", "Let a developer choose which panels the dashboard mounts",
                     paths=["src/Livewire/Dashboard.php"], test_lines=40, other_lines=120,
                     issue_types=("Feature",)),
            "new")

    # ---- the UPPER bound: rules 1 to 5 still win -------------------------------------------

    def test_a_maintenance_label_still_wins(self):
        """The real `#259`, which is not the fixture people assume it is.

        It closes `#258`, typed `Bug` and labeled `documentation` -- so **rule 2** holds it, three
        rules before the path rule does. Asserting it through rule 5 would describe a change that
        does not exist.
        """
        self.assertEqual(
            g.bucket("s", "Refuse the comma form for multiple closes, and make the scan refuse both directions",
                     labels=["documentation", "afk"],
                     paths=[".claude/skills/writing-pull-requests/SKILL.md"],
                     test_lines=0, other_lines=45, issue_types=("Bug",)),
            "maint")

    def test_a_skill_only_path_still_wins_without_any_label(self):
        """Rule 5 on its own, with the labels removed so it is the rule actually under test.

        A change to a skill file is maintenance whatever its ticket is typed. This is the assertion
        that fails if the type rule is moved above rule 5.
        """
        for path in (".claude/skills/writing-pull-requests/SKILL.md", "composer.json",
                     "README.md", "CLAUDE.md", "tests/FooTest.php"):
            self.assertEqual(g.bucket("s", "Anything at all", paths=[path], issue_types=("Bug",)),
                             "maint", path)

    def test_security_and_the_published_surface_still_win(self):
        self.assertEqual(
            g.bucket("s", "Prevent an XSS in the enrollment page",
                     paths=["src/X.php"], issue_types=("Feature",)),
            "sec")
        self.assertEqual(
            g.bucket("s", "Anything at all", labels=["security"],
                     paths=["src/X.php"], issue_types=("Feature",)),
            "sec")
        self.assertEqual(
            g.bucket("s", "Anything at all", paths=["config/robot-council.php"],
                     issue_types=("Bug",)),
            "new")

    # ---- the LOWER bound: rules 6 and 7 win too, which #268 did not pin ---------------------

    def test_a_maintenance_title_still_wins(self):
        """#264's decision, which a type rule placed one rule higher would silently reverse.

        `Raise dependency floors ...` is Maintenance because the title SAYS so, whatever its diff
        shape. Typing that ticket `Bug` must not turn it into a fix.
        """
        for title in ("Raise dependency floors to their latest stable releases",
                      "Refactor the presence sweep",
                      "Close the mutation survivors in the three read stores"):
            self.assertEqual(
                g.bucket("s", title, paths=["CLAUDE.md", "src/RobotCouncilServiceProvider.php"],
                         test_lines=0, other_lines=10, issue_types=("Bug",)),
                "maint", title)

    def test_a_test_only_diff_still_wins(self):
        """A change that edited no source changed no behavior, so it is coverage, not a fix."""
        self.assertEqual(
            g.bucket("s", "Neutral title", paths=["docs/x.md"],
                     test_lines=50, other_lines=10, issue_types=("Bug",)),
            "maint")

    # ---- the rest of #268's criteria --------------------------------------------------------

    def test_task_is_not_consulted(self):
        """`Task` predicted `fix` 6 of 6 on the measured range, and the correlation is an artifact.

        Non-vacuous: one side passes `("Task",)` and the other passes nothing, so adding a `Task`
        branch to the cascade turns this red.
        """
        for title, paths, t, o in [
            ("Read the legacy credential once per refusal, not twice", ["src/A.php"], 108, 32),
            ("Count the session ending, and bound stopping the reader", ["src/B.php"], 219, 10),
        ]:
            self.assertEqual(
                g.bucket("s", title, paths=paths, test_lines=t, other_lines=o, issue_types=("Task",)),
                g.bucket("s", title, paths=paths, test_lines=t, other_lines=o),
                title)

    def test_an_untyped_issue_routes_as_before(self):
        """Asserted against LITERALS.

        Comparing `bucket(issue_types=())` with `bucket()` would be a tautology, because `()` is the
        declared default -- the same call twice. Measured: a mutant routing every untyped change to
        Maintenance leaves such a comparison green.
        """
        self.assertEqual(g.bucket("s", "Send the package prefix root to the dashboard",
                                  paths=["src/A.php"], test_lines=173, other_lines=99), "new")
        self.assertEqual(g.bucket("s", "Raise dependency floors to their latest stable releases",
                                  paths=["src/A.php"], test_lines=20, other_lines=10), "maint")
        self.assertEqual(g.bucket("s", "Document the published install",
                                  paths=[".claude/x.md"], test_lines=0, other_lines=34), "maint")
        self.assertEqual(g.bucket("s", "Prevent an XSS in the enrollment page",
                                  paths=["src/A.php"]), "sec")

    def test_a_feature_titled_with_an_outcome_verb_is_not_a_fix(self):
        """The shape most likely to be caught by a wrong rule: a feature phrased as an outcome."""
        for types in ((), ("Feature",), ("Task",)):
            self.assertNotEqual(
                g.bucket("s", "Say when nothing on the fleet can reach a waiting agent",
                         paths=["src/A.php"], test_lines=177, other_lines=151, issue_types=types),
                "fix", str(types))

    def test_several_issues_of_differing_types_are_a_fix(self):
        """Asserted in every order, so the answer cannot depend on what GraphQL returned first."""
        for types in (("Bug", "Feature"), ("Feature", "Bug"), ("Task", "Bug"), ("Bug", "Task")):
            self.assertEqual(
                g.bucket("s", "Closes two at once", paths=["src/A.php"],
                         test_lines=10, other_lines=10, issue_types=types),
                "fix", str(types))
        self.assertEqual(
            g.bucket("s", "Closes two at once", paths=["src/A.php"],
                     test_lines=10, other_lines=10, issue_types=("Task", "Feature")),
            "new")


class IssueTypePlumbing(unittest.TestCase):
    """The cache widening and the accessor, which no `bucket()` test reaches (#268).

    The per-pull-request cache went from a 2-tuple to a 3-tuple. Nothing else in the suite indexes
    it, so an arity slip would surface only as every title silently falling back to a commit subject.
    """

    def setUp(self):
        self._saved = dict(g._pr_cache)
        g._pr_cache.clear()

    def tearDown(self):
        g._pr_cache.clear()
        g._pr_cache.update(self._saved)

    def test_a_cold_cache_yields_empty_rather_than_raising(self):
        """`pr_labels` and `pr_issue_types` read the cache and never prime it.

        `pr_title` is deliberately not called here: it primes on a miss, which would put a live
        GraphQL call inside the unit suite.
        """
        self.assertEqual(g.pr_issue_types(999999), ())
        self.assertEqual(g.pr_labels(999999), ())

    def test_every_accessor_reads_its_own_slot(self):
        g._pr_cache[7] = ("A title", ("development",), ("Bug",))
        self.assertEqual(g.pr_title(7, "robot-council/core"), "A title")
        self.assertEqual(g.pr_labels(7), ("development",))
        self.assertEqual(g.pr_issue_types(7), ("Bug",))

    def test_the_miss_default_is_length_three(self):
        """A 2-tuple default would make `pr_issue_types` raise, or read a label as a type."""
        g._pr_cache[8] = (None, (), ())
        self.assertEqual(len(g._pr_cache[8]), 3)
        self.assertEqual(g.pr_issue_types(8), ())
        self.assertEqual(g.pr_labels(8), ())


class UnresolvedReference(unittest.TestCase):
    """A bullet that loses its `[#N]` link says so (#294).

    **Where the warning sits is the whole of the correctness.** `main()` primes every `#N` a
    subject contains, and `resolve()` asks for exactly one of them, so a miss on any of the others
    costs nothing. Warning at priming time reported those and was wrong to -- measured on a real
    subject, below.
    """

    def setUp(self):
        self._saved = dict(g._pr_cache)

    def tearDown(self):
        g._pr_cache.clear()
        g._pr_cache.update(self._saved)

    def _resolve(self, subject, cache):
        g._pr_cache.clear()
        g._pr_cache.update(cache)
        err = io.StringIO()
        with contextlib.redirect_stderr(err):
            out = g.resolve(subject, "robot-council/core")
        return err.getvalue(), out

    def test_a_primed_but_unused_reference_is_not_reported(self):
        """The negative control, and a real subject rather than a constructed one.

        `Cover the two dashboard guarantees #30 claimed and nothing asserted (#110)` is on
        `v0.1.0..v0.2.0`. `#30` is an ISSUE, primed because it appears in the subject and never
        asked for; `#110` is the pull request and resolves. A warning here would fire on a healthy
        range, and a warning that fires on healthy data gets switched off.
        """
        warn, (pr, _title, link) = self._resolve(
            "Cover the two dashboard guarantees #30 claimed and nothing asserted (#110)",
            {110: ("Cover the two dashboard guarantees", (), ()), 30: (None, (), ())})
        self.assertEqual(pr, 110)
        self.assertIn("/pull/110", link)
        self.assertEqual(warn, "", "a primed-but-unused miss must not warn")

    def test_priming_a_reference_nobody_asks_for_is_silent(self):
        """**The test that would have caught this being built the wrong way** (#294).

        The first attempt warned inside `prime_pr_cache()`, which sees every `#N` a subject
        contains rather than the one `resolve()` chooses. It reported a miss on
        `robot-council/core#30` while generating `v0.1.0..v0.2.0` -- a healthy range -- because
        that issue number appears in a subject whose pull request is `#110`.

        The two tests below call `resolve()` with a pre-seeded cache, so priming never runs and
        neither of them can see that defect. This one drives the priming path itself.
        """
        class R:
            def __init__(s_, out):
                s_.stdout, s_.stderr, s_.returncode = out, "", 0

        real = g.subprocess
        g._pr_cache.clear()
        try:
            # #30 is an issue: the alias comes back null. #110 is the pull request.
            body = json.dumps({"data": {"repository": {
                "p30": None,
                "p110": {"title": "Cover the two dashboard guarantees",
                         "closingIssuesReferences": {"totalCount": 0, "nodes": []}}}}})
            g.subprocess = types.SimpleNamespace(run=lambda *a, **k: R(body))
            err = io.StringIO()
            with contextlib.redirect_stderr(err):
                g.prime_pr_cache([30, 110], "robot-council/core")
            self.assertEqual(err.getvalue(), "",
                             "priming a reference nobody asks for must not warn")
            self.assertIsNone(g.pr_title(30, "robot-council/core"))
            self.assertEqual(g.pr_title(110, "robot-council/core"),
                             "Cover the two dashboard guarantees")
        finally:
            g.subprocess = real

    def test_a_reference_that_loses_its_link_is_reported(self):
        """The case the warning exists for: the chosen reference does not resolve."""
        warn, (pr, _title, link) = self._resolve("Some direct commit (#999999)",
                                                 {999999: (None, (), ())})
        self.assertIsNone(pr)
        self.assertEqual(link, "")
        self.assertIn("did not resolve", warn)
        self.assertIn("#999999", warn)

    def test_a_subject_with_no_reference_is_silent(self):
        """Nothing was lost, so there is nothing to report."""
        warn, (pr, _title, link) = self._resolve("A direct commit with no number", {})
        self.assertIsNone(pr)
        self.assertEqual(link, "")
        self.assertEqual(warn, "")


class IssueTypeGuards(unittest.TestCase):
    """The two warnings the type rule made necessary (#268).

    Both were verified by hand when they were written, which is exactly the standard this commit
    set out to raise: a guard nobody re-runs is a guard that stops working silently.
    """

    def setUp(self):
        self._real, self._cache = g.subprocess, dict(g._pr_cache)
        g._pr_cache.clear()

    def tearDown(self):
        g.subprocess = self._real
        g._pr_cache.clear()
        g._pr_cache.update(self._cache)

    def _drive(self, stdout, nums=(7,), returncode=0):
        class R:
            def __init__(s_, out, rc):
                s_.stdout, s_.stderr, s_.returncode = out, "", rc
        g.subprocess = types.SimpleNamespace(run=lambda *a, **k: R(stdout, returncode))
        err = io.StringIO()
        with contextlib.redirect_stderr(err):
            g.prime_pr_cache(list(nums), "robot-council/core")
        return err.getvalue()

    @staticmethod
    def _ok(total, nodes):
        return json.dumps({"data": {"repository": {"p7": {
            "title": "T", "closingIssuesReferences": {"totalCount": total, "nodes": nodes}}}}})

    def test_every_no_data_shape_warns_not_only_the_ones_with_an_errors_array(self):
        """The three silent shapes an earlier draft missed, and the two it caught.

        Each leaves the whole batch uncached, so every bullet in it loses its link -- the outcome
        the partial-data handling exists to prevent. Gating on `errors` covered two of five.
        """
        shapes = {
            "graphql error": json.dumps({"data": None, "errors": [
                {"type": "INVALID", "message": "Field 'issueType' doesn't exist on type 'Issue'"}]}),
            "empty errors array": json.dumps({"data": None, "errors": []}),
            "repository null": json.dumps({"data": {"repository": None}}),
            "empty stdout": "",
            "an HTML error page": "<html>gateway timeout</html>",
        }
        for label, body in shapes.items():
            out = self._drive(body, returncode=1 if not body.startswith("{") else 0)
            self.assertIn("warning:", out, label)
            self.assertIn("carry no links", out, label)
            self.assertEqual(g.pr_issue_types(7), (), label)
            g._pr_cache.clear()

    def test_a_malformed_errors_array_warns_rather_than_raising(self):
        """The branch exists to REPORT a failure, so it must not become one (#293).

        Every shape below violates GraphQL's specification, which says `errors` is a list of
        objects carrying a string `message`. That is exactly why they reach here: the response
        this branch reads is by definition one that already went wrong. Measured before the fix,
        each raised instead of warning -- `KeyError: 0`, two `AttributeError`s on `'str'` and
        `'NoneType'`, and a `TypeError` from slicing a null `message`. **Before the warning
        existed these cached a miss quietly, so the guard made the bad case worse.**
        """
        shapes = {
            "errors is an object": {"a": 1},
            "errors is a string": "boom",
            "an entry is a string": ["boom"],
            "an entry is null": [None],
            "an entry's message is null": [{"type": "X", "message": None}],
            "an entry's message is a number": [{"type": "X", "message": 7}],
        }
        for label, errs in shapes.items():
            out = self._drive(json.dumps({"data": None, "errors": errs}), returncode=1)
            self.assertIn("warning:", out, label)
            self.assertIn("carry no links", out, label)
            g._pr_cache.clear()

    def test_a_null_node_is_skipped_and_reported_as_truncated(self):
        """A `null` inside `nodes` crashed the loop, which reads `.get()` on each entry (#293).

        Dropping it is not enough on its own: the pull request really did close two issues and
        only one could be read, so the run must say so rather than quietly deciding a bucket from
        half the evidence. `totalCount` is what makes that visible.
        """
        out = self._drive(self._ok(2, [None, {"issueType": {"name": "Bug"}, "labels": {"nodes": []}}]))
        self.assertIn("closes 2 issues", out)
        self.assertEqual(g.pr_issue_types(7), ("Bug",), "the surviving node is still read")

    def test_a_healthy_response_warns_about_nothing(self):
        """The negative control. A guard that cries wolf is turned off within a week."""
        for label, body in {
            "one typed issue": self._ok(1, [{"issueType": {"name": "Bug"},
                                             "labels": {"nodes": [{"name": "development"}]}}]),
            "one untyped issue": self._ok(1, [{"issueType": None, "labels": {"nodes": []}}]),
            "no closing issues": self._ok(0, []),
            "nodes null": json.dumps({"data": {"repository": {"p7": {"title": "T",
                "closingIssuesReferences": {"totalCount": 0, "nodes": None}}}}}),
        }.items():
            self.assertEqual(self._drive(body), "", label)
            g._pr_cache.clear()

    def test_truncation_is_reported_rather_than_guessed(self):
        """Since this rule a dropped closing issue can decide the BUCKET, not just lose a label."""
        out = self._drive(self._ok(25, [{"issueType": {"name": "Feature"}, "labels": {"nodes": []}}]))
        self.assertIn("closes 25 issues", out)
        self.assertEqual(g.pr_issue_types(7), ("Feature",))


class RemoteParsing(unittest.TestCase):
    """`owner/repo` out of a git remote URL, without a checkout or a network."""

    def test_reads_the_shapes_github_actually_hands_out(self):
        for url in (
            "https://github.com/robot-council/core.git",
            "https://github.com/robot-council/core",
            "git@github.com:robot-council/core.git",
            "ssh://git@github.com/robot-council/core.git",
            "https://github.com/robot-council/core/",
        ):
            self.assertEqual(g.parse_remote(url), "robot-council/core", url)

    def test_strips_only_a_trailing_dot_git(self):
        # A repository legitimately named with a dot must survive.
        self.assertEqual(g.parse_remote("git@github.com:o/my.repo.git"), "o/my.repo")
        self.assertEqual(g.parse_remote("git@github.com:o/my.repo"), "o/my.repo")

    def test_refuses_what_is_not_a_remote(self):
        # The point of the whole ticket: a non-answer must be a non-answer, not something
        # plausible. A path-style remote names no GitHub repository.
        for url in ("", None, "   ", "/srv/git/bare.git", "not a url"):
            self.assertIsNone(g.parse_remote(url), repr(url))


class RepoDerivation(unittest.TestCase):
    """Which repository a run is about, derived rather than defaulted."""

    @staticmethod
    def runner(results):
        """A fake command runner: maps the first two argv words to (code, stdout)."""
        def run(args):
            return results.get(" ".join(args[:2]), (1, ""))
        return run

    def test_prefers_gh_which_knows_the_configured_repository(self):
        run = self.runner({"gh repo": (0, "robot-council/core")})
        self.assertEqual(g.derive_repo(run), "robot-council/core")

    def test_falls_back_to_the_origin_remote_when_gh_cannot_answer(self):
        run = self.runner({
            "gh repo": (1, ""),
            "git remote": (0, "git@github.com:robot-council/core.git"),
        })
        self.assertEqual(g.derive_repo(run), "robot-council/core")

    def test_ignores_a_gh_answer_that_is_not_a_repository(self):
        # `gh` exiting 0 with something unusable must not be taken as an answer.
        run = self.runner({
            "gh repo": (0, "not-a-repo"),
            "git remote": (0, "https://github.com/robot-council/core.git"),
        })
        self.assertEqual(g.derive_repo(run), "robot-council/core")

    def test_returns_nothing_when_there_is_no_github_remote(self):
        # The case the ticket names: a checkout with no GitHub remote must produce nothing, so the
        # caller can name the flag rather than fall back to a repository that happens to exist.
        run = self.runner({"gh repo": (1, ""), "git remote": (0, "/srv/git/bare.git")})
        self.assertIsNone(g.derive_repo(run))

    def test_returns_nothing_when_there_is_no_remote_at_all(self):
        self.assertIsNone(g.derive_repo(self.runner({})))


if __name__ == "__main__":
    unittest.main()


class DeliberateFailureProvingTheJobGates(unittest.TestCase):
    """TEMPORARY. Removed in the next commit.

    #295's third criterion asks that the job be shown to FAIL, not watched to pass. This is that
    proof: it exists for exactly one CI run, and its removal is the commit after.
    """

    def test_this_must_turn_the_generator_job_red(self):
        self.assertEqual(1, 2, "deliberate: proving the generator job gates ci-passed")
