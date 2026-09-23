<?php

declare(strict_types=1);

namespace RobotCouncil\Http\ViewComposers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use RobotCouncil\Access\CurrentDeveloper;
use RobotCouncil\Support\AgentLogins;
use RobotCouncil\Support\DashboardSections;

/**
 * Supplies the dashboard's shell with what every page inside it needs.
 *
 * **A composer rather than data passed by each page.** The shell names the signed-in developer and
 * marks where they are, and neither depends on which page is rendering -- so requiring every
 * component and controller to pass them would be several chances to forget, and a page that forgot
 * would render a shell with no developer and no current marker rather than failing.
 *
 * **It supplies no URLs, deliberately.** Every link in the layout is written as a literal
 * `route('...')` call in the template, because that is the only form `EscapingGuardTest` admits in
 * a URL attribute: a URL arriving through a variable is indistinguishable, in the template, from
 * one a requester supplied, and escaping does nothing to a `javascript:` URL. The cost is that a
 * destination is added by editing the sidebar rather than by adding a list entry, which is the
 * trade the #70 guard exists to force.
 *
 * `isAdmin` is resolved here through `CurrentDeveloper` rather than asked for with `@can`. `@can`
 * resolves the HOST'S DEFAULT guard, so on a host whose default is another guard the administration
 * entry would be hidden from a real admin. This decides what is *shown*; the administration
 * component authorizes its own render regardless, because a control that is not drawn is not an
 * authorization boundary.
 */
final class DashboardLayoutComposer
{
    /**
     * @param  CurrentDeveloper  $developer  Who is signed in on the package's guard.
     * @param  AgentLogins  $logins  Resolves a host user key to a GitHub login.
     * @param  Router  $router  Names the route being served, for the current marker.
     * @param  Request  $request  Carries the section selection, for the jump links.
     */
    public function __construct(
        private readonly CurrentDeveloper $developer,
        private readonly AgentLogins $logins,
        private readonly Router $router,
        private readonly Request $request
    ) {}

    /**
     * Bind the shell's own data onto the view.
     *
     * @param  View  $view  The layout being rendered.
     */
    public function compose(View $view): void
    {
        $isAdmin = $this->developer->isAdmin();

        $view->with([
            'developerLogin' => $this->login(),
            'isAdmin' => $isAdmin,

            // The route NAME rather than the path: a host mounts this package under a prefix of its
            // choosing, so the path is not knowable here
            'currentRoute' => $this->router->currentRouteName(),

            // Which panels the page has, so a jump link never points at a section that is not
            // there. Read through the same call the index mounts by, so the two cannot disagree
            // about what a selection means -- and filtered by the same admin rule, so the
            // administration link cannot be reached by editing the query string.
            //
            // **It reflects the URL, which is where Livewire keeps the selection.** A developer who
            // toggles a section without reloading changes the URL through `pushState` but not this
            // sidebar, which re-renders on a full request. The stale entry is the one they just put
            // away, and clicking it does what clicking it did before this existed: nothing.
            'showingSections' => DashboardSections::from($this->selection(), $isAdmin),
        ]);
    }

    /**
     * The section selection the request carried, if any.
     *
     * Taken as `mixed` and narrowed here rather than type-hinted: a query parameter is whatever the
     * requester sent, and `?show[]=x` makes it an array. Anything that is not a string is no
     * selection at all, which `DashboardSections::from()` reads as every section.
     *
     * @return string|null The raw value, or null when none was sent or it was not a string.
     */
    private function selection(): ?string
    {
        $show = $this->request->query('show');

        return \is_string($show) ? $show : null;
    }

    /**
     * The signed-in developer's GitHub login.
     *
     * Resolved from the host user key rather than through a session, because `forUsers()` is the
     * lookup that never goes near a session id -- and session ids are reused, so a name resolved
     * that way can be somebody else's.
     *
     * @return string|null The login, or null when nobody is signed in or the account has no
     *                     recorded identity.
     */
    private function login(): ?string
    {
        $key = $this->developer->key();

        if ($key === null) {
            return null;
        }

        return $this->logins->forUsers([$key])[$key] ?? null;
    }
}
