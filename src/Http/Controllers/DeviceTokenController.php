<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\DeviceCodeError;
use RobotCouncil\Support\DeviceCodes;
use RobotCouncil\Support\Installations;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exchanges an approved device code for an installation credential. Unauthenticated, like the code
 * endpoint: the verifier is what proves the caller is the helper that started this enrollment.
 *
 * The helper polls this until it gets a credential or a reason to stop, so every unfinished state
 * answers with the RFC 8628 section 3.5 error for it, and every one of them is HTTP 400.
 *
 * The success body is this package's own, not the RFC's. Its request already diverges deliberately
 * -- a verifier rather than `grant_type` and `client_id` -- so no standard client can complete this
 * exchange whatever the response is named, and `access_token` would promise OAuth affordances this
 * service does not have: no scopes, no refresh token, no introspection, no server metadata.
 */
final class DeviceTokenController
{
    /**
     * Claim an approved code, or say why it cannot be claimed.
     *
     * @param  Request  $request  The incoming request.
     * @param  DeviceCodes  $deviceCodes  The device-code store.
     * @param  Installations  $installations  The installation store.
     * @param  Credentials  $credentials  The configured lifetimes.
     * @return JsonResponse The credential, or an RFC 8628 error.
     */
    public function __invoke(
        Request $request,
        DeviceCodes $deviceCodes,
        Installations $installations,
        Credentials $credentials
    ): JsonResponse {
        $request->validate([
            'device_code' => ['required', 'string', 'max:255'],

            // RFC 7636 section 4.1 floors the verifier at 43 characters, because the whole of
            // "a stolen device code is inert" rests on it. A helper is free to send more entropy
            // than that and none to send less.
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
        ]);

        $claimed = $deviceCodes->consume(
            $request->string('device_code')->value(),
            $request->string('code_verifier')->value()
        );

        if ($claimed instanceof DeviceCodeError) {
            return new JsonResponse(['error' => $claimed->value], Response::HTTP_BAD_REQUEST);
        }

        $issued = $installations->createFrom($claimed);
        $installation = $issued->owner;

        return new JsonResponse([
            'installation_id' => $installation->getKey(),
            'token' => $issued->plainTextToken,

            // `abilities` is what THIS token carries, as it is on every other response. An
            // installation credential carries one ability and cannot act on the fleet at all.
            'abilities' => $issued->abilities,

            // **`granted_abilities` is gone from here** (#239), and its absence is the breaking
            // half of this change. It described what a session started by this installation would
            // carry, which stopped being true at `robot-council/core#222`: a session takes its
            // abilities from its `Access\Role` preset, so the list answered for no session.
            // `robot-council/cli` stopped reading it in `robot-council/cli#152` and shipped that in
            // v0.3.0; a client older than that gets one key fewer and must tolerate its absence.

            // A duration rather than an instant, as on the session endpoints: a helper whose clock
            // is wrong can still tell how long it has.
            'expires_in' => $credentials->installationMaxAgeDays() * 24 * 60 * 60,
        ], Response::HTTP_CREATED);
    }
}
