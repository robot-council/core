{{--
    Shown after a developer signs themselves out.

    **It does not redirect anywhere, and that is the point.** Sending the developer to the dashboard
    would put them straight back through `EnsureAllowlistedDeveloper` and on to GitHub, which with a
    live GitHub session signs them in again -- so the control they just used would look broken. A
    link needs a click.

    It renders its own document rather than the dashboard's shell, because the shell names the
    signed-in developer and marks where they are, and there is no developer here. The stylesheet is
    reachable without signing in by design, so this page is styled rather than bare.

    Nothing from the request is printed.
--}}
<!DOCTYPE html>
{{-- No `data-theme`, for the reason the shell has none: it is what lets the dark theme apply. --}}
<html lang="en">
<head>
    @include('robot-council::partials.head', ['title' => 'Signed out'])
</head>
<body class="min-h-screen bg-base-200 font-sans antialiased">
    <main class="mx-auto flex min-h-screen max-w-lg items-center p-4">
        <div class="card w-full bg-base-100 shadow-sm">
            <div class="card-body">
                <h1 class="card-title">Signed out</h1>

                <p>This session has ended. Nothing on the fleet was changed.</p>

                <div class="card-actions mt-2">
                    <a class="btn btn-target btn-primary" href="{{ route('robot-council.auth.redirect') }}">Sign in again</a>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
