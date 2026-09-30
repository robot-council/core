{{--
    A link that leaves the dashboard (#387). Every one opens in a new tab and says so in words every
    reader gets, seen or heard, as WCAG's G201 asks: words rather than an icon, because the
    accessibility rule gives every icon a text label and forbids leaning on `title=`. The target
    and rel are written ahead of anything passed in, and a passed `target`, `rel` or `href` in any
    case is dropped, so a page cannot quietly undo them; `ExternalLinkGuardTest` refuses an external
    link written anywhere else without them.

    Takes a `reference` for a ticket or pull request, or a `login` for a profile, and builds the URL
    itself with `TicketLink`, which admits only GitHub, so `EscapingGuardTest` still sees a URL the
    server built rather than a value handed in. A value `TicketLink` refuses renders as the plain
    slot, never as a link to nowhere. Any other attribute, such as `class`, passes through.
--}}
@props(['reference' => null, 'login' => null])
@if ($login !== null && \RobotCouncil\Support\TicketLink::profile($login) !== null)
<a href="{{ \RobotCouncil\Support\TicketLink::profile($login) }}" target="_blank" rel="noopener noreferrer" {{ $attributes->filter(static fn (mixed $value, string $key): bool => ! in_array(strtolower($key), ['href', 'target', 'rel'], true)) }}>{{ $slot }} (new tab)</a>
@elseif ($login === null && \RobotCouncil\Support\TicketLink::url($reference) !== null)
<a href="{{ \RobotCouncil\Support\TicketLink::url($reference) }}" target="_blank" rel="noopener noreferrer" {{ $attributes->filter(static fn (mixed $value, string $key): bool => ! in_array(strtolower($key), ['href', 'target', 'rel'], true)) }}>{{ $slot }} (new tab)</a>
@else
{{ $slot }}
@endif
