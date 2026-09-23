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
     * The requestable abilities among some values, in order, without duplicates.
     *
     * **One narrowing, called from three places, so the rule cannot drift between them.**
     * `Support\DeviceCodes::issue()` bounds what it stores, `Support\DeviceCodes::approve()` bounds
     * what it records as granted, and `granted()` below bounds what an approval turns into a
     * token's abilities. Written as a check per call site, three copies would have to be kept
     * agreeing, and the one that fell behind would be the one nobody reads (#170).
     *
     * **The argument is `mixed`, not `array`, and the container guard is the reason.** Callers hand
     * over what a `json` column decoded to, and that is whatever the row holds: an `array` parameter
     * would raise a `TypeError` for the JSON literal `null` or a bare scalar, which is the defect
     * #167 fixed in this class and in two models, arriving through a different door.
     *
     * The elements are `mixed` for the same reason. The strict `in_array()` refuses every
     * non-string on its own -- and it has to be strict: PHP compares a bool against a string by
     * casting the string to bool, so a loose comparison finds `true` equal to any non-empty
     * ability name and would admit a boolean as an ability. PHPStan narrows the element from the
     * strict flag too, so no type guard belongs beside it; one added there is dead code, measured.
     *
     * (Spelled out rather than written as an expression because Pest's `strict()` arch preset
     * scans the raw source, comments included, and refuses a loose comparison operator in it.)
     *
     * @param  mixed  $values  Whatever the caller offered, including what a `json` column decoded to.
     * @return list<string> Those that may be asked for, in the order given, without duplicates.
     */
    public static function requestableFrom(mixed $values): array
    {
        if (! \is_array($values)) {
            return [];
        }

        $requestable = self::values(self::requestable());

        return array_values(array_unique(array_filter(
            $values,
            static fn (mixed $value): bool => \in_array($value, $requestable, true)
        )));
    }

    /**
     * Narrow what an enrollment asked for to what the server is willing to grant.
     *
     * The requested list is read from the stored row, never from the request that approves it, so
     * an ability added to an approval POST reaches nothing. Anything outside the requestable list
     * -- `*`, an unknown name, or the coordinator's -- is dropped rather than refused, because the
     * request that carried it was already validated when the code was issued.
     *
     * **What a DEVICE-CODE approval may grant and what an enrollment may ask for are one list,
     * deliberately.** An admin grant is a different question and a wider list: `grantable()` adds
     * `coordinator:direct`, which `Support\Installations::setAbility()` is the only path to.
     * An ability that could be requested and never granted would be a trap: the verification page
     * would show it as asked for and the token would silently not carry it. So this is
     * `requestableFrom()` under the name its call site reads by, and the two cannot drift apart.
     *
     * Its elements are `mixed` for the reason recorded there -- the caller's source is a `json`
     * column, and a declared `string` raised a `TypeError` rather than dropping the value (#167).
     *
     * @param  array<array-key, mixed>  $requested  The abilities the enrollment asked for.
     * @return list<string> The abilities to grant, without duplicates.
     */
    public static function granted(array $requested): array
    {
        return self::requestableFrom($requested);
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
