<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * How one repository's latest backlog fetch went (#383), as `robot_council_backlog_fetches` stores it.
 *
 * Every case but `Read` stored no reading, so the meter for that repository reads unreadable; these
 * say why, for `robot-council:doctor`.
 */
enum BacklogFetchOutcome: string
{
    /**
     * GitHub answered with a count, and it was stored.
     */
    case Read = 'read';

    /**
     * The App is not installed on the repository's owner, so no count was asked for.
     */
    case NoInstallation = 'no installation';

    /**
     * GitHub refused: a 401, 403, 404, 422, or any other status that is not a 2xx.
     */
    case Refused = 'refused';

    /**
     * GitHub refused because a rate limit was reached. The run stops asking at this one.
     */
    case RateLimited = 'rate limited';

    /**
     * GitHub did not answer: a timeout, or a connection that failed.
     */
    case Unreachable = 'unreachable';

    /**
     * GitHub answered 2xx with a body that holds no count this package stores.
     */
    case Unparseable = 'unparseable';

    /**
     * GitHub's search timed out on its side and said its count may be short. Not stored, because a
     * count GitHub itself calls incomplete is a wrong one.
     */
    case Incomplete = 'incomplete';

    /**
     * The App's key or id could not be used to sign a request.
     */
    case KeyUnusable = 'key unusable';

    /**
     * Something other than GitHub failed while fetching: the cache store, the database, or a bug.
     * Logged with the exception's class alone.
     */
    case Error = 'error';

    /**
     * Whether this outcome can pass without anyone doing anything: GitHub not answering, a rate
     * limit, an incomplete search, or a failure outside GitHub. Doctor reports these as
     * undetermined rather than failed.
     *
     * @return bool Whether it is transient.
     */
    public function transient(): bool
    {
        return \in_array($this, [self::Unreachable, self::RateLimited, self::Incomplete, self::Error], true);
    }
}
