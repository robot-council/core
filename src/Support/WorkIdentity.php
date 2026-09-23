<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use InvalidArgumentException;

/**
 * The one place the two strings a session says about where it is working are bounded.
 *
 * The sibling of `MachineIdentity`, and named for the parallel: that class bounds the two strings a
 * machine calls itself, this one the two a session calls its work. Both are written together, by
 * one caller, which is why each is one class rather than two.
 *
 * **They are bounded because they reach other developers' agents.** `AgentSessions::start()` puts
 * both into the `session.joined` event's `meta`, and `FleetFeed` serves that to every session in
 * the fleet. Event content is untrusted input to something that may have shell access, so what
 * crosses that boundary is charset-limited rather than merely length-limited -- the reason
 * `CLAUDE.md` names `project_id` beside `harness` and `machine_label`, and these two inherit it.
 *
 * **Neither is ever read by the authorization path, and that is a rule rather than a fact about
 * today's code.** Both are supplied by the client and the service resolves neither, exactly as
 * `project_id` is. `robot-council/cli#125` records what went wrong in a draft that gated an ability
 * on one of them.
 */
final class WorkIdentity
{
    /**
     * The longest repository path the package stores.
     *
     * GitHub bounds an owner at 39 characters and a repository name at 100, so `owner/name` cannot
     * exceed 140. Taken from what the source can actually produce rather than rounded to a number
     * that means nothing.
     */
    public const int MAX_REPOSITORY = 140;

    /**
     * What a repository path may contain.
     *
     * `owner/name`, with exactly one separator. Both halves take the character set GitHub admits in
     * an owner and in a repository name, **including upper case**: `UAMS-Web` is a real owner, and
     * lower-casing it here would write a path that resolves to nothing.
     *
     * The per-segment lengths are deliberately not baked in. They are GitHub's and can move, and a
     * bound written twice is a bound that goes stale in one of the two places -- `MachineIdentity`
     * records the same reasoning. `MAX_REPOSITORY` is the length, and this is the shape.
     */
    public const string REPOSITORY = '/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/D';

    /**
     * The longest work location the package stores.
     *
     * A conventional label -- `a`, `ci`, `primary` -- rather than a path, so this is generous
     * rather than accommodating.
     */
    public const int MAX_LOCATION = 32;

    /**
     * What a work location may contain.
     *
     * **Lower case, for the reason `MachineIdentity::HARNESS` is.** The label exists to be compared
     * across machines: `josh-office` and `josh-home` both run `a` and `ci` of each repository, and
     * that comparison is the whole point of storing a label rather than a directory name. `a` and
     * `A` arriving as two locations for one thing would defeat it exactly as two spellings of one
     * harness would.
     */
    public const string LOCATION = '/^[a-z0-9._-]+$/D';

    /**
     * Refuse a repository or a work location the package will not store.
     *
     * Null is allowed for either, independently: naming where the work is happening is optional on
     * every surface that takes one, and a client may know its repository without having a label for
     * the checkout.
     *
     * The arguments are `mixed` rather than `?string` for the reason `ProjectId::ensure()` records:
     * this is a public method on a class a host can resolve, so a declared `string` turns a wrong
     * type into a `TypeError` from inside the store rather than the refusal this exists to give.
     *
     * @param  mixed  $repository  The repository path the caller is asking to store.
     * @param  mixed  $workLocation  The work location the caller is asking to store.
     *
     * @throws InvalidArgumentException When either is present and outside its bound.
     */
    public static function ensure(mixed $repository, mixed $workLocation): void
    {
        // Characters, not bytes: the unit every bound in this package uses, because that is what
        // Laravel's `max:` rule measures and what Postgres and MySQL count a `varchar` in
        if ($repository !== null && (! \is_string($repository) || mb_strlen($repository) > self::MAX_REPOSITORY || preg_match(self::REPOSITORY, $repository) !== 1)) {
            throw new InvalidArgumentException(sprintf(
                'A repository is up to %d characters of owner/name in [A-Za-z0-9._-], and this one is %s.',
                self::MAX_REPOSITORY,
                \is_string($repository) ? sprintf('%d characters', mb_strlen($repository)) : get_debug_type($repository)
            ));
        }

        if ($workLocation !== null && (! \is_string($workLocation) || mb_strlen($workLocation) > self::MAX_LOCATION || preg_match(self::LOCATION, $workLocation) !== 1)) {
            throw new InvalidArgumentException(sprintf(
                'A work location is up to %d characters of [a-z0-9._-], and this one is %s.',
                self::MAX_LOCATION,
                \is_string($workLocation) ? sprintf('%d characters', mb_strlen($workLocation)) : get_debug_type($workLocation)
            ));
        }
    }

    /**
     * Read a repository and a work location out of a legacy `project_id`.
     *
     * **This is a split, not a guess, and it was measured before it was written.** Every non-null
     * `project_id` on the deployed fleet on 2026-09-23 already carried the structure: 54 rows of
     * `owner/name` and one of `owner/name/location`, out of 75. The ticket assumed there was no
     * reliable way to split the value; there is, and `robot-council/core#220` records the counts.
     *
     * **Anything that does not fit yields two nulls rather than a best effort.** A value that is
     * neither a repository path nor a label would be wrong in whichever field it landed in, and
     * `project_id` is kept beside these two until the epic's final slice retires it -- so refusing
     * to guess loses nothing that is not still on the row.
     *
     * The result is always inside `ensure()`'s bounds, so a caller can store it without checking
     * again; `selfConsistent()` in the test suite is what keeps that true.
     *
     * @param  string|null  $projectId  Whatever the row or the request carried.
     * @return array{string|null, string|null} The repository and the work location, in that order.
     */
    public static function fromProjectId(?string $projectId): array
    {
        if ($projectId === null) {
            return [null, null];
        }

        $parts = explode('/', $projectId);

        // Two segments is a repository with no label; three is a repository and its label. Anything
        // else -- one segment, four, or an empty one anywhere -- is not this shape.
        $repository = match (\count($parts)) {
            2, 3 => $parts[0].'/'.$parts[1],
            default => null,
        };

        $location = \count($parts) === 3 ? $parts[2] : null;

        // Bounded before it is handed back, so the split can never produce a value the store would
        // refuse. A repository that fails leaves the label behind too: `a` on its own says nothing.
        if ($repository === null || mb_strlen($repository) > self::MAX_REPOSITORY || preg_match(self::REPOSITORY, $repository) !== 1) {
            return [null, null];
        }

        if ($location !== null && (mb_strlen($location) > self::MAX_LOCATION || preg_match(self::LOCATION, $location) !== 1)) {
            return [$repository, null];
        }

        return [$repository, $location];
    }
}
