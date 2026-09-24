<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use InvalidArgumentException;

/**
 * The one place the repository or workspace an agent names is bounded.
 *
 * `robot_council_tasks.project_id` holds it, and three endpoints validate it, so before this there
 * were three copies of one rule and no copy at all on the path a host reaches.
 *
 * **It was two tables until `robot-council/core#285`.** `robot_council_agent_sessions.project_id`
 * was the other, and `Support\WorkIdentity` replaced it with a repository and a work location.
 * `Http\Controllers\SessionStartController` still validates the key against this class's bounds,
 * because a client may still send one for `WorkIdentity::fromProjectId()` to split, but no session
 * stores it and `Support\Tasks::create()` is the only store that calls `ensure()`.
 *
 * **It is bounded because it reaches other developers' agents, not because of the column.** A task's
 * own words stay behind `TaskList`'s visibility rule, but `Support\Tasks::create()` puts `project_id`
 * into the change feed's `meta`, and `FleetFeed` serves that to every session in the fleet. That is
 * the reason `CLAUDE.md` names it beside `harness` and `machine_label`: event content is untrusted
 * input to something that may have shell access, so what crosses that boundary is charset-limited.
 *
 * The length half is bounded here for a second reason. `$table->string('project_id')` takes its
 * length from `Schema::$defaultStringLength`, which is a public static a host may lower --
 * `Schema::defaultStringLength(191)` is the canonical utf8mb4 workaround and is still widely copied
 * -- so the column's width is the host's business and cannot be the bound. The columns are pinned to
 * `MAX` in the migrations and checked against it here.
 */
final class ProjectId
{
    /**
     * The longest project id the package stores.
     */
    public const int MAX = 128;

    /**
     * What a project id may contain.
     *
     * The same restricted set every other agent-facing identifier carries. `/D` so that a trailing
     * newline cannot slip past `$`, which is the whole point of a charset limit at an edge.
     */
    public const string PATTERN = '/^[A-Za-z0-9._\/-]{1,128}$/D';

    /**
     * Refuse a project id the package will not store.
     *
     * Null is allowed: naming a project is optional on every surface that takes one.
     *
     * @param  mixed  $projectId  What the caller is asking to store.
     *
     * @throws InvalidArgumentException When it is present and outside the bound.
     */
    public static function ensure(mixed $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        // Measured in characters, as the `max:` rule the endpoints apply does, so the store and the
        // edge refuse the same values rather than nearly the same ones
        if (! \is_string($projectId) || mb_strlen($projectId) > self::MAX || preg_match(self::PATTERN, $projectId) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A project id is up to %d characters of [A-Za-z0-9._/-], and this one is %s.',
                self::MAX,
                \is_string($projectId) ? sprintf('%d characters', mb_strlen($projectId)) : get_debug_type($projectId)
            ));
        }
    }
}
