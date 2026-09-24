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
