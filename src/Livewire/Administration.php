<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\CurrentDeveloper;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\InstallationList;
use RobotCouncil\Support\Installations;
use RobotCouncil\Support\PollInterval;
use RobotCouncil\Support\RoleRequests;
use RobotCouncil\Support\Scope;
use RobotCouncil\Support\SessionPresence;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The only dashboard panel that writes, and the only one that changes authorization.
 *
 * **Every entry point authorizes, including `render()`.** The gate is on the action, never on
 * whether a control was rendered: a Livewire action is an ordinary POST to `/livewire/update`
 * carrying a snapshot, so a hidden button is markup a client can simply not need. `render()`
 * authorizes for the same reason in reverse -- a developer whose admin status is taken away is
 * still holding a valid snapshot, and without this their open page would keep listing every
 * installation in the fleet until they happened to reload.
 *
 * **With a route of its own, nothing else decides whether this mounts.** `mount()` refuses first,
 * so a direct visit answers 403 from the component and the route needs to know nothing about
 * admins -- a route-level gate would be a second place to get the same rule right. The sidebar
 * does not offer the link, decided on `Access\CurrentDeveloper` rather than with `@can`, which
 * resolves the host's default guard and would hide it from a real admin wherever the two differ.
 * A non-admin therefore rarely meets the refusal; it is there for the client that asks anyway.
 *
 * **The panel shows no credential, and cannot.** `Support\InstallationList` reads no column that
 * holds one -- Sanctum stores a hash, and a `device_code` and its verifier live in a table it never
 * touches -- so this is a property of the reader rather than a rule the view remembers.
 */
#[Layout('robot-council::layouts.dashboard')]
final class Administration extends Component
{
    /**
     * How many installations the panel lists.
     *
     * A fleet has one installation per harness per machine, which is a far smaller number than it
     * has tasks or events, so this is a ceiling rather than a page size. #83 is where the presence
     * lists get a cursor; if this list ever needs one it gets it there too.
     */
    public const int PER_PAGE = 50;

    /**
     * The interval this panel refreshes on, in seconds.
     */
    #[Locked]
    public int $pollSeconds = PollInterval::DEFAULT;

    /**
     * Which installations are listed: those that can still act, or every row.
     */
    #[Url(as: 'installations', keep: false)]
    public ?string $scope = null;

    /**
     * The id of the last installation on the page before this one, or null at the head.
     */
    #[Locked]
    public ?int $after = null;

    /**
     * Refuse anyone who is not an admin, and take the polling interval.
     *
     * **The refusal is here as well as in `render()` and every action**, and with a route of its
     * own that is the one that matters: a direct visit to this page answers 403 from the component
     * without the route knowing anything about admins. A route-level gate would be a second place
     * to get the same rule right.
     *
     * @param  Repository  $config  The application's configuration repository.
     * @param  int|null  $pollSeconds  The interval a parent passed, or null to read the host's.
     */
    public function mount(Repository $config, ?int $pollSeconds = null): void
    {
        $this->authorizeAdmin();

        // Null when a route mounted this directly rather than a parent passing it down.
        $this->pollSeconds = PollInterval::orConfig($pollSeconds, $config);
    }

    /**
     * Show the installations after the last one on this page.
     *
     * @param  int  $after  The id the last read handed back.
     */
    public function showNext(int $after): void
    {
        $this->authorizeAdmin();

        $this->after = max(0, $after);
    }

    /**
     * Go back to the newest installations.
     */
    public function showFirst(): void
    {
        $this->authorizeAdmin();

        $this->after = null;
    }

    /**
     * Widen or narrow which installations are listed, and start again from the head.
     *
     * @param  string  $scope  The scope to read with.
     */
    public function showScope(string $scope): void
    {
        $this->authorizeAdmin();

        $this->scope = Scope::orDefault($scope, Scope::Live)->value;

        $this->after = null;
    }

    /**
     * Give an installation one ability.
     *
     * **It does not reach a session that is already running, and since `robot-council/core#221`
     * it does not decide what one holds either.** A session's abilities come from its
     * `Access\Role` preset; this list decides which roles the MACHINE may run, so granting
     * `coordinator:direct` makes its next session a coordinator and leaves the running ones
     * alone. Granting any other ability changes what is stored and nothing else.
     *
     * @param  int  $installationId  The installation to re-scope.
     * @param  string  $ability  The ability to grant, as the rendered control named it.
     */
    public function grant(int $installationId, string $ability): void
    {
        $this->setAbility($installationId, $ability, granted: true);
    }

