{{--
    The queue. Every value here comes from another developer's agent, so nothing is rendered
    unescaped and no agent-supplied value reaches a URL attribute -- the #67 and #70 guards refuse
    both, and this is the page they were written for.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="card-title">Queue</h2>

            <div class="flex flex-wrap gap-1" role="group" aria-label="Filter by status">
                <button type="button" wire:click="showStatus('')"
                    aria-pressed="{{ $shownStatus === '' ? 'true' : 'false' }}"
                    class="btn btn-xs {{ $shownStatus === '' ? 'btn-primary' : 'btn-outline' }}">All</button>

                @foreach ($statuses as $option)
                    <button type="button" wire:key="status-{{ $option->value }}" wire:click="showStatus('{{ \RobotCouncil\Support\WireArgument::of($option) }}')"
                        aria-pressed="{{ $shownStatus === $option->value ? 'true' : 'false' }}"
                        class="btn btn-xs {{ $shownStatus === $option->value ? 'btn-primary' : 'btn-outline' }}">
                        {{ $option->value }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($tasks === [])
            <p class="py-6 text-center opacity-80">
                {{ $afterId === null ? 'Nothing in the queue.' : 'Nothing further -- this is past the end of the queue.' }}
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
                                    <div class="font-medium">{{ $task['title'] }}</div>

                                    @if ($task['project_id'])
                                        <div class="text-meta opacity-80">{{ $task['project_id'] }}</div>
                                    @endif
                                </td>

                                <td role="cell" data-label="Status"><span class="badge badge-sm">{{ $task['status'] }}</span></td>

                                <td role="cell" data-label="Priority">{{ $task['priority'] }}</td>

                                <td role="cell" data-label="Filed by">
                                    @if ($task['created_by'])
                                        {{ $task['created_by']['github_login'] ?? 'an unknown account' }}

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
                                        {{ $task['claimed_by']['github_login'] ?? 'an unknown account' }}
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
                    <button type="button" wire:click="showFirst" class="btn btn-sm btn-outline">First page</button>
                @endif

                @if ($hasMore && $cursor)
                    <button type="button"
                        wire:click="showNext({{ \RobotCouncil\Support\WireArgument::of($cursor['priority']) }}, {{ \RobotCouncil\Support\WireArgument::of($cursor['id']) }})"
                        class="btn btn-sm btn-outline">Next page</button>
                @endif
            </div>
        @endif
    </div>
</div>
