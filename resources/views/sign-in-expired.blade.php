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
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign-in expired &middot; robot-council</title>
    <style>
        :root { color-scheme: light; }
        body {
            margin: 0 auto;
            padding: 2rem 1rem 4rem;
            max-width: 34rem;
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            color: #1a1a1a;
        }
        h1 { font-size: 1.35rem; margin: 0 0 0.75rem; }
        p { margin: 0 0 1rem; }
        .muted { color: #555; font-size: 0.9rem; }
        a.button {
            display: inline-block;
            padding: 0.6rem 1.1rem;
            border-radius: 0.4rem;
            background: #1a1a1a;
            color: #fff;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <h1>That sign-in attempt expired</h1>

    <p>
        Sign-in carries a one-time value that is checked when GitHub sends you back, and this
        request did not carry a valid one. Refreshing the page after signing in does it, and so does
        leaving the GitHub screen open long enough for the session to lapse.
    </p>

    <p><a class="button" href="{{ route('robot-council.auth.redirect') }}">Start again</a></p>

    <p class="muted">
        If starting again brings you straight back here, your browser is most likely refusing the
        cookie this site needs to remember the attempt.
    </p>
</body>
</html>
