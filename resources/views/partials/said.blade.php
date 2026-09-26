{{--
    What the last action did, or why it did nothing, beside the control that caused it (#402).

    The region is always on the page and only its words change, because a screen reader often
    announces nothing for a live region that arrives with its text already inside it. `show` says
    whether this is the place the words belong; everything printed is escaped.
--}}
<div role="status" @class([$class ?? '']) data-said-region>
    @if ($show)
        <p @class(['font-semibold text-error' => $refused, 'font-medium' => ! $refused]) data-said>{{ $said }}</p>
    @endif
</div>
