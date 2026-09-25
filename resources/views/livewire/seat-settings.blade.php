{{--
    A developer's own seats, assignment hours and days off (#322).

    Everything here belongs to the signed-in developer and nobody else: the component reads them off
    the package's guard, and the stores refuse a seat that is not theirs. Nothing is rendered
    unescaped, which the #67 guard enforces, and **only values this package chose reach a `wire:`
    expression** -- a seat id and a date this page already validated. The harness, machine label,
    repository and work location are agent-supplied and are rendered as text only.
--}}

<div class="flex flex-col gap-6">
    @if ($notice !== null)
        <div role="alert" class="alert alert-warning">{{ $notice }}</div>
    @endif

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">My seats</h2>

            <p class="text-meta opacity-90">
                A seat is one of your machines working in one repository. A parked seat takes no new
                work until you lift it, and only you can lift it -- time never does. An exempt seat
                ignores your assignment hours.
            </p>

            @if ($seats === [])
                <p class="py-6 text-center opacity-80">
                    None of your sessions has reported a repository yet. Seats appear here once one does.
                </p>
            @else
                <ul class="divide-y divide-base-200">
                    @foreach ($seats as $seat)
                        <li wire:key="seat-{{ $seat->id }}" class="flex flex-wrap items-center justify-between gap-2 py-3">
                            <div>
                                <div class="font-medium">
                                    {{ $seat->repository }}@if ($seat->work_location !== '') / {{ $seat->work_location }}@endif
                                </div>
                                <div class="text-meta opacity-90">
                                    {{ $seat->installation->harness }} on {{ $seat->installation->machine_label }}
                                    @if ($seat->parked_at !== null)
                                        &middot; parked {{ $seat->parked_at->diffForHumans() }}
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                @if ($seat->hours_exempt)
                                    <span class="badge badge-sm">exempt from hours</span>
                                    <button type="button" wire:click="unexempt({{ \RobotCouncil\Support\WireArgument::of($seat->id) }})" class="btn btn-sm btn-ghost">Apply my hours</button>
                                @else
                                    <button type="button" wire:click="exempt({{ \RobotCouncil\Support\WireArgument::of($seat->id) }})" class="btn btn-sm btn-ghost">Exempt from hours</button>
                                @endif

                                @if ($seat->isParked())
                                    <span class="badge badge-sm badge-warning">parked</span>
                                    <button type="button" wire:click="lift({{ \RobotCouncil\Support\WireArgument::of($seat->id) }})" class="btn btn-sm btn-primary">Lift</button>
                                @else
                                    <button type="button" wire:click="park({{ \RobotCouncil\Support\WireArgument::of($seat->id) }})" class="btn btn-sm btn-warning">Park</button>
                                @endif
                            </div>
                            {{-- #320: a waiver lets exactly one placement through one refusal on this
                                 seat. Only the seat's developer can grant it; a coordinator cannot. --}}
                            <details class="w-full text-meta">
                                <summary class="cursor-pointer opacity-90">
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
                                                <button type="button" wire:click="withdrawWaiver({{ \RobotCouncil\Support\WireArgument::of($seat->id) }}, '{{ \RobotCouncil\Support\WireArgument::of($rule) }}')" class="btn btn-xs btn-ghost">Withdraw waiver</button>
                                            @else
                                                <button type="button" wire:click="waive({{ \RobotCouncil\Support\WireArgument::of($seat->id) }}, '{{ \RobotCouncil\Support\WireArgument::of($rule) }}')" class="btn btn-xs">Waive once</button>
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
                    <input type="checkbox" wire:model="skipWeekends" class="checkbox checkbox-sm">
                    <span class="label-text">Skip weekends</span>
                </label>

                <button type="submit" class="btn btn-sm btn-primary">Save hours</button>

                @if ($hours !== null)
                    <button type="button" wire:click="clearHours" class="btn btn-sm btn-ghost">Remove hours</button>
                @endif
            </form>
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

                <button type="submit" class="btn btn-sm">Add day off</button>
            </form>

            @if ($holidays !== [])
                <ul class="flex flex-wrap gap-2">
                    @foreach ($holidays as $day)
                        <li wire:key="holiday-{{ $day }}" class="badge badge-lg gap-2">
                            {{ $day }}
                            <button type="button" wire:click="removeHoliday('{{ \RobotCouncil\Support\WireArgument::of($day) }}')" class="btn btn-xs btn-ghost" aria-label="Remove {{ $day }}">&times;</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
