{{--
    Shown when GitHub refuses the request that reads the account during the sign-in callback.

    **Like the expired page, it does not restart the sign-in itself.** Nobody is signed in, so a
    redirect would be answered by `EnsureAllowlistedDeveloper` sending the visitor straight back to
    GitHub, which for a rate limit is refused again. A link needs a click, which ends the loop.

    Nothing from the request or from GitHub's response body is printed. `$limited` is a boolean the
    controller derived, and `$resetsAt` is a time built from a validated integer header, never a
    string GitHub sent.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    @include('robot-council::partials.head', ['title' => 'GitHub refused sign-in'])
</head>
<body class="flex min-h-screen flex-col bg-base-200 font-sans antialiased">
    <main class="mx-auto flex w-full max-w-lg grow items-center p-4">
        <div class="card w-full bg-base-100 shadow-sm">
            <div class="card-body">
                <h1 class="card-title">GitHub did not allow that sign-in</h1>

                @if ($limited)
                    <p>
                        GitHub is rate-limiting your account, so it refused the request that reads
                        your profile. Nothing is wrong with this application or with your access.
                        @if ($resetsAt !== null)
                            Try again later, after about {{ $resetsAt->format('H:i') }} UTC.
                        @else
                            Try again later.
                        @endif
                    </p>
                @else
                    <p>
                        GitHub refused the request that reads your profile, so you were not signed
                        in. This is usually temporary. Try again in a few minutes.
                    </p>
                @endif

                <div class="card-actions mt-2">
                    <a class="btn btn-target btn-primary" href="{{ route('robot-council.auth.redirect') }}">Try again</a>
                </div>
            </div>
        </div>
    </main>

    @include('robot-council::partials.version')
</body>
</html>
