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
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ?? 'Robot Council' }}</title>

    <link rel="stylesheet" href="{{ route('robot-council.dashboard.stylesheet') }}">

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
                    class="btn btn-square btn-ghost drawer-button lg:hidden"
                    aria-label="Show navigation">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16">
                    </svg>
                </label>

                <div class="min-w-0 grow">
                    <p class="truncate font-semibold">Robot Council</p>
                    <p class="truncate text-xs opacity-70">Fleet coordination</p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    @if ($developerLogin !== null)
                        <span class="hidden max-w-40 truncate text-sm opacity-70 sm:inline">{{ $developerLogin }}</span>
                    @endif

                    {{--
                        POST, like approve and deny. A sign-out reachable by following a link is one
                        another site can trigger with an embedded image and a link prefetcher can
                        trigger with nobody clicking anything.
                    --}}
                    <form method="POST" action="{{ route('robot-council.sign-out') }}">
                        @csrf

                        <button type="submit" class="btn btn-sm btn-ghost">Sign out</button>
                    </form>
                </div>
            </header>

            <main class="mx-auto w-full max-w-7xl grow p-4 sm:p-6">
                {{ $slot }}
            </main>
        </div>

        <div class="drawer-side z-40">
            <label for="robot-council-navigation" class="drawer-overlay" aria-label="Hide navigation"></label>

            <nav class="min-h-full w-64 bg-base-100 lg:border-r lg:border-base-300" aria-label="Dashboard">
                <ul class="menu w-full gap-1 p-4">
                    <li class="menu-title">Fleet</li>

                    <li>
                        <a href="{{ route('robot-council.dashboard') }}"
                            @class(['menu-active' => $currentRoute === 'robot-council.dashboard'])
                            @if ($currentRoute === 'robot-council.dashboard') aria-current="page" @endif>Overview</a>
                    </li>

                    <li>
                        <a href="{{ route('robot-council.enroll.show') }}"
                            @class(['menu-active' => $currentRoute === 'robot-council.enroll.show'])
                            @if ($currentRoute === 'robot-council.enroll.show') aria-current="page" @endif>Enroll a machine</a>
                    </li>

                    {{--
                        Jump links into the page being rendered, so they are offered only there. The
                        panels are stacked on one page by the decision on #187; #191 turns these
                        into a choice of which are mounted at all.
                    --}}
                    @if ($currentRoute === 'robot-council.dashboard')
                        <li class="menu-title">On this page</li>

                        @if (\in_array('presence', $showingSections, true))
                            <li><a href="#robot-council-presence">Presence</a></li>
                        @endif

                        @if (\in_array('queue', $showingSections, true))
                            <li><a href="#robot-council-queue">Queue</a></li>
                        @endif

                        @if (\in_array('feed', $showingSections, true))
                            <li><a href="#robot-council-change-feed">Change feed</a></li>
                        @endif

                        {{--
                            Each link is offered only when the section is actually on the page, so
                            none of them jumps to nothing. The administration entry is filtered by
                            `Support\DashboardSections`, which drops it for an account that is not
                            an admin whatever the query string says -- decided there rather than
                            with `@can`, which resolves the host's default guard and would hide it
                            from a real admin on a host where the two differ. The component
                            authorizes its own render regardless.
                        --}}
                        @if (\in_array('administration', $showingSections, true))
                            <li><a href="#robot-council-administration">Administration</a></li>
                        @endif
                    @endif
                </ul>
            </nav>
        </div>
    </div>

    @if ($livewireAssets ?? true)
        @livewireScripts
    @endif
</body>
</html>
