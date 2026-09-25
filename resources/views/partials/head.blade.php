{{--
    The one `<head>` every page this package renders shares (#313): the dashboard shell, the
    signed-out page and the sign-in-expired page. A tag that belongs on every page goes here, so it
    cannot land on two of three -- which is how `robots` came to be missing from the one page most
    likely to be reached without a session. What only the dashboard needs (its CSRF token and
    Livewire's styles) stays in the layout.

    `$title` is the page's own title, or null on the index; `DashboardName` composes it with the
    fleet's name, which a host configures and which is printed escaped like anything else.
    No social preview tags: every page is `noindex, nofollow` and the console is private, so they
    would be markup nobody can reach.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="description" content="{{ \RobotCouncil\Support\DashboardName::value() }}: coordination for a fleet of AI coding agents.">
{{-- The two themes' `base-100`, daisyUI's stock values, which `dashboard.css` does not override --}}
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1d232a" media="(prefers-color-scheme: dark)">

<title>{{ \RobotCouncil\Support\DashboardName::title($title ?? null) }}</title>

<link rel="stylesheet" href="{{ route('robot-council.dashboard.stylesheet') }}">
