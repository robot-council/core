{{--
    The enrollment verification page. Everything the requester supplied is printed as a claim, and
    nothing on this page is trusted to decide anything: the abilities granted on approval are read
    from the stored request, not from the form that is posted back.

    Every value is escaped. There is no `{!! !!}` here, and there should never be: `harness`,
    `machine_label`, and the ability names all arrive from an unauthenticated endpoint.

    It renders inside the dashboard's shell as of #189, through `@component` rather than an
    `x-` component: the layout's contract is a `$slot`, `@component` supplies one from any plain
    Blade view, and neither the layout nor the service provider has to learn anything new. Livewire's
    assets are declined, because this page mounts no component and is the one page whose entire job
    is a human decision.
--}}
@component('robot-council::layouts.dashboard', ['title' => 'Enroll a machine', 'livewireAssets' => false])
    <div class="mx-auto w-full max-w-3xl">
        <header class="mb-6">
            <h1 class="text-xl font-semibold">Approve a machine</h1>
            <p class="mt-1 text-meta opacity-90">A machine has asked to enroll with robot-council. Approve it only if you started it yourself.</p>
        </header>

        @if (session('status'))
            <div role="status" class="alert mb-6">{{ session('status') }}</div>
        @endif

        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <form method="GET" action="{{ route('robot-council.enroll.show') }}" class="flex flex-wrap items-end gap-3">
                    <div class="grow">
                        <label for="user_code" class="mb-1 block text-meta font-medium">The code shown on that machine</label>

                        <input
                            id="user_code"
                            name="user_code"
                            type="text"
                            value="{{ $userCode }}"
                            class="input font-mono tracking-widest"
                            autocomplete="off"
                            autocapitalize="characters"
                            spellcheck="false"
                            size="12"
                        >
                    </div>

                    <button type="submit" class="btn">Look up</button>
                </form>

                @error('user_code')
                    <p class="mt-2 text-meta font-semibold text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        @if ($searched && $code === null)
            <div role="alert" class="alert alert-warning mt-4">
                <span>No enrollment is waiting on that code. Codes expire a few minutes after they are requested, so ask the machine for a new one.</span>
            </div>
        @endif

        @if ($code !== null)
            <div class="card mt-4 bg-base-100 shadow-sm">
                <div class="card-body">
                    <p class="font-mono text-2xl tracking-widest">{{ $code->user_code }}</p>

                    @if ($code->isDecided())
                        <div role="status" class="alert mt-2">
                            <span>This request was already {{ $code->approved_at !== null ? 'approved' : 'denied' }}. Nothing further will happen to it.</span>
                        </div>
                    @endif

                    <h2 class="card-title mt-4 text-body">What the machine says about itself</h2>
                    <p class="text-meta opacity-90">Whoever asked for this code supplied the harness and the machine label below. They are claims, not facts, and nothing has checked them.</p>

                    <dl class="mt-2 grid grid-cols-[max-content_1fr] gap-x-5 gap-y-1 text-meta">
                        <dt class="font-semibold">Harness</dt>
                        <dd class="break-words"><code>{{ $code->harness }}</code></dd>

                        <dt class="font-semibold">Machine label</dt>
                        <dd class="break-words"><code>{{ $code->machine_label }}</code></dd>

                        <dt class="font-semibold">Asked for</dt>
                        <dd class="break-words">
                            {{-- `@forelse` rather than `@foreach`: the list can legitimately be empty, and an
                                 empty `<ul>` under "Asked for" reads as a rendering fault rather than as
                                 the claim it is. `Models\DeviceCode::requestedAbilities()` drops anything
                                 that is not text (#167) and `Support\DeviceCodes::issue()` drops anything
                                 outside the requestable list (#170), so a row whose every entry was
                                 dropped arrives here as nothing to show. --}}
                            <ul class="list-inside list-disc">
                                @forelse ($code->requestedAbilities() as $ability)
                                    <li><code>{{ $ability }}</code></li>
                                @empty
                                    <li>Nothing this server recognizes.</li>
                                @endforelse
                            </ul>

                            {{-- **What approval actually grants, which is no longer the list above.** Since
                                 #221 a session's abilities come from its role's preset, so approving this
                                 machine lets its agents create and claim tasks, take locks, and narrate,
                                 whichever subset was asked for. Saying so here rather than leaving the list
                                 to imply a narrower grant: this page is the only description the approving
                                 developer gets, and a consent surface that describes a mechanism the server
                                 stopped using is worse than no list at all. Posting directives is not in it
                                 and cannot be asked for. --}}
                            <p class="mt-2 text-meta opacity-90">
                                Approving lets this machine's agents create and claim tasks, take
                                locks, and post narration, whatever the list above says. Directing
                                other developers' agents is not included and cannot be requested.
                            </p>
                        </dd>
                    </dl>

                    <h2 class="card-title mt-4 text-body">Where and when it was asked for</h2>

                    <dl class="grid grid-cols-[max-content_1fr] gap-x-5 gap-y-1 text-meta">
                        <dt class="font-semibold">Requested</dt>
                        <dd>{{ $code->ageInSeconds() }} seconds ago</dd>

                        <dt class="font-semibold">Requested from</dt>
                        <dd class="break-words"><code>{{ $code->requested_ip ?? 'an address the server could not read' }}</code></dd>

                        <dt class="font-semibold">You are at</dt>
                        <dd class="break-words"><code>{{ $approverIp ?? 'an address the server could not read' }}</code></dd>
                    </dl>

                    <p class="text-meta opacity-90">A request you did not just start, or one from an address that is not yours, is what an attempt to borrow your approval looks like.</p>

                    @unless ($code->isDecided())
                        @if ($superseded->isNotEmpty())
                            {{--
                                Above the approve button, never below it: this is the only warning that an
                                approval ENDS something. The harness and machine label are the requester's
                                claims, so two of your own machines can collide on them -- and without this
                                the approval would take out a working installation silently (#106).
                            --}}
                            <div role="alert" class="alert alert-warning mt-4 flex-col items-start gap-2 text-left">
                                <h2 class="font-semibold">Approving will end {{ $superseded->count() === 1 ? 'an existing installation' : 'existing installations' }}</h2>

                                <p>You already have {{ $superseded->count() === 1 ? 'an installation' : 'installations' }} for this harness and machine label. Approving replaces {{ $superseded->count() === 1 ? 'it' : 'them' }}, and {{ $superseded->count() === 1 ? 'its credential stops' : 'their credentials stop' }} working immediately.</p>

                                <ul class="list-inside list-disc">
                                    @foreach ($superseded as $installation)
                                        <li>
                                            Enrolled {{ $installation->created_at?->diffForHumans() ?? 'at an unrecorded time' }},
                                            expires {{ $installation->expires_at->diffForHumans() }}
                                        </li>
                                    @endforeach
                                </ul>

                                <p class="text-meta">If this is a different machine that happens to share a label, deny this request and enroll it again with <code>--machine-label</code> set to something else.</p>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('robot-council.enroll.approve') }}" class="mt-4">
                            @csrf
                            <input type="hidden" name="user_code" value="{{ $code->user_code }}">

                            <label class="flex items-start gap-2">
                                <input type="checkbox" name="confirmed" value="1" class="checkbox checkbox-sm mt-0.5">
                                <span>This code is displayed on a machine I control, and I started this enrollment.</span>
                            </label>

                            @error('confirmed')
                                <p class="mt-2 text-meta font-semibold text-error">{{ $message }}</p>
                            @enderror

                            <div class="card-actions mt-4">
                                <button type="submit" class="btn btn-primary">Approve this machine</button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('robot-council.enroll.deny') }}" class="mt-2">
                            @csrf
                            <input type="hidden" name="user_code" value="{{ $code->user_code }}">
                            <button type="submit" class="btn btn-ghost">Deny</button>
                        </form>
                    @endunless
                </div>
            </div>
        @endif
    </div>
@endcomponent
