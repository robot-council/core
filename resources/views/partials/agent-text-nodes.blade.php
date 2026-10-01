{{--
    The nodes `Support\AgentText` made from an agent's text, each string printed through `{{ }}`
    (#537). Written as one line per node and with no indentation, because whitespace between
    inline elements is rendered: a space would open between a code span and the comma after it.

    `loneLink` is set by `agent-text` when the whole field is one link (#554), and sizes that link
    as a 44px target. A nested include inherits it, which is harmless: it is true only when the
    field is that one link, and a link cannot contain another.
--}}
@foreach ($nodes as $node)
@if ($node['type'] === 'text'){{ $node['text'] }}@elseif ($node['type'] === 'code')<code>{{ $node['text'] }}</code>@elseif ($node['type'] === 'emphasis')<em>@include('robot-council::partials.agent-text-nodes', ['nodes' => $node['children']])</em>@elseif ($node['type'] === 'strong')<strong>@include('robot-council::partials.agent-text-nodes', ['nodes' => $node['children']])</strong>@elseif ($node['type'] === 'break')<br>@elseif ($node['type'] === 'link' && ($loneLink ?? false))<x-robot-council::external-link :reference="$node['reference']" class="link inline-block min-h-11 min-w-11 py-3">@include('robot-council::partials.agent-text-nodes', ['nodes' => $node['children']])</x-robot-council::external-link>@elseif ($node['type'] === 'link')<x-robot-council::external-link :reference="$node['reference']" class="link">@include('robot-council::partials.agent-text-nodes', ['nodes' => $node['children']])</x-robot-council::external-link>@elseif ($node['type'] === 'paragraph')<p>@include('robot-council::partials.agent-text-nodes', ['nodes' => $node['children']])</p>@elseif ($node['type'] === 'list' && $node['ordered'] && $node['start'] !== null && $node['start'] !== 1)<ol start="{{ (int) $node['start'] }}">@foreach ($node['items'] as $item)<li>@include('robot-council::partials.agent-text-nodes', ['nodes' => $item])</li>@endforeach</ol>@elseif ($node['type'] === 'list' && $node['ordered'])<ol>@foreach ($node['items'] as $item)<li>@include('robot-council::partials.agent-text-nodes', ['nodes' => $item])</li>@endforeach</ol>@elseif ($node['type'] === 'list')<ul>@foreach ($node['items'] as $item)<li>@include('robot-council::partials.agent-text-nodes', ['nodes' => $item])</li>@endforeach</ul>@endif
@endforeach
