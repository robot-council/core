<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * Which panels the dashboard mounts.
 *
 * **The selection arrives from the query string, so it is requester-supplied and is treated as
 * such.** Nothing here trusts the value: it is split, matched against a fixed list, and anything
 * unrecognized is dropped rather than rejected, because a link somebody shared after a section was
 * renamed should still show the sections that do exist rather than erroring.
 *
 * **The admin panel cannot be selected into existence.** An account that is not an admin has it
 * filtered out here whatever the query string says -- and `Livewire\Administration` authorizes its
 * own mount, render and every action regardless, because a panel that is merely not drawn is not
 * an authorization boundary. This decides what is *offered*.
 *
 * An empty or absent selection means every section, so a developer who has chosen nothing sees
 * what this package has always shown rather than an empty page.
 *
 * A class of its own for the reason `HostKey`, `ProjectId` and `AggregateCount` are: two callers
 * need the same answer -- the component that mounts the panels and the shell that lists them -- and
 * a second copy of the rule is how the two come to disagree.
 */
final class DashboardSections
{
    /**
     * Every section, in the order the page renders them.
     *
     * The keys are the package's own vocabulary rather than class names: `resources/views` is the
     * only Tailwind source, so a class name written in PHP reaches no stylesheet, and
     * `EscapingGuardTest` refuses one under `src/`.
     */
    public const array ALL = ['presence', 'queue', 'feed', 'administration'];

    /**
     * The section only an admin may be offered.
     */
    public const string ADMIN_ONLY = 'administration';

    /**
     * The sections to mount, from whatever the query string carried.
     *
     * @param  string|null  $selection  The comma-separated value, or null when none was given.
     * @param  bool  $isAdmin  Whether the signed-in developer holds the admin ability.
     * @return list<string> The sections to mount, in the page's own order, never empty.
     */
    public static function from(?string $selection, bool $isAdmin): array
    {
        $offered = self::offered($isAdmin);

        if ($selection === null || trim($selection) === '') {
            return $offered;
        }

        $asked = array_map(trim(...), explode(',', $selection));

        // Filtered against the offered list rather than the full one, so a non-admin naming the
        // admin panel gets everything else rather than an error or a panel they may not have
        $chosen = array_values(array_filter($offered, static fn (string $section): bool => \in_array($section, $asked, true)));

        // Nothing recognized means the link was for a page this version no longer has. Showing
        // everything is the reading that loses nobody a panel.
        return $chosen === [] ? $offered : $chosen;
    }

    /**
     * Every section this developer may be offered.
     *
     * @param  bool  $isAdmin  Whether the signed-in developer holds the admin ability.
     * @return list<string> The offered sections, in the page's own order.
     */
    public static function offered(bool $isAdmin): array
    {
        if ($isAdmin) {
            return self::ALL;
        }

        // Rebuilt rather than filtered. `array_filter()` leaves a gap wherever the dropped key sat,
        // and `array_values()` closing it reads as redundant to the analyzer **only because the
        // admin key happens to be last today** -- so the filter form is correct by coincidence and
        // would break silently the first time somebody reorders the list above.
        $offered = [];

        foreach (self::ALL as $section) {
            if ($section !== self::ADMIN_ONLY) {
                $offered[] = $section;
            }
        }

        return $offered;
    }

    /**
     * The selection as the query string carries it.
     *
     * @param  list<string>  $sections  The sections to encode.
     * @return string The comma-separated value.
     */
    public static function toQuery(array $sections): string
    {
        return implode(',', $sections);
    }
}
