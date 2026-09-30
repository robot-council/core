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
            Last change
            @if ($board['last_change'] !== null)
                <time datetime="{{ \RobotCouncil\Support\DisplayTime::iso($board['last_change']) }}">{{ $time->at($board['last_change']) }}</time>
            @else
                none recorded
            @endif
            &middot; read <time datetime="{{ \RobotCouncil\Support\DisplayTime::iso($board['observed_at']) }}">{{ $time->clockAt($board['observed_at']) }}</time>
        </span>
    </div>

    @include('robot-council::partials.glossary', ['terms' => ['lane', 'harness', 'working', 'idle', 'parked', 'blocked', 'not_observed', 'gate', 'watcher', 'tickets_held', 'task', 'ticket', 'hand_back', 'subagent', 'branch', 'taken_up', 'validating', 'pull_request_state', 'known_since', 'open_issues', 'waiting_on_developer']])

    @if ($board['meters'] !== [])
        {{-- Whose 8 a.m.: the fleet's, which everyone shares, named rather than assumed (#487) --}}
        <p class="text-meta max-w-xl leading-relaxed opacity-90" data-baseline>Each count is compared with the same repository's count at <time datetime="{{ \RobotCouncil\Support\Backlog::BASELINE_AT }}">{{ $time->baseline() }}</time> each day.</p>

        <div class="flex flex-wrap gap-3">
            @foreach ($board['meters'] as $repository => $meter)
                <div wire:key="meter-{{ $repository }}" class="card bg-base-100 shadow-sm">
                    <div class="card-body p-4">
                        <div class="text-meta opacity-90"><x-robot-council::avatar :repository="$repository" /><code>{{ $repository }}</code> open issues</div>
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
                                    <span class="opacity-90">no <time datetime="{{ \RobotCouncil\Support\Backlog::BASELINE_AT }}">{{ $time->baseline() }}</time> count yet</span>
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
                <h2 class="card-title"><x-robot-council::avatar :repository="$repository" />{{ $repository === '' ? 'No repository reported' : $repository }}</h2>

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
                                        {{-- Named as the queue and the locks page name a session (#421) --}}
                                        @if ($lane['label'] !== null)
                                            <div class="font-medium"><code>{{ $lane['label'] }}</code> &middot; <x-robot-council::avatar :login="$lane['developer']" />{{ $lane['developer'] ?? 'unknown developer' }}</div>
                                        @else
                                            <div class="font-medium"><x-robot-council::avatar :login="$lane['developer']" />{{ $lane['developer'] ?? 'unknown developer' }} &middot; <code>{{ $lane['machine'] }}</code>@if ($lane['slot'] !== null) / <code>{{ $lane['slot'] }}</code>@endif</div>
                                        @endif
                                        <div class="text-meta opacity-90"><code>{{ $lane['harness'] }}</code>@if ($lane['is_gate']) &middot; gate @endif</div>
                                        {{-- Occupancy against capacity (#409): the number `lane_free` refuses a
                                             placement on once the two are equal --}}
                                        <div class="text-meta opacity-90"><span data-occupancy>{{ $lane['holding'] }} / {{ $lane['capacity'] }}</span> {{ $lane['holding'] === 1 ? 'task' : 'tasks' }} held</div>
                                    </td>
                                    <td role="cell" data-label="State" class="2xl:whitespace-nowrap" data-state="{{ $lane['state'] }}">{{ $lane['state'] }}</td>
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
                                                    <x-robot-council::external-link :reference="$lane['on_what']['gate_pull_request']" class="link"><code>{{ $lane['on_what']['gate_pull_request'] }}</code></x-robot-council::external-link>
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
                                                                <x-robot-council::external-link :reference="$work['ticket']" class="link inline-flex min-h-11 min-w-11 items-center"><code>{{ $work['ticket'] }}</code></x-robot-council::external-link>
                                                            @elseif ($work['ticket'] !== null)
                                                                <code>{{ $work['ticket'] }}</code>
                                                            @else
                                                                {{-- A task that names no ticket, in its issue or at the start of
                                                                     its title, is shown by its title (#422), whole: a task id says
                                                                     nothing to a person, and no text here is cut short (#401) --}}
                                                                {{ $work['title'] }}
                                                                <span class="text-meta opacity-90">(task <code>#{{ $work['task_id'] }}</code>)</span>
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
                                        @elseif (is_array($lane['on_what']) && ($lane['on_what']['kind'] ?? null) === 'repository')
                                            {{-- Idle on purpose (#471): its repository has nothing it could start --}}
                                            <span data-on-what>Nothing startable in <code>{{ $lane['on_what']['party'] }}</code></span>
                                        @elseif (is_array($lane['on_what']))
                                            <span data-on-what>
                                            @if (\RobotCouncil\Support\TicketLink::url($lane['on_what']['party']) !== null)
                                                <x-robot-council::external-link :reference="$lane['on_what']['party']" class="link"><code>{{ $lane['on_what']['party'] }}</code></x-robot-council::external-link>
                                            @else
                                                {{ $lane['on_what']['party'] }}
                                            @endif
                                            &mdash; {{ $lane['on_what']['what'] }}
                                            </span>
                                        @else
                                            <span class="opacity-80">&mdash;</span>
                                        @endif
                                    </td>
                                    <td role="cell" data-label="Known since" class="2xl:whitespace-nowrap text-meta opacity-90">{{ $lane['known_since']?->diffForHumans() ?? 'never' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($repository !== '')
                    <div class="mt-2">
                        <h3 class="font-medium">Pull requests</h3>
                        @if (($board['pull_requests'][$repository] ?? []) === [])
                            <p class="max-w-xl text-meta opacity-80">None open: GitHub has reported no open pull request for this repository.</p>
                        @else
                            <ul class="text-meta">
                                @foreach ($board['pull_requests'][$repository] as $pull)
                                    <li wire:key="pull-{{ $repository }}-{{ $pull['number'] }}">
                                        <x-robot-council::external-link :reference="$pull['reference']" class="link"><code>#{{ $pull['number'] }}</code></x-robot-council::external-link>
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
        <p class="mx-auto max-w-xl py-6 text-center opacity-80">No lanes: no session that can take work is connected. A lane appears here when an agent joins the fleet.</p>
    @endforelse

    @if ($board['truncated'])
        <p class="max-w-xl text-meta opacity-90">Only the first {{ \RobotCouncil\Support\LaneBoard::MAX_LANES }} lanes are shown.</p>
    @endif

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">Waiting on a developer</h2>

            {{-- `General` first, then one section per developer (#335). An item naming a developer
                 the fleet no longer knows is dropped by `Support\OwedItems::open()`, never moved to
                 `General` where it would stop being anyone's.

                 Built to be scanned at the load it carries (#390): each section is a bordered block
                 whose heading says how many items it holds, items are divided by a rule, and each
                 item reads as three parts -- the ticket on its own line, the question as the main
                 text, and the reason and age beneath it as secondary. The same three parts, in the
                 same order, as the Waiting on me page's items. --}}
            @if ($board['waiting'] === [])
                <p class="max-w-xl text-meta opacity-80">Nothing waiting: no agent has asked a developer for a decision or an action.</p>
            @else
                <div class="space-y-4">
                @foreach ($board['waiting'] as $section)
                <section wire:key="owed-{{ $section['developer'] ?? '-general' }}" class="rounded-box border border-base-300 p-3 sm:p-4" data-owed-section="{{ $section['developer'] ?? 'General' }}">
                    <h3 class="text-lg font-semibold"><x-robot-council::avatar :login="$section['developer']" />{{ $section['developer'] ?? 'General' }} <span class="text-meta font-normal opacity-90" data-owed-count>&middot; {{ count($section['items']) }} {{ count($section['items']) === 1 ? 'item' : 'items' }}</span></h3>
                    <ul class="divide-y divide-base-300">
                        @foreach ($section['items'] as $item)
                            <li wire:key="owed-item-{{ $item['id'] }}" class="max-w-xl space-y-1 py-3" data-owed-item>
                                <div class="text-meta">
                                    @if (\RobotCouncil\Support\TicketLink::url($item['ticket']) !== null)
                                        <x-robot-council::external-link :reference="$item['ticket']" class="link"><code>{{ $item['ticket'] }}</code></x-robot-council::external-link>
                                    @else
                                        <code>{{ $item['ticket'] }}</code>
                                    @endif
                                </div>
                                <p class="leading-relaxed" data-owed-question>{{ $item['question'] }}</p>
                                <p class="text-meta leading-relaxed opacity-90">{{ $item['why'] }} &middot; waiting {{ $item['recorded_at']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
                @endforeach
                </div>
            @endif
        </div>
    </div>

    <p class="max-w-xl text-meta opacity-80">
        Rendered from measured state, not edited by hand. Liveness comes from observed presence, not from
        any declared roster.
    </p>
</div>
