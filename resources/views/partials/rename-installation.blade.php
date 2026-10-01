{{--
    One installation's rename field (#534), shared by the Administration page and a developer's own
    seats page so the two say and do the same thing.

    Pass `installation` as an array with `id`, `harness` and `machine_label`, `said` as the id of the
    row's said region, and `failed` as whether the last action refused this row's rename, so the
    field is marked invalid and read with the refusal. Both components name
    the action `renameInstallation`, so only the id reaches a `wire:` expression, through
    `WireArgument`; the label is bound with `wire:model` and read back by the component as
    untrusted input. The harness and label are agent-supplied and rendered as text only.
--}}
@php($renameName = $installation['harness'].' on '.$installation['machine_label'])
<form wire:submit="renameInstallation({{ \RobotCouncil\Support\WireArgument::of($installation['id']) }})" class="mt-3 flex w-full flex-wrap items-end gap-3" data-rename-installation="{{ $installation['id'] }}">
    {{-- Each field and button names its installation to a screen reader, since every row has one --}}
    <label class="flex w-full flex-col gap-1 sm:w-auto" data-field>
        <span>Machine label<span class="sr-only"> for {{ $renameName }}</span></span>
        {{-- No `maxlength`: a browser would cut a pasted label short and rename to something nobody
             typed, where the store refuses an over-long one in words --}}
        <input type="text" autocomplete="off" spellcheck="false" wire:model="labels.{{ \RobotCouncil\Support\WireArgument::of($installation['id']) }}" class="input w-full sm:w-64" @if ($failed) aria-invalid="true" aria-describedby="{{ $said }} installation-{{ $installation['id'] }}-rename-help" @else aria-describedby="installation-{{ $installation['id'] }}-rename-help" @endif>
    </label>
    <button type="submit" class="btn btn-target btn-outline">Rename<span class="sr-only"> {{ $renameName }}</span></button>
    <p id="installation-{{ $installation['id'] }}-rename-help" class="max-w-xl text-meta leading-relaxed opacity-90">
        Up to {{ \RobotCouncil\Support\MachineIdentity::MAX_LABEL }} letters, digits, dots, dashes and underscores.
        The machine keeps working and does not enroll again; the fleet sees the new name from now on.
    </p>
</form>
