<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Validation\ValidationException;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Task;

/**
 * Who a narration is addressed to, resolved from the session ids and task ids a poster named (#315).
 *
 * **Addressing widens who reads a narration, which is what makes it a security boundary.** #29
 * restricts narration to its author's own developer's sessions; a session named here also reads it,
 * whatever developer it belongs to. So every id is resolved to a row and the row's own key is what
 * gets recorded, exactly as `DirectiveTargets` does -- an id that resolved to nothing never reaches
 * the recorded list, so there is no path where an unknown id is written.
 *
 * **A task names whoever holds it at post time.** That is what lets a CI session hand work back to
 * a build session belonging to somebody else: it knows the task behind a pull request, not the
 * session id, and a session id does not survive a restart while a reassigned task follows the new
 * one. The task is read without a lock, so a reassignment committing in the same instant can leave
 * the narration with the previous holder; the event records which session each task resolved to, so
 * that is visible rather than silent.
 *
 * Shared by the HTTP controller and the MCP tool, for the reason `DirectiveTargets` gives: two copies
 * of a rule that decides a security-relevant field are two places for it to drift.
 */
final class NarrationAddressees
{
    /**
     * How many sessions, and separately how many tasks, one narration may name.
     *
     * The same bound `directive_post` puts on `targets`, and for the same reason: past this a
     * message is for the fleet, which a coordinator's directive already is.
     */
    public const int MAX = DirectiveTargets::MAX;

    /**
     * Resolve what a poster named into the sessions to address.
     *
     * **Takes `mixed` and holds its own bounds**, rather than trusting the validators in front of
     * it, because it is a public method on a `final` class a host can resolve and call.
     *
     * @param  mixed  $sessions  The session ids named under `to`.
     * @param  mixed  $tasks  The task ids named under `to_tasks`.
     * @param  AgentSession  $poster  The session posting the narration.
     * @param  bool  $asCoordinator  Whether the poster holds `coordinator:direct`.
     * @return array{sessions: list<AgentSession>, tasks: list<array{task_id: int, session_id: int}>}
     *                                                                                                The addressed sessions, deduplicated and ordered by id, and which session each task
     *                                                                                                resolved to.
     *
     * @throws ValidationException When too many are named, when an id is not a whole number, or when
     *                             an id names nothing that can be reached.
     */
    public static function resolve(mixed $sessions, mixed $tasks, AgentSession $poster, bool $asCoordinator): array
    {
        $sessionIds = self::ids($sessions, 'to', 'sessions');
        $taskIds = self::ids($tasks, 'to_tasks', 'tasks');

        $named = self::sessions($sessionIds);
        $viaTasks = self::holders($taskIds, $poster, $asCoordinator);

        // Keyed by id so a session named directly and through a task is addressed once
        $addressed = [];

        foreach ([...$named, ...array_column($viaTasks, 'session')] as $session) {
            $addressed[$session->id] = $session;
        }

        ksort($addressed);

        return [
            'sessions' => array_values($addressed),
            'tasks' => array_map(
                static fn (array $resolved): array => ['task_id' => $resolved['task_id'], 'session_id' => $resolved['session']->id],
                $viaTasks
            ),
        ];
    }

