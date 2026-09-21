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
<div wire:poll.{{ $pollSeconds }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <h2 class="card-title">Installations</h2>

        <p class="text-sm opacity-70">
            What each machine may do, and which of its sessions are alive. Changes take effect on
            the next request, not on the next renewal.
        </p>

        @if ($installations === [])
            <p class="py-6 text-center opacity-60">No machine has enrolled yet.</p>
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
                                        wire:click="revokeInstallation({{ $installation['id'] }})"
                                        wire:confirm="Revoke this installation? Its credential and every session token it issued stop working immediately."
                                        class="btn btn-sm btn-warning">
                                        Revoke installation
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            {{-- One control per grantable ability, from `Ability::grantable()`, so
                                 what is offered is exactly what the action accepts. `*` is not in
                                 that list and is refused by the action regardless of what is
                                 rendered here.

                                 The label carries the state as well as the action, rather than
                                 leaving the filled button to mean "held". Colour alone cannot say
                                 which of two buttons is the granted one to a reader who does not
                                 see it, and the distinction here decides authorization. --}}
                            @foreach ($grantable as $ability)
                                @if (in_array($ability->value, $installation['abilities'], true))
                                    <button type="button"
                                        wire:click="revokeAbility({{ $installation['id'] }}, '{{ $ability->value }}')"
                                        class="btn btn-xs btn-primary">
                                        Revoke {{ $ability->value }}
                                    </button>
                                @else
                                    <button type="button"
                                        wire:click="grant({{ $installation['id'] }}, '{{ $ability->value }}')"
                                        class="btn btn-xs btn-ghost">
                                        Grant {{ $ability->value }}
                                    </button>
                                @endif
                            @endforeach
                        </div>

                        @if ($installation['sessions'] !== [])
                            <ul class="mt-3 space-y-1">
                                @foreach ($installation['sessions'] as $session)
                                    <li wire:key="admin-session-{{ $session['id'] }}"
                                        class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="badge badge-sm">{{ $session['status'] }}</span>

                                        @if (($session['project_id'] ?? null) === null)
                                            <span class="opacity-50">no project</span>
                                        @else
                                            <span class="opacity-70">{{ $session['project_id'] }}</span>
                                        @endif

                                        @if ($session['revocable'])
                                            <button type="button"
                                                wire:click="revokeSession({{ $session['id'] }})"
                                                class="btn btn-xs btn-ghost">
                                                Revoke session
                                            </button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
