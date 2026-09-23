<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Guard;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\HostKey;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Records a developer's decision on an enrollment request. Both actions accept POST only, so
 * nothing a developer can be led to follow -- a link, an image, a redirect -- can approve an
 * installation.
 *
 * A decision is final. Each is one conditional update, so a second decision on the same code, from
 * a double submit or a second browser tab, changes nothing and says so.
 */
final class EnrollmentDecisionController
{
    /**
     * @param  DeviceCodes  $deviceCodes  The device-code store.
     * @param  AuthFactory  $auth  The host application's authentication factory.
     * @param  Guard  $guard  The configured guard's name.
     */
    public function __construct(
        private readonly DeviceCodes $deviceCodes,
        private readonly AuthFactory $auth,
        private readonly Guard $guard
    ) {}

    /**
     * Approve a request, granting the abilities it asked for that are on the fixed list.
     *
     * @param  Request  $request  The incoming request.
     * @return RedirectResponse Back to the page, which then shows the decision.
     *
     * @throws NotFoundHttpException When no live request carries that code.
     * @throws ConflictHttpException When the request was already decided or exchanged.
     */
    public function approve(Request $request): RedirectResponse
    {
        $request->validate([
            'user_code' => ['required', 'string', 'max:32'],

            // RFC 8628 section 5.4: the developer confirms the code is displayed on a machine they
            // control, which is what an approval from a phishing page cannot truthfully claim
            'confirmed' => ['accepted'],
        ]);

        $code = $this->live($request->string('user_code')->value());

        // Read from the stored row rather than the request, so abilities added to this POST reach
        // nothing, and so the list shown on the page is the list that is granted
        $granted = Ability::granted($code->requestedAbilities());

        if (! $this->deviceCodes->approve($code, $this->developerKey(), $granted)) {
            throw new ConflictHttpException;
        }

        return $this->back($code, 'Approved. The machine that asked for this code can now enroll.');
    }

    /**
     * Deny a request.
     *
     * @param  Request  $request  The incoming request.
     * @return RedirectResponse Back to the page, which then shows the decision.
     *
     * @throws NotFoundHttpException When no live request carries that code.
     * @throws ConflictHttpException When the request was already decided or exchanged.
     */
    public function deny(Request $request): RedirectResponse
    {
        $request->validate([
            'user_code' => ['required', 'string', 'max:32'],
        ]);

        $code = $this->live($request->string('user_code')->value());

        if (! $this->deviceCodes->deny($code, $this->developerKey())) {
            throw new ConflictHttpException;
        }

        return $this->back($code, 'Denied. Nothing was enrolled.');
    }

    /**
     * Find the live request a code names.
     *
     * @param  string  $userCode  What the developer typed.
     * @return DeviceCode The request.
     *
     * @throws NotFoundHttpException When the code is unknown or has expired.
     */
    private function live(string $userCode): DeviceCode
    {
        $code = $this->deviceCodes->findByUserCode($userCode);

        if (! $code instanceof DeviceCode) {
            throw new NotFoundHttpException;
        }

        return $code;
    }

    /**
     * The approving developer's key in the host application's users table.
     *
     * @return string The developer's key, as the package stores it.
     *
     * @throws RuntimeException When the guard has nobody, or the host's key is not storable.
     */
    private function developerKey(): string
    {
        return HostKey::from($this->auth->guard($this->guard->name())->user()?->getAuthIdentifier());
    }

    /**
     * Send the developer back to the page showing this code.
     *
     * @param  DeviceCode  $code  The request that was decided.
     * @param  string  $status  What to tell the developer.
     * @return RedirectResponse The redirect.
     */
    private function back(DeviceCode $code, string $status): RedirectResponse
    {
        return redirect()
            ->route('robot-council.enroll.show', ['user_code' => $code->user_code])
            ->with('status', $status);
    }
}
