{{--
    The queue. Every value here comes from another developer's agent, so nothing is rendered
    unescaped and no agent-supplied value reaches a URL attribute -- the #67 and #70 guards refuse
    both, and this is the page they were written for.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="card-title">Queue</h1>

            {{-- One labelled list rather than a button per status (#480): eight buttons at the 44px
                 target wrap onto two or three rows of a phone. It filters as a status is picked,
                 through the same action the buttons called, so the cursor is dropped and the
                 status stays in the URL. The id carries the component's own, because a host may
                 mount the board twice and a second label would otherwise name the first list. --}}
            <div class="flex items-center gap-2">
                <label for="queue-status-{{ $this->getId() }}" class="font-medium">Status</label>

                <select id="queue-status-{{ $this->getId() }}" data-status-filter wire:change="showStatus($event.target.value)" class="select w-auto">
                    <option value="" @selected($shownStatus === '')>All</option>

                    @foreach ($statuses as $option)
                        <option wire:key="status-{{ $option->value }}" value="{{ $option->value }}" @selected($shownStatus === $option->value)>{{ $option->value }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @include('robot-council::partials.glossary', ['terms' => ['task', 'ticket', 'pending', 'claimed', 'in_progress', 'blocked_task', 'done', 'failed', 'cancelled', 'priority', 'filed_by', 'project', 'held_by_task', 'lane', 'coordinator']])

        {{-- What the unfiltered board is leaving out (#420): finished tasks past their display window,
             by status, each linking to the filter that shows every task in that status. A window is
             not a deletion; `retention.tasks_days` is. --}}
        @if ($hiddenFinished !== [])
            @php($windowFor = fn (int $hours): string => $hours >= 48 && $hours % 24 === 0 ? ($hours / 24).' days' : $hours.' '.($hours === 1 ? 'hour' : 'hours'))
            <p class="max-w-xl text-meta leading-relaxed" data-hidden-finished>
                Hidden, {{ array_sum($hiddenFinished) === 1 ? 'a finished task past its' : 'finished tasks past their' }} display window:
                @foreach ($hiddenFinished as $hiddenStatus => $hiddenCount)
                    <a wire:key="hidden-{{ $hiddenStatus }}" href="{{ route('robot-council.queue', ['status' => $hiddenStatus]) }}" class="link">{{ $hiddenCount }} {{ $hiddenStatus }}<span class="sr-only"> {{ $hiddenCount === 1 ? 'task' : 'tasks' }}, show every {{ $hiddenStatus }} task</span></a>{{ $loop->last ? '' : ($loop->remaining === 1 ? ' and' : ',') }}
                @endforeach
                ({{ collect($hiddenFinished)->keys()->map(fn (string $hiddenStatus): string => $hiddenStatus.' after '.$windowFor($finishedWindows[$hiddenStatus] ?? 0))->implode(', ') }}).
                Choose one to see every task in that status.
            </p>
        @endif

        @if ($tasks === [])
            <p class="mx-auto max-w-xl py-6 text-center opacity-80">
                @if ($afterId !== null)
                    End of the queue: nothing comes after this page. Choose First page to go back.
                @elseif ($shownStatus !== '')
                    No {{ $shownStatus }} tasks. Choose All to see every task.
                @elseif ($hiddenFinished !== [])
                    Nothing open: every task still on the queue is finished and past its display window.
                @else
                    Queue empty: the fleet has not been asked to do anything yet.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-stack" role="table">
                    <thead role="rowgroup">
                        <tr role="row">
                            <th role="columnheader">Task</th>
                            <th role="columnheader">Status</th>
                            <th role="columnheader">Priority</th>
                            <th role="columnheader">Filed by</th>
                            <th role="columnheader">Held by</th>
                            <th role="columnheader">Age</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup">
                        @foreach ($tasks as $task)
                            <tr role="row" wire:key="task-{{ $task['id'] }}">
                                <td role="cell" data-label="Task">
                                    <div class="font-medium" data-task-title><x-robot-council::agent-text :text="$task['title']" inline /></div>

                                    @if ($task['project_id'])
                                        <div class="text-meta opacity-80"><code>{{ $task['project_id'] }}</code></div>
                                    @endif
                                </td>

                                <td role="cell" data-label="Status" class="2xl:whitespace-nowrap"><span class="badge badge-sm">{{ $task['status'] }}</span></td>

                                <td role="cell" data-label="Priority" class="2xl:whitespace-nowrap">{{ $task['priority'] }}</td>

                                <td role="cell" data-label="Filed by">
                                    {{-- The session by where it works, `<repository>/<machine>/<slot>` (#421): one
                                         developer runs many sessions, so the login alone names none of
                                         them. The login stays visible beneath, rather than in hover text
                                         a keyboard or touch reader cannot reach. Name no class in this
                                         comment that the markup does not use. --}}
                                    @if ($task['created_by'])
                                        @if (($task['created_by']['label'] ?? null) !== null)
                                            <div><code>{{ $task['created_by']['label'] }}</code></div>
                                            <div class="text-meta opacity-80"><x-robot-council::avatar :login="$task['created_by']['github_login'] ?? null" />{{ $task['created_by']['github_login'] ?? 'an unknown account' }}</div>
                                        @else
                                            <x-robot-council::avatar :login="$task['created_by']['github_login'] ?? null" />{{ $task['created_by']['github_login'] ?? 'an unknown account' }}
                                        @endif

                                        {{-- #16 decides who may claim this, and the flag is what was
                                             true when the task was filed rather than now --}}
                                        @if ($task['created_by']['coordinator_direct'] ?? false)
                                            <span class="badge badge-sm badge-outline">coordinator</span>
                                        @endif
                                    @else
                                        <span class="opacity-80">a session since deleted</span>
                                    @endif
                                </td>

                                <td role="cell" data-label="Held by">
                                    @if ($task['claimed_by'])
                                        @if (($task['claimed_by']['label'] ?? null) !== null)
                                            <div><code>{{ $task['claimed_by']['label'] }}</code></div>
                                            <div class="text-meta opacity-80"><x-robot-council::avatar :login="$task['claimed_by']['github_login'] ?? null" />{{ $task['claimed_by']['github_login'] ?? 'an unknown account' }}</div>
                                        @else
                                            <x-robot-council::avatar :login="$task['claimed_by']['github_login'] ?? null" />{{ $task['claimed_by']['github_login'] ?? 'an unknown account' }}
                                        @endif
                                    @else
                                        <span class="opacity-80">nobody</span>
                                    @endif
                                </td>

                                <td role="cell" data-label="Age" class="whitespace-nowrap text-meta opacity-90">
                                    {{ $task['age'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @endif

        {{-- Outside the branch above, because a reader who has paged past the end still needs the
             way back. Inside it, an exact multiple of the page size stranded them on an empty page
             whose only escape was the filter button that was already active. --}}
        @if ($afterId !== null || $hasMore)
            <div class="flex items-center justify-end gap-2 pt-2">
                @if ($afterId !== null)
                    <button type="button" wire:click="showFirst" class="btn btn-target btn-outline">First page</button>
                @endif

                @if ($hasMore && $cursor)
                    <button type="button"
                        wire:click="showNext({{ \RobotCouncil\Support\WireArgument::of($cursor['priority']) }}, {{ \RobotCouncil\Support\WireArgument::of($cursor['id']) }})"
                        class="btn btn-target btn-outline">Next page</button>
                @endif
            </div>
        @endif
    </div>
</div>
