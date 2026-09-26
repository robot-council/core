{{--
    The lane board (#317). Rendered from measured state, never typed.

    Nothing is rendered unescaped, which the #67 guard enforces, and nothing here reaches a `wire:`
    expression. A ticket reference is linked only when it is repository-qualified; the URL comes from
    `Support\TicketLink`, and a bare `#N` renders as text, since linking it would invent a repository.
    Every agent-supplied string -- a machine label, a harness, a repository, a slot, a branch, a
    sub-label, a title, a hold's party -- is rendered as escaped text and nothing else.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="flex flex-col gap-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h1 class="text-2xl font-semibold">Lanes</h1>
        <span class="text-meta opacity-90" data-last-change>
            Last change {{ $board['last_change']?->copy()->setTimezone($timezone)->format('Y-m-d H:i T') ?? 'none recorded' }}
            &middot; read {{ $board['observed_at']->copy()->setTimezone($timezone)->format('H:i T') }}
        </span>
    </div>

    @include('robot-council::partials.glossary', ['terms' => ['lane', 'harness', 'working', 'idle', 'parked', 'blocked', 'not_observed', 'gate', 'watcher', 'tickets_held', 'task', 'ticket', 'hand_back', 'subagent', 'branch', 'taken_up', 'validating', 'pull_request_state', 'known_since', 'open_issues', 'waiting_on_developer']])

    @if ($board['meters'] !== [])
        <div class="flex flex-wrap gap-3">
            @foreach ($board['meters'] as $repository => $meter)
                <div wire:key="meter-{{ $repository }}" class="card bg-base-100 shadow-sm">
                    <div class="card-body p-4">
                        <div class="text-meta opacity-90"><code>{{ $repository }}</code> open issues</div>
                        {{-- Unreadable is a dash and says so, never a number: no count reported,
                             or one older than `backlog.stale_after_minutes` (#339) --}}
                        @if ($meter['count'] === null)
                            <div class="text-xl" data-meter="unreadable">&mdash; <span class="text-meta opacity-90">count unreadable</span></div>
                        @else
                            <div class="text-xl" data-meter="read">{{ $meter['count'] }}</div>
                            {{-- The direction in words and a sign, not only a colour: the theme's
                                 success colour measures 1.96:1 on a card in the light theme, so
                                 only "up" -- the bad direction -- is coloured, with the measured
                                 error colour --}}
                            <div class="text-meta" data-meter-delta>
                                @if ($meter['delta'] === null)
                                    <span class="opacity-90">no baseline today</span>
                                @elseif ($meter['delta'] > 0)
                                    <span class="font-semibold text-error">up {{ $meter['delta'] }}</span>
                                @elseif ($meter['delta'] < 0)
                                    <span>down {{ abs($meter['delta']) }}</span>
                                @else
                                    <span class="opacity-90">flat</span>
                                @endif
                                <span class="opacity-90">&middot; read {{ \Carbon\CarbonInterval::seconds($meter['age_seconds'] ?? 0)->cascade()->forHumans(short: true) }} ago</span>
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
                    <table class="table table-stack" role="table">
                        <thead role="rowgroup">
                            <tr role="row">
                                <th role="columnheader">Lane</th>
                                <th role="columnheader">State</th>
                                <th role="columnheader">Watcher</th>
                                <th role="columnheader">On what</th>
                                <th role="columnheader">Known since</th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup">
                            @foreach ($lanes as $lane)
                                <tr role="row" wire:key="lane-{{ $lane['id'] }}" @class(['bg-base-200' => $lane['is_gate']])>
                                    <td role="cell" data-label="Lane">
                                        <div class="font-medium">{{ $lane['developer'] ?? 'unknown developer' }} &middot; <code>{{ $lane['machine'] }}</code>@if ($lane['slot'] !== null) / <code>{{ $lane['slot'] }}</code>@endif</div>
                                        <div class="text-meta opacity-90"><code>{{ $lane['harness'] }}</code>@if ($lane['is_gate']) &middot; gate @endif</div>
                                        {{-- Occupancy against capacity (#409): the number `lane_free` refuses a
                                             placement on once the two are equal --}}
                                        <div class="text-meta opacity-90"><span data-occupancy>{{ $lane['holding'] }} / {{ $lane['capacity'] }}</span> {{ $lane['holding'] === 1 ? 'ticket' : 'tickets' }} held</div>
                                    </td>
                                    <td role="cell" data-label="State" data-state="{{ $lane['state'] }}">{{ $lane['state'] }}</td>
                                    {{-- Its own column, separate from State, from the watcher's own heartbeat (#337) --}}
                                    <td role="cell" data-label="Watcher" data-watcher="{{ $lane['watcher']['state'] }}">
                                        @switch ($lane['watcher']['state'])
                                            @case('alive')
                                                alive <span class="text-meta opacity-90">{{ $lane['watcher']['age_seconds'] }}s ago</span>
                                                @break
                                            @case('stale')
                                                <span class="font-semibold text-error">stale {{ $lane['watcher']['age_seconds'] }}s</span>
                                                @break
                                            @case('unknown')
                                                <span class="opacity-90">unknown, re-read</span>
                                                @break
                                            @default
                                                <span class="opacity-90">absent</span>
                                        @endswitch
                                    </td>
                                    <td role="cell" data-label="On what">
                                        @if ($lane['state'] === 'Working' && is_array($lane['on_what']) && isset($lane['on_what']['gate_pull_request']))
                                            <span data-gate-run>
                                                validating
                                                @if (\RobotCouncil\Support\TicketLink::url($lane['on_what']['gate_pull_request']) !== null)
                                                    <a href="{{ \RobotCouncil\Support\TicketLink::url($lane['on_what']['gate_pull_request']) }}" class="link" rel="noopener noreferrer"><code>{{ $lane['on_what']['gate_pull_request'] }}</code></a>
                                                @endif
                                                @if ($lane['repository'] !== null)
                                                    &middot; {{ $board['queue_depth'][$lane['repository']] ?? 0 }} queued
                                                @endif
                                            </span>
                                        @elseif ($lane['state'] === 'Working' && is_array($lane['on_what']))
                                            {{-- Every held ticket, not the first with a count (#409). The sub-label is
                                                 the lane's own word for which subagent works it, rendered as text --}}
                                            <ul class="flex flex-col gap-2">
                                                @foreach ($lane['on_what']['tasks'] as $work)
                                                    <li wire:key="lane-{{ $lane['id'] }}-task-{{ $work['task_id'] }}" data-held-task>
                                                        <div>
                                                            @if (\RobotCouncil\Support\TicketLink::url($work['ticket']) !== null)
                                                                <a href="{{ \RobotCouncil\Support\TicketLink::url($work['ticket']) }}" class="link" rel="noopener noreferrer"><code>{{ $work['ticket'] }}</code></a>
                                                            @elseif ($work['ticket'] !== null)
                                                                <code>{{ $work['ticket'] }}</code>
                                                            @else
                                                                task <code>#{{ $work['task_id'] }}</code>, no ticket
                                                            @endif
                                                            @if ($work['hand_back'])
                                                                <span class="badge badge-sm badge-warning" data-hand-back>hand-back</span>
                                                            @endif
                                                            @if ($work['sub_label'] !== null)
                                                                <span class="text-meta">subagent <code data-sub-label>{{ $work['sub_label'] }}</code></span>
                                                            @endif
                                                        </div>
                                                        <div class="text-meta opacity-90">
                                                            <code>{{ $work['branch'] }}</code>
                                                            &middot; {{ $work['taken_up'] ? 'taken up' : ($work['blocked'] ? 'taken up, blocked' : 'placed, not taken up') }}
                                                            &middot; {{ $work['provenance'] }}
                                                        </div>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @elseif (is_array($lane['on_what']))
                                            <span data-on-what>
                                            @if (\RobotCouncil\Support\TicketLink::url($lane['on_what']['party']) !== null)
                                                <a href="{{ \RobotCouncil\Support\TicketLink::url($lane['on_what']['party']) }}" class="link" rel="noopener noreferrer"><code>{{ $lane['on_what']['party'] }}</code></a>
                                            @else
                                                {{ $lane['on_what']['party'] }}
                                            @endif
                                            &mdash; {{ $lane['on_what']['what'] }}
                                            </span>
                                        @else
                                            <span class="opacity-80">&mdash;</span>
                                        @endif
                                    </td>
                                    <td role="cell" data-label="Known since" class="text-meta opacity-90">{{ $lane['known_since']?->diffForHumans() ?? 'never' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($repository !== '')
                    <div class="mt-2">
                        <h3 class="font-medium">Pull requests</h3>
                        @if (($board['pull_requests'][$repository] ?? []) === [])
                            <p class="text-meta opacity-80">None open: GitHub has reported no open pull request for this repository.</p>
                        @else
                            <ul class="text-meta">
                                @foreach ($board['pull_requests'][$repository] as $pull)
                                    <li wire:key="pull-{{ $repository }}-{{ $pull['number'] }}">
                                        <a href="{{ \RobotCouncil\Support\TicketLink::url($pull['reference']) }}" class="link" rel="noopener noreferrer"><code>#{{ $pull['number'] }}</code></a>
                                        {{ $pull['title'] }}
                                        <span class="badge badge-sm" data-pull-state>{{ $pull['state'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @empty
        <p class="py-6 text-center opacity-80">No lanes: no session that can take work is connected. A lane appears here when an agent joins the fleet.</p>
    @endforelse

    @if ($board['truncated'])
        <p class="text-meta opacity-90">Only the first {{ \RobotCouncil\Support\LaneBoard::MAX_LANES }} lanes are shown.</p>
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
                    <ul class="text-meta">
                        @foreach ($section['items'] as $item)
                            <li wire:key="owed-item-{{ $item['id'] }}" data-owed-item>
                                @if (\RobotCouncil\Support\TicketLink::url($item['ticket']) !== null)
                                    <a href="{{ \RobotCouncil\Support\TicketLink::url($item['ticket']) }}" class="link" rel="noopener noreferrer"><code>{{ $item['ticket'] }}</code></a>
                                @endif
                                &mdash; {{ $item['question'] }}
                                <span class="opacity-90">{{ $item['why'] }} &middot; waiting {{ $item['recorded_at']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="text-meta opacity-80">Nothing waiting: no agent has asked a developer for a decision or an action.</p>
            @endforelse
        </div>
    </div>

    <p class="text-meta opacity-80">
        Rendered from measured state, not edited by hand. Liveness comes from observed presence, not from
        any declared roster.
    </p>
</div>
