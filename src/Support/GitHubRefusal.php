<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RuntimeException;

/**
 * GitHub did not give `Support\GitHubApp` what it asked for (#383).
 *
 * **The message is built here from the outcome and the status alone**, never from the response or
 * the underlying exception, because either can carry a request's URL or headers and a failure is
 * written to the log. Nothing a caller passes in can put a credential into it.
 */
final class GitHubRefusal extends RuntimeException
{
    /**
     * @param  BacklogFetchOutcome  $outcome  What kind of failure it was.
     * @param  int|null  $status  The HTTP status GitHub answered with, or null when it did not answer.
     */
    public function __construct(
        public readonly BacklogFetchOutcome $outcome,
        public readonly ?int $status = null
    ) {
        parent::__construct($status === null
            ? sprintf('GitHub: %s.', $outcome->value)
            : sprintf('GitHub: %s (HTTP %d).', $outcome->value, $status));
    }
}
