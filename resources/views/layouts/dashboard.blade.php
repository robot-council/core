{{--
    The shell every dashboard page extends.

    The stylesheet comes from a package route rather than a published asset, so the file that ships
    is the file that renders and a host that never ran a publish still sees a styled page. Every URL
    here is built by `route()` with a literal name, which is what the #70 guard requires: escaping
    does nothing to a `javascript:` URL, and a URL arriving through a variable is indistinguishable
    in a template from one a requester supplied. That is why the sidebar's destinations are written
    out rather than iterated from a list.

    The sidebar collapses on a checkbox rather than on script. `drawer-toggle` is what daisyUI
    styles against, so the shell opens and closes with no JavaScript reached at all -- which matters
    because the only script on the page is Livewire's, and a developer whose session has lapsed
    still needs to be able to open the navigation and leave.

    Nothing in this file renders anything unescaped. The #67 guard refuses `{!! !!}`, a `@php` block
    and a raw PHP tag anywhere under `resources/views/`, and it refuses this package from building an
    `Htmlable`, because `{{ }}` does not escape one of those either.
--}}
<!DOCTYPE html>
{{--
    `data-theme` is written only when a theme was asked for. Pinning it unconditionally -- which is
    what `$theme ?? 'light'` did -- made the attribute always present, and the dark theme is served
    by `:root:not([data-theme])`, so it could never match: the theme shipped but nothing could
    reach it. Omitted, an unthemed page is light by default and dark where the system asks, and an
    explicit value still wins over the system.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if (($theme ?? null) !== null) data-theme="{{ $theme }}" @endif>
<head>
    @include('robot-council::partials.head', ['title' => $title ?? null])

    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{--
        Only where a page mounts a component. The enrollment page mounts none, and it is the page
        that decides whether a machine joins the fleet -- the fewer scripts loaded there, the
        smaller the surface on the one page whose whole job is a human decision. Defaults to true,
        so a page that says nothing keeps what the dashboard has always had.
    --}}
    @if ($livewireAssets ?? true)
        @livewireStyles
    @endif
</head>
<body class="min-h-screen bg-base-200 font-sans antialiased">
    <div class="drawer lg:drawer-open">
        <input id="robot-council-navigation" type="checkbox" class="drawer-toggle">

        <div class="drawer-content flex min-h-screen min-w-0 flex-col">
            <header class="navbar sticky top-0 z-30 gap-2 border-b border-base-300 bg-base-100 px-4">
                <label for="robot-council-navigation"
                    class="btn btn-square btn-target btn-ghost drawer-button lg:hidden"
                    aria-label="Show navigation">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16">
                    </svg>
                </label>

                <div class="min-w-0 grow">
                    <p class="truncate font-semibold">{{ \RobotCouncil\Support\DashboardName::value() }}</p>
                    <p class="truncate text-meta opacity-90">Fleet coordination</p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    @if ($developerLogin !== null)
                        <span class="hidden max-w-40 truncate text-meta opacity-90 sm:inline">{{ $developerLogin }}</span>
                    @endif

                    {{--
                        POST, like approve and deny. A sign-out reachable by following a link is one
                        another site can trigger with an embedded image and a link prefetcher can
                        trigger with nobody clicking anything.
                    --}}
                    <form method="POST" action="{{ route('robot-council.sign-out') }}">
                        @csrf

                        <button type="submit" class="btn btn-target btn-ghost">Sign out</button>
                    </form>
                </div>
            </header>

            <main class="mx-auto w-full max-w-7xl grow p-4 sm:p-6">
                {{ $slot }}
            </main>
        </div>

        <div class="drawer-side z-40">
            <label for="robot-council-navigation" class="drawer-overlay" aria-label="Hide navigation"></label>

            <nav class="min-h-full w-64 bg-base-100 lg:border-r lg:border-base-300"
                aria-labelledby="robot-council-menu-heading">
                <h2 id="robot-council-menu-heading"
                    class="px-4 pt-4 text-meta font-semibold uppercase tracking-wide opacity-80">Menu</h2>

                {{--
                    The console's sections are children of the page they belong to rather than peers
                    of it. daisyUI draws the indent and the vertical rule through
                    `.menu :where(li ul,li menu)`, so there is no CSS here.

                    That selector was already in the committed artifact before any view nested a
                    menu, because daisyUI emits a component's rules together rather than per class
                    found. So asserting it guards a daisyUI version that stopped shipping the rule
                    rather than a scan that stopped finding a class, and `DashboardShellTest`
                    asserts it on exactly that narrower ground.

                    **Name no class in this comment that the markup does not use.** Tailwind scans
                    this file whole and cannot tell a sentence from an attribute, so a class named
                    here only to discuss it is emitted into the shipped stylesheet. An earlier draft
                    of this comment named two, and added 1,385 bytes of rules for components nothing
                    renders; a later one used an ordinary English word that is also a plugin's class
                    name, and added three more. Check the artifact after editing this, not before.

                    The nested list needs `flex flex-col gap-1` of its own. `gap` is not inherited
                    and applies only to a flex or grid box; `.menu` makes the OUTER list flex, and
                    the rule above gives the nested one margin and padding but no display, so it is
                    a block box on which the parent's `gap-1` does nothing. Without these three the
                    four sections sit flush while the two entries above them are spaced.

                    `Enroll a machine` stays outside the group deliberately: it is a destination of
                    its own rather than a section of the console, and the nesting is what says so.
                --}}
                <ul class="menu w-full gap-1 p-4">
                    <li>
                        <a href="{{ route('robot-council.dashboard') }}"
                            @class(['menu-active' => $currentRoute === 'robot-council.dashboard'])
                            @if ($currentRoute === 'robot-council.dashboard') aria-current="page" @endif>Dashboard</a>

                        <ul class="flex flex-col gap-1">
                            <li>
                                <a href="{{ route('robot-council.agents') }}"
                                    @class(['menu-active' => $currentRoute === 'robot-council.agents'])
                                    @if ($currentRoute === 'robot-council.agents') aria-current="page" @endif>Agents</a>
                            </li>

                            <li>
                                <a href="{{ route('robot-council.locks') }}"
                                    @class(['menu-active' => $currentRoute === 'robot-council.locks'])
                                    @if ($currentRoute === 'robot-council.locks') aria-current="page" @endif>Locks</a>
                            </li>

                            <li>
                                <a href="{{ route('robot-council.lanes') }}"
                                    @class(['menu-active' => $currentRoute === 'robot-council.lanes'])
                                    @if ($currentRoute === 'robot-council.lanes') aria-current="page" @endif>Lanes</a>
                            </li>

                            <li>
                                <a href="{{ route('robot-council.queue') }}"
                                    @class(['menu-active' => $currentRoute === 'robot-council.queue'])
                                    @if ($currentRoute === 'robot-council.queue') aria-current="page" @endif>Queue</a>
                            </li>

                            <li>
                                <a href="{{ route('robot-council.feed') }}"
                                    @class(['menu-active' => $currentRoute === 'robot-council.feed'])
                                    @if ($currentRoute === 'robot-council.feed') aria-current="page" @endif>Change feed</a>
                            </li>

                            <li>
                                <a href="{{ route('robot-council.seats') }}"
                                    @class(['menu-active' => $currentRoute === 'robot-council.seats'])
                                    @if ($currentRoute === 'robot-council.seats') aria-current="page" @endif>My seats and hours</a>
                            </li>

                            {{--
                                Offered only to an admin, decided in `Http\ViewComposers\DashboardLayoutComposer`
                                on the package's own guard rather than with `@can`, which resolves the host's
                                default and would hide a real admin's own page from them. The component
                                refuses in `mount()` regardless, so the route is safe whether or not this is
                                drawn -- a control that is not drawn is not an authorization boundary.
                            --}}
                            @if ($isAdmin)
                                <li>
                                    <a href="{{ route('robot-council.administration') }}"
                                        @class(['menu-active' => $currentRoute === 'robot-council.administration'])
                                        @if ($currentRoute === 'robot-council.administration') aria-current="page" @endif>Administration</a>
                                </li>
                            @endif
                        </ul>
                    </li>

                    <li>
                        <a href="{{ route('robot-council.enroll.show') }}"
                            @class(['menu-active' => $currentRoute === 'robot-council.enroll.show'])
                            @if ($currentRoute === 'robot-council.enroll.show') aria-current="page" @endif>Enroll a machine</a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>

    @if ($livewireAssets ?? true)
        @livewireScripts
    @endif
</body>
</html>
