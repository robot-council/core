#!/usr/bin/env python3
"""
Offline tests for gen_release_notes.py: title cleanup and bucket routing. Neither calls
`git` or `gh`, so these run without a network or a checkout history.

  python3 -m unittest discover -s .claude/skills/writing-release-notes
"""
import os
import sys
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
    """The type a human set on the linked issue, read after the maintenance-path rule (#268).

    Every fixture here is a real pull request from `robot-council/cli`'s v0.3.0 range, named by
    number, because the decision on `robot-council/cli#162` was measured against that range and a
    synthetic case cannot be checked back against it. `IssueTypeOnThisRepository` below carries the
    same properties against pull requests from THIS repository's own range, which is what #268's
    criteria ask for by name -- the two are not redundant, because the cascade is shared source and
    a fixture from one tree proves nothing about the other's data.
    """

    SOURCE = ["app/Support/Bridge.php", "tests/Feature/BridgeTest.php"]

    def test_bug_is_a_fix_and_feature_is_new(self):
        # `cli#158` closes an issue typed `Bug`; `cli#149` closes one typed `Feature`.
        self.assertEqual(
            g.bucket("s", "Tell a session the sweep marked it stale or gone",
                     paths=self.SOURCE, test_lines=82, other_lines=32, issue_types=("Bug",)),
            "fix")
        self.assertEqual(
            g.bucket("s", "Renew when this session's role changes",
                     paths=self.SOURCE, test_lines=648, other_lines=160, issue_types=("Feature",)),
            "new")

    def test_a_skill_only_change_stays_maintenance_although_its_issue_is_a_bug(self):
        """The placement, asserted rather than described.

        `robot-council/cli#154` is typed `Bug` and touches `.claude/` alone. Rule 5 takes it first
        and calls it Maintenance, which is right: a change to a skill file is maintenance whatever
        the ticket it closes is typed. This is the assertion that fails if the rule is moved up.
        """
        self.assertEqual(
            g.bucket("s", "Take the pull-request skill from `robot-council/core` verbatim",
                     paths=[".claude/skills/writing-pull-requests/SKILL.md"], issue_types=("Bug",)),
            "maint")

        # And the same for the other maintenance paths rule 5 owns.
        for path in ("composer.json", "README.md", "CLAUDE.md", "tests/FooTest.php"):
            self.assertEqual(g.bucket("s", "Anything at all", paths=[path], issue_types=("Bug",)),
                             "maint", path)

    def test_task_is_not_consulted(self):
        """`Task` predicted `fix` 6 of 6 on the measured range, and the correlation is an artifact.

        `writing-issues` assigns it to a spike, a decision fork, a cleanup or an epic -- never to a
        bug. A change typed `Task` routes exactly as an untyped one does.
        """
        for title, paths, t, o in [
            ("Read the legacy credential once per refusal, not twice", self.SOURCE, 108, 32),
            ("Count the session ending, and bound stopping the reader", self.SOURCE, 219, 10),
        ]:
            self.assertEqual(
                g.bucket("s", title, paths=paths, test_lines=t, other_lines=o, issue_types=("Task",)),
                g.bucket("s", title, paths=paths, test_lines=t, other_lines=o),
                title)

    def test_an_untyped_issue_routes_exactly_as_before(self):
        """14 of the 25 on the measured range carry no type, so this is the majority path."""
        cases = [
            ("Renew when this session's role changes", self.SOURCE, 648, 160),
            ("Fix the provider name", self.SOURCE, 10, 200),
            ("Raise dependency floors to their latest stable releases", self.SOURCE, 20, 10),
            ("Document the published install", [".claude/x.md"], 0, 34),
        ]
        for title, paths, t, o in cases:
            self.assertEqual(
                g.bucket("s", title, paths=paths, test_lines=t, other_lines=o, issue_types=()),
                g.bucket("s", title, paths=paths, test_lines=t, other_lines=o),
                title)

    def test_a_feature_titled_with_an_outcome_verb_is_not_a_fix(self):
        """The shape most likely to be caught by a wrong rule.

        `cli#121`, `Say when nothing on the fleet can reach a waiting agent`, is a FEATURE whose
        title opens the way this repository writes a fix. Nothing may route it to What's fixed.
        """
        for types in ((), ("Feature",), ("Task",)):
            self.assertNotEqual(
                g.bucket("s", "Say when nothing on the fleet can reach a waiting agent",
                         paths=self.SOURCE, test_lines=177, other_lines=151, issue_types=types),
                "fix", str(types))

    def test_several_issues_of_differing_types_are_a_fix(self):
        """The tie is broken deliberately rather than by whichever came back first.

        A pull request closing a bug and a feature has repaired something, and a reader scanning
        What's fixed for a regression they hit is worse served by its absence than a reader of
        What's new is by its absence there. Asserted in both orders, so the answer does not depend
        on what GraphQL happened to return first.
        """
        for types in (("Bug", "Feature"), ("Feature", "Bug"), ("Task", "Bug"), ("Bug", "Task")):
            self.assertEqual(
                g.bucket("s", "Closes two at once", paths=self.SOURCE,
                         test_lines=10, other_lines=10, issue_types=types),
                "fix", str(types))

        # `Feature` with anything that is not `Bug` is still new.
        self.assertEqual(
            g.bucket("s", "Closes two at once", paths=self.SOURCE,
                     test_lines=10, other_lines=10, issue_types=("Task", "Feature")),
            "new")

    def test_security_and_the_published_surface_still_win(self):
        """The rules above this one are unmoved, which a new rule is the usual way to break."""
        self.assertEqual(
            g.bucket("s", "Prevent an XSS in the enrollment page",
                     paths=self.SOURCE, issue_types=("Feature",)),
            "sec")
        self.assertEqual(
            g.bucket("s", "Anything at all", labels=["security"],
                     paths=self.SOURCE, issue_types=("Feature",)),
            "sec")
        self.assertEqual(
            g.bucket("s", "Anything at all", paths=["config/robot-council.php"],
                     issue_types=("Bug",)),
            "new")
        self.assertEqual(
            g.bucket("s", "Anything at all", labels=["documentation"],
                     paths=self.SOURCE, issue_types=("Feature",)),
            "maint")


