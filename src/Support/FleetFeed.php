<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;

/**
 * Reads the change feed for one agent session, applying the visibility rule decided in #29.
 *
 * **Narration is the restricted kind that matters most, and not the only one.** An agent reads
 * narration from its own developer's sessions, from any session that held `coordinator:direct` when
 * it posted, and from any session that addressed it by name or through a task it holds (#315).
 * `lane.quiet` and `lane.condition` reach only their addressees, and `placement.instruction` its
 * addressee and its poster's developer -- the one coordinator post the coordinator flag does not
 * broadcast (#331). State changes and directives reach everyone, because they describe the fleet
 * rather than one agent's opinion of it.
 *
 * The reason the rule is worth this much care: task, event, and directive content is untrusted
 * input to an agent that may have shell access. Narrowing whose words reach whom is what keeps one
 * developer's agent from putting instructions in front of another's.
 */
final class FleetFeed
{
    /**
     * The most events one read returns, whatever the caller asks for.
     */
    public const int MAX_PAGE = 200;

    /**
     * The most event ids one read may examine, whatever it finds among them.
     *
     * **This is what keeps one call's cost independent of the fleet's traffic.** The read walks
     * forward from a cursor through ids it may not be allowed to see, and without a ceiling a
     * reader whose developers are quiet would scan further the busier everyone else is -- which any
     * holder of `events:post` could arrange for the whole fleet.
     *
     * A thousand, because the page has to be reachable. robot-council/core#62 measured a reader
     * holding 5% of a million-event feed seeing 65 of every 200 ids -- roughly a third, since state
     * changes and directives reach everyone -- so filling a 200-row page needs about 600 ids
     * examined. A cap at the page size is what produced the four round trips this replaces.
     */
    public const int EXAMINE_CAP = 1000;

    /**
     * @param  AgentLogins  $logins  Who each session belongs to, as the fleet reads provenance.
     * @param  FeedCursors  $cursors  Where each session has read to.
     */
    public function __construct(
        private readonly AgentLogins $logins,
        private readonly FeedCursors $cursors
    ) {}

    /**
     * Read the events after a cursor that this session may see.
     *
     * **The page is a window of IDs, not a window of results.** Applying the limit after the
     * visibility filter would mean a reader whose window is entirely another developer's narration
     * gets an empty page and a cursor that cannot move -- so every later poll rescans the same
     * growing tail, for as long as the feed lives. Any holder of `events:post` could arrange that
     * for the whole fleet by narrating. Paging the ID space instead means a page may be short, or
     * empty, but the cursor always advances.
     *
     * **A missing cursor resumes, and a supplied one is an acknowledgement.** Both agent-facing
     * surfaces default `after` to null rather than to zero, and null means the position this
     * session last acknowledged -- so an agent that omits the argument reads on from where it was
     * instead of walking the entire history, which is what both of them used to do. A supplied
     * cursor says the reader processed everything through it, and moves the stored position
     * forward; it can never move it back, so re-reading old history is free of consequence (#86).
     *
     * The acknowledgement is written after the page is read, so a read that throws records
     * nothing.
     *
     * **A reader that follows the feed on the agent's behalf reads without acknowledging (#354).**
     * The stored position is the AGENT's: what its no-argument read resumes from. A bridge that
     * polls every few seconds and acknowledged as it went would move that position past events the
     * agent was never shown -- a task placed on it included -- so its reads pass
     * `$acknowledge = false`, keep their own position in memory, and leave the agent's alone.
     *
     * @param  AgentSession  $reader  The session doing the reading.
     * @param  int|null  $after  The last event ID the reader has seen, or null to resume.
     * @param  int  $limit  The most visible events to return; how far a read looks is `EXAMINE_CAP`.
     * @param  bool  $acknowledge  Whether a supplied `after` moves the stored position.
     * @return array{events: list<array<string, mixed>>, cursor: int} The visible events and where
     *                                                                to read from next.
     */
    public function after(AgentSession $reader, ?int $after, int $limit, bool $acknowledge = true): array
    {
        $from = $after ?? $this->cursors->of($reader);

        $page = max(1, min($limit, self::MAX_PAGE));

        $examined = $this->examinedWindow($from);

        // **One statement, and the join is what makes the empty page work.** The marker is a
        // single row carrying how far the window reached, and the visible events hang off it by a
        // left join -- so a reader with nothing visible in a long run of other developers'
        // narration still gets a row back saying where the read stopped, and its cursor moves.
        // Carrying that figure on the event rows instead would lose it in exactly the case it
        // exists for, because there are no event rows then.
        $rows = DB::query()
            ->fromSub(
                DB::query()->fromSub($examined, 'counted')->selectRaw('max(counted.id) as examined_to'),
                'marker'
            )
            ->leftJoinSub(
                $this->visibleWithin($examined, $reader, $page),
                'visible',
                // A cross join written as a left join, because a cross join with nothing on the
                // right returns nothing, which is the row this needs most
                fn (JoinClause $join): JoinClause => $join->on(DB::raw('1'), '=', DB::raw('1'))
            )
            ->select('marker.examined_to', 'visible.*')
            ->orderBy('visible.id')
            ->get();

        $first = $rows->first();

        // `?? null` rather than a `property_exists` guard: the row is a `stdClass`, which the
        // analyzer treats as carrying any property, so the guard is reported as dead code
        $reached = $first !== null && is_numeric($first->examined_to ?? null)
            ? (int) $first->examined_to
            : null;

        // The marker row carries a null `id` when nothing in the window was visible, which is the
        // row that exists so the cursor can still move. It is not an event and is dropped here.
        $found = $rows
            ->filter(fn (object $row): bool => ($row->id ?? null) !== null)
            ->map(fn (object $row): array => (array) $row)
            ->values();

        $events = FleetEvent::hydrate($found->all());

        // Both columns in one lookup. `user_id` is who the event is about and `actor_user_id` is
        // who made it happen, and an administrative event has two different developers in them.
        $logins = $this->logins->forUsers(array_merge(
            $events->pluck('user_id')->all(),
            $events->pluck('actor_user_id')->all()
        ));

        /** @var list<array<string, mixed>> $described */
        $described = $events->map(fn (FleetEvent $event): array => $this->describe($event, $logins))->values()->all();

        // What the reader told us it had processed, not where this page reached. Advancing to the
        // latter would skip a page that was returned and never arrived; this way that page is
        // simply unacknowledged, and comes again.
        if ($acknowledge) {
            $this->cursors->acknowledge($reader, $from);
        }

        return ['events' => $described, 'cursor' => $this->cursor($described, $page, $reached, $from)];
    }

