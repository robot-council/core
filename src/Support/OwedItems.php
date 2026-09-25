<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\GithubIdentity;

/**
 * What the fleet is waiting on each developer for (#335).
 *
 * **An item always names a party the fleet can resolve.** A developer is named by GitHub login and
 * refused unless one of the fleet's identities signs in as it; an item for nobody in particular is
 * `General`. #314 recorded items on its board that fell into a bucket where they stopped being
 * anyone's, so an item whose developer later leaves the fleet stops rendering rather than moving to
 * `General` -- `open()` drops it.
 *
 * The question and why it matters are a coordinator's prose, shown to developers on the dashboard
 * and escaped there. They are bounded and refused past it rather than shortened, since shortening
 * content changes what it says.
 */
final class OwedItems
{
    /**
     * The longest question, or reason, stored.
     */
    public const int MAX_TEXT = 500;

    /**
     * Record an item.
     *
     * @param  AgentSession  $coordinator  The coordinator recording it.
     * @param  string|null  $developer  A GitHub login, or null for `General`.
     * @param  string  $ticket  The ticket it is about, `owner/name#N`.
     * @param  string  $question  What the developer is asked.
     * @param  string  $why  Why it matters.
     * @return int The item's id.
     *
     * @throws InvalidArgumentException When a value is refused.
     */
    public function record(AgentSession $coordinator, ?string $developer, string $ticket, string $question, string $why): int
    {
        IssueReference::ensure($ticket);

        foreach (['question' => $question, 'why' => $why] as $field => $text) {
            if (trim($text) === '' || mb_strlen($text) > self::MAX_TEXT) {
                throw new InvalidArgumentException(sprintf('A %s is up to %d characters, and not blank.', $field, self::MAX_TEXT));
            }
        }

        $login = $developer === null ? null : $this->known($developer);

        if ($developer !== null && $login === null) {
            throw new InvalidArgumentException(sprintf('No developer in this fleet signs in as `%s`.', $developer));
        }

        return (int) DB::table('robot_council_owed_items')->insertGetId([
            'developer' => $login,
            'ticket' => $ticket,
            'question' => $question,
            'why' => $why,
            'recorded_by' => $coordinator->getKey(),
            'recorded_at' => Carbon::now(),
        ]);
    }

    /**
     * Settle one item.
     *
     * @param  int  $id  The item.
     * @param  string  $because  Why, for the record.
     * @return bool True when it was open.
     */
    public function settle(int $id, string $because = 'coordinator'): bool
    {
        return DB::table('robot_council_owed_items')
            ->where('id', $id)
            ->whereNull('settled_at')
            ->update(['settled_at' => Carbon::now(), 'settled_because' => $because]) === 1;
    }

    /**
     * Settle every open item about a ticket.
     *
     * Compared without case, as GitHub names repositories.
     *
     * @param  string  $ticket  `owner/name#N`.
     * @param  string  $because  Why, for the record.
     * @return int How many it settled.
     */
    public function settleTicket(string $ticket, string $because): int
    {
        return DB::table('robot_council_owed_items')
            ->whereRaw('lower(ticket) = ?', [mb_strtolower($ticket)])
            ->whereNull('settled_at')
            ->update(['settled_at' => Carbon::now(), 'settled_because' => $because]);
    }

    /**
     * The open items, `General` first and then one section per developer, oldest first in each.
     *
     * An item naming a developer the fleet no longer knows is left out, not moved to `General`.
     * A list of sections rather than an array keyed by name, because `General` is also a login
     * somebody can hold, and keying by it would fold that developer's items into the general ones.
     *
     * @return list<array{developer: string|null, items: list<array{id: int, ticket: string, question: string, why: string, recorded_at: Carbon}>}>
     *                                                                                                                                              The sections.
     */
    public function open(): array
    {
        $rows = DB::table('robot_council_owed_items')->whereNull('settled_at')->orderBy('recorded_at')->orderBy('id')->get();

        $known = [];

        foreach (GithubIdentity::query()->pluck('github_login') as $login) {
            if (\is_string($login)) {
                $known[mb_strtolower($login)] = $login;
            }
        }

        $general = [];
        $byDeveloper = [];

        foreach ($rows as $row) {
            $item = [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'ticket' => \is_string($row->ticket) ? $row->ticket : '',
                'question' => \is_string($row->question) ? $row->question : '',
                'why' => \is_string($row->why) ? $row->why : '',
                'recorded_at' => Carbon::parse(\is_string($row->recorded_at) ? $row->recorded_at : 'now'),
            ];

            if ($row->developer === null) {
                $general[] = $item;
            } elseif (\is_string($row->developer) && isset($known[mb_strtolower($row->developer)])) {
                $byDeveloper[$known[mb_strtolower($row->developer)]][] = $item;
            }
        }

        uksort($byDeveloper, static fn (string|int $a, string|int $b): int => strcasecmp((string) $a, (string) $b));

        $sections = $general === [] ? [] : [['developer' => null, 'items' => $general]];

        foreach ($byDeveloper as $developer => $items) {
            $sections[] = ['developer' => $developer, 'items' => $items];
        }

        return $sections;
    }

    /**
     * A login as the fleet spells it, or null.
     *
     * @param  string  $login  What was written.
     * @return string|null The fleet's spelling.
     */
    private function known(string $login): ?string
    {
        if (preg_match(LaneHolds::LOGIN, $login) !== 1) {
            return null;
        }

        $found = GithubIdentity::query()->whereRaw('lower(github_login) = ?', [mb_strtolower($login)])->value('github_login');

        return \is_string($found) ? $found : null;
    }
}
