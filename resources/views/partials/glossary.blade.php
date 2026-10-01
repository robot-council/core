{{--
    The words a page uses, explained on that page (#402).

    A disclosure rather than a title attribute, because touch and keyboard users cannot reach a
    tooltip. Each term is the defining instance, so it is a `dfn`. Pass `terms` as keys of the
    glossary translation file; an unknown key throws in `Support\Glossary` rather than printing
    itself. The explanations are read from that file, which says why they do not live here.

    `wire:ignore.self` keeps the reader's choice: the server never renders `open`, and Livewire's
    morph removes any attribute the new markup lacks, so on a polling page the disclosure closed
    itself within one poll of being opened. With it the morph updates the children and leaves the
    element's own attributes alone.
--}}
<details wire:ignore.self class="text-meta" data-glossary>
    <summary class="cursor-pointer py-3 font-medium">What the words on this page mean</summary>
    {{-- Each term and its meaning are one `div`, which a `dl` allows, so an entry is a unit
         (#486). Entries are set apart by a rule and by more space than lies within one, at every
         width: before this the gap between entries equalled the gap inside one, and a wrapped
         meaning ran into the next term. From `sm` up the list is two columns and each entry a
         subgrid row, so every meaning starts at the same x; a row sets only its row gap, because a
         subgrid's own column gap would replace the list's. --}}
    <dl class="mt-1 max-w-3xl divide-y divide-separator leading-relaxed sm:grid sm:grid-cols-[max-content_1fr] sm:gap-x-4" data-glossary-list>
        @foreach (\RobotCouncil\Support\Glossary::entries($terms) as $entry)
            <div class="flex flex-col gap-y-1 py-3 sm:col-span-2 sm:grid sm:grid-cols-subgrid sm:gap-y-0" data-glossary-entry>
                <dt class="font-semibold"><dfn id="term-{{ $entry['key'] }}">{{ $entry['term'] }}</dfn></dt>
                <dd>{{ $entry['means'] }}</dd>
            </div>
        @endforeach
    </dl>
</details>
