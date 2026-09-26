{{--
    The locks: every named lock, by name. Every value here was supplied by an agent or derived from
    one, so nothing is rendered unescaped and none of it reaches a URL or a `wire:` expression
    attribute. The one value that reaches a URL is a holder's session id, assigned by the server, and
    only through `route()` with a literal name -- the form the #70 guard admits.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="card-title">Locks</h1>

            <div class="flex gap-1" role="group" aria-label="Filter by scope">
                {{-- "Held" is held AND lapsed: a lease that has run out while the row still
                     names somebody is exactly what a developer is hunting, so it must not be
                     filtered away with the free ones. --}}
                <button type="button" wire:click="show('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::Live) }}')"
                    aria-pressed="{{ $lockScope === \RobotCouncil\Support\Scope::Live ? 'true' : 'false' }}"
                    class="btn btn-xs {{ $lockScope === \RobotCouncil\Support\Scope::Live ? 'btn-primary' : 'btn-outline' }}">
                    Held ({{ $locks['held'] }})
                </button>

                <button type="button" wire:click="show('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::All) }}')"
                    aria-pressed="{{ $lockScope === \RobotCouncil\Support\Scope::All ? 'true' : 'false' }}"
                    class="btn btn-xs {{ $lockScope === \RobotCouncil\Support\Scope::All ? 'btn-primary' : 'btn-outline' }}">
                    All ({{ $locks['held'] + $locks['free'] }})
                </button>
            </div>
        </div>

        {{-- Narrowed to one holder, which is where a session's link lands. Said in words for the
             reason the Agents page says its own narrowing. --}}
        @if ($holder !== null)
            <div class="flex flex-wrap items-center gap-2 text-meta">
                <span>Showing locks held by session <code>#{{ $holder }}</code>.</span>
                <button type="button" wire:click="showEveryHolder" class="btn btn-xs btn-outline">Show all</button>
            </div>
        @endif

        @if ($locks['locks'] === [])
            <p class="py-6 text-center opacity-80">
                @if ($holder !== null)
                    Session <code>#{{ $holder }}</code> holds no locks in this list.
                @else
                    Nothing is locked.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-stack" role="table">
                    <thead role="rowgroup">
                        <tr role="row">
                            <th role="columnheader">Lock</th>
                            <th role="columnheader">Held by</th>
                            <th role="columnheader">Fence</th>
                            <th role="columnheader">Lease</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup">
                        @foreach ($locks['locks'] as $lock)
                            <tr role="row" wire:key="lock-{{ $lock['id'] }}">
                                <td role="cell" data-label="Lock" class="font-medium"><code>{{ $lock['name'] }}</code></td>

                                <td role="cell" data-label="Held by">
                                    {{-- Linked to the one session holding it, which is the
                                         diagnosis path #308 kept when it split this list from the
                                         agents. A session id rather than the login: one developer
                                         runs several sessions, and the question is which one. --}}
                                    @if ($lock['holder'])
                                        <a href="{{ route('robot-council.agents', ['session' => $lock['holder']['session_id']]) }}" class="link">{{ $lock['holder']['github_login'] ?? 'an unknown account' }}</a>
                                    @else
                                        <span class="opacity-80">nobody</span>
                                    @endif

                                    {{-- Who had it last, which is what tells a reader whether a
                                         lock is being handed round or has sat with one holder --}}
                                    @if ($lock['previous_holder'])
                                        <div class="text-meta opacity-80">
                                            after {{ $lock['previous_holder']['github_login'] ?? 'an unknown account' }}
                                        </div>
                                    @endif
                                </td>

                                <td role="cell" data-label="Fence">{{ $lock['fence'] }}</td>

                                {{-- A lapsed lease is shown rather than hidden: a row that
                                     still names a holder whose lease has run out is exactly
                                     what a developer is looking for --}}
                                {{-- A lapsed lease is marked with a badge rather than with
                                     warning-colored text. That color as TEXT measures 1.76:1
                                     on this card in the light theme and fails AA at both sizes;
                                     it measures 8.98:1 in the dark one, so the two themes
                                     disagree and only the badge works in both -- it pairs the
                                     same color with `--color-warning-content` at 5.24:1
                                     whichever theme is in force. An earlier version of this
                                     note justified the badge by saying the layout pinned a
                                     light theme so the dark token never applied. It did, and
                                     that was a defect rather than a reason: nothing could
                                     reach the dark theme at all. A released lock is the
                                     ordinary case and is not marked. --}}
                                <td role="cell" data-label="Lease" class="whitespace-nowrap text-meta">
                                    @if ($lock['lapsed'])
                                        <span class="badge badge-sm badge-warning">{{ $lock['lease'] }}</span>
                                    @else
                                        <span class="opacity-90">{{ $lock['lease'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($after !== null || $locks['more'])
            <div class="flex items-center justify-end gap-2 pt-2">
                @if ($after !== null)
                    <button type="button" wire:click="showFirst" class="btn btn-sm btn-outline">First</button>
                @endif

                @if ($locks['more'] && $locks['cursor'] !== null)
                    <button type="button" wire:click="showNext('{{ \RobotCouncil\Support\WireArgument::of($locks['cursor']) }}')"
                        class="btn btn-sm btn-outline">Next</button>
                @endif
            </div>
        @endif
    </div>
</div>
