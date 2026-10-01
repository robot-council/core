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

        @include('robot-council::partials.glossary', ['terms' => ['change_feed', 'entry_type', 'narration', 'directive', 'lane_quiet', 'lane_condition', 'placement_instruction', 'coordinator', 'session', 'task', 'lock', 'lane']])

        @if ($events === [])
            <p class="py-6 text-center opacity-80">
                @if ($before === null)
                    No entries yet: the feed fills as agents join, take work and report.
                @else
                    Start of the feed: nothing is older than this. Choose Latest to go back.
                @endif
            </p>
        @else
            {{-- A table, as every other list of records on the dashboard is (#311): the age, the
                 type and who acted are short, fixed values, so each has a column and lines up
                 across rows, and the body -- the one free-text value, and the event itself -- takes
                 what is left. Below `md` a row stacks, each cell under its column's name, so a long
                 type does not squeeze the body into a sliver on a phone; the type still sits above
                 the body there, as #481 kept it. The body wraps rather than truncates, at a
                 readable measure however wide the window (SC 1.4.8, the measure recorded on #311
                 from #494). --}}
            <div class="overflow-x-auto" data-feed>
                <table class="table table-stack" role="table">
                    <thead role="rowgroup">
                        <tr role="row">
                            <th role="columnheader" class="w-px">Age</th>
                            <th role="columnheader" class="w-px">Type</th>
                            <th role="columnheader" class="w-px">Who</th>
                            <th role="columnheader">What happened</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup">
                        @foreach ($events as $event)
                            <tr role="row" wire:key="event-{{ $event['id'] }}" class="align-top">
                                <td role="cell" data-label="Age" class="whitespace-nowrap text-meta opacity-90">
                                    @if ($event['age'] !== null)
                                        {{ $event['age'] }}
                                    @endif
                                </td>

                                <td role="cell" data-label="Type">
                                    <span class="badge badge-sm" data-feed-type>{{ $event['type'] }}</span>
                                </td>

                                {{-- Each line kept whole, since the column is only as wide as its
                                     widest line: a login is a fixed value, like the age beside it --}}
                                <td role="cell" data-label="Who" class="whitespace-nowrap text-meta">
                                    <div data-feed-who>
                                        @if ($event['actor']['github_login'] !== null)
                                            <x-robot-council::avatar :login="$event['actor']['github_login']" />{{ $event['actor']['github_login'] }}
                                        @elseif ($event['actor']['session_id'] !== null)
                                            an unknown account
                                        @else
                                            the server
                                        @endif
                                    </div>

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
                                         inversion #115 exists to remove. It stays in this cell,
                                         since it qualifies who acted. --}}
                                    @if (($event['performed_by']['github_login'] ?? null) !== null
                                        && $event['performed_by']['github_login'] !== $event['actor']['github_login'])
                                        <div class="opacity-90">by {{ $event['performed_by']['github_login'] }}</div>
                                    @endif

                                    {{-- Recorded on the event when it was written, so revoking the
                                         ability afterwards does not rewrite what the page says --}}
                                    @if ($event['actor']['coordinator_direct'])
                                        <div><span class="badge badge-sm badge-outline">coordinator</span></div>
                                    @endif
                                </td>

                                <td role="cell" data-label="What happened" data-feed-body>
                                    {{-- At the body size, since a table's cells are otherwise at
                                         the smaller table size and this is the page's prose --}}
                                    <div class="max-w-xl text-body leading-relaxed">
                                        {{-- What an agent or a person wrote, in the safe Markdown
                                             subset (#537): narration, a directive, and a placement's
                                             instruction, which is the coordinator's own words (#540).
                                             A body the package composed stays the text it is --}}
                                        @if (in_array($event['type'], [\RobotCouncil\Models\FleetEventType::Narration->value, \RobotCouncil\Models\FleetEventType::Directive->value, \RobotCouncil\Models\FleetEventType::PlacementInstruction->value], true))
                                            <x-robot-council::agent-text :text="$event['body']" class="break-words" data-feed-prose />
                                        @else
                                            <p class="break-words">{{ $event['body'] }}</p>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Outside the branch above, because a reader who has paged past the oldest event still
             needs the way back. A feed is not a list: what falls out of a fixed head window is
             unreachable rather than merely unsorted, which is why #76 asked for a cursor. --}}
        @if ($before !== null || $hasOlder)
            <div class="flex items-center justify-end gap-2 pt-2">
                @if ($before !== null)
                    <button type="button" wire:click="showLatest" class="btn btn-target btn-outline">Latest</button>
                @endif

                @if ($hasOlder && $oldest !== null)
                    <button type="button" wire:click="showOlder({{ \RobotCouncil\Support\WireArgument::of($oldest) }})" class="btn btn-target btn-outline">Older</button>
                @endif
            </div>
        @endif
    </div>
</div>
