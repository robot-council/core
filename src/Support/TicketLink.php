<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * Where a ticket reference points, or nowhere (#317).
 *
 * **Only a repository-qualified reference is linked.** A bare `#N` names a number that exists in
 * every tracker, so linking it would invent a repository its writer never chose; it renders as text.
 * The URL is built from a reference already matched against `IssueReference::PATTERN`, whose
 * character set holds nothing a URL or an HTML attribute would need escaped, and Blade escapes it
 * regardless.
 */
final class TicketLink
{
    /**
     * The GitHub URL for a reference.
     *
     * `/issues/N` for both issues and pull requests: GitHub redirects an issue URL naming a pull
     * request to the pull request, and the board does not always know which it is.
     *
     * @param  string|null  $reference  `owner/name#N`, or anything else.
     * @return string|null The URL, or null when the reference is not repository-qualified.
     */
    public static function url(?string $reference): ?string
    {
        if ($reference === null || preg_match(IssueReference::PATTERN, $reference) !== 1) {
            return null;
        }

        [$repository, $number] = explode('#', $reference, 2);

        return sprintf('https://github.com/%s/issues/%s', $repository, $number);
    }
}
