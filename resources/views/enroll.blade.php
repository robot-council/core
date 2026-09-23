{{--
    The enrollment verification page. Everything the requester supplied is printed as a claim, and
    nothing on this page is trusted to decide anything: the abilities granted on approval are read
    from the stored request, not from the form that is posted back.

    Every value is escaped. There is no `{!! !!}` here, and there should never be: `harness`,
    `machine_label`, and the ability names all arrive from an unauthenticated endpoint.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Approve a machine &middot; robot-council</title>
    <style>
        :root { color-scheme: light; }
        body {
            margin: 0 auto;
            padding: 2rem 1rem 4rem;
            max-width: 42rem;
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            color: #1a1a1a;
            background: #ffffff;
        }
        h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
        h2 { font-size: 1.125rem; margin: 2rem 0 0.5rem; }
        p { margin: 0.5rem 0; }
        .muted { color: #595959; }
        .panel { border: 1px solid #595959; border-radius: 6px; padding: 1rem 1.25rem; margin: 1.5rem 0; }
        .notice { border-left: 4px solid #1a1a1a; padding: 0.75rem 1rem; background: #f2f2f2; margin: 1.5rem 0; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 0.35rem 1.25rem; margin: 0; }
        dt { font-weight: 600; }
        dd { margin: 0; overflow-wrap: anywhere; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .code { font-size: 1.5rem; letter-spacing: 0.15em; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        ul { margin: 0.25rem 0; padding-left: 1.25rem; }
        label { display: block; margin: 0.75rem 0; }
        input[type="text"] { font: inherit; padding: 0.45rem 0.6rem; border: 1px solid #595959; border-radius: 4px; }
        button { font: inherit; padding: 0.5rem 1rem; border-radius: 4px; border: 1px solid #1a1a1a; cursor: pointer; }
        .approve { background: #1a1a1a; color: #ffffff; }
        .deny { background: #ffffff; color: #1a1a1a; }
        .actions { display: flex; gap: 0.75rem; align-items: center; margin-top: 1rem; }
        .error { color: #a4000f; font-weight: 600; }
    </style>
</head>
<body>
    <h1>Approve a machine</h1>
    <p class="muted">A machine has asked to enroll with robot-council. Approve it only if you started it yourself.</p>

    @if (session('status'))
        <p class="notice">{{ session('status') }}</p>
    @endif

    <form method="GET" action="{{ route('robot-council.enroll.show') }}">
        <label for="user_code">The code shown on that machine</label>
        <input
            id="user_code"
            name="user_code"
            type="text"
            value="{{ $userCode }}"
            autocomplete="off"
            autocapitalize="characters"
            spellcheck="false"
            size="12"
        >
        <button type="submit" class="deny">Look up</button>
    </form>

    @error('user_code')
        <p class="error">{{ $message }}</p>
    @enderror

    @if ($searched && $code === null)
        <p class="notice error">No enrollment is waiting on that code. Codes expire a few minutes after they are
            requested, so ask the machine for a new one.</p>
    @endif

    @if ($code !== null)
        <div class="panel">
            <p class="code">{{ $code->user_code }}</p>

            @if ($code->isDecided())
                <p class="notice">
                    This request was already
                    {{ $code->approved_at !== null ? 'approved' : 'denied' }}. Nothing further will happen to it.
                </p>
            @endif

            <h2>What the machine says about itself</h2>
            <p class="muted">Whoever asked for this code supplied the harness and the machine label below. They are
                claims, not facts, and nothing has checked them.</p>

            <dl>
                <dt>Harness</dt>
                <dd><code>{{ $code->harness }}</code></dd>

                <dt>Machine label</dt>
                <dd><code>{{ $code->machine_label }}</code></dd>

                <dt>Asked for</dt>
                <dd>
                    {{-- `@forelse` rather than `@foreach`: the list can legitimately be empty, and an
                         empty `<ul>` under "Asked for" reads as a rendering fault rather than as
                         the claim it is. `Models\DeviceCode::requestedAbilities()` drops anything
                         that is not text (#167) and `Support\DeviceCodes::issue()` drops anything
                         outside the requestable list (#170), so a row whose every entry was
                         dropped arrives here as nothing to show. Approving one grants nothing. --}}
                    <ul>
                        @forelse ($code->requestedAbilities() as $ability)
                            <li><code>{{ $ability }}</code></li>
                        @empty
                            <li>Nothing this server recognizes. Approving grants no abilities.</li>
                        @endforelse
                    </ul>
                </dd>
            </dl>

            <h2>Where and when it was asked for</h2>
            <dl>
                <dt>Requested</dt>
                <dd>{{ $code->ageInSeconds() }} seconds ago</dd>

                <dt>Requested from</dt>
                <dd><code>{{ $code->requested_ip ?? 'an address the server could not read' }}</code></dd>

                <dt>You are at</dt>
                <dd><code>{{ $approverIp ?? 'an address the server could not read' }}</code></dd>
            </dl>

            <p class="muted">A request you did not just start, or one from an address that is not yours, is what an
                attempt to borrow your approval looks like.</p>

            @unless ($code->isDecided())
                @if ($superseded->isNotEmpty())
                    {{--
                        Above the approve button, never below it: this is the only warning that an
                        approval ENDS something. The harness and machine label are the requester's
                        claims, so two of your own machines can collide on them -- and without this
                        the approval would take out a working installation silently (#106).
                    --}}
                    <div class="notice">
                        <h2>Approving will end {{ $superseded->count() === 1 ? 'an existing installation' : 'existing installations' }}</h2>

                        <p>You already have {{ $superseded->count() === 1 ? 'an installation' : 'installations' }} for this
                            harness and machine label. Approving replaces {{ $superseded->count() === 1 ? 'it' : 'them' }},
                            and {{ $superseded->count() === 1 ? 'its credential stops' : 'their credentials stop' }} working
                            immediately.</p>

                        <ul>
                            @foreach ($superseded as $installation)
                                <li>
                                    Enrolled {{ $installation->created_at?->diffForHumans() ?? 'at an unrecorded time' }},
                                    expires {{ $installation->expires_at->diffForHumans() }}
                                </li>
                            @endforeach
                        </ul>

                        <p class="muted">If this is a different machine that happens to share a label, deny this request
                            and enroll it again with <code>--machine-label</code> set to something else.</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('robot-council.enroll.approve') }}">
                    @csrf
                    <input type="hidden" name="user_code" value="{{ $code->user_code }}">

                    <label>
                        <input type="checkbox" name="confirmed" value="1">
                        This code is displayed on a machine I control, and I started this enrollment.
                    </label>

                    @error('confirmed')
                        <p class="error">{{ $message }}</p>
                    @enderror

                    <div class="actions">
                        <button type="submit" class="approve">Approve this machine</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('robot-council.enroll.deny') }}" class="actions">
                    @csrf
                    <input type="hidden" name="user_code" value="{{ $code->user_code }}">
                    <button type="submit" class="deny">Deny</button>
                </form>
            @endunless
        </div>
    @endif
</body>
</html>
