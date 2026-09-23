<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use RobotCouncil\Access\Ability;

/**
 * One harness on one machine, approved once by a developer through the device-code flow. Its
 * credential can do nothing but start and renew agent sessions, so a leak costs short-lived
 * session tokens rather than the fleet.
 *
 * It is a token owner rather than a user: the developer it belongs to is `user_id`, a key in the
 * host application's users table, which the package does not own and holds no foreign key to. It is
 * authenticatable only because Sanctum resolves a token's owner through an authentication provider,
 * and it shares no ancestor with any host user model, for the reason `AgentSession` records.
 *
 * @property int $id
 * @property string $user_id
 * @property string $harness
 * @property string $machine_label
 * @property list<string> $granted_abilities
 * @property string|null $approved_by
 * @property string|null $requested_ip
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property-read Collection<int, AgentSession> $sessions
 *
 * @phpstan-use HasApiTokens<PersonalAccessToken>
 */
#[Fillable([
    'user_id',
    'harness',
    'machine_label',
    'granted_abilities',
    'approved_by',
    'requested_ip',
    'expires_at',
])]
#[Table(name: 'robot_council_installations')]
final class Installation extends Model implements AuthenticatableContract
{
    use Authenticatable;

    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    /**
     * The attribute casts.
     *
     * Public rather than protected, because Pest's `strict()` preset forbids protected methods in
     * the package's namespaces, and PHP allows a subclass to widen a parent's visibility.
     *
     * @return array<string, string> The casts Eloquent applies to this model's attributes.
     */
    public function casts(): array
    {
        return [
            'granted_abilities' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * The agent sessions this installation has started.
     *
     * @return HasMany<AgentSession, $this> The sessions, in no particular order.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }

    /**
     * Determine whether the installation may still act.
     *
     * Checked on every request rather than trusted from the token, so revoking an installation or
     * shortening the configured maximum age takes effect on the next request.
     *
     * @return bool True while the installation is neither revoked nor past its expiry.
     */
    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * The installations that may still act, as a query.
     *
     * **The set-level half of `isUsable()`, and the two have to keep agreeing.** A row-level
     * predicate cannot bound a read and a `where` cannot answer for a model already in hand, so
     * both forms exist; they are adjacent, and `FleetCanDirectTest` asserts that the query returns
     * exactly the rows `isUsable()` answers true for, because a drift between them would be
     * invisible -- each is correct on its own terms and only the disagreement is the defect. That
     * assertion includes a row expiring at this very instant, which is where `>` and `>=` differ
     * and which no mutation run can reach, because the operator here is a string argument to
     * `where()` rather than a PHP operator.
     *
     * **Usable is not the same as able to act.** `Http\Middleware\EnsureInstallation` takes a
     * third gate this query cannot: the developer must still be on the access list. A caller
     * asking whether anything can be done, rather than whether a credential has lapsed, has to
     * apply that too -- `Support\FleetAbilities` is the one that does.
     *
     * `Carbon::now()` rather than a fixed clock, because `expires_at` is written on the
     * application's clock by `Support\Credentials` and compared against it by Sanctum. Moving
     * one side alone is what #149 declined to do.
     *
     * @return Builder<static> The installations neither revoked nor past their expiry.
     */
    public static function usable(): Builder
    {
        return self::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now());
    }

    /**
     * The abilities this installation's session tokens carry.
     *
     * @return list<string> The granted abilities, with anything outside the fixed list dropped.
     */
    public function abilities(): array
    {
        $known = Ability::values(Ability::grantable());

        // Drop anything the fixed list no longer holds, so a renamed or retired ability cannot
        // survive in a stored row and be checked against a route later
        return array_values(array_filter(
            $this->granted_abilities,
            static fn (string $ability): bool => \in_array($ability, $known, true)
        ));
    }
}
