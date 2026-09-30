<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * One GitHub account as its public profile describes it (#484).
 *
 * Built only by `GitHubApp::account()`, from an answer whose every field it has checked: the id is a
 * positive whole number, the login matches `LaneHolds::LOGIN`, and the type is one GitHub names. So
 * the login can go into a URL path or onto a page without escaping more than Blade already does.
 */
final readonly class GitHubAccount
{
    /**
     * The type GitHub gives a person's account, the only kind that can sign in.
     */
    public const string USER = 'User';

    /**
     * @param  int  $id  The account's numeric user ID, which never changes.
     * @param  string  $login  Its login as of this read, which can be renamed.
     * @param  string  $type  `User`, `Organization` or `Bot`.
     */
    public function __construct(
        public int $id,
        public string $login,
        public string $type
    ) {}

    /**
     * Whether a person holds it, which is the only kind of account that can sign in.
     *
     * @return bool True for a `User` account.
     */
    public function isPerson(): bool
    {
        return $this->type === self::USER;
    }
}