    /**
     * Take one ability away from an installation, and demote any live session it no longer
     * qualifies to run.
     *
     * **Only `coordinator:direct` reaches a running session**, because it is the only ability a
     * role turns on. Revoking one of the four an enrollment may request changes the stored list
     * and nothing about any session, now or later -- `Access\Role`'s presets carry all four
     * whatever this column says. `robot-council/core#223` retires these controls.
     *
     * @param  int  $installationId  The installation to re-scope.
     * @param  string  $ability  The ability to revoke, as the rendered control named it.
     */
    public function revokeAbility(int $installationId, string $ability): void
    {
        $this->setAbility($installationId, $ability, granted: false);
    }

    /**
     * Revoke an installation: its own credential and every session token it issued.
     *
     * @param  int  $installationId  The installation to revoke.
     */
    public function revokeInstallation(int $installationId): void
    {
        $this->authorizeAdmin();

        $installation = Installation::query()->find($installationId);

        if (! $installation instanceof Installation) {
            return;
        }

        $this->service(Installations::class)->revoke($installation, $this->actor());
    }

    /**
     * Revoke one agent session, leaving the installation it belongs to alone.
     *
     * Through `SessionPresence`, which is where #24 put every status decision, so this writes the
     * same conditional transition and dispatches the same `SessionGone` as a sweep or a sign-off.
     *
     * @param  int  $sessionId  The session to revoke.
     */
    public function revokeSession(int $sessionId): void
    {
        $this->authorizeAdmin();

        $session = AgentSession::query()->find($sessionId);

        if (! $session instanceof AgentSession) {
            return;
        }

        // Named, like the other three administrative actions. Killing another developer's
        // running agent was the one the feed could not attribute (#115).
        $this->service(SessionPresence::class)->revoke($session, $this->actor());
    }

