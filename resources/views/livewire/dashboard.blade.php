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

            {{-- These descriptions are dimmed one level less than the placeholders elsewhere, and
                 the reason is the surface rather than the text. daisyUI paints a hovered or
                 keyboard-focused row with `base-content` at 10%, which lifts the background toward
                 the text: dimmer than this measures 4.33:1 there in the light theme, against the
                 4.5:1 this size needs, while at rest on the card it measures 4.64:1 and passes.
                 A row that only fails while it is being pointed at is still a row that fails. --}}
            <ul class="menu w-full gap-1 p-0">
                <li>
                    <a href="{{ route('robot-council.presence') }}">
                        <span class="grow">Presence</span>
                        <span class="text-xs opacity-70">Agents and the locks they hold</span>
                    </a>
                </li>

                <li>
                    <a href="{{ route('robot-council.queue') }}">
                        <span class="grow">Queue</span>
                        <span class="text-xs opacity-70">What the fleet has been asked to do</span>
                    </a>
                </li>

                <li>
                    <a href="{{ route('robot-council.feed') }}">
                        <span class="grow">Change feed</span>
                        <span class="text-xs opacity-70">What has happened, newest first</span>
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
                            <span class="text-xs opacity-70">Installations and their sessions</span>
                        </a>
                    </li>
                @endif
            </ul>
        </div>
    </div>
</div>
