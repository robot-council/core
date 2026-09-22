<?php

declare(strict_types=1);

namespace RobotCouncil\Support\Contracts;

use RobotCouncil\Support\NewDeveloper;

/**
 * Supplies the attributes a developer's user row is created with.
 *
 * **This exists because the package cannot guess a host's users table** (#36). `HostUsers::create()`
 * writes `name` and `email`, and `robot-council:install` relaxes nullability on `users.password`
 * and `users.email` because those are the two the framework's own skeleton makes `NOT NULL`. Any
 * other `NOT NULL` column without a default -- `tenant_id`, `organization_id`, `role_id`, `locale`,
 * or a `first_name`/`last_name` pair instead of `name` -- is ordinary in a real application, and it
 * failed the very first GitHub sign-in with a raw integrity error, **after** the OAuth round trip.
 *
 * A host could not fix that from outside: `HostUsers` is `final`, the package's `strict()` arch
 * preset forbids `protected`, and nothing dispatched an event or took a callback.
 *
 * **A contract bound in the container, matching `DrawsUserCodes`**, rather than a static closure or
 * an event. One binding means one implementation, so two registrations cannot silently fight; a
 * host that binds nothing gets the package's default; and "the host supplied nothing" is not
 * confusable with "the host's listener did nothing", which is the failure an event would have.
 *
 * ```php
 * // A host's own service provider
 * $this->app->bind(SuppliesUserAttributes::class, TenantUserAttributes::class);
 * ```
 *
 * **It supplies attributes, not the row.** The package still constructs and saves the model, so it
 * can still state what a developer's row holds -- which matters because the GitHub identity mapping
 * is what decides who somebody is. A host whose user creation has side effects of its own, such as
 * provisioning a tenant, is a case this deliberately does not cover; #36 records it.
 */
interface SuppliesUserAttributes
{
    /**
     * The attributes to create this developer's user row with.
     *
     * Whatever is returned is force-filled, because a host model's `$fillable` is not the package's
     * to assume, and it is written as given -- the package adds nothing back on top.
     *
     * **A host that changes `email` owns what follows from that.** Sign-in is unaffected, because
     * `robot_council_github_identities` maps an account to a user by GitHub ID rather than by
     * address. What moves is the duplicate check: the package already refused this sign-in if the
     * **GitHub** address was held by another user, so writing a different one puts the row outside
     * the check that was made for it, and a collision on the written address surfaces as the
     * integrity error it would have before.
     *
     * @param  NewDeveloper  $developer  The account signing in, already admitted by the allowlist.
     * @return array<string, mixed> The attributes, which the package writes as given.
     */
    public function for(NewDeveloper $developer): array;
}
