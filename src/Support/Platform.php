<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use InvalidArgumentException;

/**
 * The operating system and architecture a session runs on, reported by the bridge (#351).
 *
 * **Reported by the bridge at session start, never typed in**: the bridge already branches on
 * `PHP_OS_FAMILY`, so the value exists at the one place that knows it for certain. It is information
 * for a coordinator placing platform-bound work, and nothing refuses a placement on it.
 *
 * Both values reach every agent in the fleet through `session.joined`, so both are bounded here as
 * well as at the endpoint, for the reason `WorkIdentity` gives: this is a public class a host can
 * resolve and call, and a rule in a controller protects the endpoint and nothing else.
 */
final class Platform
{
    /**
     * The OS families a session may report: PHP's `PHP_OS_FAMILY` values, exactly.
     *
     * @var list<string>
     */
    public const array OS_FAMILIES = ['Windows', 'Darwin', 'Linux', 'BSD', 'Solaris', 'Unknown'];

    /**
     * The longest architecture a session may report, in characters -- the column's width.
     */
    public const int MAX_ARCH = 32;

    /**
     * What an architecture may contain: `arm64`, `x86_64`, `AMD64`, `aarch64`.
     */
    public const string ARCH = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/D';

    /**
     * Refuse an OS family or architecture outside its bound. Both may be null, or the architecture alone.
     *
     * The arguments are `mixed` for the reason `WorkIdentity::ensure()` records.
     *
     * @param  mixed  $osFamily  The OS family the caller is asking to store.
     * @param  mixed  $arch  The architecture the caller is asking to store.
     *
     * @throws InvalidArgumentException When either is present and outside its bound.
     */
    public static function ensure(mixed $osFamily, mixed $arch): void
    {
        if ($osFamily !== null && ! \in_array($osFamily, self::OS_FAMILIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'An OS family is one of %s.',
                implode(', ', self::OS_FAMILIES)
            ));
        }

        // The endpoint's rule, held here too: an architecture with no OS family is a report nobody
        // could have made, since the bridge reads both from the same place
        if ($arch !== null && $osFamily === null) {
            throw new InvalidArgumentException('An architecture is reported with its OS family.');
        }

        if ($arch !== null && (! \is_string($arch) || preg_match(self::ARCH, $arch) !== 1)) {
            throw new InvalidArgumentException(sprintf(
                'An architecture is up to %d characters of [A-Za-z0-9._-], starting with a letter or digit.',
                self::MAX_ARCH
            ));
        }
    }
}
