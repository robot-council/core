{{--
    Agent-written prose in the safe Markdown subset (#537): code spans, emphasis, lists and GitHub
    links, with everything else shown as the characters that were written. `Support\AgentText`
    parses it into plain nodes and `partials/agent-text-nodes` prints every string through `{{ }}`,
    so nothing an agent wrote becomes markup; a link reaches the page only through `external-link`.

    `inline` is for a field shown inside a line -- a title, a reason after a dash -- where a list
    or a paragraph would break the markup around it. Without it the text is paragraphs and lists in
    a `div`, styled by `.agent-text` in the stylesheet, so its container must be one that can hold
    them: a `div` or a cell, never a `p` or a `span`.

    `standalone`, with `inline`, is for a field that is the whole of its element, such as a task
    title on the Queue or the Lanes page (#554). Text that is nothing but one link, emphasized or
    not, then renders that link as a 44px target, the size of the Lanes ticket link, because with no
    words round it SC 2.5.8's Inline exception no longer applies. A link with any text beside it
    keeps the line's height, and what is rendered is the same subset either way: only the link's
    size changes.
--}}
@props(['text' => null, 'inline' => false, 'standalone' => false])
@if ($inline)
@php
    $nodes = \RobotCouncil\Support\AgentText::inline($text);
    // Down through emphasis that wraps the whole field, to whatever it wraps
    $within = $nodes;
    while (count($within) === 1 && in_array($within[0]['type'], ['emphasis', 'strong'], true)) {
        $within = $within[0]['children'];
    }
@endphp
@include('robot-council::partials.agent-text-nodes', ['nodes' => $nodes, 'loneLink' => $standalone && count($within) === 1 && $within[0]['type'] === 'link'])
@else
<div {{ $attributes->class(['agent-text']) }}>@include('robot-council::partials.agent-text-nodes', ['nodes' => \RobotCouncil\Support\AgentText::blocks($text)])</div>
@endif
