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
     * The abilities this installation has been granted.
     *
     * **It decides nothing about authorization, and has no authorization reader at all.** Since
     * `robot-council/core#221` a session token carries its `Access\Role` preset; `#222` removed
     * `Role::permittedBy()`, which had been this column's one authorization reader, so machine-level
     * eligibility stopped existing rather than moving somewhere else. `#231` then retired the
     * controls that wrote it. What remains reads it for display and for the enrollment response a
     * released client still parses, which is what `robot-council/core#239` is waiting on before the
     * column can go. Treat a value here as a record of what an enrollment asked for, not as a
     * statement about any session.
     *
     * **It reads the attribute as `mixed`, because the column is `json` and the row decides.**
     * `@property list<string>` states what this package writes, not what the accessor can be
     * handed: a host calling the model directly, a seeder, a hand-edited row, or a restore can
     * leave `null`, a scalar, or a nested value there. A declared `string` parameter on the
     * filter raised a `TypeError` for any of them. Between #159 and
     * `robot-council/core#223`, `Support\FleetAbilities` read **every** usable installation to
     * answer one request, so one malformed row 500s `GET {prefix}/api/agent/session` for every
     * agent in the fleet. Dropping the value answers that request instead of failing it.
     *
     * **#223 narrowed the blast radius back to this row, and the guard stays anyway.** That
     * question reads live sessions and their roles now, so this accessor no longer runs fleet-wide
     * -- but it is still what the enrollment page, the approval path and `robot-council:doctor`
     * read, it is public on a model a host can call directly, and `MalformedAbilitiesTest` pins
     * both directions of the narrowing rather than assuming it.
     *
     * **Dropping did not make one bad row that row's own problem while the read WAS fleet-wide, and
     * the reasoning is kept because it is what the guard was built for.** `fleet_can_direct` was
     * computed across every installation, so one whose abilities could not be read changed what
     * every other session was told: the answer went from a 500 to a quiet `false`, which
     * `Http\Controllers\AgentSessionController` documents as
     * meaning nothing will ever arrive. Nothing logs the drop and `robot-council:doctor` has no
     * check that would name it. #171 is that gap.
     *
     * The same reasoning covers the container: a row holding the JSON literal `null` or a bare
     * scalar decodes to something `array_filter()` cannot take at all. The column is NOT NULL,
     * which stops SQL `NULL` and not `'null'::json`.
     *
     * **A top-level JSON object is accepted rather than dropped**, because `json_decode($v, true)`
     * turns one into a PHP array and `array_values()` discards its keys. That is deliberate rather
     * than missed: a row holding `{"a": "tasks:create"}` grants `tasks:create`, which is no wider
     * than the row already claimed, since every value still has to be in the fixed list. It is
     * also not separable from a well-formed one, because `{"0": "tasks:create"}` decodes to a list.
     *
     * @return list<string> The granted abilities, with anything outside the fixed list dropped.
     */
    public function abilities(): array
    {
        $known = Ability::values(Ability::grantable());
        $stored = $this->getAttribute('granted_abilities');

        if (! \is_array($stored)) {
            return [];
        }

        // Drop anything the fixed list no longer holds, so a renamed or retired ability cannot
        // survive in a stored row and be checked against a route later.
        //
        // **The strict `in_array()` is the whole filter, and an `is_string()` beside it was
        // measured dead.** `$known` is a `list<string>`, so a strict comparison already refuses
        // every non-string a row can hold -- over null, the booleans, ints, floats, arrays, an
        // object and a resource the two expressions disagree on nothing -- and PHPStan narrows
        // the element from the same fact, so the declared `list<string>` verifies without it.
        // That second half was checked rather than assumed: removing `array_values()` makes this
        // file fail with `should return list<string>`, so a passing analysis here is a result and
        // not a silence. **Do not add a type guard back**; what stops the `TypeError` is the
        // parameter being `mixed` rather than `string`
        return array_values(array_filter(
            $stored,
            static fn (mixed $ability): bool => \in_array($ability, $known, true)
        ));
    }
}
