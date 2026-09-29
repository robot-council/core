<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Extra search qualifiers for the backlog fetch's count, by owner or by repository (#488).
 *
 * A host that plans its work in a GitHub Project reads the meter as "how much work is left", and an
 * unfiltered `repo:OWNER/NAME is:issue is:open` counts issues outside that project too.
 * `robot-council.backlog.search_qualifiers` maps an owner (`UAMS-Web`) or a repository
 * (`UAMS-Web/uams-statamic`) to qualifiers the fetch appends, such as `project:UAMS-Web/1`. A
 * repository's entry replaces its owner's, an empty one included, and with neither the query is
 * exactly what it was. Keys are compared without case, as GitHub compares logins.
 *
 * The key takes an array, or the string an environment variable can carry:
 * `UAMS-Web=project:UAMS-Web/1;UAMS-Web/site=label:web`, entries separated by `;` and each key
 * from its qualifiers by the first `=`.
 *
 * **Qualifiers that would widen or redefine the count are refused, not appended.** GitHub ORs
 * repeated `repo:`, `org:` and `user:` terms, so one of those would add another repository's issues
 * to this one's meter; `OR`, `AND`, `NOT` and parentheses could do the same by regrouping the
 * query; and `is:`, `type:` and `state:` are what the fetch already says. A refused
 * entry is not dropped either, because dropping it would store the unfiltered count as if it were
 * the filtered one: its repositories record `qualifiers invalid` and no reading, and doctor fails.
 */
final class BacklogQualifiers
{
    /**
     * The longest qualifier string accepted, in characters.
     *
     * GitHub's search refuses a query longer than 256 characters, and the fetch's own part is at
     * most `repo:` and a 140-character repository, then ` is:issue is:open`.
     */
    public const int MAX_LENGTH = 90;

    /**
     * Qualifiers the fetch owns, or that GitHub ORs across terms and so would widen the count.
     */
    public const string RESERVED = '/(?:^|\s)-?(?:repo|org|user|is|type|state):/i';

    /**
     * A qualifier that names a GitHub Project, which a token needs projects read to resolve.
     */
    public const string PROJECT = '/(?:^|\s)-?project:/i';

    /**
     * @param  Repository  $config  Where the map is configured.
     */
    public function __construct(
        private readonly Repository $config
    ) {}

    /**
     * Why a qualifier string cannot be appended, if it cannot.
     *
     * @param  string  $qualifiers  The qualifiers, as configured.
     * @return string|null The reason, or null when they can be appended.
     */
    public static function refusal(string $qualifiers): ?string
    {
        if (mb_strlen($qualifiers) > self::MAX_LENGTH) {
            return sprintf('longer than %d characters', self::MAX_LENGTH);
        }

        // Printable ASCII only: nothing that could end a line or hide in a log
        if (preg_match('/^[\x20-\x7E]*$/D', $qualifiers) !== 1) {
            return 'not printable ASCII';
        }

        if (preg_match(self::RESERVED, $qualifiers) === 1) {
            return "repo:, org:, user:, is:, type: and state: are the fetch's own";
        }

        // GitHub's boolean operators and grouping could put an OR around the fetch's own terms, so
        // that a qualifier matched issues outside the repository
        if (preg_match('/(?:^|\s)(?:OR|AND|NOT)(?:\s|$)|[()]/', $qualifiers) === 1) {
            return 'OR, AND, NOT and parentheses could widen the count past the repository';
        }

        return null;
    }

    /**
     * The qualifiers in effect for one repository.
     *
     * @param  string  $repository  `owner/name`.
     * @return string|null The qualifiers, `''` for none, or null when the entry in effect is refused.
     */
    public function for(string $repository): ?string
    {
        $entries = $this->entries();
        $key = mb_strtolower($repository);

        $qualifiers = $entries[$key] ?? $entries[mb_strtolower(explode('/', $repository, 2)[0])] ?? '';

        return self::refusal($qualifiers) === null ? $qualifiers : null;
    }

    /**
     * The entry configured under one owner or repository itself, as written.
     *
     * @param  string  $key  An owner's login, or `owner/name`.
     * @return string|null Its qualifiers, `''` for an empty entry, or null when there is no entry.
     */
    public function entry(string $key): ?string
    {
        return $this->entries()[mb_strtolower($key)] ?? null;
    }

    /**
     * An entry as doctor shows it: the qualifiers, and why they are refused if they are.
     *
     * @param  string  $qualifiers  The entry.
     * @return string The description.
     */
    public static function describe(string $qualifiers): string
    {
        if ($qualifiers === '') {
            return 'searched with no extra qualifiers';
        }

        $refusal = self::refusal($qualifiers);

        return $refusal === null
            ? sprintf('searched with `%s`', $qualifiers)
            : sprintf('search qualifiers refused, %s', $refusal);
    }

    /**
     * The configured map, keyed in lower case, each value with its whitespace collapsed.
     *
     * Anything that is not a string key with a string value is left out, because it cannot name an
     * owner or say what to append.
     *
     * @return array<string, string> Qualifiers by owner or repository.
     */
    private function entries(): array
    {
        $configured = $this->config->get('robot-council.backlog.search_qualifiers');

        if (\is_string($configured)) {
            $configured = self::parse($configured);
        }

        if (! \is_array($configured)) {
            return [];
        }

        $entries = [];

        foreach ($configured as $key => $qualifiers) {
            if (! \is_string($key) || ! \is_string($qualifiers) || trim($key) === '') {
                continue;
            }

            $entries[mb_strtolower(trim($key))] = trim((string) preg_replace('/[ \t]+/', ' ', $qualifiers));
        }

        return $entries;
    }

    /**
     * The map from its environment-variable form.
     *
     * @param  string  $value  `key=qualifiers;key=qualifiers`.
     * @return array<string, string> Qualifiers by key.
     */
    private static function parse(string $value): array
    {
        $entries = [];

        foreach (explode(';', $value) as $entry) {
            if (! str_contains($entry, '=')) {
                continue;
            }

            [$key, $qualifiers] = explode('=', $entry, 2);
            $entries[trim($key)] = $qualifiers;
        }

        return $entries;
    }
}
