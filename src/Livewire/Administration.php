<?php

declare(strict_types=1);

namespace RobotCouncil\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RobotCouncil\Access\CurrentDeveloper;
use RobotCouncil\Access\Role;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\AssignmentWindow;
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
#[Title('Administration')]
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
     * What the last action did, or why it did nothing, in words (#402).
     *
     * Locked, like every value here a client could otherwise set: the page prints it as the
     * server's own account of what happened.
     */
    #[Locked]
    public ?string $said = null;

    /**
     * The installation the last action was about, so the page shows `said` beside it, or null.
     *
     * A session's actions are shown on its installation rather than on the session's own row,
     * because revoking a session takes that row out of the list.
     */
    #[Locked]
    public ?int $saidAt = null;

    /**
     * Whether the last action was refused or changed nothing, which the page shows as an error.
     */
    #[Locked]
    public bool $refused = false;

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
        $this->said = null;
    }

    /**
     * Go back to the newest installations.
     */
    public function showFirst(): void
    {
        $this->authorizeAdmin();

        $this->after = null;
        $this->said = null;
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
        $this->said = null;
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
            $this->say(null, 'Not found: that installation no longer exists. The list shows the ones that do.', refused: true);

            return;
        }

        if ($installation->revoked_at !== null) {
            $this->say($installation->id, sprintf('Already revoked: %s on %s was stopped before, so nothing changed.', $installation->harness, $installation->machine_label), refused: true);

            return;
        }

        $this->service(Installations::class)->revoke($installation, $this->actor());

        $this->say($installation->id, sprintf(
            'Revoked: %s on %s can no longer act, and neither can any session it started.',
            $installation->harness,
            $installation->machine_label
        ));
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

        $session = $this->session($sessionId);

        if (! $session instanceof AgentSession) {
            return;
        }

        // Named, like the other three administrative actions. Killing another developer's
        // running agent was the one the feed could not attribute (#115).
        // Decided from the status rather than from what `revoke()` returns, which counts the tokens
        // it deleted: a live session whose installation was revoked has none left, and revoking it
        // still ends it
        if ($session->status === AgentSessionStatus::Gone) {
            $this->say($session->installation_id, sprintf('Already gone: session #%d had already ended, so nothing changed.', $session->id), refused: true);

            return;
        }

        $this->service(SessionPresence::class)->revoke($session, $this->actor());

        $this->say($session->installation_id, sprintf('Revoked: session #%d has ended, and its agent can no longer act.', $session->id));
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

        $session = $this->session($sessionId);

        if (! $session instanceof AgentSession) {
            return;
        }

        if (! $this->service(RoleRequests::class)->approve($session, $expected, $this->actor()) instanceof Role) {
            $this->unchanged($session, sprintf(
                'Not approved: session #%d no longer asks to be %s. The list shows what it asks for now.',
                $session->id,
                $expected->value
            ));

            return;
        }

        $this->say($session->installation_id, sprintf('Approved: session #%d is now %s.', $session->id, $expected->value));
    }

    /**
     * Refuse what a session asked to be, leaving it as it is.
     *
     * @param  int  $sessionId  The session whose request to refuse.
     */
    public function denyRole(int $sessionId): void
    {
        $this->authorizeAdmin();

        $session = $this->session($sessionId);

        if (! $session instanceof AgentSession) {
            return;
        }

        if (! $this->service(RoleRequests::class)->deny($session, $this->actor())) {
            $this->unchanged($session, sprintf('Nothing to deny: session #%d has no request waiting now.', $session->id));

            return;
        }

        $this->say($session->installation_id, sprintf('Denied: session #%d stays %s.', $session->id, $session->refresh()->role->value));
    }

    /**
     * Put a session in a role with no request outstanding.
     *
     * **This is the emergency demotion**, and the administrator's own action is the approval. It is
     * also the only way a role narrows. `robot-council/core#222` took the last machine-level lever
     * away -- a stored ability list stopped reaching any session -- and
     * `robot-council/core#231` removed the controls that wrote it, so this is the whole of what an
     * administrator can do about a session that should not be coordinating.
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

        $session = $this->session($sessionId);

        if (! $session instanceof AgentSession) {
            return;
        }

        if (! $this->service(RoleRequests::class)->impose($session, $resolved, $this->actor())) {
            $this->unchanged($session, sprintf('No change: session #%d was already %s.', $session->id, $resolved->value));

            return;
        }

        $this->say($session->installation_id, sprintf('Changed: session #%d is now %s.', $session->id, $resolved->value));
    }

    /**
     * Render the panel.
     *
     * @param  InstallationList  $installations  The installation reader.
     * @param  Repository  $config  The application's configuration, for the zone times are shown in.
     * @return View The panel.
     */
    public function render(InstallationList $installations, Repository $config): View
    {
        $this->authorizeAdmin();

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::livewire.administration';

        $page = $installations->everything(self::PER_PAGE, Scope::orDefault($this->scope, Scope::Live), $this->after);

        return view($template, [
            'page' => $page,

            // Not `scope`, which is the public property's name. Livewire hands the view every
            // public property AFTER `render()` returns (`Utils::generateBladeView()` calls
            // `->with($properties)` on the view this builds), so a key named after one is
            // overwritten by the raw string -- and `'all' === Scope::All` is false, which left
            // neither scope button marked selected and hid the "Choose All" hint (#309).
            'installationScope' => Scope::orDefault($this->scope, Scope::Live),
            'installations' => $page['installations'],

            // Every role, for the same reason: a control per case, so a fourth role is offered the
            // day it exists rather than the day somebody remembers this file.
            'roles' => Role::cases(),

            // The zone a session's join and contact times are shown in (#419), as the lane board
            // shows its stamps; a zone the host mistyped falls back rather than failing the page
            'timezone' => AssignmentWindow::isTimezone($timezone = $config->get('robot-council.dashboard.timezone')) && \is_string($timezone) ? $timezone : 'UTC',
        ]);
    }

    /**
     * The session an action names, or null having said that it no longer exists.
     *
     * @param  int  $sessionId  The session's id, as the page rendered it.
     * @return AgentSession|null The session.
     */
    private function session(int $sessionId): ?AgentSession
    {
        $session = AgentSession::query()->find($sessionId);

        if (! $session instanceof AgentSession) {
            $this->say(null, sprintf('Not found: session #%d no longer exists. The list shows the ones that do.', $sessionId), refused: true);

            return null;
        }

        return $session;
    }

    /**
     * Say why a role action changed nothing, naming a session that has gone as the reason.
     *
     * The stores answer false both when the row already said what was asked and when the session
     * ended in between, and only the second is something the administrator cannot fix by looking
     * again, so it is read back rather than guessed.
     *
     * @param  AgentSession  $session  The session, as it was before the action.
     * @param  string  $otherwise  What to say when it is still live.
     */
    private function unchanged(AgentSession $session, string $otherwise): void
    {
        // Through the model, so the cast applies: `value()` on an Eloquent query casts too, and
        // comparing its answer with the enum's string would never match
        $status = AgentSession::query()->whereKey($session->id)->first()?->status;

        $this->say($session->installation_id, $status === AgentSessionStatus::Gone
            ? sprintf('Gone: session #%d has ended, so its role can no longer be changed.', $session->id)
            : $otherwise, refused: true);
    }

    /**
     * Record what the last action did, for the page to show beside the installation it was about.
     *
     * @param  int|null  $installationId  The installation, or null to show it above the list.
     * @param  string  $words  What happened, leading with the word that sums it up.
     * @param  bool  $refused  Whether it was refused or changed nothing.
     */
    private function say(?int $installationId, string $words, bool $refused = false): void
    {
        $this->said = $words;
        $this->saidAt = $installationId;
        $this->refused = $refused;
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
