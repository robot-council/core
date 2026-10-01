{{--
    Agent-written prose in the safe Markdown subset (#537): code spans, emphasis, lists and GitHub
    links, with everything else shown as the characters that were written. `Support\AgentText`
    parses it into plain nodes and `partials/agent-text-nodes` prints every string through `{{ }}`,
    so nothing an agent wrote becomes markup; a link reaches the page only through `external-link`.

    `inline` is for a field shown inside a line -- a title, a reason after a dash -- where a list
    or a paragraph would break the markup around it. Without it the text is paragraphs and lists in
    a `div`, styled by `.agent-text` in the stylesheet, so its container must be one that can hold
    them: a `div` or a cell, never a `p` or a `span`.
--}}
@props(['text' => null, 'inline' => false])
@if ($inline)
@include('robot-council::partials.agent-text-nodes', ['nodes' => \RobotCouncil\Support\AgentText::inline($text)])
@else
<div {{ $attributes->class(['agent-text']) }}>@include('robot-council::partials.agent-text-nodes', ['nodes' => \RobotCouncil\Support\AgentText::blocks($text)])</div>
@endif
