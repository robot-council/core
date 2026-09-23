<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    |
    | The GitHub accounts that may sign in, as numeric GitHub user IDs. Numeric
    | IDs rather than logins, because a login can be renamed and then claimed
    | by someone else. Admins are developers with the `robot-council-admin`
    | ability as well. Both lists accept a comma-separated string or an array,
    | and both are read on every request. A host that runs `php artisan config:cache`
    | bakes them in: re-run that command after changing either list, or the old
    | list stays live.
    |
    */

    'access' => [
        'developers' => env('ROBOT_COUNCIL_DEVELOPERS', ''),
        'admins' => env('ROBOT_COUNCIL_ADMINS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | The guard the package signs developers in on, and checks them against. The
    | host application's default guard is deliberately not assumed, because a
    | host with several guards may default to another one.
    |
    */

    'auth' => [
        'guard' => env('ROBOT_COUNCIL_GUARD', 'web'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Where the package mounts its routes in the host application, and the
    | middleware groups they run in. The human-facing routes need a session, so
    | that group has to start a session and verify CSRF tokens. The machine
    | routes are stateless and carry a bearer token instead.
    |
    */

    'routes' => [
        'web_prefix' => env('ROBOT_COUNCIL_WEB_PREFIX', 'robot-council'),
        'web_middleware' => ['web'],
        'api_prefix' => env('ROBOT_COUNCIL_API_PREFIX', 'robot-council/api'),

        // Deliberately not `['api']`. These endpoints are stateless and bring their own
        // throttling, and an application's `api` group is not: `statefulApi()` prepends Sanctum's
        // `EnsureFrontendRequestsAreStateful`, which promotes a request whose origin is on
        // `sanctum.stateful` into a session request and answers the unauthenticated device
        // endpoints with 419, while `throttleApi()` re-keys them on the caller's address. Add this
        // application's own middleware here if it needs to run.
        'api_middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | How long each credential lives. A developer approves an installation --
    | one harness on one machine -- once, and that installation starts a session
    | per agent process. Session tokens are short and renew without a human, so
    | a leaked one is useful for minutes rather than weeks.
    |
    | Leave `sanctum.expiration` null. Sanctum measures it from a token's
    | `created_at`, so it would cut off a renewed session token regardless of the
    | token's own expiry; `robot-council:install` reports a non-null value.
    |
    | These are what is still measured on `app.timezone`. A token's expiry has
    | to be: Sanctum compares `expires_at` against the application's clock, and
    | writing it on another would put the two sides an offset apart. A device
    | code's has no such coupling and simply has not moved yet. So a
    | daylight-saving transition can expire or extend either by an hour, and
    | `robot-council:doctor` reports a non-UTC `app.timezone` for this.
    |
    */

    'credentials' => [
        'installation_max_age_days' => (int) env('ROBOT_COUNCIL_INSTALLATION_MAX_AGE_DAYS', 30),
        'session_ttl_minutes' => (int) env('ROBOT_COUNCIL_SESSION_TTL_MINUTES', 60),

        // RFC 8628 puts no ceiling on a device code's lifetime; ten minutes is
        // this package's, and a larger value is clamped to it.
        'device_code_ttl_seconds' => (int) env('ROBOT_COUNCIL_DEVICE_CODE_TTL_SECONDS', 600),

        // What the enrollment helper is told to wait between polls.
        'device_code_interval_seconds' => (int) env('ROBOT_COUNCIL_DEVICE_CODE_INTERVAL_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slack
    |--------------------------------------------------------------------------
    |
    | Where the fleet's events are mirrored for humans to read. Leave the webhook
    | URL unset and no mirror runs at all. Slack is one-way: nothing in this
    | package reads from it, and no coordination decision depends on it, so an
    | outage there costs visibility and never correctness.
    |
    | The mirror runs on its own queue, away from anything a request waits on.
    |
    */

    'slack' => [
        'webhook_url' => env('ROBOT_COUNCIL_SLACK_WEBHOOK_URL'),
        'queue' => env('ROBOT_COUNCIL_SLACK_QUEUE', 'robot-council-slack'),

        // The queue connection the mirror runs on. Leave it null to use the application's
        // default, but note what `sync` means here: the job runs inline inside the agent's own
        // request, `queue` above is ignored, a rate-limit release is silently dropped, and a
        // Slack failure surfaces on a request whose event is already committed. The mirror is
        // meant to cost visibility and never correctness, which only holds off `sync`.
        'connection' => env('ROBOT_COUNCIL_SLACK_CONNECTION'),

        // Whether narration is mirrored. Narration is the one kind of event the feed restricts by
        // reader (#29), and that rule is about what one developer's AGENT may read from another's
        // -- because event content is untrusted input to something that may have shell access.
        // A Slack channel is a human surface, so mirroring narration there is the point of having
        // one, and it does mean everyone with channel access reads every agent's narration. Turn
        // this off to mirror only state changes and directives.
        'mirror_restricted' => env('ROBOT_COUNCIL_SLACK_MIRROR_NARRATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Presence
    |--------------------------------------------------------------------------
    |
    | How long an agent session may go without contact, in minutes. Every
    | authenticated agent request counts as contact, and a process with nothing
    | else to send posts a heartbeat. A stale session still holds what it
    | claimed and is active again on its next request; a session that has gone
    | is final, its tokens are refused, and its claims and locks are released.
    |
    | `gone_after_minutes` is always read as at least a minute past
    | `stale_after_minutes`, so the warning state is reachable rather than
    | skipped. Both are measured by `robot-council:sweep-sessions`, so neither
    | can fire sooner than the interval that command runs on.
    |
    | Both measure ELAPSED time, and `app.timezone` does not reach them. Contact
    | times and cutoffs are written, compared, and read back on one fixed clock
    | (`Support\PresenceClock`, which is UTC), so a daylight-saving transition
    | moves neither. A lock's lease is on the same clock -- see the Locks
    | block. What still rides `app.timezone` is a token's expiry and a device
    | code's -- see the Credentials block.
    |
    */

    'presence' => [
        'stale_after_minutes' => (int) env('ROBOT_COUNCIL_PRESENCE_STALE_AFTER_MINUTES', 5),
        'gone_after_minutes' => (int) env('ROBOT_COUNCIL_PRESENCE_GONE_AFTER_MINUTES', 30),

        // How many sessions one sweep may mark in each of its two passes. A fleet that went
        // silent at once -- an outage, a network partition -- is otherwise a single unbounded
        // batch, and every session in it takes the change feed's one writer lock in turn while
        // every agent's narration queues behind it. What is left over is marked a minute later.
        'max_per_sweep' => (int) env('ROBOT_COUNCIL_PRESENCE_MAX_PER_SWEEP', 500),

        // A request refused by `rate_limits.agent_per_session` records no contact: the limiter is
        // answered before the middleware that records it. That does not cost a busy process its
        // session, because the limiter is a fixed window -- it lets the whole allowance through at
        // the top of every minute, and those succeed and are contact. The margin is one minute
        // against a threshold that is never under two, which is why `gone_after_minutes` is read as
        // at least a minute past `stale_after_minutes` and why a configured limit is never read as
        // zero. `tests/ThrottledPresenceTest.php` is what holds that gap open.
    ],

    /*
    |--------------------------------------------------------------------------
    | Locks
    |--------------------------------------------------------------------------
    |
    | Named advisory leases. A lock expires on its own, so a session that
    | stopped answering cannot block the fleet past its lease, and a fence
    | value lets whatever the lock guards notice that the lease lapsed.
    |
    | `max_hold_seconds` is the ceiling a renewal cannot push a lease past,
    | measured from when it was first acquired, so a session cannot hold one
    | name forever by renewing. It is the outer bound: a `max_ttl_seconds`
    | longer than it is read as equal to it, so the longest lease the API
    | advertises is one a renewal can actually be granted. Re-acquiring a name
    | after letting it lapse starts a new hold, with a new fence.
    | `max_per_session` bounds how many one session can hold at once.
    |
    | A lease measures ELAPSED time, and `app.timezone` does not reach it.
    | `acquired_at` and `expires_at` are written, compared, and read back on
    | one fixed clock (`Support\PresenceClock`, which is UTC), so a
    | daylight-saving transition cannot lapse a held lock. Upgrading a host
    | that is NOT on UTC reinterprets its existing lock rows once, and west of
    | UTC that reads every held lease as already lapsed -- see the note on
    | `Models\Lock`. The window is bounded by `max_ttl_seconds` rather than by
    | the offset: whatever a re-read says, no lease outlives its ceiling, so
    | every affected row is re-acquired or gone within that.
    |
    */

    'locks' => [
        'max_ttl_seconds' => (int) env('ROBOT_COUNCIL_LOCKS_MAX_TTL_SECONDS', 900),
        'max_hold_seconds' => (int) env('ROBOT_COUNCIL_LOCKS_MAX_HOLD_SECONDS', 14400),
        'max_per_session' => (int) env('ROBOT_COUNCIL_LOCKS_MAX_PER_SESSION', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Whether the package adds its own entries to this application's schedule:
    | an hourly prune of expired device codes, and a sweep of agent-session
    | presence every minute. Turn either off to run `robot-council:prune-device-codes`
    | or `robot-council:sweep-sessions` on another schedule, or from something
    | other than Laravel's scheduler. Turning the sweep off without running it
    | elsewhere means no session is ever marked stale or gone, and whatever a
    | dead process was holding stays held.
    |
    */

    'schedule' => [
        'prune_device_codes' => true,
        'sweep_sessions' => true,
        'prune_events' => true,
        'prune_tasks' => true,
        'prune_locks' => true,
        'prune_sessions' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long the fleet's history is kept before a scheduled command deletes it.
    | These tables are written continuously and nothing else removes from them, so
    | without a retention they grow for the life of the deployment.
    |
    | The feed is the one that grows fastest: every task transition, lock, session
    | change and line of narration is a row. A day of a busy fleet is a lot of rows,
    | and almost none of it is read twice -- an agent pages the feed forward and a
    | developer reads the head of it.
    |
    | Set a value to zero to keep that table forever, which is what a host running
    | its own archiving wants. The schedule entry above turns the command off
    | entirely; this decides what it deletes when it runs.
    |
    */

    'retention' => [
        'events_days' => (int) env('ROBOT_COUNCIL_EVENT_RETENTION_DAYS', 30),
        'tasks_days' => (int) env('ROBOT_COUNCIL_TASK_RETENTION_DAYS', 90),

        // Shorter than the other two, because a lock row that nobody holds carries nothing worth
        // reading. It is not the fence: that comes from one sequence shared by every name, so a
        // deleted row cannot lower the next number issued for the name it held.
        'locks_days' => (int) env('ROBOT_COUNCIL_LOCK_RETENTION_DAYS', 7),

        // Longer than the others, because a session row is what a reader consults to find out what
        // a process was after it ended -- #24 keeps it for exactly that. Only a session that has
        // gone is ever deleted, and never one still holding a task or a lock.
        'sessions_days' => (int) env('ROBOT_COUNCIL_SESSION_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | How often the dashboard's pages ask the server for fresh state. There is no
    | broadcasting, so this interval is the whole of the fleet's liveness on screen:
    | a change an agent commits is visible within one of these, and no sooner.
    |
    */

    'dashboard' => [
        'poll_seconds' => (int) env('ROBOT_COUNCIL_DASHBOARD_POLL_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Attempts per minute, each against the subject named by its key. The token
    | endpoint is limited twice, because a helper polling every few seconds is
    | ordinary traffic while thousands of device codes from one address are not.
    |
    */

    'rate_limits' => [
        'device_code_per_ip' => (int) env('ROBOT_COUNCIL_RATE_DEVICE_CODE_PER_IP', 10),
        'device_token_per_code' => (int) env('ROBOT_COUNCIL_RATE_DEVICE_TOKEN_PER_CODE', 30),
        'device_token_per_ip' => (int) env('ROBOT_COUNCIL_RATE_DEVICE_TOKEN_PER_IP', 120),
        'verification_per_user' => (int) env('ROBOT_COUNCIL_RATE_VERIFICATION_PER_USER', 20),
        'sessions_per_installation' => (int) env('ROBOT_COUNCIL_RATE_SESSIONS_PER_INSTALLATION', 60),
        'agent_per_session' => (int) env('ROBOT_COUNCIL_RATE_AGENT_PER_SESSION', 120),

        // Slack's own guidance is about one message a second per webhook
        'slack_per_minute' => (int) env('ROBOT_COUNCIL_RATE_SLACK_PER_MINUTE', 60),
    ],

];
