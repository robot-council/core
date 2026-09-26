{{--
    The robot-council/core release this deployment is running (#405), at the foot of every page, in
    the same place on each. Read from the installed package by `Support\CoreVersion`, never from
    GitHub. Plain text in the page's own color rather than a dimmed line, so it meets the contrast
    the rest of the page does.
--}}
<footer class="px-4 py-3 text-center text-meta" data-core-version>
    {{ \RobotCouncil\Support\CoreVersion::current() }}
</footer>
