{{--
    Presence and locks. Every value here was supplied by an agent or derived from one, so nothing is
    rendered unescaped and nothing reaches a URL or a `wire:` expression attribute -- the last of
    those is not yet covered by a guard, which is robot-council/core#81.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="grid gap-4 lg:grid-cols-2">
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="card-title">Agents</h2>

                {{-- The scope, and what each one holds. A count beside the button is what stops a
                     narrowed list reading as an empty fleet. --}}
                <div class="flex gap-1">
                    <button type="button" wire:click="showSessions('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::Live) }}')"
                        class="btn btn-xs {{ $sessionScope === \RobotCouncil\Support\Scope::Live ? 'btn-primary' : 'btn-ghost' }}">
                        Live ({{ $sessions['live'] }})
                    </button>

                    <button type="button" wire:click="showSessions('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::All) }}')"
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

                                         **Every stored combination renders, and that is the point
                                         rather than tidiness.** The three fields are independently
                                         nullable, which is an acceptance criterion, so a session
                                         naming only a location is a shape the endpoint accepts. An
                                         earlier version gated the whole cell on the repository and
                                         printed `none` for exactly that row -- the page asserting a
                                         session named nothing when it had named something, and the
                                         one field the split exists for the least visible of the
                                         three. Absent is still shown as absent, and all three are
                                         agent-supplied and escaped. --}}
                                    <td class="text-xs">
                                        @php($where = $session['repository'] ?? $session['project_id'] ?? null)

                                        @if ($where !== null)
                                            <div>{{ $where }}</div>
                                        @endif

                                        @if (($session['work_location'] ?? null) !== null)
                                            {{-- Dimmer than the line above it, and above the 4.5:1
                                                 bar the dashboard theme test holds dimmed text to
                                                 (#197).

                                                 An earlier draft of this very comment used the
                                                 ordinary English word for a stage in a sequence and
                                                 put 1.5 KB of daisyUI rules into the shipped
                                                 stylesheet, which is the trap `CLAUDE.md` records
                                                 and which `npm run check` caught. Tailwind scans
                                                 this file whole and cannot tell a sentence from an
                                                 attribute. --}}
                                            <div class="opacity-60">{{ $session['work_location'] }}</div>
                                        @endif

                                        @if ($where === null && ($session['work_location'] ?? null) === null)
                                            <span class="opacity-60">none</span>
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
                        <button type="button" wire:click="showNextSessions({{ \RobotCouncil\Support\WireArgument::of($sessions['cursor']) }})"
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
                    <button type="button" wire:click="showLocks('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::Live) }}')"
                        class="btn btn-xs {{ $lockScope === \RobotCouncil\Support\Scope::Live ? 'btn-primary' : 'btn-ghost' }}">
                        Held ({{ $locks['held'] }})
                    </button>

                    <button type="button" wire:click="showLocks('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::All) }}')"
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
                        <button type="button" wire:click="showNextLocks('{{ \RobotCouncil\Support\WireArgument::of($locks['cursor']) }}')"
                            class="btn btn-sm">Next</button>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
