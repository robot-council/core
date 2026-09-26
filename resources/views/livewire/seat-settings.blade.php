{{--
    A developer's own seats, how many tickets each takes at once (#409), assignment hours and days
    off (#322).

    Everything here belongs to the signed-in developer and nobody else: the component reads them off
    the package's guard, and the stores refuse a seat that is not theirs. Nothing is rendered
    unescaped, which the #67 guard enforces, and **only values this package chose reach a `wire:`
    expression** -- a seat id and a date this page already validated. The harness, machine label,
    repository and work location are agent-supplied and are rendered as text only.
--}}

<div class="flex flex-col gap-6">
    <h1 class="text-2xl font-semibold">My seats and hours</h1>

    @include('robot-council::partials.glossary', ['terms' => ['seat', 'harness', 'machine_label', 'park', 'exempt', 'tickets_at_once', 'placement', 'waive', 'assignment_hours', 'days_off', 'gate', 'hand_back', 'coordinator']])


    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">My seats</h2>

            <p class="text-meta opacity-90">
                A seat is one of your machines working in one repository. A parked seat takes no new
                work until you lift it, and only you can lift it -- time never does. An exempt seat
                ignores your assignment hours. Tickets at once caps how many tickets a coordinator may
                place on one session in the seat.
            </p>

            @if ($seats === [])
                <p class="py-6 text-center opacity-80">
                    No seats yet: a seat appears once one of your sessions reports the repository it works in.
                </p>
            @else
                <ul class="divide-y divide-base-200">
                    @foreach ($seats as $seat)
                        <li wire:key="seat-{{ $seat->id }}" class="flex flex-wrap items-center justify-between gap-2 py-3">
                            {{-- Every control below names its seat to a screen reader (#400), since each
                                 repeats once per seat and a list of identical names says nothing. --}}
                            @php($seatName = $seat->repository.($seat->work_location !== '' ? ' / '.$seat->work_location : ''))
                            <div>
                                <div class="font-medium">
                                    <code>{{ $seat->repository }}</code>@if ($seat->work_location !== '') / <code>{{ $seat->work_location }}</code>@endif
                                </div>
                                <div class="text-meta opacity-90">
                                    <code>{{ $seat->installation->harness }}</code> on <code>{{ $seat->installation->machine_label }}</code>
                                    &middot; <span data-seat-capacity>takes up to {{ $seat->max_capacity }} {{ $seat->max_capacity === 1 ? 'ticket' : 'tickets' }} at once</span>
                                    @if ($seat->parked_at !== null)
                                        &middot; parked {{ $seat->parked_at->diffForHumans() }}
                                    @endif
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                @if ($seat->hours_exempt)
                                    <span class="badge badge-sm">exempt from hours</span>
                                    <button type="button" wire:click="unexempt({{ \RobotCouncil\Support\WireArgument::of($seat->id) }})" class="btn btn-target btn-ghost">Apply my hours<span class="sr-only"> for {{ $seatName }}</span></button>
                                @else
                                    <button type="button" wire:click="exempt({{ \RobotCouncil\Support\WireArgument::of($seat->id) }})" class="btn btn-target btn-ghost">Exempt from hours<span class="sr-only"> for {{ $seatName }}</span></button>
                                @endif

                                @if ($seat->isParked())
                                    <span class="badge badge-sm badge-warning">parked</span>
                                    <button type="button" wire:click="lift({{ \RobotCouncil\Support\WireArgument::of($seat->id) }})" class="btn btn-target btn-primary">Lift<span class="sr-only"> for {{ $seatName }}</span></button>
                                @else
                                    <button type="button" wire:click="park({{ \RobotCouncil\Support\WireArgument::of($seat->id) }})" class="btn btn-target btn-warning">Park<span class="sr-only"> for {{ $seatName }}</span></button>
                                @endif
                            </div>
                            {{-- #409: the cap on what a session in this seat declares when it joins.
                                 Only a seat id reaches the `wire:` expressions, through `WireArgument`;
                                 the number is read back by the component as untrusted input. --}}
                            {{-- What the last action on this seat did (#402), beside its buttons --}}
                            @if ($said !== null && $saidAt === 'seat-'.$seat->id)
                                @if ($refused)
                                    <p role="alert" class="w-full font-semibold text-error" data-said>{{ $said }}</p>
                                @else
                                    <p role="status" class="w-full font-medium" data-said>{{ $said }}</p>
                                @endif
                            @endif

                            @php($capacityFailed = $capacitySeat === $seat->id && $capacityError !== null)
                            <form wire:submit="setCapacity({{ \RobotCouncil\Support\WireArgument::of($seat->id) }})" class="flex w-full flex-wrap items-end gap-3">
                                {{-- Each field and button names its seat to a screen reader, since every
                                     seat on the page has one and "Tickets at once" alone says which of
                                     them nothing. The error, when there is one, is next to the field it
                                     is about and is what the field is described by first. --}}
                                <label class="form-control">
                                    <span class="label-text">Tickets at once<span class="sr-only"> for {{ $seatName }}</span></span>
                                    <input type="number" min="{{ \RobotCouncil\Support\Capacity::DEFAULT }}" max="{{ \RobotCouncil\Support\Capacity::MAX }}" step="1" inputmode="numeric" wire:model="capacities.{{ \RobotCouncil\Support\WireArgument::of($seat->id) }}" class="input input-bordered input-sm w-24" @if ($capacityFailed) aria-invalid="true" aria-describedby="seat-{{ $seat->id }}-capacity-error seat-{{ $seat->id }}-capacity-help" @else aria-describedby="seat-{{ $seat->id }}-capacity-help" @endif>
                                </label>
                                <button type="submit" class="btn btn-target">Set tickets at once<span class="sr-only"> for {{ $seatName }}</span></button>
                                @if ($capacityFailed)
                                    <p id="seat-{{ $seat->id }}-capacity-error" role="alert" class="font-semibold text-error" data-capacity-error>{{ $capacityError }}</p>
                                @elseif ($capacitySeat === $seat->id)
                                    <p role="status" data-capacity-saved>Saved: up to {{ $seat->max_capacity }} {{ $seat->max_capacity === 1 ? 'ticket' : 'tickets' }} at once.</p>
                                @endif
                                <p id="seat-{{ $seat->id }}-capacity-help" class="max-w-xl text-meta leading-relaxed opacity-90">
                                    The most tickets one session in this seat may hold at the same time, from
                                    {{ \RobotCouncil\Support\Capacity::DEFAULT }} to {{ \RobotCouncil\Support\Capacity::MAX }}.
                                    A session that works through subagents asks for its number when it joins, and gets no
                                    more than this. At 1, a session holds one ticket, as it always has.
                                </p>
                            </form>
                            {{-- #320: a waiver lets exactly one placement through one refusal on this
                                 seat. Only the seat's developer can grant it; a coordinator cannot. --}}
                            <details class="w-full text-meta">
                                <summary class="cursor-pointer py-3 opacity-90">
                                    Waive a placement refusal
                                    @if ($waived[$seat->id] !== [])
                                        ({{ count($waived[$seat->id]) }} waived for the next placement)
                                    @endif
                                </summary>

                                <ul class="mt-2 flex flex-col gap-1">
                                    @foreach ($rules as $rule)
                                        <li wire:key="seat-{{ $seat->id }}-rule-{{ $rule->value }}" class="flex items-center justify-between gap-2">
                                            <span>Refused when {{ $rule->reads() }}</span>
                                            @if (in_array($rule, $waived[$seat->id], true))
                                                <button type="button" wire:click="withdrawWaiver({{ \RobotCouncil\Support\WireArgument::of($seat->id) }}, '{{ \RobotCouncil\Support\WireArgument::of($rule) }}')" class="btn btn-target btn-ghost">Withdraw waiver<span class="sr-only"> for {{ $seatName }}</span></button>
                                            @else
                                                <button type="button" wire:click="waive({{ \RobotCouncil\Support\WireArgument::of($seat->id) }}, '{{ \RobotCouncil\Support\WireArgument::of($rule) }}')" class="btn btn-target">Waive once<span class="sr-only"> for {{ $seatName }}</span></button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">Assignment hours</h2>

            <p class="text-meta opacity-90">
                When your seats take new placements, on your own clock. Work already placed, gate
                queues and hand-backs are never held to these.
                @if ($hours === null)
                    You have none set, so nothing is gated.
                @endif
            </p>

            <form wire:submit="saveHours" class="flex flex-wrap items-end gap-3">
                <label class="form-control">
                    <span class="label-text">Timezone</span>
                    <input type="text" wire:model="timezone" placeholder="America/Chicago" class="input input-bordered input-sm" maxlength="64">
                </label>

                <label class="form-control">
                    <span class="label-text">From</span>
                    <input type="time" wire:model="startsAt" class="input input-bordered input-sm">
                </label>

                <label class="form-control">
                    <span class="label-text">Until</span>
                    <input type="time" wire:model="endsAt" class="input input-bordered input-sm">
                </label>

                <label class="label cursor-pointer gap-2">
                    <input type="checkbox" wire:model="skipWeekends" class="checkbox">
                    <span class="label-text">Skip weekends</span>
                </label>

                <button type="submit" class="btn btn-target btn-primary">Save hours</button>

                @if ($hours !== null)
                    <button type="button" wire:click="clearHours" class="btn btn-target btn-ghost">Remove hours</button>
                @endif
            </form>

            @if ($said !== null && $saidAt === 'hours')
                @if ($refused)
                    <p role="alert" class="font-semibold text-error" data-said>{{ $said }}</p>
                @else
                    <p role="status" class="font-medium" data-said>{{ $said }}</p>
                @endif
            @endif
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">Days off</h2>

            <p class="text-meta opacity-90">
                Your own holidays, as dates on your clock. They apply once you have set assignment
                hours, since a date needs a timezone to say when it starts.
            </p>

            <form wire:submit="addHoliday" class="flex flex-wrap items-end gap-3">
                <label class="form-control">
                    <span class="label-text">Date</span>
                    <input type="date" wire:model="holiday" class="input input-bordered input-sm">
                </label>

                <button type="submit" class="btn btn-target">Add day off</button>
            </form>

            @if ($said !== null && $saidAt === 'days-off')
                @if ($refused)
                    <p role="alert" class="font-semibold text-error" data-said>{{ $said }}</p>
                @else
                    <p role="status" class="font-medium" data-said>{{ $said }}</p>
                @endif
            @endif

            @if ($holidays !== [])
                <ul class="flex flex-wrap gap-2">
                    @foreach ($holidays as $day)
                        <li wire:key="holiday-{{ $day }}" class="flex items-center gap-1 rounded-box bg-base-200 pl-3">
                            {{ $day }}
                            <button type="button" wire:click="removeHoliday('{{ \RobotCouncil\Support\WireArgument::of($day) }}')" class="btn btn-square btn-target btn-ghost" aria-label="Remove {{ $day }}">&times;</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
