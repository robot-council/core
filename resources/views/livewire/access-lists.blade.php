{{--
    Who may sign in and who may administer (#407). Administrators only; the component refuses
    everyone else in mount, render and every action.

    Nothing is rendered unescaped, which the #67 guard enforces over every template here. Only
    values this package chose reach a `wire:` expression -- a list name from `Access\AccessList`
    and a GitHub ID the store read as a whole number. A login is rendered as text and reaches no
    attribute; `aria-label`s name an entry by its ID alone for that reason.
--}}

<div class="space-y-6">
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h1 class="card-title">Access</h1>

            @include('robot-council::partials.glossary', ['terms' => ['allowlist', 'developer_list', 'administrator_list', 'from_configuration', 'github_id']])

            <p class="max-w-xl leading-relaxed">
                Who may sign in to this dashboard and connect agents, and who may administer it.
                Entries added here take effect on the next request. Entries marked
                <strong>from configuration</strong> come from the server's settings and can only be
                changed there, so an administrator named there can always get back in.
            </p>

            <form wire:submit="add" class="flex flex-wrap items-end gap-3">
                <label class="form-control">
                    <span class="label-text">List</span>
                    <select wire:model="list" class="select select-bordered">
                        <option value="developer">Developers</option>
                        <option value="admin">Administrators</option>
                    </select>
                </label>

                <label class="form-control">
                    <span class="label-text">GitHub user ID (a number)</span>
                    <input type="text" inputmode="numeric" wire:model="githubId" maxlength="20" class="input input-bordered w-40" autocomplete="off">
                </label>

                <label class="form-control">
                    <span class="label-text">GitHub login</span>
                    <input type="text" wire:model="login" maxlength="39" class="input input-bordered w-48" autocomplete="off">
                </label>

                <button type="submit" class="btn btn-target btn-primary">Add to list</button>
            </form>

            @include('robot-council::partials.said', ['show' => $said !== null && $saidAt === 'add', 'class' => ''])
        </div>
    </div>

    @foreach ([['developer', 'Developers', 'May sign in, use the dashboard, and connect agents.'], ['admin', 'Administrators', 'May also approve roles, revoke machines, and change these lists. An administrator is admitted whether or not they are on the developer list.']] as [$key, $heading, $what])
        <div wire:key="list-{{ $key }}" class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title">{{ $heading }}</h2>
                <p class="max-w-xl leading-relaxed">{{ $what }}</p>

                @include('robot-council::partials.said', ['show' => $said !== null && $saidAt === $key, 'class' => ''])

                @if ($lists[$key]['configured'] === [] && $lists[$key]['stored'] === [])
                    <p class="py-4 opacity-80">None yet: nobody is on this list.</p>
                @else
                    <ul class="divide-y divide-base-200">
                        @foreach ($lists[$key]['configured'] as $entry)
                            <li wire:key="{{ $key }}-configured-{{ $entry['github_id'] }}" class="flex flex-wrap items-center justify-between gap-2 py-3">
                                <div>
                                    <span class="font-medium">{{ $logins['id:'.$entry['github_id']] ?? 'not signed in yet' }}</span>
                                    <span class="opacity-90">&middot; GitHub user <code>{{ $entry['github_id'] }}</code></span>
                                </div>

                                <span class="badge badge-outline">from configuration</span>
                            </li>
                        @endforeach

                        @foreach ($lists[$key]['stored'] as $entry)
                            @unless ($entry['also_configured'])
                                <li wire:key="{{ $key }}-stored-{{ $entry['github_id'] }}" class="flex flex-wrap items-center justify-between gap-2 py-3">
                                    <div>
                                        <span class="font-medium">{{ $entry['login'] }}</span>
                                        <span class="opacity-90">&middot; GitHub user <code>{{ $entry['github_id'] }}</code></span>
                                        @if ($entry['is_self'])
                                            <strong>(you)</strong>
                                        @endif
                                    </div>

                                    {{-- Removing yourself, or the last administrator the table holds, is
                                         allowed and warned about first (#312's decision): an administrator
                                         from configuration can always add one back. Each warning is fixed
                                         text, because nothing interpolated may reach a `wire:` attribute
                                         (the #67 guard); the row beside it says whose entry it is. --}}
                                    @if ($entry['is_self'])
                                        <button type="button"
                                            wire:click="remove('{{ \RobotCouncil\Support\WireArgument::of($key) }}', {{ \RobotCouncil\Support\WireArgument::of($entry['github_id']) }})"
                                            wire:confirm="Remove yourself from this list? You may lose that access on your next request, unless the server configuration names you."
                                            aria-label="Remove yourself, GitHub user {{ $entry['github_id'] }}, from the {{ $key === 'admin' ? 'administrator' : 'developer' }} list"
                                            class="btn btn-target btn-warning">
                                            Remove
                                        </button>
                                    @elseif ($key === 'admin' && $lastTableAdmin === $entry['github_id'])
                                        <button type="button"
                                            wire:click="remove('{{ \RobotCouncil\Support\WireArgument::of($key) }}', {{ \RobotCouncil\Support\WireArgument::of($entry['github_id']) }})"
                                            wire:confirm="Remove the last administrator added here? Only administrators from the server configuration will remain."
                                            aria-label="Remove GitHub user {{ $entry['github_id'] }} from the administrator list"
                                            class="btn btn-target btn-warning">
                                            Remove
                                        </button>
                                    @else
                                        <button type="button"
                                            wire:click="remove('{{ \RobotCouncil\Support\WireArgument::of($key) }}', {{ \RobotCouncil\Support\WireArgument::of($entry['github_id']) }})"
                                            wire:confirm="Remove this account from the list? It takes effect on their next request."
                                            aria-label="Remove GitHub user {{ $entry['github_id'] }} from the {{ $key === 'admin' ? 'administrator' : 'developer' }} list"
                                            class="btn btn-target btn-warning">
                                            Remove
                                        </button>
                                    @endif
                                </li>
                            @endunless
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endforeach
</div>
