<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * How much of a fleet list a reader is asking for.
 *
 * **Every table the dashboard lists keeps its dead rows on purpose**, and each for a different
 * reason: #24 keeps a `gone` session because a task sitting held is explained by the process that
 * ended, the locks migration keeps a released row for the fence it carries, and an installation
 * keeps its row so a revocation stays auditable. So each list grows without bound while the part
 * anybody is looking at does not, and ordering alone cannot stop the live rows being pushed off the
 * end by the dead ones.
 *
 * A scope rather than a boolean, because `live` and `all` are what a reader means and `true` is
 * not. It also gives the three lists one vocabulary: **`Live` is the rows still worth acting on**,
 * which each store defines for its own table.
 */
enum Scope: string
{
    /**
     * Only the rows still worth acting on: a session that has not gone, a lock that still names a
     * holder, an installation that is neither revoked nor expired.
     */
    case Live = 'live';

    /**
     * Everything the table holds, dead rows included. What a reader asks for when the question is
     * what *happened* rather than what is happening.
     */
    case All = 'all';

    /**
     * The scope a client named, or the caller's default when it named nothing this recognizes.
     *
     * Named rather than `from()`, which is the enum's own and cannot be given this behaviour.
     *
     * Falling back rather than throwing: this arrives from a query string or a Livewire action, so
     * an unknown value is a stale link rather than an attack.
     *
     * **The default is the caller's, because the two presence lists want different ones.** A
     * session that has gone explains a live problem -- a task sitting held is explained by the
     * process that ended, which is what #75 decided it should be listed for -- so sessions default
     * to `All`. A lock that was released explains nothing; its row is kept for the fence it
     * carries, and those accumulate forever, so locks default to `Live`.
     *
     * @param  string|null  $value  Whatever the client sent.
     * @param  self  $default  What to read with when the client named nothing usable.
     * @return self The scope to read with.
     */
    public static function orDefault(?string $value, self $default): self
    {
        return $value === null ? $default : (self::tryFrom($value) ?? $default);
    }
}
