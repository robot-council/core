{{--
    Presence and locks. Every value here was supplied by an agent or derived from one, so nothing is
    rendered unescaped and nothing reaches a URL or a `wire:` expression attribute -- the last of
    those is not yet covered by a guard, which is robot-council/core#81.
--}}
<div wire:poll.{{ $pollSeconds }}s class="grid gap-4 lg:grid-cols-2">
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">Agents</h2>

            @if ($sessions === [])
                <p class="py-6 text-center opacity-60">No agent has enrolled yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Developer</th>
                                <th>Machine</th>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sessions as $session)
                                <tr wire:key="session-{{ $session['id'] }}">
                                    <td>{{ $session['github_login'] ?? 'an unknown account' }}</td>

                                    <td>
                                        <div>{{ $session['machine_label'] ?? 'an unknown machine' }}</div>
                                        <div class="text-xs opacity-60">{{ $session['harness'] ?? '' }}</div>
                                    </td>

                                    {{-- What the session called its checkout, which is the only
                                         thing telling two worktrees on one machine and harness
                                         apart. Absent is shown as absent: a session that started
                                         without one is still listed, and no name is invented for
                                         it. Escaped like every agent-supplied string here. --}}
                                    <td class="text-xs">
                                        @if (($session['project_id'] ?? null) === null)
                                            <span class="opacity-50">none</span>
                                        @else
                                            {{ $session['project_id'] }}
                                        @endif
                                    </td>

                                    {{-- Read from the row, which #24 made the decision, rather than
                                         re-derived from the contact time --}}
                                    <td>
                                        <span class="badge badge-sm">{{ $session['status'] }}</span>
                                    </td>

                                    <td class="whitespace-nowrap text-xs opacity-70">
                                        {{ $session['seconds_since_contact'] }}s ago
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">Locks</h2>

            @if ($locks === [])
                <p class="py-6 text-center opacity-60">Nothing is locked.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Lock</th>
                                <th>Held by</th>
                                <th>Fence</th>
                                <th>Lease</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($locks as $lock)
                                <tr wire:key="lock-{{ $lock['id'] }}">
                                    <td class="font-medium">{{ $lock['name'] }}</td>

                                    <td>
                                        @if ($lock['holder'])
                                            {{ $lock['holder']['github_login'] ?? 'an unknown account' }}
                                        @else
                                            <span class="opacity-60">nobody</span>
                                        @endif

                                        {{-- Who had it last, which is what tells a reader whether a
                                             lock is being handed round or has sat with one holder --}}
                                        @if ($lock['previous_holder'])
                                            <div class="text-xs opacity-60">
                                                after {{ $lock['previous_holder']['github_login'] ?? 'an unknown account' }}
                                            </div>
                                        @endif
                                    </td>

                                    <td>{{ $lock['fence'] }}</td>

                                    {{-- A lapsed lease is shown rather than hidden: a row that
                                         still names a holder whose lease has run out is exactly
                                         what a developer is looking for --}}
                                    {{-- A lapsed lease is marked with a badge rather than coloured
                                         text: `text-warning` measures 1.76:1 on this card and fails
                                         AA at both sizes, and the layout pins `data-theme="light"`
                                         so the dark token never applies. The badge pairs the same
                                         colour with `--color-warning-content` at 5.24:1. A released
                                         lock is the ordinary case and is not marked at all. --}}
                                    <td class="whitespace-nowrap text-xs">
                                        @if ($lock['lapsed'])
                                            <span class="badge badge-sm badge-warning">{{ $lock['lease'] }}</span>
                                        @else
                                            <span class="opacity-70">{{ $lock['lease'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