    /**
     * Approve a session's pending request, naming the role this page rendered.
     *
     * **The role is passed in, and that is a security property rather than plumbing.** The control
     * carries what the administrator was shown; the store refuses if the row has moved on. Reading
     * it off the row instead let a session ask for `ci`, wait for a button to render with no
     * coordinator warning on it, ask for `coordinator`, and collect `coordinator:direct` from the
     * next click. `Support\RoleRequests::approve()` records the mechanism.
     *
     * The role arrives as a string from rendered markup, so it goes through the enum rather than
     * being trusted: a Livewire action is an ordinary POST a client can shape however it likes.
     *
     * @param  int  $sessionId  The session whose request to approve.
     * @param  string  $role  The role the page rendered as pending.
     */
    public function approveRole(int $sessionId, string $role): void
    {
        $this->authorizeAdmin();

        $expected = Role::tryFrom($role);

        if (! $expected instanceof Role) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Roles are: %s.',
                implode(', ', array_map(static fn (Role $case): string => $case->value, Role::cases()))
            ));
        }

        $session = AgentSession::query()->find($sessionId);

        if (! $session instanceof AgentSession) {
            return;
        }

        $this->service(RoleRequests::class)->approve($session, $expected, $this->actor());
    }

    /**
     * Refuse what a session asked to be, leaving it as it is.
     *
     * @param  int  $sessionId  The session whose request to refuse.
     */
    public function denyRole(int $sessionId): void
    {
        $this->authorizeAdmin();

        $session = AgentSession::query()->find($sessionId);

        if (! $session instanceof AgentSession) {
            return;
        }

        $this->service(RoleRequests::class)->deny($session, $this->actor());
    }

    /**
     * Put a session in a role with no request outstanding.
     *
     * **This is the emergency demotion**, and the administrator's own action is the approval. It is
     * also the only way a role narrows: `Support\Installations::setAbility()` stopped reaching
     * sessions when a role became the thing that decides what one may do.
     *
     * The role arrives as a string from a rendered control, so it goes through the enum rather than
     * being trusted: an unknown name is refused with a 422, because a Livewire action is an
     * ordinary POST a client can shape however it likes.
     *
     * @param  int  $sessionId  The session to change.
     * @param  string  $role  The role to impose.
     */
    public function imposeRole(int $sessionId, string $role): void
    {
        $this->authorizeAdmin();

        $resolved = Role::tryFrom($role);

        if (! $resolved instanceof Role) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Roles are: %s.',
                implode(', ', array_map(static fn (Role $case): string => $case->value, Role::cases()))
            ));
        }

        $session = AgentSession::query()->find($sessionId);

        if (! $session instanceof AgentSession) {
            return;
        }

        $this->service(RoleRequests::class)->impose($session, $resolved, $this->actor());
    }

    /**
     * Render the panel.
     *
     * @param  InstallationList  $installations  The installation reader.
     * @return View The panel.
     */
    public function render(InstallationList $installations): View
    {
        $this->authorizeAdmin();

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.administration';

        $page = $installations->everything(self::PER_PAGE, Scope::orDefault($this->scope, Scope::Live), $this->after);

        return view($template, [
            'page' => $page,
            'scope' => Scope::orDefault($this->scope, Scope::Live),
            'installations' => $page['installations'],

            // The fixed list, so the controls offered are exactly what `setAbility()` accepts and
            // the two cannot drift apart
            'grantable' => Ability::grantable(),

            // Every role, for the same reason: a control per case, so a fourth role is offered the
            // day it exists rather than the day somebody remembers this file.
            'roles' => Role::cases(),
        ]);
    }

    /**
     * Add or remove one ability, refusing anything an admin may not grant.
     *
     * @param  int  $installationId  The installation to re-scope.
     * @param  string  $ability  The ability, as the client named it.
     * @param  bool  $granted  True to add it, false to remove it.
     *
     * @throws UnprocessableEntityHttpException When the ability is not one an admin may grant.
     */
    private function setAbility(int $installationId, string $ability, bool $granted): void
    {
        $this->authorizeAdmin();

        // Resolved through the enum's own gate rather than compared here, so `*` -- which Sanctum
        // reads as every ability -- and `sessions:start`, which no session token carries, are
        // refused by the same expression the console command uses.
        $resolved = Ability::grantableFrom($ability);

        if (! $resolved instanceof Ability) {
            Log::warning('robot-council refused an ability outside the grantable list.', [
                'ability' => $ability,
            ]);

            // A 422 rather than a silent return: the rendered controls only ever name a grantable
            // ability, so a request carrying anything else was not built by this page.
            throw new UnprocessableEntityHttpException;
        }

        $installation = Installation::query()->find($installationId);

        if (! $installation instanceof Installation) {
            return;
        }

        $this->service(Installations::class)->setAbility($installation, $resolved, $granted, $this->actor());
    }

    /**
     * Refuse anyone who is not an admin.
     *
     * Called from every entry point rather than from a shared middleware, because Livewire strips
     * from `/livewire/update` every middleware outside its own persistent list -- and what the
     * package does register there, `EnsureAllowlistedDeveloper`, admits developers as well as
     * admins. The allowlist is who may see the dashboard; this is who may change it.
     *
     * Through `Access\CurrentDeveloper` rather than a bare `Gate::authorize()`, which reads the
     * host's default guard instead of the package's. That class records why.
     */
    private function authorizeAdmin(): void
    {
        $this->service(CurrentDeveloper::class)->authorizeAdmin();
    }

    /**
     * The signed-in developer's host key, for the event each change writes.
     *
     * The same principal `authorizeAdmin()` decided on, from the same place, so the account that
     * was allowed to make the change is the account the change is recorded against.
     *
     * @return string|null The key, or null when the guard resolves nobody.
     */
    private function actor(): ?string
    {
        return $this->service(CurrentDeveloper::class)->key();
    }

    /**
     * One service, resolved out of the application.
     *
     * A Livewire component is hydrated from a snapshot rather than constructed, so nothing can be
     * injected into it; `render()` and the actions take what they need as arguments, and the rest
     * is resolved here. Typed through a generic so the analyzer keeps narrowing what comes back.
     *
     * The wording here is deliberate and the reason is in `CLAUDE.md`: Tailwind scans this
     * directory for class candidates, so an ordinary English word in a comment can add a utility
     * to the stylesheet this package ships.
     *
     * @template TService of object
     *
     * @param  class-string<TService>  $abstract  What to resolve.
     * @return TService The service.
     *
     * @throws RuntimeException When the application answers with something else, which a host can
     *                          arrange by rebinding any of these.
     */
    private function service(string $abstract): object
    {
        $service = app($abstract);

        // Checked rather than pinned with a docblock: `app()` answers `mixed`, and every caller
        // here is about to authorize or revoke something. A host may rebind any of these, so the
        // narrowing is a real check rather than a note to the analyzer.
        if (! $service instanceof $abstract) {
            throw new RuntimeException(sprintf('robot-council resolved something other than %s.', $abstract));
        }

        return $service;
    }
}
