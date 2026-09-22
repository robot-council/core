<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use RobotCouncil\Access\Guard;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Support\Installations;

/**
 * The page a developer opens to approve or deny an enrollment.
 *
 * It is the only place a human sees an enrollment request, so it is where the phishing defense
 * lives: the page prints what the requester claimed as claims, shows how long ago the code was
 * asked for and from which address, and makes the developer confirm that the code is on a machine
 * they control before it will accept an approval.
 *
 * It is also the only warning that an approval **ends** an existing installation (#106), which is
 * why this controller reads that before the decision rather than reporting it after.
 */
final class EnrollmentPageController
{
    /**
     * @param  AuthFactory  $auth  The host application's authentication factory.
     * @param  Guard  $guard  The configured guard's name.
     * @param  Installations  $installations  The installation store.
     */
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Guard $guard,
        private readonly Installations $installations
    ) {}

    /**
     * Show the code-entry form, and the request it names once a code is entered.
     *
     * @param  Request  $request  The incoming request.
     * @param  DeviceCodes  $deviceCodes  The device-code store.
     * @return View The verification page.
     */
    public function __invoke(Request $request, DeviceCodes $deviceCodes): View
    {
        $typed = $request->query('user_code');
        $typed = \is_string($typed) ? $typed : '';

        $code = $typed === '' ? null : $deviceCodes->findByUserCode($typed);

        // Pinned, because whether the analyzer can resolve a package view depends on whether it
        // could boot the application, which differs between a developer's machine and CI
        /** @var view-string $template */
        $template = 'robot-council::enroll';

        return view($template, [
            'userCode' => DeviceCodes::normalizeUserCode($typed),
            'code' => $code,

            // Told apart so the page can say "no live request by that code" rather than showing an
            // empty form again, which reads as the code having been accepted
            'searched' => $typed !== '',
            'approverIp' => $request->ip(),

            // What approving would end, read before the decision rather than reported after it
            'superseded' => $this->superseded($code),
        ]);
    }

    /**
     * The installations approving this request would revoke.
     *
     * **This is the whole of the warning an approver gets** (#106). Approving supersedes the live
     * installations for the same developer, harness, and machine label -- and `harness` and
     * `machine_label` are what the requester claimed, which this page already says nothing has
     * checked. Two of one developer's machines can collide on them, so without this the approval
     * would silently end a working installation and only the fleet event would show it.
     *
     * Read as the signed-in developer, because `user_id` on an installation is the **approver**
     * rather than the requester, and `EnsureAllowlistedDeveloper` admits nobody who is not one.
     *
     * A decided code is skipped, because nothing further will be approved from it and naming
     * installations it would have ended reads as a threat to something already past.
     *
     * @param  DeviceCode|null  $code  The request being shown, when a code was found.
     * @return Collection<int, Installation> What an approval would revoke; empty when nothing would.
     */
    private function superseded(?DeviceCode $code): Collection
    {
        if (! $code instanceof DeviceCode || $code->isDecided()) {
            /** @var Collection<int, Installation> $none */
            $none = new Collection;

            return $none;
        }

        $approver = $this->auth->guard($this->guard->name())->user()?->getAuthIdentifier();

        if ($approver === null) {
            /** @var Collection<int, Installation> $none */
            $none = new Collection;

            return $none;
        }

        return $this->installations->liveFor(
            HostKey::from($approver),
            $code->harness,
            $code->machine_label
        );
    }
}