class IssueTypeOnThisRepository(unittest.TestCase):
    """The same rule, measured against `robot-council/core`'s own v0.3.2..HEAD range (#268).

    Every path list and line count below was read from the pull request's own files on 2026-09-24,
    not invented, so a fixture that stops describing this repository is a fixture that will be
    caught. The range holds 29 changes: 9 close a `Bug`, 6 a `Feature`, 13 a `Task`, and 1 closes
    nothing typed -- so the criterion's "14 of 25 carry none" is `robot-council/cli`'s figure, not
    this repository's, and the property it names is what carries over rather than the number.
    """

    def test_the_two_bugs_the_rule_moves(self):
        """`#235` and `#224`, the whole measured reach of this change on this repository.

        Both are titled with an outcome verb -- `Send`, `Register` -- so rule 4's verb list cannot
        see them, and both edit `src/`, so #266's source exclusion disarms rule 7. Before this rule
        they fell through to `new`. They are the shape the ticket exists for.
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

    def test_this_repositorys_own_skill_only_bug_stays_maintenance(self):
        """The placement criterion, against the change #268 names rather than a stand-in.

        `#259` closes a `Bug` and edits one file under `.claude/`. Rule 5 takes it, and must: a
        change to a skill file is maintenance whatever its ticket is typed. Moving the type rule
        above rule 5 turns this red.
        """
        self.assertEqual(
            g.bucket("s", "Refuse the comma form for multiple closes, and make the scan refuse both directions",
                     paths=[".claude/skills/writing-pull-requests/SKILL.md"],
                     test_lines=0, other_lines=58, issue_types=("Bug",)),
            "maint")

    def test_a_feature_with_an_outcome_verb_stays_new(self):
        """`#243`, a `Feature` titled `Let a session ...` rather than `Add ...`.

        All six `Feature`-closing changes on the range held at `new` with the rule in place and
        without it, so the `Feature` half changed no outcome here. It earns its line by being
        stated rather than left to rule 8's default, which any later rule could displace.
        """
        for types in ((), ("Feature",)):
            self.assertEqual(
                g.bucket("s", "Let a session request a role, and an administrator decide it",
                         paths=["config/robot-council.php", "src/Access/Role.php",
                                "routes/api.php", "resources/views/livewire/administration.blade.php"],
                         test_lines=0, other_lines=417, issue_types=types),
                "new", str(types))

    def test_an_earlier_rule_still_wins_where_it_should(self):
        """Six of the nine `Bug`-closing changes are held above rule 6, and each is held correctly.

        Three route to `sec` (`#242`, `#252`, `#266`), which is where a security fix belongs rather
        than in What's fixed; one to `maint` (`#259`, above); and three to `new` at rule 3 because
        they touch a published surface (`#213`, `#229`, `#262`). **The last group is the one worth
        knowing about**: rule 3 precedes rule 4 as well, so a published-surface change titled `Fix
        ...` has always routed to `new`. That predates this rule and this rule does not change it.
        """
        self.assertEqual(
            g.bucket("s", "Stop the release cascade filing a test-heavy feature as maintenance",
                     paths=[".claude/skills/writing-release-notes/gen_release_notes.py"],
                     labels=["security"], issue_types=("Bug",)),
            "sec")
        # Rule 3: a published surface, so `new` despite the `Bug`. Not a defect of this rule.
        self.assertEqual(
            g.bucket("s", "Raise the absent-value placeholders above the WCAG AA bar",
                     paths=["resources/views/livewire/fleet-presence.blade.php"],
                     test_lines=40, other_lines=20, issue_types=("Bug",)),
            "new")


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
