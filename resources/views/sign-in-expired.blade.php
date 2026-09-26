{{--
    Shown when a GitHub callback arrives whose state does not match the session.

    **This page does not restart the sign-in itself, and that is the point.** The visitor is not
    signed in, so anything this redirected to would be answered by `EnsureAllowlistedDeveloper`
    sending them to GitHub again -- and the most common causes of a mismatched state are a session
    that cannot persist at all. Blocked cookies would bounce between GitHub and here forever. A link
    needs a click, which ends the loop.

    Nothing from the request is printed. Not the state, not the code, not a message derived from
    either: the values that reach this page are supplied by whoever made the request.
--}}
<!DOCTYPE html>
{{-- The shared head and the signed-out page's card, rather than a document of its own (#313) --}}
<html lang="en">
<head>
    @include('robot-council::partials.head', ['title' => 'Sign-in expired'])
</head>
<body class="min-h-screen bg-base-200 font-sans antialiased">
    <main class="mx-auto flex min-h-screen max-w-lg items-center p-4">
        <div class="card w-full bg-base-100 shadow-sm">
            <div class="card-body">
                <h1 class="card-title">That sign-in attempt expired</h1>

                <p>
                    Sign-in carries a one-time value that is checked when GitHub sends you back, and
                    this request did not carry a valid one. Refreshing the page after signing in does
                    it, and so does leaving the GitHub screen open long enough for the session to lapse.
                </p>

                <div class="card-actions mt-2">
                    <a class="btn btn-target btn-primary" href="{{ route('robot-council.auth.redirect') }}">Start again</a>
                </div>

                <p class="text-meta opacity-90">
                    If starting again brings you straight back here, your browser is most likely
                    refusing the cookie this site needs to remember the attempt.
                </p>
            </div>
        </div>
    </main>
</body>
</html>
