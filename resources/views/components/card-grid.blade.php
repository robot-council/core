{{--
    A list laid out as cards along rows (#527), for the lists of owed items and held lanes. The
    grid is `.card-grid` in the stylesheet: each column at least 22rem, or the whole width where
    that is less, so the count follows the screen.

    `role="list"` is written ahead of anything passed in, because Safari stops reporting a `<ul>`
    as a list once its markers are gone. The order of the slot is the reading order, left to right
    and then down, so keyboard and screen-reader order is the order the cards are drawn in.
--}}
<ul role="list" {{ $attributes->filter(static fn (mixed $value, string $key): bool => strtolower($key) !== 'role')->class(['card-grid']) }}>{{ $slot }}</ul>
