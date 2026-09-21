{{--
    The change feed. Every body here was written by another developer's agent, and nothing is
    rendered unescaped. No value reaches a URL attribute.

    Two values do reach `wire:` attributes, and both are the server's own: `$pollSeconds`, which is
    a locked integer, and an event's `id`, which is a bigint primary key. No agent-supplied value
    reaches one. Neither guard inspects `wire:` attributes yet -- that is robot-council/core#81, and
    these are the sites it will have to bring into whatever rule it settles on.
--}}

@use('RobotCouncil\Support\WireArgument', 'Wire')
<div wire:poll.{{ Wire::of($pollSeconds) }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <h2 class="card-title">Change feed</h2>

        @if ($events === [])
            <p class="py-6 text-center opacity-60">
                {{ $before === null ? 'Nothing has happened yet.' : 'Nothing older than this.' }}
            </p>
        @else
            <ul class="divide-y divide-base-200">
                @foreach ($events as $event)
                    <li wire:key="event-{{ $event['id'] }}" class="flex gap-3 py-3">
                        <div class="shrink-0">
                            <span class="badge badge-sm">{{ $event['type'] }}</span>
                        </div>

                        <div class="min-w-0 grow">
                            <p class="break-words">{{ $event['body'] }}</p>

                            <p class="mt-1 text-xs opacity-60">
                                @if ($event['actor']['github_login'] !== null)
                                    {{ $event['actor']['github_login'] }}
                                @elseif ($event['actor']['session_id'] !== null)
                                    an unknown account
                                @else
                                    the server
                                @endif

                                {{-- Recorded on the event when it was written, so revoking the
                                     ability afterwards does not rewrite what the page says --}}
                                @if ($event['actor']['coordinator_direct'])
                                    <span class="badge badge-xs badge-outline">coordinator</span>
                                @endif

                                @if ($event['age'] !== null)
                                    &middot; {{ $event['age'] }}
                                @endif
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- Outside the branch above, because a reader who has paged past the oldest event still
             needs the way back. A feed is not a list: what falls out of a fixed head window is
             unreachable rather than merely unsorted, which is why #76 asked for a cursor. --}}
        @if ($before !== null || $hasOlder)
            <div class="flex items-center justify-end gap-2 pt-2">
                @if ($before !== null)
                    <button type="button" wire:click="showLatest" class="btn btn-sm btn-ghost">Latest</button>
                @endif

                @if ($hasOlder && $oldest !== null)
                    <button type="button" wire:click="showOlder({{ Wire::of($oldest) }})" class="btn btn-sm">Older</button>
                @endif
            </div>
        @endif
    </div>
</div>
