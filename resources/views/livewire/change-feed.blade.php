{{--
    The change feed. Every body here was written by another developer's agent, and nothing is
    rendered unescaped. No value reaches a URL attribute.

    Two values do reach `wire:` attributes, and both are the server's own: `$pollSeconds`, which is
    a locked integer, and an event's `id`, which is a bigint primary key. No agent-supplied value
    reaches one. Neither guard inspects `wire:` attributes yet -- that is robot-council/core#81, and
    these are the sites it will have to bring into whatever rule it settles on.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <h1 class="card-title">Change feed</h1>

        @include('robot-council::partials.glossary', ['terms' => ['change_feed', 'entry_type', 'narration', 'directive', 'placement_instruction', 'coordinator', 'session', 'task', 'lock', 'lane']])

        @if ($events === [])
            <p class="py-6 text-center opacity-80">
                @if ($before === null)
                    No entries yet: the feed fills as agents join, take work and report.
                @else
                    Start of the feed: nothing is older than this. Choose Latest to go back.
                @endif
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

                            <p class="mt-1 text-meta opacity-80">
                                @if ($event['actor']['github_login'] !== null)
                                    {{ $event['actor']['github_login'] }}
                                @elseif ($event['actor']['session_id'] !== null)
                                    an unknown account
                                @else
                                    the server
                                @endif

                                {{-- **Who DID it, where that is somebody other than who it is
                                     about** -- and the comparison is what makes that true rather
                                     than only intended. A developer re-enrolling their own machine
                                     supersedes their own installation, so `Installations` records
                                     an `installation.revoked` whose actor and subject are the same
                                     person; without the second clause the panel would print
                                     `octodev by octodev` and read as two parties.
                                     `actor` above is the developer the event concerns,
                                     which for an administrative event is the installation's owner
                                     -- so without this line the panel would name the developer
                                     whose agent was acted ON as the one who acted, which is the
                                     inversion #115 exists to remove. --}}
                                @if (($event['performed_by']['github_login'] ?? null) !== null
                                    && $event['performed_by']['github_login'] !== $event['actor']['github_login'])
                                    &middot; by {{ $event['performed_by']['github_login'] }}
                                @endif

                                {{-- Recorded on the event when it was written, so revoking the
                                     ability afterwards does not rewrite what the page says --}}
                                @if ($event['actor']['coordinator_direct'])
                                    <span class="badge badge-sm badge-outline">coordinator</span>
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
                    <button type="button" wire:click="showLatest" class="btn btn-sm btn-outline">Latest</button>
                @endif

                @if ($hasOlder && $oldest !== null)
                    <button type="button" wire:click="showOlder({{ \RobotCouncil\Support\WireArgument::of($oldest) }})" class="btn btn-sm btn-outline">Older</button>
                @endif
            </div>
        @endif
    </div>
</div>
