{{--
    No `wire:poll` here. Each panel polls itself, and a parent refresh does not re-execute a child
    component -- Livewire spoofs an already-rendered child into a placeholder -- so a poll on this
    element would be a round trip that changes nothing.
--}}
<div class="grid gap-4">
    <livewire:robot-council-fleet-presence :poll-seconds="$pollSeconds" />

    <livewire:robot-council-task-board :poll-seconds="$pollSeconds" />

    <livewire:robot-council-change-feed :poll-seconds="$pollSeconds" />

    {{--
        Mounted only for an admin, so a developer who may see the dashboard never renders a panel
        that would refuse them. This decides what is *shown*; the component authorizes every action
        and its own render regardless, because a control that is not drawn is not an authorization
        boundary.

        `$isAdmin` is resolved in `Livewire\Dashboard` rather than asked for with `@can`, which
        would resolve the host's default guard instead of `robot-council.auth.guard` and hide the
        panel from a real admin on a host where those differ.
    --}}
    @if ($isAdmin)
        <livewire:robot-council-administration :poll-seconds="$pollSeconds" />
    @endif
</div>
