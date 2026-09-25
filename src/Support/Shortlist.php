<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Models\GitHubItem;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;

/**
 * The tickets a coordinator could place, per repository, each with what the service cannot verify
 * about it -- and in no order that implies a choice (#321).
 *
 * **The service shortlists; the coordinator decides.** #314 put choosing which ticket goes to which
 * lane out of the service's scope, and a ranking is a choice, so entries are ordered by repository
 * and number and by nothing a priority could move.
 *
 * **A ticket the placement rules would refuse does not appear** (#320): a closed ticket, and one
 * with an open or unknown blocker. Lane-specific rules -- the lane's repository, whether it is
 * parked or free -- depend on which lane, which the shortlist does not choose either. A ticket a
 * lane already holds does not appear, since it has been placed.
 *
 * **Blind spots are shown, not resolved.** Paths the body mentions are unverified mentions and are
 * never compared: #314 recorded three false collisions in an afternoon from exactly that.
 */
final class Shortlist
{
    /**
     * The most entries listed per repository.
     */
    public const int MAX_PER_REPOSITORY = 100;

    /**
     * @param  PlacementRules  $rules  The placement rules, for what refuses and what warns.
     */
    public function __construct(private readonly PlacementRules $rules) {}

    /**
     * The shortlist.
     *
     * @return array<string, list<array{ticket: string, title: string, labels: list<string>, mentioned_paths: list<string>, blind_spots: list<string>}>>
     *                                                                                                                                                   By repository, in name order.
     */
    public function read(): array
    {
        $held = [];

        foreach (Task::query()->whereIn('status', TaskStatus::values(TaskStatus::held()))->whereNotNull('issue')->pluck('issue') as $issue) {
            if (\is_string($issue)) {
                $held[mb_strtolower($issue)] = true;
            }
        }

        $shortlist = [];

        $open = GitHubItem::query()
            ->where('is_pull_request', false)
            ->where('state', 'open')
            ->orderBy('repository')
            ->orderBy('number')
            ->get();

        foreach ($open as $item) {
            if (isset($held[mb_strtolower($item->reference())]) || $this->rules->blocked($item)) {
                continue;
            }

            if (\count($shortlist[$item->repository] ?? []) >= self::MAX_PER_REPOSITORY) {
                continue;
            }

            $shortlist[$item->repository][] = [
                'ticket' => $item->reference(),
                'title' => $item->title,
                'labels' => $item->labels,
                'mentioned_paths' => $item->mentioned_paths ?? [],
                'blind_spots' => $this->blindSpots($item),
            ];
        }

        return $shortlist;
    }

    /**
     * What the service cannot settle about a ticket, in words.
     *
     * @param  GitHubItem  $item  The ticket.
     * @return list<string> The blind spots.
     */
    private function blindSpots(GitHubItem $item): array
    {
        $spots = [];

        if (\in_array('hitl', $item->labels, true)) {
            $spots[] = 'It is labeled hitl: completing it needs a human decision or action.';
        }

        foreach (self::humanVerbsIn($item->title) as $verb) {
            $spots[] = sprintf('The title says "%s", which needs a human whatever the labels say.', $verb);
        }

        if ($item->checkboxes > 0 && $item->checkboxes_ticked === $item->checkboxes) {
            $spots[] = 'Every acceptance criterion is ticked and the ticket is still open.';
        }

        if (($item->mentioned_paths ?? []) !== []) {
            $spots[] = 'The paths it mentions are unverified: they say nothing about whether it collides with other work.';
        }

        return $spots;
    }

    /**
     * The title words that need a human, as `PlacementRules` names them.
     *
     * @param  string  $title  The title.
     * @return list<string> The words it contains.
     */
    private static function humanVerbsIn(string $title): array
    {
        $lower = mb_strtolower($title);

        return array_values(array_filter(PlacementRules::HUMAN_VERBS, static fn (string $verb): bool => preg_match('/\b'.$verb.'\b/u', $lower) === 1));
    }
}
