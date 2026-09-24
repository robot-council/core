<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Admit a GitHub webhook delivery only when it is signed with the configured secret (#318).
 *
 * **The HMAC is over the raw body, compared in constant time.** GitHub sends
 * `X-Hub-Signature-256: sha256=<hex>`, the SHA-256 HMAC of the exact bytes it posted, keyed with the
 * secret configured on the webhook. The body is read as bytes rather than re-encoded, since any
 * re-encoding produces different bytes and a signature that never matches, and `hash_equals`
 * keeps a timing difference from telling an attacker how many leading characters were right.
 *
 * **With no secret configured the route answers 404.** An unset secret would otherwise make every
 * signature comparison against an empty key -- which anybody can compute -- so the endpoint does
 * not exist until an operator has configured one. The same answer is given for a secret shorter
 * than 16 characters, which is a secret in name only.
 *
 * Declared AFTER the route's limiter, which `CLAUDE.md` records is what decides the order: a
 * limiter declared behind this would never run for a request this refuses, and an unsigned flood
 * would be unlimited.
 */
final class VerifyGitHubSignature
{
    /**
     * The shortest secret the endpoint accepts as configured.
     */
    public const int MIN_SECRET_LENGTH = 16;

    /**
     * @param  Repository  $config  The application's configuration.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * Refuse an unsigned or mis-signed delivery.
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure(Request): Response  $next  The rest of the pipeline.
     * @return Response The pipeline's response.
     *
     * @throws NotFoundHttpException When no usable secret is configured.
     * @throws UnauthorizedHttpException When the signature is missing or wrong.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->config->get('robot-council.github.webhook_secret');

        if (! \is_string($secret) || \strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new NotFoundHttpException;
        }

        $signature = $request->headers->get('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! \is_string($signature) || ! hash_equals($expected, $signature)) {
            throw new UnauthorizedHttpException('X-Hub-Signature-256', "The delivery is not signed with this fleet's secret.");
        }

        return $next($request);
    }
}
