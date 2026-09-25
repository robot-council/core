{{--
    The overview.

    It mounts no panel -- each is a page of its own as of #215, and agents and locks each have one
    as of #308 -- so this holds the question a developer opens the console to ask, and a way into
    each section. No `wire:poll` here: the totals row polls itself, and a parent refresh does not
    re-execute a child.

    The links duplicate the sidebar deliberately. The sidebar is off-canvas below daisyUI's `lg`
    breakpoint, so on a phone this is the only way through.
--}}
<div class="grid gap-4">
    <livewire:robot-council-fleet-totals :poll-seconds="$pollSeconds" />

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">Sections</h2>

            {{-- These descriptions are dimmed one level less than the placeholders elsewhere,
                 because they sit on a surface that moves. daisyUI paints a hovered or
                 keyboard-focused row with `base-content` at 10%, which lifts the background toward
                 the text. When the two steps were 60 and 70, the dimmer one measured 4.33:1 there
                 against the 4.5:1 bar of the time. Since #310 raised them to 80 and 90 for the
                 7:1 AAA bar, both clear it on a hovered row, so the lighter step here is a margin
                 rather than a requirement: the dimmer one's closest case is 7.69:1, in the dark
                 theme. `DashboardThemeTest` measures both steps on this surface. --}}
            <ul class="menu w-full gap-1 p-0">
                <li>
                    <a href="{{ route('robot-council.agents') }}">
                        <span class="grow">Agents</span>
                        <span class="text-meta opacity-90">Who is working, and where</span>
                    </a>
                </li>

                <li>
                    <a href="{{ route('robot-council.locks') }}">
                        <span class="grow">Locks</span>
                        <span class="text-meta opacity-90">What the fleet is holding, and who holds it</span>
                    </a>
                </li>

                <li>
                    <a href="{{ route('robot-council.lanes') }}">
                        <span class="grow">Lanes</span>
                        {{-- The lane board's summary (#317): how many lanes are in each of its states --}}
                        <span class="text-meta opacity-90" data-lanes-summary>
                            {{ collect($laneCounts)->map(fn (int $count, string $state): string => $count.' '.mb_strtolower($state))->implode(', ') }}
                        </span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('robot-council.queue') }}">
                        <span class="grow">Queue</span>
                        <span class="text-meta opacity-90">What the fleet has been asked to do</span>
                    </a>
                </li>

                <li>
                    <a href="{{ route('robot-council.feed') }}">
                        <span class="grow">Change feed</span>
                        <span class="text-meta opacity-90">What has happened, newest first</span>
                    </a>
                </li>

                {{--
                    Offered only to an admin, decided in `Http\ViewComposers\DashboardLayoutComposer`
                    on the package's own guard rather than with `@can`, which resolves the host's
                    default. `Livewire\Administration` refuses in `mount()` regardless, which is
                    what makes the route safe whether or not this link is drawn.
                --}}
                @if ($isAdmin)
                    <li>
                        <a href="{{ route('robot-council.administration') }}">
                            <span class="grow">Administration</span>
                            <span class="text-meta opacity-90">Installations and their sessions</span>
                        </a>
                    </li>
                @endif
            </ul>
        </div>
    </div>
</div>
