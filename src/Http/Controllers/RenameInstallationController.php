<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\Installations;
use RobotCouncil\Support\MachineIdentity;
use RobotCouncil\Support\Outcome;
use Symfony\Component\HttpFoundation\Response;

/**
 * An installation renames itself with its own credential (#590).
 *
 * The route names no installation: it renames the one the request authenticated as, so there is
 * nothing here that could name another. A session's token and another installation's credential
 * never reach it, because `EnsureInstallation` checks the principal's class and its token's class.
 * It needs no ability, as starting a session needs none beyond the credential itself.
 *
 * `Support\Installations::rename()` decides everything else and records `installation.renamed`
 * with `old_label` and `new_label`, exactly as a rename from the dashboard does. A label that
 * enrollment would refuse, and a label another live installation of the same developer and harness
 * already holds, are refused with 422 and the reason in words; a label the installation already
 * carries is 409; an installation revoked between the credential being checked and the rename is
 * 404.
 */
final class RenameInstallationController
{
    /**
     * Rename the installation.
     *
     * @param  Request  $request  The incoming request.
     * @param  Installations  $installations  The installation store.
     * @return JsonResponse The old and new labels, or the reason it was refused.
     *
     * @throws ValidationException When the label is refused, with the reason.
     */
    public function __invoke(Request $request, Installations $installations): JsonResponse
    {
        // The rules the device code applies, so a label refused at enrollment is refused here with
        // the same message
        $request->validate([
            'machine_label' => ['required', 'string', 'max:'.MachineIdentity::MAX_LABEL, 'regex:'.MachineIdentity::LABEL],
        ]);

        $installation = Principal::installation($request);
        $label = $request->string('machine_label')->value();
        $previous = $installation->machine_label;

        try {
            $outcome = $installations->rename($installation->id, $label, $installation->user_id, asAdmin: false);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw ValidationException::withMessages(['machine_label' => [$invalidArgumentException->getMessage()]]);
        }

        return match ($outcome) {
            Outcome::Applied => new JsonResponse([
                'installation_id' => $installation->id,
                'harness' => $installation->harness,
                'old_label' => $previous,
                'new_label' => $label,
            ], Response::HTTP_OK),
            Outcome::Conflict => new JsonResponse([
                'message' => sprintf('This %s installation is already called %s, so nothing changed.', $installation->harness, $label),
                'installation_id' => $installation->id,
                'machine_label' => $label,
            ], Response::HTTP_CONFLICT),
            // `Forbidden` cannot answer an installation renaming itself, and `Added` is a task's
            // alone (#433); both read as the one thing left, an installation that is no longer live
            Outcome::NotFound, Outcome::Forbidden, Outcome::Added => new JsonResponse([
                'message' => sprintf('This %s installation has been revoked, so it has no name to change.', $installation->harness),
            ], Response::HTTP_NOT_FOUND),
        };
    }
}
