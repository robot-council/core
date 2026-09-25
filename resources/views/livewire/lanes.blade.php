{{--
    The lane board (#317). Rendered from measured state, never typed.

    Nothing is rendered unescaped, which the #67 guard enforces, and nothing here reaches a `wire:`
    expression. A ticket reference is linked only when it is repository-qualified; the URL comes from
    `Support\TicketLink`, and a bare `#N` renders as text, since linking it would invent a repository.
    Every agent-supplied string -- a machine label, a harness, a repository, a slot, a branch, a
    title, a hold's party -- is rendered as escaped text and nothing else.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="flex flex-col gap-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h1 class="text-2xl font-semibold">Lanes</h1>
        <span class="text-sm opacity-70" data-last-change>
            Last change {{ $board['last_change']?->copy()->setTimezone($timezone)->format('Y-m-d H:i T') ?? 'none recorded' }}
            &middot; read {{ $board['observed_at']->copy()->setTimezone($timezone)->format('H:i T') }}
        </span>
    </div>

    @if ($board['meters'] !== [])
        <div class="flex flex-wrap gap-3">
            @foreach ($board['meters'] as $repository => $meter)
                <div wire:key="meter-{{ $repository }}" class="card bg-base-100 shadow-sm">
                    <div class="card-body p-4">
                        <div class="text-xs opacity-70">{{ $repository }} open issues</div>
                        {{-- Unreadable is a dash and says so, never a number: no count reported,
                             or one older than `backlog.stale_after_minutes` (#339) --}}
                        @if ($meter['count'] === null)
                            <div class="text-xl" data-meter="unreadable">&mdash; <span class="text-sm opacity-70">count unreadable</span></div>
                        @else
                            <div class="text-xl" data-meter="read">{{ $meter['count'] }}</div>
                            {{-- The direction in words and a sign, not only a colour: the theme's
                                 success colour measures 1.96:1 on a card in the light theme, so
                                 only "up" -- the bad direction -- is coloured, with the measured
                                 error colour --}}
                            <div class="text-xs" data-meter-delta>
                                @if ($meter['delta'] === null)
                                    <span class="opacity-70">no baseline today</span>
                                @elseif ($meter['delta'] > 0)
                                    <span class="font-semibold text-error">up {{ $meter['delta'] }}</span>
                                @elseif ($meter['delta'] < 0)
                                    <span>down {{ abs($meter['delta']) }}</span>
                                @else
                                    <span class="opacity-70">flat</span>
                                @endif
                                <span class="opacity-70">&middot; read {{ \Carbon\CarbonInterval::seconds($meter['age_seconds'] ?? 0)->cascade()->forHumans(short: true) }} ago</span>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @forelse ($board['lanes'] as $repository => $lanes)
        <div wire:key="lanes-{{ $repository }}" class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title">{{ $repository === '' ? 'No repository reported' : $repository }}</h2>

                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Lane</th>
                                <th>State</th>
                                <th>Watcher</th>
                                <th>On what</th>
                                <th>Known since</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lanes as $lane)
                                <tr wire:key="lane-{{ $lane['id'] }}" @class(['bg-base-200' => $lane['is_gate']])>
                                    <td>
                                        <div class="font-medium">{{ $lane['developer'] ?? 'unknown developer' }} &middot; {{ $lane['machine'] }}@if ($lane['slot'] !== null) / {{ $lane['slot'] }}@endif</div>
                                        <div class="text-xs opacity-70">{{ $lane['harness'] }}@if ($lane['is_gate']) &middot; gate @endif</div>
                                    </td>
                                    <td data-state="{{ $lane['state'] }}">{{ $lane['state'] }}</td>
                                    {{-- Its own column, separate from State: not reported until #337 --}}
                                    <td class="opacity-70" data-watcher>not reported</td>
                                    <td>
                                        @if ($lane['state'] === 'Working' && is_array($lane['on_what']))
                                            @php($work = $lane['on_what'])
                                            <div>
                                                @if (\RobotCouncil\Support\TicketLink::url($work['ticket']) !== null)
                                                    <a href="{{ \RobotCouncil\Support\TicketLink::url($work['ticket']) }}" class="link" rel="noopener noreferrer">{{ $work['ticket'] }}</a>
                                                @elseif ($work['ticket'] !== null)
                                                    {{ $work['ticket'] }}
                                                @else
                                                    task #{{ $work['task_id'] }}, no ticket
                                                @endif
                                                @if ($work['hand_back'])
                                                    <span class="badge badge-sm badge-warning" data-hand-back>hand-back</span>
                                                @endif
                                                @if ($work['also_holds'] > 0)
                                                    <span class="badge badge-sm" data-also-holds>and {{ $work['also_holds'] }} more held</span>
                                                @endif
                                            </div>
                                            <div class="text-xs opacity-70">
                                                {{ $work['branch'] }}
                                                &middot; {{ $work['taken_up'] ? 'taken up' : ($work['blocked'] ? 'taken up, blocked' : 'placed, not taken up') }}
                                                &middot; {{ $work['provenance'] }}
                                            </div>
                                        @elseif (is_array($lane['on_what']))
                                            <span data-on-what>
                                            @if (\RobotCouncil\Support\TicketLink::url($lane['on_what']['party']) !== null)
                                                <a href="{{ \RobotCouncil\Support\TicketLink::url($lane['on_what']['party']) }}" class="link" rel="noopener noreferrer">{{ $lane['on_what']['party'] }}</a>
                                            @else
                                                {{ $lane['on_what']['party'] }}
                                            @endif
                                            &mdash; {{ $lane['on_what']['what'] }}
                                            </span>
                                        @else
                                            <span class="opacity-60">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="text-sm opacity-70">{{ $lane['known_since']?->diffForHumans() ?? 'never' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($repository !== '')
                    <div class="mt-2">
                        <h3 class="font-medium">Pull requests</h3>
                        @if (($board['pull_requests'][$repository] ?? []) === [])
                            <p class="text-sm opacity-60">None open, as far as GitHub has told the fleet.</p>
                        @else
                            <ul class="text-sm">
                                @foreach ($board['pull_requests'][$repository] as $pull)
                                    <li wire:key="pull-{{ $repository }}-{{ $pull['number'] }}">
                                        <a href="{{ \RobotCouncil\Support\TicketLink::url($pull['reference']) }}" class="link" rel="noopener noreferrer">#{{ $pull['number'] }}</a>
                                        {{ $pull['title'] }}
                                        <span class="badge badge-sm" data-pull-state>{{ $pull['state'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="text-xs opacity-60">Which pull request a gate is running is not reported yet.</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @empty
        <p class="py-6 text-center opacity-60">No lane is live.</p>
    @endforelse

    @if ($board['truncated'])
        <p class="text-sm opacity-70">Only the first {{ \RobotCouncil\Support\LaneBoard::MAX_LANES }} lanes are shown.</p>
    @endif

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">Waiting on a developer</h2>

            {{-- `General` first, then one section per developer (#335). An item naming a developer
                 the fleet no longer knows is dropped by `Support\OwedItems::open()`, never moved to
                 `General` where it would stop being anyone's --}}
            @forelse ($board['waiting'] as $section)
                <div wire:key="owed-{{ $section['developer'] ?? '-general' }}" data-owed-section="{{ $section['developer'] ?? 'General' }}">
                    <h3 class="font-medium">{{ $section['developer'] ?? 'General' }}</h3>
                    <ul class="text-sm">
                        @foreach ($section['items'] as $item)
                            <li wire:key="owed-item-{{ $item['id'] }}" data-owed-item>
                                @if (\RobotCouncil\Support\TicketLink::url($item['ticket']) !== null)
                                    <a href="{{ \RobotCouncil\Support\TicketLink::url($item['ticket']) }}" class="link" rel="noopener noreferrer">{{ $item['ticket'] }}</a>
                                @endif
                                &mdash; {{ $item['question'] }}
                                <span class="opacity-70">{{ $item['why'] }} &middot; waiting {{ $item['recorded_at']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="text-sm opacity-60">Nothing is waiting on a developer.</p>
            @endforelse
        </div>
    </div>

    <p class="text-xs opacity-60">
        Rendered from measured state, not edited by hand. Liveness comes from observed presence, not from
        any declared roster.
    </p>
</div>
