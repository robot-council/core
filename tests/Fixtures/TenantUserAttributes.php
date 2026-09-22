<?php

declare(strict_types=1);

namespace RobotCouncil\Tests\Fixtures;

use RobotCouncil\Support\Contracts\SuppliesUserAttributes;
use RobotCouncil\Support\NewDeveloper;

/**
 * A host's own attributes for a developer's first user row.
 *
 * Stands for the shape #36 is about: a users table with a `NOT NULL` column the package knows
 * nothing about, and a host that fills it by binding this rather than by extending anything.
 *
 * It also reads the GitHub account rather than ignoring it, because that is the other half of what
 * the contract has to be able to do -- a host deriving a value from who is signing in, not merely
 * pasting a constant.
 */
final class TenantUserAttributes implements SuppliesUserAttributes
{
    /**
     * The tenant every developer created through this fixture lands in.
     */
    public const int TENANT_ID = 77;

    /**
     * Name, email, and the column the package cannot guess.
     *
     * @param  NewDeveloper  $developer  The account signing in.
     * @return array<string, mixed> The attributes.
     */
    public function for(NewDeveloper $developer): array
    {
        return [
            'name' => $developer->login,
            'email' => $developer->email,
            'tenant_id' => self::TENANT_ID,

            // Derived from the account, so a test can tell this apart from a constant
            'external_ref' => 'github:'.$developer->githubId,
        ];
    }
}
