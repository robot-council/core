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

/**
 * Records a developer's decision on an enrollment request. Both actions accept POST only, so
 * nothing a developer can be led to follow -- a link, an image, a redirect -- can approve an
 * installation.
 *
 * A decision is final. Each is one conditional update, so a second decision on the same code, from
 * a double submit or a second browser tab, changes nothing and says so.
 *
 * **A refusal is answered on the page, not with an error page** (#452). An expired code and a
 * request already decided are ordinary for a developer -- a page left open, a second click -- so
 * each redirects back to the enrollment page, as a decision that succeeds does, with a message
 * that leads with its keyword and says what to do. They were 404 and 409 before, and the
 * framework's error page named neither the request nor a next step. A redirect rather than the
 * page rendered with that status, so reloading it does not resubmit the form.
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
     * @return RedirectResponse Back to the page, which then shows the decision, or why none was made.
     */
    public function approve(Request $request): RedirectResponse
    {
        $request->validate([
            'user_code' => ['required', 'string', 'max:32'],

            // RFC 8628 section 5.4: the developer confirms the code is displayed on a machine they
            // control, which is what an approval from a phishing page cannot truthfully claim
            'confirmed' => ['accepted'],
        ]);

        $code = $this->deviceCodes->findByUserCode($request->string('user_code')->value());

        if (! $code instanceof DeviceCode) {
            return $this->expired($request->string('user_code')->value());
        }

        // Read from the stored row rather than the request, so abilities added to this POST reach
        // nothing, and so the list shown on the page is the list that is granted
        $granted = Ability::granted($code->requestedAbilities());

        if (! $this->deviceCodes->approve($code, $this->developerKey(), $granted)) {
            return $this->refused($code);
        }

        return $this->back($code, 'Approved: the machine that asked for this code can now enroll.');
    }

    /**
     * Deny a request.
     *
     * @param  Request  $request  The incoming request.
     * @return RedirectResponse Back to the page, which then shows the decision, or why none was made.
     */
    public function deny(Request $request): RedirectResponse
    {
        $request->validate([
            'user_code' => ['required', 'string', 'max:32'],
        ]);

        $code = $this->deviceCodes->findByUserCode($request->string('user_code')->value());

        if (! $code instanceof DeviceCode) {
            return $this->expired($request->string('user_code')->value());
        }

        if (! $this->deviceCodes->deny($code, $this->developerKey())) {
            return $this->refused($code);
        }

        return $this->back($code, 'Denied: nothing was enrolled, and the machine that asked cannot use this code.');
    }

    /**
     * Send the developer back to say the code is no longer waiting.
     *
     * An unknown code reads the same, because the store cannot tell a code that expired and was
     * pruned from one that never existed, and the next step is the same for both.
     *
     * @param  string  $userCode  What the developer submitted.
     * @return RedirectResponse The redirect.
     */
    private function expired(string $userCode): RedirectResponse
    {
        return redirect()
            ->route('robot-council.enroll.show', ['user_code' => DeviceCodes::normalizeUserCode($userCode)])
            ->with('refused', 'Expired: this code is no longer waiting. Ask the machine for a new one.');
    }

    /**
     * Send the developer back to say why the store refused the decision.
     *
     * The store's update refuses a request that is decided, exchanged or expired, and says only
     * that it refused, so the row is read again to tell them apart: a code that expired or was
     * pruned between the lookup and the update is not "already decided".
     *
     * @param  DeviceCode  $code  The request the decision was about.
     * @return RedirectResponse The redirect.
     */
    private function refused(DeviceCode $code): RedirectResponse
    {
        $now = $code->fresh();

        // Only an approved request is ever exchanged, so decided covers consumed too
        if (! $now instanceof DeviceCode || ! $now->isDecided()) {
            return $this->expired($code->user_code);
        }

        return redirect()
            ->route('robot-council.enroll.show', ['user_code' => $code->user_code])
            ->with('refused', 'Already decided: this request was approved or denied before. Nothing further will happen to it.');
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
