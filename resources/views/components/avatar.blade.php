{{--
    A developer's GitHub picture, as a circle beside their login (#410). One component, so size and
    shape are the same everywhere; #416 builds repository and organization pictures on it.

    Decorative: the login written beside it names the developer, so the picture is hidden from
    assistive technology and says nothing the text does not. The letter is not selectable, so
    copying a login does not copy it too. Sized in rem, so it scales with the reader's text and zoom
    rather than against them; its border is transparent, and draws the disc under forced colors.

    Takes the `login`, and renders nothing without one: an account nobody can name is not a
    developer a picture could stand for. The picture is the one sign-in stored, read by
    `GitHubAccounts::avatarOf()`, which asks GitHub nothing; the browser loads it. Pass
    `:pictured="false"` for an account that has not signed in: its login is only what GitHub or an
    administrator said, and a picture found under it would be whoever signed in with it before.
    Beneath the picture sits the login's first letter on a neutral disc, which is what shows for a
    developer with no stored picture, and for one whose picture fails to load, since the
    dashboard's script takes a failed picture away. Any other attribute goes on the outer element.
--}}
@props(['login' => null, 'pictured' => true])
@if ($login !== null && $login !== '')<span {{ $attributes->merge(['class' => 'avatar avatar-placeholder me-1.5 shrink-0 align-middle']) }} aria-hidden="true" data-avatar><span class="relative grid size-6 place-items-center rounded-full border border-transparent bg-neutral text-neutral-content"><span class="text-meta leading-none select-none">{{ mb_strtoupper(mb_substr($login, 0, 1)) }}</span>@if ($pictured && \RobotCouncil\Support\GitHubAccounts::avatarOf($login) !== null)<img src="{{ \RobotCouncil\Support\GitHubAccounts::avatarOf($login) }}" alt="" class="absolute inset-0 size-full rounded-full object-cover" decoding="async" referrerpolicy="no-referrer">@endif</span></span>@endif