    /**
     * The narration's `meta`: what the client sent, and who the server resolved it to.
     *
     * **An unaddressed narration gains no key**, so its `meta` is exactly what it was before #315.
     * The addressees sit beside `client` rather than inside it, so nothing a caller sent can be
     * mistaken for something the server derived.
     *
     * @param  array<array-key, mixed>|null  $client  What the caller sent under `meta`.
     * @param  array{sessions: list<AgentSession>, tasks: list<array{task_id: int, session_id: int}>}  $resolved
     *                                                                                                            What `resolve()` returned.
     * @return array<string, mixed> The meta to record.
     */
    public static function meta(?array $client, array $resolved): array
    {
        return array_filter([
            'client' => $client === [] ? null : $client,
            'to' => $resolved['sessions'] === []
                ? null
                : array_map(static fn (AgentSession $session): int => $session->id, $resolved['sessions']),
            'to_tasks' => $resolved['tasks'] === [] ? null : $resolved['tasks'],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The whole numbers a poster supplied under one field, bounded and deduplicated.
     *
     * @param  mixed  $supplied  What the poster sent.
     * @param  string  $field  The field's name, for the refusal.
     * @param  string  $noun  What the ids name, for the refusal.
     * @return list<int> The ids, in the order first named.
     *
     * @throws ValidationException When too many are named or any is not a whole number.
     */
    private static function ids(mixed $supplied, string $field, string $noun): array
    {
        if (! \is_array($supplied) || $supplied === []) {
            return [];
        }

        if (\count($supplied) > self::MAX) {
            throw ValidationException::withMessages([
                $field => sprintf('The %s field may not name more than %d %s.', $field, self::MAX, $noun),
            ]);
        }

        $ids = [];

        foreach ($supplied as $id) {
            // `filter_var` rather than `is_int`, because that is what Laravel's `integer` rule
            // tests, so a value the validator admitted cannot be refused here
            $integer = filter_var($id, FILTER_VALIDATE_INT);

            if ($integer === false || $integer < 1) {
                throw ValidationException::withMessages([
                    $field => sprintf('The %s field must name %s by id.', $field, $noun),
                ]);
            }

            $ids[] = $integer;
        }

        return array_values(array_unique($ids));
    }

    /**
     * The named sessions, every one of which must still be reachable.
     *
     * @param  list<int>  $ids  The session ids.
     * @return list<AgentSession> The sessions.
     *
     * @throws ValidationException When any names no session, or one that has gone.
     */
    private static function sessions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = AgentSession::query()->whereKey($ids)->get()->keyBy('id');

        $reachable = [];
        $unreachable = [];

        foreach ($ids as $id) {
            $session = $found->get($id);

            // The same predicate a reassignment and a directive's targets use: a session that has
            // gone will never read another page, so addressing it would record a reader that does
            // not exist
            if ($session instanceof AgentSession && TaskList::canBeAssigned($session)) {
                $reachable[] = $session;
            } else {
                $unreachable[] = $id;
            }
        }

        if ($unreachable !== []) {
            throw ValidationException::withMessages([
                'to' => sprintf('The to field names sessions that are unknown or have gone: %s.', implode(', ', $unreachable)),
            ]);
        }

        return $reachable;
    }

    /**
     * The session holding each named task now.
     *
     * @param  list<int>  $ids  The task ids.
     * @param  AgentSession  $poster  The session posting.
     * @param  bool  $asCoordinator  Whether the poster holds `coordinator:direct`.
     * @return list<array{task_id: int, session: AgentSession}> Each task and its holder.
     *
     * @throws ValidationException When a task is unknown, unreadable to the poster, or has no live
     *                             holder.
     */
    private static function holders(array $ids, AgentSession $poster, bool $asCoordinator): array
    {
        if ($ids === []) {
            return [];
        }

        $tasks = Task::query()->whereKey($ids)->with('claimant')->get()->keyBy('id');

        $resolved = [];
        $refused = [];

        foreach ($ids as $id) {
            $task = $tasks->get($id);

            // A task this session may not read is one it has no business addressing: its holder
            // belongs to a developer whose work this session was never shown. The audience is
            // `TaskList::page()`'s -- the task's own developer, or a coordinator.
            if (! $task instanceof Task || ! $asCoordinator && ! $task->isClaimableBy($poster)) {
                $refused[] = $id;

                continue;
            }

            $holder = $task->status->isHeld() ? $task->claimant : null;

            if (! $holder instanceof AgentSession || ! TaskList::canBeAssigned($holder)) {
                $refused[] = $id;

                continue;
            }

            $resolved[] = ['task_id' => $task->id, 'session' => $holder];
        }

        if ($refused !== []) {
            throw ValidationException::withMessages([
                'to_tasks' => sprintf(
                    'The to_tasks field names tasks that are unknown, not readable to this session, or held by no live session: %s.',
                    implode(', ', $refused)
                ),
            ]);
        }

        return $resolved;
    }
}
