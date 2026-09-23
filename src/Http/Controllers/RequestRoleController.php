<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RobotCouncil\Access\Role;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\RoleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a session ask to be a different role.
 *
 * **It needs no ability, because asking is not doing.** Every other write on the agent API is
 * gated by `Http\Middleware\RequireAbility`; this one is deliberately not, since a session with the
 * narrowest role there is has to be able to ask for a wider one -- gating the request on an ability
 * only the wider role carries would make the flow reachable only by sessions that did not need it.
 *
 * **The request changes nothing the session may do.** It records what was asked and returns; the
 * token in the caller's hand is untouched, and stays untouched until an administrator decides. That
 * is the whole point: `POST api/sessions` is an unattended call from a bridge, so a role granted on
 * the strength of asking would be a role asserted, and any checkout could take `coordinator:direct`
 * by asking for it.
 */
final class RequestRoleController
{
    /**
     * Record the role this session would like to be.
     *
     * @param  Request  $request  The incoming request.
     * @param  RoleRequests  $requests  The request store.
     * @return JsonResponse What is pending, and what the session still holds meanwhile.
     */
    public function __invoke(Request $request, RoleRequests $requests): JsonResponse
    {
        $session = Principal::agentSession($request);

        $request->validate([
            // `Rule::enum` rather than a hand-written `in:`, so a fourth role needs no edit here.
            // An unknown name is a 422 rather than a silent `build`, which is what an
            // `Access\Role::tryFrom()` would have given.
            'role' => ['required', 'string', Rule::enum(Role::class)],
        ], [
            // **The message names the valid roles, which the rule's own does not.** Laravel's is
            // "The selected role is invalid", and a client that misspelled one learns nothing from
            // it -- an acceptance criterion of `robot-council/core#222` is that the refusal says
            // what would have worked. Built from the enum so it cannot fall behind the cases.
            'role' => 'Roles are: '.implode(', ', array_map(
                static fn (Role $role): string => $role->value,
                Role::cases()
            )).'.',
        ]);

        $wanted = Role::from($request->string('role')->value());

        $pending = $requests->request($session, $wanted);

        return new JsonResponse([
            'session_id' => $session->getKey(),

            // False when the session already holds that role, or has gone. Either way nothing is
            // waiting for an administrator, and saying so is what stops a client retrying forever.
            'pending' => $pending,
            'requested_role' => $pending ? $wanted->value : null,

            // **What it holds NOW, which the request did not change.** Returned beside the request
            // so a client cannot read a 2xx as the change having happened.
            'role' => $session->role->value,
        ], $pending ? Response::HTTP_ACCEPTED : Response::HTTP_OK);
    }
}
