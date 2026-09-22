<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Support\Contracts\SuppliesUserAttributes;

/**
 * The attributes the package writes when a host supplies none of its own.
 *
 * Exactly what `HostUsers::create()` wrote before `Contracts\SuppliesUserAttributes` existed, so a
 * host that binds nothing sees no change at all (#36). That is the point of it being a binding with
 * a default rather than a hook a host must fill in: the common case -- a users table that takes
 * `name` and `email` -- keeps working without anybody reading this.
 */
final class UserAttributes implements SuppliesUserAttributes
{
    /**
     * Name and email, which is what the framework's own users table takes.
     *
     * @param  NewDeveloper  $developer  The account signing in.
     * @return array<string, mixed> The attributes.
     */
    public function for(NewDeveloper $developer): array
    {
        return [
            'name' => $developer->login,
            'email' => $developer->email,
        ];
    }
}
