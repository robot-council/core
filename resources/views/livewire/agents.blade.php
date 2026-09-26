{{--
    The agents: every session in the fleet, one page at a time. Every value here was supplied by an
    agent or derived from one, so nothing is rendered unescaped and none of it reaches a URL or a
    `wire:` expression attribute. The one value that reaches a URL is a session's own id, assigned by
    the server, and only through `route()` with a literal name -- the form the #70 guard admits.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="card-title">Agents</h1>

            {{-- The scope, and what each one holds. A count beside the button is what stops a
                 narrowed list reading as an empty fleet. --}}
            <div class="flex gap-1" role="group" aria-label="Filter by scope">
                <button type="button" wire:click="show('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::Live) }}')"
                    aria-pressed="{{ $sessionScope === \RobotCouncil\Support\Scope::Live ? 'true' : 'false' }}"
                    class="btn btn-xs {{ $sessionScope === \RobotCouncil\Support\Scope::Live ? 'btn-primary' : 'btn-outline' }}">
                    Live ({{ $sessions['live'] }})
                </button>

                <button type="button" wire:click="show('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::All) }}')"
                    aria-pressed="{{ $sessionScope === \RobotCouncil\Support\Scope::All ? 'true' : 'false' }}"
                    class="btn btn-xs {{ $sessionScope === \RobotCouncil\Support\Scope::All ? 'btn-primary' : 'btn-outline' }}">
                    All ({{ $sessions['live'] + $sessions['gone'] }})
                </button>
            </div>
        </div>

        @include('robot-council::partials.glossary', ['terms' => ['agent', 'session', 'live_scope', 'harness', 'machine_label', 'working_in', 'role', 'coordinator', 'active', 'stale', 'gone', 'lock']])

        {{-- Narrowed to one session, which is where a lock's holder link lands. Said in words,
             with the way back beside it, because a list of one otherwise reads as a fleet of
             one. The counts on the scope buttons stay the whole fleet's. --}}
        @if ($session !== null)
            <div class="flex flex-wrap items-center gap-2 text-meta">
                {{-- The session named as the list names it, once the page has it (#421); its id otherwise --}}
                @php($shown = collect($sessions['sessions'])->firstWhere('id', $session))
                @php($shownLabel = is_array($shown) ? \RobotCouncil\Support\SessionLabels::of($shown['repository'] ?? null, $shown['machine_label'] ?? null, $shown['work_location'] ?? null) : null)
                <span>Showing one session, <code>{{ $shownLabel ?? '#'.$session }}</code>.</span>
                <button type="button" wire:click="showEverySession" class="btn btn-xs btn-outline">Show all</button>
            </div>
        @endif

        @if ($sessions['sessions'] === [])
            <p class="py-6 text-center opacity-80">
                @if ($session !== null)
                    Not in this list: session <code>#{{ $session }}</code> is not among these sessions. Choose Show all to see every one.
                @else
                    No agents yet: a machine's sessions appear here once a developer approves its enrollment and an agent joins.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-stack" role="table">
                    <thead role="rowgroup">
                        <tr role="row">
                            <th role="columnheader">Session</th>
                            <th role="columnheader">Machine</th>
                            <th role="columnheader">Working in</th>
                            <th role="columnheader">Role</th>
                            <th role="columnheader">Status</th>
                            <th role="columnheader">Last seen</th>
                            <th role="columnheader">Locks</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup">
                        @foreach ($sessions['sessions'] as $agent)
                            <tr role="row" wire:key="session-{{ $agent['id'] }}">
                                {{-- Named as the queue, the locks page and the lane board name a session
                                     (#421), with its developer beneath --}}
                                @php($label = \RobotCouncil\Support\SessionLabels::of($agent['repository'] ?? null, $agent['machine_label'] ?? null, $agent['work_location'] ?? null))
                                <td role="cell" data-label="Session">
                                    @if ($label !== null)
                                        <div><code>{{ $label }}</code></div>
                                        <div class="text-meta opacity-80">{{ $agent['github_login'] ?? 'an unknown account' }}</div>
                                    @else
                                        {{ $agent['github_login'] ?? 'an unknown account' }}
                                    @endif
                                </td>

                                <td role="cell" data-label="Machine">
                                    @if (($agent['machine_label'] ?? null) !== null)
                                        <div><code>{{ $agent['machine_label'] }}</code></div>
                                    @else
                                        <div>an unknown machine</div>
                                    @endif
                                    @if (($agent['harness'] ?? '') !== '')
                                        <div class="text-meta opacity-80"><code>{{ $agent['harness'] }}</code></div>
                                    @endif

                                    {{-- The operating system the bridge reported (#351), and
                                         nothing for an older bridge that reported none --}}
                                    @if (($agent['os_family'] ?? null) !== null)
                                        <div class="text-meta opacity-80">{{ $agent['os_family'] }}{{ ($agent['arch'] ?? null) !== null ? ' '.$agent['arch'] : '' }}</div>
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
                                <td role="cell" data-label="Working in" class="text-meta">
                                    @php($where = $agent['repository'] ?? null)

                                    @if ($where !== null)
                                        <div><code>{{ $where }}</code></div>
                                    @endif

                                    @if (($agent['work_location'] ?? null) !== null)
                                        {{-- Dimmer than the line above it, and above the 7:1
                                             AAA bar the dashboard theme test holds dimmed text
                                             to (#197, raised by #310).

                                             An earlier draft of this very comment used the
                                             ordinary English word for a stage in a sequence and
                                             put 1.5 KB of daisyUI rules into the shipped
                                             stylesheet, which is the trap `CLAUDE.md` records
                                             and which `npm run check` caught. Tailwind scans
                                             this file whole and cannot tell a sentence from an
                                             attribute. --}}
                                        <div class="opacity-80"><code>{{ $agent['work_location'] }}</code></div>
                                    @endif

                                    @if ($where === null && ($agent['work_location'] ?? null) === null)
                                        <span class="opacity-80">none</span>
                                    @endif
                                </td>

                                {{-- What this session is for, which is the session's own and no
                                     longer its machine's: one harness runs several checkouts,
                                     and before roles they all held identical authority with
                                     nothing here saying so. A fixed set of three, so it cannot
                                     carry anything a developer supplied -- escaped anyway,
                                     because nothing on this page is not. --}}
                                <td role="cell" data-label="Role">
                                    <span class="badge badge-sm badge-outline">{{ $agent['role'] }}</span>
                                </td>

                                {{-- Read from the row, which #24 made the decision, rather than
                                     re-derived from the contact time --}}
                                <td role="cell" data-label="Status">
                                    <span class="badge badge-sm">{{ $agent['status'] }}</span>
                                </td>

                                <td role="cell" data-label="Last seen" class="whitespace-nowrap text-meta opacity-90">
                                    {{ $agent['last_seen'] }}
                                </td>

                                {{-- The other half of the diagnosis path #215 recorded and #308
                                     kept: what this process is blocking. The id is the row's own
                                     key, assigned by the server, and it reaches the URL only
                                     through `route()` with a literal name. --}}
                                <td role="cell" data-label="Locks" class="whitespace-nowrap text-meta">
                                    {{-- Alone in its cell, so it is a target rather than a link in a sentence: at least 24px tall
                                         (SC 2.5.8), which the browser suite measures (#403) --}}
                                    <a href="{{ route('robot-council.locks', ['holder' => $agent['id']]) }}" class="link inline-flex min-h-6 items-center">Locks held</a>
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
                    <button type="button" wire:click="showFirst" class="btn btn-sm btn-outline">Newest</button>
                @endif

                @if ($sessions['more'] && $sessions['cursor'] !== null)
                    <button type="button" wire:click="showNext({{ \RobotCouncil\Support\WireArgument::of($sessions['cursor']) }})"
                        class="btn btn-sm btn-outline">Older</button>
                @endif
            </div>
        @endif
    </div>
</div>
