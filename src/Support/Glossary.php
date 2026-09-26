<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RuntimeException;

/**
 * The dashboard's vocabulary, read out of the package's translations (#402).
 *
 * Each page names the terms it shows and renders their explanations through
 * `partials/glossary.blade.php`. The words themselves are in `resources/lang/en/glossary.php`, which
 * says why they are not here.
 */
final class Glossary
{
    /**
     * The entries for the terms a page names, in the order it names them.
     *
     * **An unknown key throws rather than rendering the key.** `trans()` answers a missing key with
     * the key itself, so a misspelled term would otherwise print `robot-council::glossary.lnae` on
     * the page as though it were an explanation.
     *
     * **English is read when the host's own locales have no entry.** `trans()` tries only the
     * application's locale and its fallback, so a host running `de` with a `de` fallback would find
     * nothing, and every page would throw. A host that publishes a translation still sees its own.
     *
     * @param  list<string>  $keys  The terms, as keys of the glossary file.
     * @return list<array{key: string, term: string, means: string}> One entry per key.
     *
     * @throws RuntimeException When a key has no entry, or an entry is not two strings.
     */
    public static function entries(array $keys): array
    {
        $entries = [];

        foreach ($keys as $key) {
            $entry = trans('robot-council::glossary.'.$key);

            if (! \is_array($entry)) {
                $entry = trans('robot-council::glossary.'.$key, [], 'en');
            }

            if (! \is_array($entry) || ! \is_string($entry['term'] ?? null) || ! \is_string($entry['means'] ?? null)) {
                throw new RuntimeException(sprintf('The dashboard glossary has no entry for [%s].', $key));
            }

            $entries[] = ['key' => $key, 'term' => $entry['term'], 'means' => $entry['means']];
        }

        return $entries;
    }
}
