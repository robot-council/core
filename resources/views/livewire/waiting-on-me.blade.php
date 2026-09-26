{{--
    What the fleet is waiting on the signed-in developer for (#411). Read-only.

    Nothing is rendered unescaped, which the #67 guard enforces, and nothing here reaches a `wire:`
    expression but the poll interval. A ticket is linked only when repository-qualified, through
    `Support\TicketLink`. Every agent-supplied string -- a question, a reason, a machine label, a
    repository or slot -- is rendered as escaped text and nothing else.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="flex flex-col gap-6">
    <h1 class="text-2xl font-semibold">Waiting on me</h1>

    @include('robot-council::partials.glossary', ['terms' => ['waiting_on_developer', 'lane', 'blocked', 'ticket']])

    <p class="max-w-xl leading-relaxed">
        What the fleet's agents have asked you for, and which lanes are held until you act. Everyone's
        items are on the Lanes page; this shows only yours.
    </p>

    @if ($waiting['owed'] === [] && $waiting['holds'] === [])
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <p class="leading-relaxed" data-waiting-empty>Nothing is waiting on you: no agent has asked you for a decision or an action, and no lane is held on you.</p>
            </div>
        </div>
    @else
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title">Asked of you</h2>

                @if ($waiting['owed'] === [])
                    <p class="text-meta opacity-80">None: no agent has asked you for a decision or an action.</p>
                @else
                    <ul class="divide-y divide-base-200">
                        @foreach ($waiting['owed'] as $item)
                            <li wire:key="owed-item-{{ $item['id'] }}" class="py-3" data-owed-item>
                                @if (\RobotCouncil\Support\TicketLink::url($item['ticket']) !== null)
                                    <a href="{{ \RobotCouncil\Support\TicketLink::url($item['ticket']) }}" class="link" rel="noopener noreferrer"><code>{{ $item['ticket'] }}</code></a>
                                @else
                                    <code>{{ $item['ticket'] }}</code>
                                @endif
                                &mdash; {{ $item['question'] }}
                                <div class="text-meta opacity-90">{{ $item['why'] }} &middot; waiting {{ $item['recorded_at']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title">Lanes held on you</h2>

                @if ($waiting['holds'] === [])
                    <p class="text-meta opacity-80">None: no lane is held until you act.</p>
                @else
                    <ul class="divide-y divide-base-200">
                        @foreach ($waiting['holds'] as $hold)
                            <li wire:key="held-lane-{{ $hold['session_id'] }}" class="py-3" data-held-lane>
                                <code>{{ $hold['label'] ?? $hold['machine'] }}</code>
                                <span class="badge badge-outline">Blocked</span>
                                &mdash; waiting on you for {{ $hold['reason'] }}
                                <div class="text-meta opacity-90">held {{ $hold['held_at']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</div>
