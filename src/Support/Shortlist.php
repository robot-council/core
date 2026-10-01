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
 * **One set of filters (#530).** A repository with `robot-council.backlog.search_qualifiers` lists
 * only the tickets those qualifiers matched when the backlog fetch last asked GitHub, which is what
 * its meter counts; one whose matches are not known lists nothing, and `report()` says why. One
 * with no qualifiers lists exactly what it did before. `Support\BacklogMembers` decides which.
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
     * @param  BacklogMembers  $members  Which tickets each repository's search qualifiers match.
     */
    public function __construct(
        private readonly PlacementRules $rules,
        private readonly BacklogMembers $members
    ) {}

    /**
     * The shortlist.
     *
     * @return array<string, list<array{ticket: string, title: string, labels: list<string>, mentioned_paths: list<string>, blind_spots: list<string>}>>
     *                                                                                                                                                   By repository, in name order.
     */
    public function read(): array
    {
        return $this->report()['repositories'];
    }

    /**
     * The shortlist, with how each repository's tickets were filtered (#530).
     *
     * `filters` names every repository that has a placeable ticket before the search qualifiers
     * are applied, so a repository whose tickets were all filtered out, or which lists nothing
     * because its filter is unresolved, still says so.
     *
     * @return array{
     *     repositories: array<string, list<array{ticket: string, title: string, labels: list<string>, mentioned_paths: list<string>, blind_spots: list<string>}>>,
     *     filters: array<string, array{status: string, qualifiers: string|null, reason: string|null}>
     * } The tickets and the filters, each by repository in name order.
     */
    public function report(): array
    {
        $held = [];

        foreach (Task::query()->whereIn('status', TaskStatus::values(TaskStatus::held()))->whereNotNull('issue')->pluck('issue') as $issue) {
            if (\is_string($issue)) {
                $held[mb_strtolower($issue)] = true;
            }
        }

        $candidates = [];

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

            $candidates[$item->repository][] = $item;
        }

        $filters = $this->members->filters(array_keys($candidates));
        $shortlist = [];

        foreach ($candidates as $repository => $items) {
            $filter = $filters[$repository];

            // Unresolved lists nothing: the unfiltered tickets are not the ones configured
            if ($filter['status'] === BacklogMembers::UNRESOLVED) {
                continue;
            }

            foreach ($items as $item) {
                if ($filter['status'] === BacklogMembers::FILTERED && ! isset($filter['numbers'][$item->number])) {
                    continue;
                }

                if (\count($shortlist[$repository] ?? []) >= self::MAX_PER_REPOSITORY) {
                    break;
                }

                $shortlist[$repository][] = [
                    'ticket' => $item->reference(),
                    'title' => $item->title,
                    'labels' => $item->labels,
                    'mentioned_paths' => $item->mentioned_paths ?? [],
                    'blind_spots' => $this->blindSpots($item),
                ];
            }
        }

        return [
            'repositories' => $shortlist,
            'filters' => array_map(static fn (array $filter): array => [
                'status' => $filter['status'],
                'qualifiers' => $filter['qualifiers'],
                'reason' => $filter['reason'],
            ], $filters),
        ];
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