    /**
     * The ids this read is allowed to look at: everything after the cursor, up to the cap.
     *
     * @param  int  $after  The last event ID the reader has already seen.
     * @return QueryBuilder The capped window.
     */
    private function examinedWindow(int $after): QueryBuilder
    {
        return DB::table(new FleetEvent()->getTable())
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::EXAMINE_CAP);
    }

    /**
     * The events inside that window this reader may see, up to one page of them.
     *
     * @param  QueryBuilder  $examined  The capped window.
     * @param  AgentSession  $reader  The session doing the reading.
     * @param  int  $page  How many visible events to return.
     * @return QueryBuilder The page.
     */
    private function visibleWithin(QueryBuilder $examined, AgentSession $reader, int $page): QueryBuilder
    {
        return DB::query()
            ->fromSub($examined, 'window')
            ->where(function (QueryBuilder $query) use ($reader): void {
                // Everything that is not narration, plus the narration this reader may see
                // Asked of the enum rather than hardcoded, so a later restricted type is
                // restricted by declaring itself so rather than by somebody remembering to edit
                // this clause. Getting that wrong fails open.
                //
                // The third branch reads the event's OWN `user_id`, recorded when it was written,
                // rather than asking which live session holds its `agent_session_id` now. There is
                // no foreign key on that column (#50), so a deleted session's id can be reissued to
                // a different developer -- and a subquery against the live table would then serve
                // that developer this event. The rule is about who posted, which is a fact from the
                // past, so it is decided from what the past recorded.
                //
                // The fourth branch is addressing (#315): a narration that named this reader. It
                // matches the addressee's developer as well as its session id, both recorded when
                // the narration was posted, because session ids are reused -- a match on the id
                // alone would hand a dead session's mail to whoever holds its id now.
                $query->whereNotIn('type', FleetEventType::restrictedValues())
                    // A coordinator's post reaches everyone -- except a type that stays addressed
                    // even when a coordinator posts it (#331)
                    ->orWhere(fn (QueryBuilder $coordinator): QueryBuilder => $coordinator
                        ->where('posted_with_coordinator', true)
                        ->whereNotIn('type', FleetEventType::addressedOnlyValues()))
                    ->orWhere('user_id', $reader->user_id)
                    ->orWhereExists(fn (QueryBuilder $addressed): QueryBuilder => $addressed
                        ->select(DB::raw(1))
                        ->from(FleetEvents::ADDRESSEE_TABLE)
                        ->whereColumn(FleetEvents::ADDRESSEE_TABLE.'.event_id', 'window.id')
                        ->where(FleetEvents::ADDRESSEE_TABLE.'.agent_session_id', $reader->id)
                        ->where(FleetEvents::ADDRESSEE_TABLE.'.user_id', $reader->user_id));
            })
            ->orderBy('id')
            ->limit($page);
    }

    /**
     * How far this read got, which is what the reader asks from next time.
     *
     * **A full page stops at its last row, and a short one stops at the cap.** When the page filled,
     * the window may still hold visible events above the last one returned, so the cursor cannot
     * claim them; when it did not, every id up to the cap was examined and decided, so the cursor
     * takes all of them. Reporting the last returned id in both cases is the bug this reshape
     * exists to remove, and it would be invisible -- the events returned would be identical, and
     * only the poll after the next one would show the reader rescanning a tail it cannot see.
     *
     * @param  list<array<string, mixed>>  $described  The events being returned.
     * @param  int  $page  The page size that was asked for.
     * @param  int|null  $reached  The highest id examined, or null when the feed had nothing after
     *                             the cursor.
     * @param  int  $after  Where the read started.
     * @return int Where to read from next.
     */
    private function cursor(array $described, int $page, ?int $reached, int $after): int
    {
        if ($reached === null) {
            // Nothing at all after the cursor: a reader that is caught up keeps its place rather
            // than being sent back to the start
            return $after;
        }

        if (\count($described) < $page) {
            return $reached;
        }

        $last = $described[\count($described) - 1]['id'] ?? null;

        return \is_int($last) ? $last : $reached;
    }

    /**
     * The most recent events, newest first, with nothing filtered out.
     *
     * **Named rather than flagged, and taking no session, deliberately.** #29's filter shows
     * narration only to its own developer's sessions and to sessions that held `coordinator:direct`
     * when they posted; the decision on robot-council/core#73 waives that for a signed-in developer,
     * who is GitHub-authenticated and on the access list and is reading a dashboard rather than
     * taking instructions from it. That decision records this as a privacy call rather than a
     * security one, and as the first thing to revisit if the fleet ever spans parties who should not
     * read each other's narration.
     *
     * A `bool $unfiltered` on `after()` would be the shape later passed `true` from an agent path by
     * mistake. This cannot be reached by an agent-facing call at all.
     *
     * The order is the opposite of `after()`'s, and that is the point rather than an oversight: an
     * agent reads forward from where it left off, and a person reads the top of the page.
     *
     * The caller is responsible for having established that the reader is an allowlisted developer.
     * Nothing here checks it, because nothing here can.
     *
     * `$before` walks backwards into older events, which is the direction a person reads a stream.
     * Without it the panel would show a fixed window of the head and everything older would be
     * unreachable -- and a feed is not a list: what falls out of the window is gone rather than
     * merely unsorted. One presence sweep over a hundred lapsed sessions writes a hundred events in
     * a burst, so the window turns over quickly enough for that to matter.
     *
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @param  int|null  $before  The oldest event already seen, or null for the head.
     * @return list<array<string, mixed>> The events, newest first.
     */
    public function latest(int $limit, ?int $before = null): array
    {
        $events = FleetEvent::query()
            ->when($before !== null, fn (Builder $query) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(max(1, min($limit, self::MAX_PAGE)))
            ->get();

        // Both columns in one lookup. `user_id` is who the event is about and `actor_user_id` is
        // who made it happen, and an administrative event has two different developers in them.
        $logins = $this->logins->forUsers(array_merge(
            $events->pluck('user_id')->all(),
            $events->pluck('actor_user_id')->all()
        ));

        return array_values($events->map(fn (FleetEvent $event): array => $this->describe($event, $logins))->all());
    }

    /**
     * One event as a reader sees it, with the provenance the fleet decides trust on.
     *
     * @param  FleetEvent  $event  The event.
     * @param  array<string, string>  $logins  GitHub logins, keyed by host user key.
     * @return array<string, mixed> The event.
     */
    private function describe(FleetEvent $event, array $logins): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'body' => $event->body,
            'meta' => $event->meta,
            'created_at' => $event->created_at?->toIso8601String(),

            // Derived by the server on every read, never taken from what the poster claimed.
            //
            // **`actor` is whoever the event is ABOUT**, which for everything a session records is
            // the same as who posted it. For an administrative event there is no session and this
            // is the installation's owner -- the developer whose agent was affected -- with the
            // admin who did it in `performed_by` beside it (#115).
            'actor' => [
                'session_id' => $event->agent_session_id,

                // Keyed by the event's own `user_id` rather than by its session id, so a session
                // row that has gone -- or whose id now belongs to somebody else -- cannot put the
                // wrong developer's name on what somebody else said
                'github_login' => $event->user_id === null
                    ? null
                    : ($logins[$event->user_id] ?? null),
                'coordinator_direct' => $event->posted_with_coordinator,
            ],

            // Null for everything a process, a sweep or a console command records, which is most of
            // the feed. Present where a signed-in developer changed an installation's
            // authorization -- usually somebody else's, which is the case "by whom" exists for,
            // but not always: `Installations::createFrom()` revokes the installation a
            // re-enrollment supersedes and passes the approver, who is that installation's own
            // owner. So this can equal `actor`, and a reader should not take a value here as
            // meaning two different people were involved.
            'performed_by' => $event->actor_user_id === null
                ? null
                : ['github_login' => $logins[$event->actor_user_id] ?? null],
        ];
    }
}
