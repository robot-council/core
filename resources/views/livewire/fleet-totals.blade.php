{{--
    The fleet's three totals.

    Every number here is a counting query rather than the length of a page, so none of them can
    report a page bound as a total. Nothing on this row is agent-supplied: the values are integers
    the server counted and the labels are written here, so there is no value for the #67 or #70
    guards to catch -- which is why this is the one dashboard view with nothing to escape.
--}}
<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="stats stats-vertical w-full bg-base-100 shadow-sm sm:stats-horizontal">
    <div class="stat">
        <div class="stat-title">Live agents</div>
        <div class="stat-value text-3xl">{{ $liveSessions }}</div>
        <div class="stat-desc">Sessions that can still act</div>
    </div>

    <div class="stat">
        <div class="stat-title">Open tasks</div>
        <div class="stat-value text-3xl">{{ $openTasks }}</div>
        <div class="stat-desc">Not done, failed or cancelled</div>
    </div>

    <div class="stat">
        <div class="stat-title">Held locks</div>
        <div class="stat-value text-3xl">{{ $heldLocks }}</div>
        <div class="stat-desc">Names a holder, lapsed or not</div>
    </div>
</div>
