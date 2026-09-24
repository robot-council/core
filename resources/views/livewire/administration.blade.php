{{--
    Installation administration. The only dashboard panel whose controls write.

    Two rules hold here, and neither is a habit of this file. Nothing is rendered unescaped, which
    the #67 guard enforces over every template under `resources/views/`. And **only values this
    package chose reach a `wire:` expression** -- a row id, and an ability from the fixed list in
    `Access\Ability`. Every agent-supplied string on this page (a harness, a machine label, a
    project id) is rendered as text and reaches no attribute at all, which is what keeps #81's gap
    theoretical here rather than load-bearing.

    Nothing on this page is a credential. `Support\InstallationList` reads no column that holds one.
--}}

@use('RobotCouncil\Support\WireArgument', 'Wire')
<div wire:poll.{{ Wire::of($pollSeconds) }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="card-title">Installations</h2>

            {{-- A revoked or expired installation is behind a scope rather than sorted below a
                 live one. Sorting would put a mutable column in the ordering, and a cursor over
                 one of those skips rows silently -- which is the defect #83 records. --}}
            <div class="flex gap-1">
                <button type="button" wire:click="showScope('{{ Wire::of(\RobotCouncil\Support\Scope::Live) }}')"
                    class="btn btn-xs {{ $scope === \RobotCouncil\Support\Scope::Live ? 'btn-primary' : 'btn-ghost' }}">
                    Usable ({{ $page['live'] }})
                </button>

                <button type="button" wire:click="showScope('{{ Wire::of(\RobotCouncil\Support\Scope::All) }}')"
                    class="btn btn-xs {{ $scope === \RobotCouncil\Support\Scope::All ? 'btn-primary' : 'btn-ghost' }}">
                    All ({{ $page['live'] + $page['retired'] }})
                </button>
            </div>
        </div>

        <p class="text-sm opacity-70">
            What each machine may do, and which of its sessions are alive. Changes take effect on
            the next request, not on the next renewal.
        </p>

        @if ($installations === [])
            <p class="py-6 text-center opacity-60">
                {{ $scope === \RobotCouncil\Support\Scope::Live && $page['retired'] > 0
                    ? 'No machine is currently usable. Choose All to see the revoked and expired ones.'
                    : 'No machine has enrolled yet.' }}
            </p>
        @else
            <ul class="divide-y divide-base-200">
                @foreach ($installations as $installation)
                    <li wire:key="installation-{{ $installation['id'] }}" class="py-4">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                {{-- Agent-supplied, escaped, and rendered as text --}}
                                <div class="font-medium">
                                    {{ $installation['harness'] }} on {{ $installation['machine_label'] }}
                                </div>

                                <div class="text-xs opacity-70">
                                    approved for {{ $installation['github_login'] ?? 'an unknown account' }}
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
                                        wire:click="revokeInstallation({{ Wire::of($installation['id']) }})"
                                        wire:confirm="Revoke this installation? Its credential and every session token it issued stop working immediately."
                                        class="btn btn-sm btn-warning">
                                        Revoke installation
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if ($installation['sessions']['shown'] !== [])
                            <ul class="mt-3 space-y-1">
                                @foreach ($installation['sessions']['shown'] as $session)
                                    <li wire:key="admin-session-{{ $session['id'] }}"
                                        class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="badge badge-sm">{{ $session['status'] }}</span>

                                        {{-- The role, which is the whole of what this session may
                                             do. There is no longer a machine-level list beside it:
                                             a stored ability list stopped reaching any session, and
                                             the controls that wrote it are gone with it. --}}
                                        <span class="badge badge-sm badge-outline">{{ $session['role'] }}</span>

                                        {{-- Where it is working, as the two fields #220 split the
                                             one label into, with the legacy one as the fallback.
                                             Every stored combination renders: a session naming only
                                             a location is a shape the endpoint accepts, and an
                                             earlier version printed `no project` for it. --}}
                                        @php($where = $session['repository'] ?? $session['project_id'] ?? null)

                                        @if ($where !== null)
                                            <span class="opacity-70">{{ $where }}</span>
                                        @endif

                                        @if (($session['work_location'] ?? null) !== null)
                                            <span class="opacity-60">{{ $session['work_location'] }}</span>
                                        @endif

                                        @if ($where === null && ($session['work_location'] ?? null) === null)
                                            <span class="opacity-60">no project</span>
                                        @endif

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
                                            <span class="badge badge-sm badge-warning">asked for {{ $session['requested_role'] }}</span>

                                            <button type="button"
                                                wire:click="approveRole({{ Wire::of($session['id']) }}, '{{ Wire::of($session['requested_role']) }}')"
                                                @if ($session['requested_role'] === \RobotCouncil\Access\Role::Coordinator->value)
                                                    wire:confirm="Approve coordinator? This session will be able to release, reassign or cancel any developer's task, and post directives to the whole fleet."
                                                @endif
                                                class="btn btn-xs btn-primary">
                                                Approve
                                            </button>

                                            <button type="button"
                                                wire:click="denyRole({{ Wire::of($session['id']) }})"
                                                class="btn btn-xs btn-ghost">
                                                Deny
                                            </button>
                                        @endif

                                        {{-- Imposing needs no request, which is what makes an
                                             emergency demotion possible. One control per role, from
                                             the enum, minus the one it already holds. --}}
                                        @foreach ($roles as $role)
                                            @if ($role->value !== $session['role'])
                                                <button type="button"
                                                    wire:click="imposeRole({{ Wire::of($session['id']) }}, '{{ Wire::of($role) }}')"
                                                    @if ($role === \RobotCouncil\Access\Role::Coordinator)
                                                        wire:confirm="Make this session a coordinator? It will be able to release, reassign or cancel any developer's task, and post directives to the whole fleet."
                                                    @endif
                                                    class="btn btn-xs btn-ghost">
                                                    Make {{ $role->value }}
                                                </button>
                                            @endif
                                        @endforeach

                                        {{-- Every listed session is live, so every one can be
                                             revoked. The reader bounds the list rather than the
                                             view hiding rows. --}}
                                        <button type="button"
                                            wire:click="revokeSession({{ Wire::of($session['id']) }})"
                                            class="btn btn-xs btn-ghost">
                                            Revoke session
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- What is not on the list, said rather than left to be inferred from its
                             length. A list truncated at its limit looks exactly like a complete
                             one, and the number that would show otherwise is the one not printed. --}}
                        @if ($installation['sessions']['hidden'] > 0 || $installation['sessions']['gone'] > 0)
                            <p class="mt-2 text-xs opacity-60">
                                @if ($installation['sessions']['hidden'] > 0)
                                    {{ $installation['sessions']['hidden'] }} more live session(s) not shown.
                                @endif

                                @if ($installation['sessions']['gone'] > 0)
                                    {{ $installation['sessions']['gone'] }} session(s) have ended.
                                @endif
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- Outside the empty branch, because a reader who has paged past the end still needs the
             way back. This list is the only interface for revoking a credential, so a page it
             cannot reach is a control that is not there. --}}
        @if ($after !== null || $page['more'])
            <div class="flex items-center justify-end gap-2 pt-2">
                @if ($after !== null)
                    <button type="button" wire:click="showFirst" class="btn btn-sm btn-ghost">Newest</button>
                @endif

                @if ($page['more'] && $page['cursor'] !== null)
                    <button type="button" wire:click="showNext({{ Wire::of($page['cursor']) }})"
                        class="btn btn-sm">Older</button>
                @endif
            </div>
        @endif
    </div>
</div>
