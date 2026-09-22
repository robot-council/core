<?php

declare(strict_types=1);

namespace RobotCouncil\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;

/**
 * A host application's user model that soft-deletes, which is the ordinary Laravel default for one.
 *
 * **The point is the disagreement it creates, not the trait.** `SoftDeletes` adds a global scope, so
 * `HostUsers::findByEmail()` -- which reads through `$model::query()` -- cannot see a trashed row,
 * while the `users.email` unique index still can. A developer whose GitHub email belongs to a
 * deleted account therefore reads as "no such user", is sent down the create path, and collides with
 * an index that disagrees (#38).
 *
 * Any global scope does this. A tenant scope hides a row exactly as a soft delete does, and the
 * index does not care which one it was; `SoftDeletes` is simply the one a host is most likely to
 * have.
 */
#[Table(name: 'users')]
#[Fillable(['name', 'email', 'password'])]
class SoftDeletingHostUser extends User
{
    use SoftDeletes;
}
