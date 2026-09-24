<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RobotCouncil\Support\GitHubState;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receive one GitHub webhook delivery, already verified (#318).
 *
 * `Http\Middleware\VerifyGitHubSignature` has checked the signature before this runs, and
 * `Support\GitHubState` applies the delivery. A delivery the fleet does not use answers 202, so
 * GitHub records it as delivered rather than retrying it; one carrying something GitHub would not
 * send answers 422, which GitHub shows as a failed delivery an operator can see.
 */
final class GitHubWebhookController
{
    /**
     * Apply the delivery.
     *
     * @param  Request  $request  The incoming request.
     * @param  GitHubState  $state  The store.
     * @return JsonResponse What came of it.
     */
    public function __invoke(Request $request, GitHubState $state): JsonResponse
    {
        $event = $request->headers->get('X-GitHub-Event');
        $delivery = $request->headers->get('X-GitHub-Delivery');

        // GitHub's own check that the endpoint answers, sent once when the hook is created
        if ($event === 'ping') {
            return new JsonResponse(['ok' => true]);
        }

        // Decoded from the signed bytes themselves, whatever content type was configured: a hook
        // set to `application/x-www-form-urlencoded` posts the JSON as a `payload` field
        $raw = $request->getContent();

        // Read by hand rather than with `parse_str()`, which honors `max_input_vars` and warns past
        // it -- a warning the framework turns into a 500 rather than a refusal
        if (str_starts_with((string) $request->headers->get('Content-Type'), 'application/x-www-form-urlencoded')) {
            $raw = preg_match('/(?:^|&)payload=([^&]*)/', $raw, $field) === 1 ? urldecode($field[1]) : '';
        }

        $payload = json_decode($raw, true);

        if (! \is_string($event) || ! \is_string($delivery) || ! \is_array($payload)) {
            return new JsonResponse(['error' => 'A delivery carries X-GitHub-Event, X-GitHub-Delivery and a JSON body.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $outcome = $state->receive($delivery, $event, $payload);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse(['error' => $invalidArgumentException->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['outcome' => $outcome], $outcome === 'ignored' ? Response::HTTP_ACCEPTED : Response::HTTP_OK);
    }
}
