<?php

declare(strict_types=1);

namespace RobotCouncil\Access;

/**
 * The fixed list of abilities a token may carry. Sanctum treats `*` as every ability, so the
 * package never grants it: an ability that is not on this list cannot be requested, granted, or
 * checked into existence later.
 */
enum Ability: string
{
    /**
     * Start and renew agent sessions. The only ability an installation credential carries.
     */
    case SessionsStart = 'sessions:start';

    /**
     * Create tasks for the fleet.
     */
    case TasksCreate = 'tasks:create';

    /**
     * Claim a task and work it.
     */
    case TasksClaim = 'tasks:claim';

    /**
     * Take a lock over a file or a resource.
     */
    case LocksAcquire = 'locks:acquire';

    /**
     * Post narration to the event feed.
     */
    case EventsPost = 'events:post';

    /**
     * Direct other developers' agents: release, reassign, or cancel any task, and post directives.
     * An admin grants it after enrollment, and it can never be requested.
     */
    case CoordinatorDirect = 'coordinator:direct';

    /**
     * The abilities an enrollment request may ask for.
     *
     * @return list<self> Every ability except the installation's own and the coordinator's.
     */
    public static function requestable(): array
    {
        return [
            self::TasksCreate,
            self::TasksClaim,
            self::LocksAcquire,
            self::EventsPost,
        ];
    }

    /**
     * The abilities an admin may grant to an installation after enrollment.
     *
     * @return list<self> The requestable abilities, plus the coordinator's.
     */
    public static function grantable(): array
    {
        return [...self::requestable(), self::CoordinatorDirect];
    }

    /**
     * One grantable ability, resolved from whatever a client sent.
     *
     * The single place an admin action turns a string into an ability, so `*` and anything outside
     * the fixed list are refused by the same expression rather than by a check written again per
     * call site. `*` is the one that matters: Sanctum reads it as every ability, so a grant that
     * let it through would hand an installation everything including abilities added later.
     *
     * `sessions:start` is refused too, although it is a real case. It is the installation
     * credential's own ability and is never carried by a session token, so granting it would write
     * a value no guard checks and read as authority nobody holds.
     *
     * @param  string  $value  The ability as the client named it.
     * @return self|null The ability, or null when it is not one an admin may grant.
     */
    public static function grantableFrom(string $value): ?self
    {
        $ability = self::tryFrom($value);

        return $ability !== null && \in_array($ability, self::grantable(), true) ? $ability : null;
    }

    /**
     * Narrow what an enrollment asked for to what the server is willing to grant.
     *
     * The requested list is read from the stored row, never from the request that approves it, so
     * an ability added to an approval POST reaches nothing. Anything outside the requestable list
     * -- `*`, an unknown name, or the coordinator's -- is dropped rather than refused, because the
     * request that carried it was already validated when the code was issued.
     *
     * **The elements are `mixed`, because the caller's source is a `json` column.** The in-package
     * caller hands over `Models\DeviceCode::requestedAbilities()`, which is already narrowed, but
     * this is a public static on a `final` class a host can call with anything -- and a declared
     * `string` parameter here raised a `TypeError` for a `null` or a nested value rather than
     * dropping it, which is the same defect #167 fixed one column over. The parameter type is the
     * whole fix: the strict `in_array()` below already refuses every non-string, and PHPStan
     * narrows the element from the same fact, so no type guard is needed beside it and one added
     * there would be dead. Checked rather than assumed -- removing `array_values()` makes this
     * file fail with `should return list<string>`, so a clean analysis of it is a result.
     *
     * @param  array<array-key, mixed>  $requested  The abilities the enrollment asked for.
     * @return list<string> The abilities to grant, without duplicates.
     */
    public static function granted(array $requested): array
    {
        $requestable = self::values(self::requestable());

        return array_values(array_unique(array_filter(
            $requested,
            static fn (mixed $ability): bool => \in_array($ability, $requestable, true)
        )));
    }

    /**
     * Reduce a list of abilities to their string values.
     *
     * @param  list<self>  $abilities  The abilities to convert.
     * @return list<string> The abilities as they are stored on a token.
     */
    public static function values(array $abilities): array
    {
        return array_map(static fn (self $ability): string => $ability->value, $abilities);
    }
}
