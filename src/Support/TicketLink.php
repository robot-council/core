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

    /**
     * The reference a GitHub issue or pull-request URL names, or null for any other URL (#537).
     *
     * The inverse of `url()`, for a link an agent wrote in Markdown: only an exact
     * `https://github.com/owner/name/issues/N` or `.../pull/N`, with nothing after it but an
     * optional slash, is read, and the reference it yields is matched against
     * `IssueReference::PATTERN` as any other is. The URL the link then carries is rebuilt by
     * `url()`, so nothing of what the agent wrote reaches an `href`.
     *
     * @param  string  $url  A URL, or anything else.
     * @return string|null `owner/name#N`, or null.
     */
    public static function reference(string $url): ?string
    {
        if (preg_match('#^https://github\.com/([^/?\#\s]+/[^/?\#\s]+)/(?:issues|pull)/([1-9][0-9]*)/?$#D', $url, $match) !== 1) {
            return null;
        }

        $reference = $match[1].'#'.$match[2];

        return self::url($reference) === null ? null : $reference;
    }

    /**
     * The GitHub profile URL for a login (#484).
     *
     * Built the way `url()` is, from a fixed scheme and host and a value already matched against a
     * pattern -- `LaneHolds::LOGIN`, whose letters, digits and hyphens need no escaping in a URL --
     * so `EscapingGuardTest` admits it as a whole expression for the same reason.
     *
     * @param  string|null  $login  A GitHub login, or anything else.
     * @return string|null The URL, or null when it is not a login.
     */
    public static function profile(?string $login): ?string
    {
        if ($login === null || preg_match(LaneHolds::LOGIN, $login) !== 1) {
            return null;
        }

        return 'https://github.com/'.$login;
    }
}
