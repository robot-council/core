{{--
    A link that leaves the dashboard (#387). Every one opens in a new tab, and says so to every
    reader: the arrow shows it, and the words after it are read aloud, as WCAG's G201 asks. The
    target and rel are set here and cannot be overridden, so a page cannot quietly drop them;
    `ExternalLinkGuardTest` refuses an external `<a>` written anywhere else without them.

    Takes `href`, which the caller has already built (by `TicketLink`, which admits only GitHub),
    and whatever other attributes the link needs, such as its `class`.
--}}
@props(['href'])
<a href="{{ $href }}" {{ $attributes->except(['target', 'rel']) }} target="_blank" rel="noopener noreferrer">{{ $slot }}<svg class="ms-0.5 inline size-3 align-baseline" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4.5 2.5h5v5M9.5 2.5l-7 7"/></svg><span class="sr-only"> (opens in a new tab)</span></a>
