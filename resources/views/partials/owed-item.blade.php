{{--
    What the fleet is waiting on a developer for, as one card in a `card-grid` (#390, #527): the
    ticket on a line of its own, the question as the main text, and the reason and age beneath it.
    Shared by the Lanes page and the Waiting on me page, so an owed item reads the same on both.
    The card is not a link as a whole: the ticket stays the one thing to interact with.

    The question and the reason are a coordinator's prose, shown in the safe Markdown subset
    (#537) through `agent-text`: the question as paragraphs and lists, and the reason inline in its
    line. A ticket is linked only when repository-qualified, through `Support\TicketLink`.
--}}
<li wire:key="owed-item-{{ $item['id'] }}" class="item-card space-y-1" data-owed-item>
    <div class="text-meta">
        @if (\RobotCouncil\Support\TicketLink::url($item['ticket']) !== null)
            {{-- On a line of its own it is a control standing alone, not a link
                 inside a sentence, so it takes the 44px target (#480): ordinary text
                 padded to 44px, never a flex box, which would split the
                 reference and "(new tab)" into two items (#536) --}}
            <x-robot-council::external-link :reference="$item['ticket']" class="link inline-block min-h-11 py-3"><code>{{ $item['ticket'] }}</code></x-robot-council::external-link>
        @else
            <code>{{ $item['ticket'] }}</code>
        @endif
    </div>
    <x-robot-council::agent-text :text="$item['question']" class="leading-relaxed" data-owed-question />
    <p class="text-meta leading-relaxed opacity-90" data-owed-why><x-robot-council::agent-text :text="$item['why']" inline /> &middot; waiting {{ $item['recorded_at']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</p>
</li>
