{{--
    Presence and locks. Every value here was supplied by an agent or derived from one, so nothing is
    rendered unescaped and nothing reaches a URL or a `wire:` expression attribute -- the last of
    those is not yet covered by a guard, which is robot-council/core#81.
--}}

@use('RobotCouncil\Support\WireArgument', 'Wire')
<div wire:poll.{{ Wire::of($pollSeconds) }}s class="grid gap-4 lg:grid-cols-2">
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="card-title">Agents</h2>

                {{-- The scope, and what each one holds. A count beside the button is what stops a
                     narrowed list reading as an empty fleet. --}}
                <div class="flex gap-1">
                    <button type="button" wire:click="showSessions('{{ Wire::of(\RobotCouncil\Support\Scope::Live) }}')"
                        class="btn btn-xs {{ $sessionScope === \RobotCouncil\Support\Scope::Live ? 'btn-primary' : 'btn-ghost' }}">
                        Live ({{ $sessions['live'] }})
                    </button>

                    <button type="button" wire:click="showSessions('{{ Wire::of(\RobotCouncil\Support\Scope::All) }}')"
                        class="btn btn-xs {{ $sessionScope === \RobotCouncil\Support\Scope::All ? 'btn-primary' : 'btn-ghost' }}">
                        All ({{ $sessions['live'] + $sessions['gone'] }})
                    </button>
                </div>
            </div>

            @if ($sessions['sessions'] === [])
                <p class="py-6 text-center opacity-60">No agent has enrolled yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Developer</th>
                                <th>Machine</th>
                                <th>Working in</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sessions['sessions'] as $session)
                                <tr wire:key="session-{{ $session['id'] }}">
                                    <td>{{ $session['github_login'] ?? 'an unknown account' }}</td>

                                    <td>
                                        <div>{{ $session['machine_label'] ?? 'an unknown machine' }}</div>
                                        <div class="text-xs opacity-60">{{ $session['harness'] ?? '' }}</div>
                                    </td>

                                    {{-- Where the session is working: the repository, and beneath it
                                         the checkout within it. Two lines rather than one label,
                                         the same shape the machine column uses for its harness, and
                                         the reason #220 split the field -- the location is what
                                         tells two worktrees on one machine and harness apart, and
                                         the repository is what a reader groups by.

                                         The legacy `project_id` is the fallback rather than a third
                                         line: a session that predates the split, or one whose label
                                         did not fit the shape, still says where it is. Absent is
                                         shown as absent, and every one of the three is
                                         agent-supplied and escaped. --}}
                                    <td class="text-xs">
                                        @if (($session['repository'] ?? null) !== null)
                                            <div>{{ $session['repository'] }}</div>

                                            @if (($session['work_location'] ?? null) !== null)
                                                <div class="opacity-60">{{ $session['work_location'] }}</div>
                                            @endif
                                        @elseif (($session['project_id'] ?? null) === null)
                                            {{-- Dimmer than this measured 3.38:1 on `base-100` in
                                                 the light theme, under the 4.5:1 this text needs at
                                                 its size; this measures 4.64:1 and is still fainter
                                                 than the value it stands in for (#197).

                                                 The class that failed is named nowhere here, and no
                                                 other unused class name is either. Tailwind scans
                                                 this file whole and cannot tell a sentence from an
                                                 attribute, so a class written only to discuss it
                                                 ships its rules. An ordinary English word can do it
                                                 too: naming the one that means "a stage in a
                                                 sequence" added eleven daisyUI rules here. --}}
                                            <span class="opacity-60">none</span>
                                        @else
                                            {{ $session['project_id'] }}
                                        @endif
                                    </td>

                                    {{-- What this session is for, which is the session's own and no
                                         longer its machine's: one harness runs several checkouts,
                                         and before roles they all held identical authority with
                                         nothing here saying so. A fixed set of three, so it cannot
                                         carry anything a developer supplied -- escaped anyway,
                                         because nothing on this page is not. --}}
                                    <td>
                                        <span class="badge badge-sm badge-outline">{{ $session['role'] }}</span>
                                    </td>

                                    {{-- Read from the row, which #24 made the decision, rather than
                                         re-derived from the contact time --}}
                                    <td>
                                        <span class="badge badge-sm">{{ $session['status'] }}</span>
                                    </td>

                                    <td class="whitespace-nowrap text-xs opacity-70">
                                        {{ $session['last_seen'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Outside the empty branch, because a reader who has paged past the end still needs
                 the way back. --}}
            @if ($afterSession !== null || $sessions['more'])
                <div class="flex items-center justify-end gap-2 pt-2">
                    @if ($afterSession !== null)
                        <button type="button" wire:click="showFirstSessions" class="btn btn-sm btn-ghost">Newest</button>
                    @endif

                    @if ($sessions['more'] && $sessions['cursor'] !== null)
                        <button type="button" wire:click="showNextSessions({{ Wire::of($sessions['cursor']) }})"
                            class="btn btn-sm">Older</button>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="card-title">Locks</h2>

                <div class="flex gap-1">
                    {{-- "Held" is held AND lapsed: a lease that has run out while the row still
                         names somebody is exactly what a developer is hunting, so it must not be
                         filtered away with the free ones. --}}
                    <button type="button" wire:click="showLocks('{{ Wire::of(\RobotCouncil\Support\Scope::Live) }}')"
                        class="btn btn-xs {{ $lockScope === \RobotCouncil\Support\Scope::Live ? 'btn-primary' : 'btn-ghost' }}">
                        Held ({{ $locks['held'] }})
                    </button>

                    <button type="button" wire:click="showLocks('{{ Wire::of(\RobotCouncil\Support\Scope::All) }}')"
                        class="btn btn-xs {{ $lockScope === \RobotCouncil\Support\Scope::All ? 'btn-primary' : 'btn-ghost' }}">
                        All ({{ $locks['held'] + $locks['free'] }})
                    </button>
                </div>
            </div>

            @if ($locks['locks'] === [])
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
                            @foreach ($locks['locks'] as $lock)
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
                                    {{-- A lapsed lease is marked with a badge rather than with
                                         warning-coloured text. That colour as TEXT measures 1.76:1
                                         on this card in the light theme and fails AA at both sizes;
                                         it measures 8.98:1 in the dark one, so the two themes
                                         disagree and only the badge works in both -- it pairs the
                                         same colour with `--color-warning-content` at 5.24:1
                                         whichever theme is in force. An earlier version of this
                                         note justified the badge by saying the layout pinned a
                                         light theme so the dark token never applied. It did, and
                                         that was a defect rather than a reason: nothing could
                                         reach the dark theme at all. A released lock is the
                                         ordinary case and is not marked. --}}
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

            @if ($afterLock !== null || $locks['more'])
                <div class="flex items-center justify-end gap-2 pt-2">
                    @if ($afterLock !== null)
                        <button type="button" wire:click="showFirstLocks" class="btn btn-sm btn-ghost">First</button>
                    @endif

                    @if ($locks['more'] && $locks['cursor'] !== null)
                        <button type="button" wire:click="showNextLocks('{{ Wire::of($locks['cursor']) }}')"
                            class="btn btn-sm">Next</button>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
