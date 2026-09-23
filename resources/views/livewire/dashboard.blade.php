{{--
    No `wire:poll` here. Each panel polls itself, and a parent refresh does not re-execute a child
    component -- Livewire spoofs an already-rendered child into a placeholder -- so a poll on this
    element would be a round trip that changes nothing.

    **A section that is not selected is not rendered, rather than hidden.** A panel hidden with a
    class still runs every query it would have run, and what this page offers is a lever on cost
    rather than on clutter. The decision on #187 kept the console one page for the readings that
    cross panels; this is the half of that decision that gives the cost back.

    Each mounted panel keeps a `wire:key`, so Livewire morphs the right element when a sibling
    appears or goes rather than re-using whichever happened to sit in that position.
--}}
@use('RobotCouncil\Support\WireArgument', 'Wire')
<div class="grid gap-4">
    {{--
        Above the panels, and outside any of them: the totals are counting queries, so a tile cannot
        report a page bound the way a `count()` over a panel's rows would. It is not selectable --
        three aggregates are what this page costs to answer "is anything happening at all", and a
        developer who wanted none of the panels still wants that.
    --}}
    <livewire:robot-council-fleet-totals :poll-seconds="$pollSeconds" />

    <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm opacity-70">Showing</span>

        <button type="button" wire:click="toggle('presence')"
            @class(['btn btn-xs', 'btn-primary' => \in_array('presence', $showing, true), 'btn-ghost' => ! \in_array('presence', $showing, true)])>Presence</button>

        <button type="button" wire:click="toggle('queue')"
            @class(['btn btn-xs', 'btn-primary' => \in_array('queue', $showing, true), 'btn-ghost' => ! \in_array('queue', $showing, true)])>Queue</button>

        <button type="button" wire:click="toggle('feed')"
            @class(['btn btn-xs', 'btn-primary' => \in_array('feed', $showing, true), 'btn-ghost' => ! \in_array('feed', $showing, true)])>Change feed</button>

        {{--
            Offered only to an admin, decided in `Support\DashboardSections::offered()` rather than
            here, so the control and the mount below cannot disagree about who may have it. The
            component authorizes its own mount, render and every action regardless.
        --}}
        @if (\in_array('administration', $offered, true))
            <button type="button" wire:click="toggle('administration')"
                @class(['btn btn-xs', 'btn-primary' => \in_array('administration', $showing, true), 'btn-ghost' => ! \in_array('administration', $showing, true)])>Administration</button>
        @endif
    </div>

    @if (\in_array('presence', $showing, true))
        <section id="robot-council-presence" class="scroll-mt-20">
            <livewire:robot-council-fleet-presence :poll-seconds="$pollSeconds" wire:key="section-presence" />
        </section>
    @endif

    @if (\in_array('queue', $showing, true))
        <section id="robot-council-queue" class="scroll-mt-20">
            <livewire:robot-council-task-board :poll-seconds="$pollSeconds" wire:key="section-queue" />
        </section>
    @endif

    @if (\in_array('feed', $showing, true))
        <section id="robot-council-change-feed" class="scroll-mt-20">
            <livewire:robot-council-change-feed :poll-seconds="$pollSeconds" wire:key="section-feed" />
        </section>
    @endif

    {{--
        `$showing` already excludes this for anyone who is not an admin, because
        `DashboardSections::from()` filters against the offered list rather than the full one. The
        `@if` is on the selection alone for that reason: two conditions here would be two places to
        get the same rule right.
    --}}
    @if (\in_array('administration', $showing, true))
        <section id="robot-council-administration" class="scroll-mt-20">
            <livewire:robot-council-administration :poll-seconds="$pollSeconds" wire:key="section-administration" />
        </section>
    @endif
</div>
