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
                    <button type="button" wire:click="showStatus('{{ \RobotCouncil\Support\WireArgument::of($option) }}')"
                        aria-pressed="{{ $shownStatus === $option->value ? 'true' : 'false' }}"
                        class="btn btn-xs {{ $shownStatus === $option->value ? 'btn-primary' : 'btn-outline' }}">
                        {{ $option->value }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($tasks === [])
            <p class="py-6 text-center opacity-60">
                {{ $afterId === null ? 'Nothing in the queue.' : 'Nothing further -- this is past the end of the queue.' }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Filed by</th>
                            <th>Held by</th>
                            <th>Age</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tasks as $task)
                            <tr wire:key="task-{{ $task['id'] }}">
                                <td>
                                    <div class="font-medium">{{ $task['title'] }}</div>

                                    @if ($task['project_id'])
                                        <div class="text-xs opacity-60">{{ $task['project_id'] }}</div>
                                    @endif
                                </td>

                                <td><span class="badge badge-sm">{{ $task['status'] }}</span></td>

                                <td>{{ $task['priority'] }}</td>

                                <td>
                                    @if ($task['created_by'])
                                        {{ $task['created_by']['github_login'] ?? 'an unknown account' }}

                                        {{-- #16 decides who may claim this, and the flag is what was
                                             true when the task was filed rather than now --}}
                                        @if ($task['created_by']['coordinator_direct'] ?? false)
                                            <span class="badge badge-sm badge-outline">coordinator</span>
                                        @endif
                                    @else
                                        <span class="opacity-60">a session since deleted</span>
                                    @endif
                                </td>

                                <td>
                                    @if ($task['claimed_by'])
                                        {{ $task['claimed_by']['github_login'] ?? 'an unknown account' }}
                                    @else
                                        <span class="opacity-60">nobody</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap text-xs opacity-70">
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
