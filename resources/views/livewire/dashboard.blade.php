{{--
    The overview.

    It mounts no panel -- each of the four is a page of its own as of #215, so this holds the
    question a developer opens the console to ask, and a way into each section. No `wire:poll`
    here: the totals row polls itself, and a parent refresh does not re-execute a child.

    The links duplicate the sidebar deliberately. The sidebar is off-canvas below daisyUI's `lg`
    breakpoint, so on a phone this is the only way through.
--}}
<div class="grid gap-4">
    <livewire:robot-council-fleet-totals :poll-seconds="$pollSeconds" />

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">Sections</h2>

            <ul class="menu w-full gap-1 p-0">
                <li>
                    <a href="{{ route('robot-council.presence') }}">
                        <span class="grow">Presence</span>
                        <span class="text-xs opacity-60">Agents and the locks they hold</span>
                    </a>
                </li>

                <li>
                    <a href="{{ route('robot-council.queue') }}">
                        <span class="grow">Queue</span>
                        <span class="text-xs opacity-60">What the fleet has been asked to do</span>
                    </a>
                </li>

                <li>
                    <a href="{{ route('robot-council.feed') }}">
                        <span class="grow">Change feed</span>
                        <span class="text-xs opacity-60">What has happened, newest first</span>
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
                            <span class="text-xs opacity-60">Installations and their sessions</span>
                        </a>
                    </li>
                @endif
            </ul>
        </div>
    </div>
</div>
