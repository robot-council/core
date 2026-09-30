<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\GithubIdentity;

/**
 * The GitHub login behind an agent session, which is the provenance the fleet decides trust on.
 *
 * Shared by the change feed and by the task list, so both derive it the same way. It is derived by
 * the server on every read and never taken from what a poster claimed: a login is what tells one
 * developer's agent whose words and whose work it is looking at, so a caller that could supply it
 * could impersonate anyone.
 */
final class AgentLogins
{
    /**
     * The login behind each of a set of agent sessions.
     *
     * Resolved in two queries rather than two per session, because a full page of the feed would
     * otherwise be four hundred round trips.
     *
     * @param  array<mixed>  $sessionIds  The session IDs referred to, some of them null.
     * @return array<int, string> Logins, keyed by agent session ID.
     */
    public function forSessions(array $sessionIds): array
    {
        $ids = array_values(array_unique(array_filter($sessionIds, is_int(...))));

        if ($ids === []) {
            return [];
        }

        $sessions = AgentSession::query()->whereKey($ids)->get(['id', 'user_id']);

        $identities = GithubIdentity::query()
            ->whereIn('user_id', $sessions->pluck('user_id')->unique()->all())
            ->get(['user_id', 'github_login', 'avatar_url']);

        // The same rows name each developer's avatar, so a page that shows one pays no query for it
        app(GitHubAccounts::class)->noteAvatars($identities);

        $byUser = $identities->pluck('github_login', 'user_id');

        $logins = [];

        foreach ($sessions as $session) {
            $login = $byUser->get($session->user_id);

            if (\is_string($login)) {
                $logins[$session->id] = $login;
            }
        }

        return $logins;
    }

    /**
     * The GitHub logins behind a set of host user keys.
     *
     * The same resolution as `forSessions()` without the hop through the session table, for callers
     * that already hold the user key. The change feed is one: `robot_council_events` carries
     * `user_id` precisely so that provenance survives the posting session's row being deleted, and
     * resolving through the session id instead would re-bind a dead id to whoever holds it now --
     * stamping one developer's login onto another developer's event.
     *
     * @param  array<mixed>  $userIds  The user keys referred to, some of them null.
     * @return array<string, string> Logins, keyed by host user key.
     */
    public function forUsers(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter($userIds, is_string(...))));

        if ($ids === []) {
            return [];
        }

        $identities = GithubIdentity::query()
            ->whereIn('user_id', $ids)
            ->get(['user_id', 'github_login', 'avatar_url']);

        app(GitHubAccounts::class)->noteAvatars($identities);

        /** @var array<string, string> $logins */
        $logins = $identities->pluck('github_login', 'user_id')->all();

        return $logins;
    }
}
