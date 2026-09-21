<?php

declare(strict_types=1);

namespace RobotCouncil\Access;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use RobotCouncil\RobotCouncilServiceProvider;
use RobotCouncil\Support\HostKey;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The developer this request is being made by, read from the package's own guard.
 *
 * **This exists because `Gate` does not read `robot-council.auth.guard`, and nothing says so at the
 * call site.** Laravel builds the framework gate as
 * `new Gate($app, fn () => $app['auth']->userResolver()())`, and that resolver is
 * `guard(null)->user()` -- the host's *default* guard. So `Gate::authorize()` and `@can` both decide
 * on whichever principal the host defaults to, while `EnsureAllowlistedDeveloper`, the GitHub
 * callback, and the enrollment decision all read the configured one. On a host where the two differ,
 * an admin signed in through this package is refused by its own gate, and on a host whose default
 * guard has a *different provider* the two are different people: the check would run against one
 * key and the audit record name another.
 *
 * `Access\Guard` holds which guard that is; this holds what to do with it, so the rule lives in one
 * place rather than being restated by every component that needs it. It was restated rather than
 * shared once already, which is how the dashboard's admin panel came to authorize on the wrong
 * guard while the method twelve lines below it read the right one.
 */
final class CurrentDeveloper
{
    /**
     * @param  AuthFactory  $auth  The host application's authentication factory.
     * @param  Guard  $guard  The configured guard's name.
     * @param  Gate  $gate  The authorization gate the package defines its ability on.
     */
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Guard $guard,
        private readonly Gate $gate
    ) {}

    /**
     * Whoever is signed in on the package's guard, or null.
     *
     * @return Authenticatable|null The developer, or null when the guard resolves nobody.
     */
    public function user(): ?Authenticatable
    {
        return $this->auth->guard($this->guard->name())->user();
    }

    /**
     * Whether that developer holds the package's admin ability.
     *
     * `forUser()` rather than a bare `allows()`, which is the whole point of this class: the bare
     * form asks the gate to resolve the principal itself, and it resolves the wrong one.
     *
     * @return bool True when a developer is signed in on the package's guard and is an admin.
     */
    public function isAdmin(): bool
    {
        $user = $this->user();

        return $user instanceof Authenticatable
            && $this->gate->forUser($user)->allows(RobotCouncilServiceProvider::ADMIN_ABILITY);
    }

    /**
     * Refuse anyone who is not an admin.
     *
     * A 403 rather than a redirect, because the callers are Livewire actions posted to
     * `/livewire/update`. There is no page to send anybody back to, and a redirect there is a
     * response the component would have to interpret.
     *
     * @throws AccessDeniedHttpException When nobody is signed in on the package's guard, or the
     *                                   developer who is holds no admin ability.
     */
    public function authorizeAdmin(): void
    {
        if (! $this->isAdmin()) {
            throw new AccessDeniedHttpException;
        }
    }

    /**
     * That developer's host user key, for the record of what they changed.
     *
     * Through `HostKey`, which is where the 64-character bound lives. `tryFrom` rather than `from`:
     * an unusable key means the change goes unattributed, which is worse than nothing and much
     * better than a 500 in the middle of revoking a credential.
     *
     * @return string|null The key, or null when the guard resolves nobody.
     */
    public function key(): ?string
    {
        return HostKey::tryFrom($this->user()?->getAuthIdentifier());
    }
}
