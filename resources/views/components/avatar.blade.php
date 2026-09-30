{{--
    A small circular picture beside a name: a developer's GitHub picture beside their login (#410),
    or a repository owner's beside a repository (#416). One component, so size and shape are the
    same everywhere.

    Decorative: the name written beside it says who or what it is, so the picture is hidden from
    assistive technology and says nothing the text does not. The letter is not selectable, so
    copying a name does not copy it too. Sized in rem, so it scales with the reader's text and zoom
    rather than against them. Beneath the picture sits a letter on a neutral disc, which is what
    shows when there is no stored picture, and when the picture fails to load, since the dashboard's
    script takes a failed picture away. Any other attribute goes on the outer element.

    **A developer** takes `login`, and renders nothing without one: an account nobody can name is
    not a developer a picture could stand for. The picture is the one sign-in stored, read by
    `GitHubAccounts::avatarOf()`, which asks GitHub nothing; the browser loads it. Pass
    `:pictured="false"` for an account that has not signed in: its login is only what GitHub or an
    administrator said, and a picture found under it would be whoever signed in with it before. Its
    border is transparent, and draws the disc under forced colors.

    **A repository** takes `repository`, as `owner/name`, and renders nothing without one. Its
    letter is the name's, and its picture is the owner's avatar, read by
    `GitHubAccounts::repositoryImageOf()` from what webhook deliveries stored -- GitHub gives a
    repository none of its own. Its border is drawn, a ring a developer's circle does not have, so an
    organization's picture beside a repository is not read as somebody's face. Under forced colors,
    where a developer's transparent border becomes a line too, the ring is drawn double.
--}}
@props(['login' => null, 'pictured' => true, 'repository' => null])
@if ($repository !== null && $repository !== '')@php($named = str_contains($repository, '/') ? substr($repository, strpos($repository, '/') + 1) : $repository)<span {{ $attributes->merge(['class' => 'avatar avatar-placeholder me-1.5 shrink-0 align-middle']) }} aria-hidden="true" data-avatar="repository"><span class="relative grid size-6 place-items-center rounded-full border-2 border-base-content bg-neutral forced-colors:border-4 forced-colors:border-double text-neutral-content"><span class="text-meta leading-none select-none">{{ mb_strtoupper(mb_substr($named !== '' ? $named : $repository, 0, 1)) }}</span>@if (\RobotCouncil\Support\GitHubAccounts::repositoryImageOf($repository) !== null)<img src="{{ \RobotCouncil\Support\GitHubAccounts::repositoryImageOf($repository) }}" alt="" class="absolute inset-0 size-full rounded-full object-cover" decoding="async" referrerpolicy="no-referrer">@endif</span></span>@elseif ($login !== null && $login !== '')<span {{ $attributes->merge(['class' => 'avatar avatar-placeholder me-1.5 shrink-0 align-middle']) }} aria-hidden="true" data-avatar><span class="relative grid size-6 place-items-center rounded-full border border-transparent bg-neutral text-neutral-content"><span class="text-meta leading-none select-none">{{ mb_strtoupper(mb_substr($login, 0, 1)) }}</span>@if ($pictured && \RobotCouncil\Support\GitHubAccounts::avatarOf($login) !== null)<img src="{{ \RobotCouncil\Support\GitHubAccounts::avatarOf($login) }}" alt="" class="absolute inset-0 size-full rounded-full object-cover" decoding="async" referrerpolicy="no-referrer">@endif</span></span>@endif