{{--
    What the fleet is waiting on the signed-in developer for (#411). Read-only.

    Nothing is rendered unescaped, which the #67 guard enforces, and nothing here reaches a `wire:`
    expression but the poll interval. A ticket is linked only when repository-qualified, through
    `Support\TicketLink`. Every agent-supplied string -- a question, a reason, a machine label, a
    repository or slot -- is rendered as escaped text and nothing else.

    Both lists are cards along rows (#527), from the same components as the Lanes page's Waiting on
    a developer, and an owed item is the same partial there and here.
--}}

<div wire:poll.{{ \RobotCouncil\Support\WireArgument::of($pollSeconds) }}s class="flex max-w-5xl flex-col gap-6">
    <h1 class="text-2xl font-semibold">Waiting on me</h1>

    @include('robot-council::partials.glossary', ['terms' => ['waiting_on_developer', 'lane', 'blocked', 'ticket']])

    <p class="max-w-xl leading-relaxed">
        What the fleet's agents have asked you for, and which lanes are held until you act. Everyone's
        items are on the <a href="{{ route('robot-council.lanes') }}" class="link">Lanes page</a>; this
        shows only yours.
    </p>

    @if ($waiting['owed'] === [] && $waiting['holds'] === [])
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <p class="max-w-xl leading-relaxed" data-waiting-empty>Nothing is waiting on you: no agent has asked you for a decision or an action, and no lane is held on you.</p>
            </div>
        </div>
    @else
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title">Asked of you</h2>

                @if ($waiting['owed'] === [])
                    <p class="max-w-xl text-meta opacity-80">None: no agent has asked you for a decision or an action.</p>
                @else
                    <x-robot-council::card-grid>
                        @foreach ($waiting['owed'] as $item)
                            @include('robot-council::partials.owed-item', ['item' => $item])
                        @endforeach
                    </x-robot-council::card-grid>
                @endif
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title">Lanes held on you</h2>

                @if ($waiting['holds'] === [])
                    <p class="max-w-xl text-meta opacity-80">None: no lane is held until you act.</p>
                @else
                    <x-robot-council::card-grid>
                        @foreach ($waiting['holds'] as $hold)
                            <li wire:key="held-lane-{{ $hold['session_id'] }}" class="item-card leading-relaxed" data-held-lane>
                                <code>{{ $hold['label'] ?? $hold['machine'] }}</code>
                                <span class="badge badge-outline">Blocked</span>
                                &mdash; {{ $hold['waiting'] }}
                                <div class="text-meta opacity-90">held {{ $hold['held_at']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</div>
                            </li>
                        @endforeach
                    </x-robot-council::card-grid>
                @endif
            </div>
        </div>
    @endif
</div>
