{{--
    How the Access page names one allowlisted account (#484): the login it signed in with; else
    the login GitHub gave for its ID, linked to the profile and marked as not signed in yet, since
    nobody has yet proved they hold it; else today's words. Every value is escaped text, and the
    link is built by `TicketLink::profile()`, which admits only a GitHub login.

    Takes `name`, an array of `login` (string or null) and `signed_in` (bool), and `id`.
--}}
<span class="font-medium">
    @if ($name['login'] === null)
        not signed in yet
    @elseif ($name['signed_in'])
        {{ $name['login'] }}
    @else
        <x-robot-council::external-link :href="\RobotCouncil\Support\TicketLink::profile($name['login'])" class="link">{{ $name['login'] }}</x-robot-council::external-link>
        (not signed in yet)
    @endif
</span>
<span class="opacity-90">&middot; GitHub user <code>{{ $id }}</code></span>
