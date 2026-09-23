{{--
    No `wire:poll` here. Each panel polls itself, and a parent refresh does not re-execute a child
    component -- Livewire spoofs an already-rendered child into a placeholder -- so a poll on this
    element would be a round trip that changes nothing.

    Each panel is wrapped in a section the sidebar's jump links target. `scroll-mt-20` keeps the
    heading clear of the sticky header, which would otherwise cover whatever was jumped to.
--}}
<div class="grid gap-4">
    {{--
        Above the panels, and outside any of them: the totals are counting queries, so a tile cannot
        report a page bound the way a `count()` over a panel's rows would.
    --}}
    <livewire:robot-council-fleet-totals :poll-seconds="$pollSeconds" />

    <section id="robot-council-presence" class="scroll-mt-20">
        <livewire:robot-council-fleet-presence :poll-seconds="$pollSeconds" />
    </section>

    <section id="robot-council-queue" class="scroll-mt-20">
        <livewire:robot-council-task-board :poll-seconds="$pollSeconds" />
    </section>

    <section id="robot-council-change-feed" class="scroll-mt-20">
        <livewire:robot-council-change-feed :poll-seconds="$pollSeconds" />
    </section>

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
        <section id="robot-council-administration" class="scroll-mt-20">
            <livewire:robot-council-administration :poll-seconds="$pollSeconds" />
        </section>
    @endif
</div>
