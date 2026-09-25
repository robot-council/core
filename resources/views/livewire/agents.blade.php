{{--
    The agents: every session in the fleet, one page at a time. Every value here was supplied by an
    agent or derived from one, so nothing is rendered unescaped and none of it reaches a URL or a
    `wire:` expression attribute. The one value that reaches a URL is a session's own id, assigned by
    the server, and only through `route()` with a literal name -- the form the #70 guard admits.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="card-title">Agents</h2>

            {{-- The scope, and what each one holds. A count beside the button is what stops a
                 narrowed list reading as an empty fleet. --}}
            <div class="flex gap-1">
                <button type="button" wire:click="show('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::Live) }}')"
                    class="btn btn-xs {{ $sessionScope === \RobotCouncil\Support\Scope::Live ? 'btn-primary' : 'btn-ghost' }}">
                    Live ({{ $sessions['live'] }})
                </button>

                <button type="button" wire:click="show('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::All) }}')"
                    class="btn btn-xs {{ $sessionScope === \RobotCouncil\Support\Scope::All ? 'btn-primary' : 'btn-ghost' }}">
                    All ({{ $sessions['live'] + $sessions['gone'] }})
                </button>
            </div>
        </div>

        {{-- Narrowed to one session, which is where a lock's holder link lands. Said in words,
             with the way back beside it, because a list of one otherwise reads as a fleet of
             one. The counts on the scope buttons stay the whole fleet's. --}}
        @if ($session !== null)
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span>Showing one session, #{{ $session }}.</span>
                <button type="button" wire:click="showEverySession" class="btn btn-xs btn-ghost">Show all</button>
            </div>
        @endif

        @if ($sessions['sessions'] === [])
            <p class="py-6 text-center opacity-60">
                @if ($session !== null)
                    No session #{{ $session }} in this list.
                @else
                    No agent has enrolled yet.
                @endif
            </p>
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
                            <th>Locks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions['sessions'] as $agent)
                            <tr wire:key="session-{{ $agent['id'] }}">
                                <td>{{ $agent['github_login'] ?? 'an unknown account' }}</td>

                                <td>
                                    <div>{{ $agent['machine_label'] ?? 'an unknown machine' }}</div>
                                    <div class="text-xs opacity-60">{{ $agent['harness'] ?? '' }}</div>

                                    {{-- The operating system the bridge reported (#351), and
                                         nothing for an older bridge that reported none --}}
                                    @if (($agent['os_family'] ?? null) !== null)
                                        <div class="text-xs opacity-60">{{ $agent['os_family'] }}{{ ($agent['arch'] ?? null) !== null ? ' '.$agent['arch'] : '' }}</div>
                                    @endif
                                </td>

                                {{-- Where the session is working: the repository, and beneath it
                                     the checkout within it. Two lines rather than one label,
                                     the same shape the machine column uses for its harness, and
                                     the reason #220 split the field -- the location is what
                                     tells two worktrees on one machine and harness apart, and
                                     the repository is what a reader groups by.

                                     **Every stored combination renders, and that is the point
                                     rather than tidiness.** Both fields are independently
                                     nullable, which is an acceptance criterion, so a session
                                     naming only a location is a shape the endpoint accepts. An
                                     earlier version gated the whole cell on the repository and
                                     printed `none` for exactly that row -- the page asserting a
                                     session named nothing when it had named something, and the
                                     one field the split exists for the least visible of the
                                     two. Absent is still shown as absent, and both are
                                     agent-supplied and escaped. --}}
                                <td class="text-xs">
                                    @php($where = $agent['repository'] ?? null)

                                    @if ($where !== null)
                                        <div>{{ $where }}</div>
                                    @endif

                                    @if (($agent['work_location'] ?? null) !== null)
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
                                        <div class="opacity-60">{{ $agent['work_location'] }}</div>
                                    @endif

                                    @if ($where === null && ($agent['work_location'] ?? null) === null)
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
                                    <span class="badge badge-sm badge-outline">{{ $agent['role'] }}</span>
                                </td>

                                {{-- Read from the row, which #24 made the decision, rather than
                                     re-derived from the contact time --}}
                                <td>
                                    <span class="badge badge-sm">{{ $agent['status'] }}</span>
                                </td>

                                <td class="whitespace-nowrap text-xs opacity-70">
                                    {{ $agent['last_seen'] }}
                                </td>

                                {{-- The other half of the diagnosis path #215 recorded and #308
                                     kept: what this process is blocking. The id is the row's own
                                     key, assigned by the server, and it reaches the URL only
                                     through `route()` with a literal name. --}}
                                <td class="whitespace-nowrap text-xs">
                                    <a href="{{ route('robot-council.locks', ['holder' => $agent['id']]) }}" class="link">Locks held</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Outside the empty branch, because a reader who has paged past the end still needs
             the way back. --}}
        @if ($after !== null || $sessions['more'])
            <div class="flex items-center justify-end gap-2 pt-2">
                @if ($after !== null)
                    <button type="button" wire:click="showFirst" class="btn btn-sm btn-ghost">Newest</button>
                @endif

                @if ($sessions['more'] && $sessions['cursor'] !== null)
                    <button type="button" wire:click="showNext({{ \RobotCouncil\Support\WireArgument::of($sessions['cursor']) }})"
                        class="btn btn-sm">Older</button>
                @endif
            </div>
        @endif
    </div>
</div>
