{{--
    Installation administration. The only dashboard panel whose controls write.

    Two rules hold here, and neither is a habit of this file. Nothing is rendered unescaped, which
    the #67 guard enforces over every template under `resources/views/`. And **only values this
    package chose reach a `wire:` expression** -- a row id, and an ability from the fixed list in
    `Access\Ability`. Every agent-supplied string on this page (a harness, a machine label, a
    project id) is rendered as text and reaches no `wire:` expression, which is what keeps #81's gap
    theoretical here rather than load-bearing. Two reach a plain attribute since #518, escaped: a
    machine label and a developer's login name their group in a `wire:key` and a `data-` attribute.
    A label is `[A-Za-z0-9._-]` by `MachineIdentity`, and a login is GitHub's.

    Nothing on this page is a credential. `Support\InstallationList` reads no column that holds one.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="card-title">Installations</h1>

            {{-- A revoked or expired installation is behind a scope rather than sorted below a
                 live one. Sorting would put a mutable column in the ordering, and a cursor over
                 one of those skips rows silently -- which is the defect #83 records. --}}
            <div class="flex gap-1" role="group" aria-label="Filter by scope">
                <button type="button" wire:click="showScope('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::Live) }}')"
                    aria-pressed="{{ $installationScope === \RobotCouncil\Support\Scope::Live ? 'true' : 'false' }}"
                    class="btn btn-target {{ $installationScope === \RobotCouncil\Support\Scope::Live ? 'btn-primary' : 'btn-outline' }}">
                    Usable ({{ $page['live'] }})
                </button>

                <button type="button" wire:click="showScope('{{ \RobotCouncil\Support\WireArgument::of(\RobotCouncil\Support\Scope::All) }}')"
                    aria-pressed="{{ $installationScope === \RobotCouncil\Support\Scope::All ? 'true' : 'false' }}"
                    class="btn btn-target {{ $installationScope === \RobotCouncil\Support\Scope::All ? 'btn-primary' : 'btn-outline' }}">
                    All ({{ $page['live'] + $page['retired'] }})
                </button>
            </div>
        </div>

        @include('robot-council::partials.glossary', ['terms' => ['installation', 'harness', 'machine_label', 'usable', 'revoked', 'expired', 'session', 'active', 'stale', 'gone', 'role', 'coordinator', 'ephemeral', 'asked_for_role', 'make_role', 'revoke_session', 'revoke_installation']])

        <p class="max-w-xl text-meta opacity-90">
            What each machine may do, and which of its sessions are alive. Changes take effect on
            the next request, not on the next renewal.
        </p>

        {{-- What the last action did (#402). Beside the installation it was about when that is still
             listed, and here when it is not: a revoked installation leaves the Usable list. --}}
        @include('robot-council::partials.said', ['show' => $said !== null && ! in_array($saidAt, array_column($installations, 'id'), true), 'class' => ''])

        @if ($installations === [])
            <p class="mx-auto max-w-xl py-6 text-center opacity-80">
                {{ $installationScope === \RobotCouncil\Support\Scope::Live && $page['retired'] > 0
                    ? 'None usable: no machine can act right now. Choose All to see the revoked and expired ones.'
                    : 'No machines yet: a machine appears here once a developer approves its enrollment.' }}
            </p>
        @else
            {{-- Grouped by developer, then machine, then harness (#518), over the page as read: the
                 cursor stays on `id`, so a group that began on an earlier page says "(continued)"
                 rather than moving. Each harness is a list item under its machine, so a screen
                 reader announces where an installation belongs. --}}
            <div class="flex flex-col gap-6">
                @foreach ($page['groups'] as $group)
                    <section wire:key="installations-developer-{{ $group['developer'] ?? '' }}" data-installation-developer="{{ $group['developer'] ?? '' }}">
                        <h2 class="text-lg font-semibold">
                            @if ($group['developer'] !== null)
                                <x-robot-council::avatar :login="$group['developer']" />{{ $group['developer'] }}
                            @else
                                Unknown developer
                            @endif
                            @if ($group['continued'])
                                <span class="font-normal opacity-90">(continued)</span>
                            @endif
                        </h2>

                        @foreach ($group['machines'] as $machine)
                            <div wire:key="installations-machine-{{ $group['developer'] ?? '' }}|{{ $machine['machine'] }}" class="mt-3" data-installation-machine="{{ $machine['machine'] }}">
                                {{-- Set apart from the harness entries beneath it by weight and by the
                                     rule the entries hang from, so the three levels read as three --}}
                                <h3 class="font-semibold">
                                    <code>{{ $machine['machine'] }}</code>
                                    @if ($machine['continued'])
                                        <span class="opacity-90">(continued)</span>
                                    @endif
                                </h3>

                                <ul class="mt-1 ms-2 divide-y divide-base-200 border-s-2 border-base-300 ps-4">
                                    @foreach ($machine['installations'] as $installation)
                                        <li wire:key="installation-{{ $installation['id'] }}" class="py-4">
                                            <div class="flex flex-wrap items-start justify-between gap-2">
                                                <div>
                                                    {{-- Agent-supplied, escaped, and rendered as text. The machine and the
                                                         developer are the headings above, so the entry names neither (#518) --}}
                                                    <div class="font-medium">
                                                        <code>{{ $installation['harness'] }}</code>
                                                    </div>
                                                </div>

                                                <div class="flex items-center gap-2">
                                                    {{-- Revoked and expired are shown apart. A guard treats them the
                                                         same; an admin does not, because one is a decision somebody
                                                         made and the other is only the clock. --}}
                                                    @if ($installation['revoked'])
                                                        <span class="badge badge-sm badge-warning">revoked</span>
                                                    @elseif ($installation['expired'])
                                                        <span class="badge badge-sm">expired</span>
                                                    @else
                                                        <button type="button"
                                                            wire:click="revokeInstallation({{ \RobotCouncil\Support\WireArgument::of($installation['id']) }})"
                                                            wire:confirm="Revoke this installation? Its credential and every session token it issued stop working immediately."
                                                            class="btn btn-target btn-warning">
                                                            Revoke installation
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>

                                            @include('robot-council::partials.said', ['show' => $said !== null && $saidAt === $installation['id'], 'class' => 'mt-2'])

                                            @if ($installation['sessions']['shown'] !== [])
                                                {{-- The id, the status and the role in shared columns from `sm` up (#481),
                                                     the same way the change feed aligns its types: three `max-content`
                                                     columns, then everything else, and each row a subgrid. Without them
                                                     a session's repository started wherever its own two badges ended.
                                                     The column gap matches the row's own `gap-2`, because a subgrid's
                                                     own gap replaces its parent's and would otherwise shift the cells.
                                                     Each row is set apart by padding and a dashed rule (#516), from
                                                     `session-rows` in the stylesheet. --}}
                                                <ul class="session-rows mt-3 sm:grid sm:grid-cols-[repeat(3,max-content)_1fr] sm:gap-x-2" data-admin-sessions>
                                                    @foreach ($installation['sessions']['shown'] as $session)
                                                        <li wire:key="admin-session-{{ $session['id'] }}"
                                                            class="flex flex-wrap items-center gap-2 text-meta sm:col-span-4 sm:grid sm:grid-cols-subgrid sm:items-baseline">
                                                            {{-- The session's own id, which is how agents name it in a
                                                                 narration, a placement or `sessions_list` (#419) --}}
                                                            <code data-session-id>#{{ $session['id'] }}</code>
                                                            <span class="badge badge-sm">{{ $session['status'] }}</span>

                                                            {{-- The role, which is the whole of what this session may
                                                                 do. There is no longer a machine-level list beside it:
                                                                 a stored ability list stopped reaching any session, and
                                                                 the controls that wrote it are gone with it. --}}
                                                            <span class="badge badge-sm badge-outline">{{ \RobotCouncil\Access\Role::labelOf($session['role']) }}</span>

                                                            {{-- The rest of the row stacked, one group to a line (#519): where it works, when it joined,
                                                                 when it was last seen, a pending request with its answers, then what an administrator
                                                                 can do. On one wrapping line the buttons landed somewhere different on every row. The
                                                                 lines sit closer together than #516 spaces the rows, so a row still reads as one unit.
                                                                 Below `sm` the cell wraps under the id and badges, since its actions line alone is wider
                                                                 than what is left beside them; from `sm` up the badges sit on the baseline of its first
                                                                 line rather than centered against the whole stack, where they would share a line with
                                                                 "joined". --}}
                                                            <div class="flex min-w-0 flex-col gap-1" data-session-detail>
                                                                <div class="flex flex-wrap items-center gap-2" data-session-where>
                                                                    {{-- Where it is working, as the two fields #220 split the
                                                                         one label into. The legacy fallback went with the
                                                                         column in #285; a request that still sends the old
                                                                         label is split before it is stored, so this reads the
                                                                         same value it used to fall back to. Every stored
                                                                         combination renders: a session naming only a location
                                                                         is a shape the endpoint accepts, and an earlier version
                                                                         printed `no project` for it. --}}
                                                                    @php($where = $session['repository'] ?? null)

                                                                    @if ($where !== null)
                                                                        <span class="opacity-90"><x-robot-council::avatar :repository="$where" /><code>{{ $where }}</code></span>
                                                                    @endif

                                                                    @if (($session['work_location'] ?? null) !== null)
                                                                        <span class="opacity-80"><code>{{ $session['work_location'] }}</code></span>
                                                                    @endif

                                                                    @if ($where === null && ($session['work_location'] ?? null) === null)
                                                                        <span class="opacity-80">no project</span>
                                                                    @endif

                                                                    {{-- Started around one read and on no other list (#424) --}}
                                                                    @if (($session['ephemeral'] ?? false) === true)
                                                                        <span class="opacity-80">ephemeral</span>
                                                                    @endif
                                                                </div>

                                                                {{-- When it joined and when it was last heard from (#419),
                                                                     which is what tells a restarted agent's new session from
                                                                     the old one beside it in the same checkout. Each is shown
                                                                     as a date and clock time a person can match to their own
                                                                     terminal, in the viewer's own zone and named (#487), and
                                                                     then how long ago; the exact instant is also in
                                                                     `datetime`, for software. --}}
                                                                @php($when = fn (string $instant): string => $time->at(\Illuminate\Support\Carbon::parse($instant)).' ('.\Illuminate\Support\Carbon::parse($instant)->diffForHumans().')')
                                                                <p data-session-joined>
                                                                    joined
                                                                    @if ($session['joined_at'] !== null)
                                                                        <time datetime="{{ \RobotCouncil\Support\DisplayTime::iso(\Illuminate\Support\Carbon::parse($session['joined_at'])) }}">{{ $when($session['joined_at']) }}</time>
                                                                    @else
                                                                        at an unrecorded time
                                                                    @endif
                                                                </p>
                                                                <p data-session-seen>
                                                                    last seen <time datetime="{{ \RobotCouncil\Support\DisplayTime::iso(\Illuminate\Support\Carbon::parse($session['last_seen_at'])) }}">{{ $when($session['last_seen_at']) }}</time>
                                                                </p>

                                                                {{-- **What it ASKED to be, presented as information and
                                                                     never as a nomination.** With installations keyed on
                                                                     developer, harness and machine, the coordinator
                                                                     checkout and the build checkouts present the same
                                                                     credential -- session id, repository and work location
                                                                     are all arbitrary or asserted -- so the panel shows what
                                                                     each session claims and the administrator picks. A
                                                                     queue with a pre-filled answer trains its reader to
                                                                     accept it. --}}
                                                                @if (($session['requested_role'] ?? null) !== null)
                                                                    <div class="flex flex-wrap items-center gap-2" data-session-request>
                                                                        <span class="badge badge-sm badge-warning">asked for {{ \RobotCouncil\Access\Role::labelOf($session['requested_role']) }}</span>

                                                                        <button type="button"
                                                                            wire:click="approveRole({{ \RobotCouncil\Support\WireArgument::of($session['id']) }}, '{{ \RobotCouncil\Support\WireArgument::of($session['requested_role']) }}')"
                                                                            @if ($session['requested_role'] === \RobotCouncil\Access\Role::Coordinator->value)
                                                                                wire:confirm="Approve coordinator? This session will be able to release, reassign or cancel any developer's task, and post directives to the whole fleet."
                                                                            @endif
                                                                            class="btn btn-target btn-primary">
                                                                            Approve
                                                                        </button>

                                                                        <button type="button"
                                                                            wire:click="denyRole({{ \RobotCouncil\Support\WireArgument::of($session['id']) }})"
                                                                            class="btn btn-target btn-outline">
                                                                            Deny
                                                                        </button>
                                                                    </div>
                                                                @endif

                                                                {{-- Imposing needs no request, which is what makes an
                                                                     emergency demotion possible. One control per role, from
                                                                     the enum, minus the one it already holds. --}}
                                                                <div class="flex flex-wrap items-center gap-2" data-session-actions>
                                                                    @foreach ($roles as $role)
                                                                        @if ($role->value !== $session['role'])
                                                                            <button type="button"
                                                                                wire:key="impose-{{ $session['id'] }}-{{ $role->value }}"
                                                                                wire:click="imposeRole({{ \RobotCouncil\Support\WireArgument::of($session['id']) }}, '{{ \RobotCouncil\Support\WireArgument::of($role) }}')"
                                                                                @if ($role === \RobotCouncil\Access\Role::Coordinator)
                                                                                    wire:confirm="Make this session a coordinator? It will be able to release, reassign or cancel any developer's task, and post directives to the whole fleet."
                                                                                @endif
                                                                                class="btn btn-target btn-outline">
                                                                                Make {{ $role->label() }}
                                                                            </button>
                                                                        @endif
                                                                    @endforeach

                                                                    {{-- Every listed session is live, so every one can be
                                                                         revoked, and every live session is listed (#414). --}}
                                                                    <button type="button"
                                                                        wire:click="revokeSession({{ \RobotCouncil\Support\WireArgument::of($session['id']) }})"
                                                                        class="btn btn-target btn-outline">
                                                                        Revoke session
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif

                                            {{-- Every live session is listed above (#414); the ones that have ended are
                                                 counted, said rather than left to be inferred --}}
                                            @if ($installation['sessions']['gone'] > 0)
                                                <p class="max-w-xl mt-2 text-meta opacity-80">
                                                    {{ $installation['sessions']['gone'] }} session(s) have ended.
                                                </p>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </section>
                @endforeach
            </div>
        @endif

        {{-- Outside the empty branch, because a reader who has paged past the end still needs the
             way back. This list is the only interface for revoking a credential, so a page it
             cannot reach is a control that is not there. --}}
        @if ($after !== null || $page['more'])
            <div class="flex items-center justify-end gap-2 pt-2">
                @if ($after !== null)
                    <button type="button" wire:click="showFirst" class="btn btn-target btn-outline">Newest</button>
                @endif

                @if ($page['more'] && $page['cursor'] !== null)
                    <button type="button" wire:click="showNext({{ \RobotCouncil\Support\WireArgument::of($page['cursor']) }})"
                        class="btn btn-target btn-outline">Older</button>
                @endif
            </div>
        @endif
    </div>
</div>
