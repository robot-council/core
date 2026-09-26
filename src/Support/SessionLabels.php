<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Facades\DB;

/**
 * A session named for a person, as `<repository>/<machine>/<slot>` (#421).
 *
 * **The one place a session's label is built**, so the queue and the locks page say the same thing.
 * A developer's login cannot tell one session from another -- one developer runs a dozen across
 * several machines and repositories -- and a bare session id changes on every restart and appears
 * nowhere else a person looks. Where the session works does both jobs.
 *
 * - The repository is shown by name, without its owner: `robot-council/core` reads `core`.
 * - The slot is the work location with the repository's own prefix taken off:
 *   `robot-council-core-a` reads `a` for `robot-council/core` (the `<owner>-<name>-` prefix, the
 *   maintainer's decision on #421), and `uams-statamic-a` reads `a` for `UAMS-Web/uams-statamic`
 *   (the `<name>-` prefix). A work location with neither prefix is shown whole rather than guessed
 *   at, so `primary` stays `primary`.
 * - A session with no repository has no label, and the page falls back to the login.
 *
 * Only for what a person reads. Agents keep session ids and logins in `task_list`, `sessions_list`
 * and the feed, which they pass back in tool calls.
 */
final readonly class SessionLabels
{
    /**
     * @param  AgentLogins  $logins  Who each session belongs to.
     */
    public function __construct(private AgentLogins $logins) {}

    /**
     * The label for one session's values, or null when it names no repository.
     *
     * @param  string|null  $repository  `owner/name`, as the session reported it.
     * @param  string|null  $machine  The installation's machine label.
     * @param  string|null  $workLocation  The session's work location.
     * @return string|null The label.
     */
    public static function of(?string $repository, ?string $machine, ?string $workLocation): ?string
    {
        if ($repository === null || $repository === '') {
            return null;
        }

        $slash = strrpos($repository, '/');
        $owner = $slash === false ? '' : substr($repository, 0, $slash);
        $name = $slash === false ? $repository : substr($repository, $slash + 1);

        $parts = [$name];

        if ($machine !== null && $machine !== '') {
            $parts[] = $machine;
        }

        if ($workLocation !== null && $workLocation !== '') {
            $parts[] = self::slot($owner, $name, $workLocation);
        }

        return implode('/', $parts);
    }

    /**
     * Each of a set of sessions as a person reads it: its label, and its developer's login.
     *
     * **Two queries however many sessions there are, which is what `AgentLogins::forSessions()`
     * already cost the pages that use this**, so naming a session by where it works adds nothing to
     * their query budgets: the session read that resolved the login now carries the repository, the
     * work location and the machine too. A session that has been deleted is absent, and a caller
     * falls back to what it showed before; one that names no repository has a login and no label.
     *
     * @param  array<mixed>  $sessionIds  The sessions referred to, some of them null.
     * @return array<int, array{label: string|null, login: string|null}> Keyed by agent session id.
     */
    public function forSessions(array $sessionIds): array
    {
        $ids = array_values(array_unique(array_filter($sessionIds, is_int(...))));

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('robot_council_agent_sessions as sessions')
            ->leftJoin('robot_council_installations as installations', 'installations.id', '=', 'sessions.installation_id')
            ->whereIn('sessions.id', $ids)
            ->get(['sessions.id', 'sessions.user_id', 'sessions.repository', 'sessions.work_location', 'installations.machine_label']);

        $logins = $this->logins->forUsers($rows->pluck('user_id')->all());

        $people = [];

        foreach ($rows as $row) {
            if (! is_numeric($row->id ?? null)) {
                continue;
            }

            $user = $row->user_id ?? null;

            $people[(int) $row->id] = [
                'label' => self::of(
                    \is_string($row->repository ?? null) ? $row->repository : null,
                    \is_string($row->machine_label ?? null) ? $row->machine_label : null,
                    \is_string($row->work_location ?? null) ? $row->work_location : null,
                ),
                'login' => \is_string($user) ? ($logins[$user] ?? null) : null,
            ];
        }

        return $people;
    }

    /**
     * The slot a work location names, with the repository's own prefix taken off.
     *
     * The longer prefix is tried first, because `<name>-` is also the start of
     * `<owner>-<name>-` whenever the owner begins with the name. A work location that is nothing
     * but the prefix keeps its whole value, since an empty slot says nothing.
     */
    private static function slot(string $owner, string $name, string $workLocation): string
    {
        foreach ($owner === '' ? [$name.'-'] : [$owner.'-'.$name.'-', $name.'-'] as $prefix) {
            // Without regard to case: an owner such as `UAMS-Web` is often written lower-case in the
            // name of a checkout
            // Measured and cut in characters, so a letter whose lower case is shorter in bytes cannot
            // move the cut into the middle of another
            if (str_starts_with(mb_strtolower($workLocation), mb_strtolower($prefix)) && mb_strlen($workLocation) > mb_strlen($prefix)) {
                return mb_substr($workLocation, mb_strlen($prefix));
            }
        }

        return $workLocation;
    }
}
