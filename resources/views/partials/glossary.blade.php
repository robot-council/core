{{--
    The words a page uses, explained on that page (#402).

    A disclosure rather than a title attribute, because touch and keyboard users cannot reach a
    tooltip. Each term is the defining instance, so it is a `dfn`. Pass `terms` as keys of the
    glossary translation file; an unknown key throws in `Support\Glossary` rather than printing
    itself. The explanations are read from that file, which says why they do not live here.
--}}
<details class="text-meta" data-glossary>
    <summary class="cursor-pointer py-3 font-medium">What the words on this page mean</summary>
    <dl class="mt-1 grid max-w-3xl gap-x-4 gap-y-2 leading-relaxed sm:grid-cols-[max-content_1fr]">
        @foreach (\RobotCouncil\Support\Glossary::entries($terms) as $entry)
            <dt class="font-semibold"><dfn id="term-{{ $entry['key'] }}">{{ $entry['term'] }}</dfn></dt>
            <dd>{{ $entry['means'] }}</dd>
        @endforeach
    </dl>
</details>
