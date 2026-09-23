<?php

declare(strict_types=1);

/**
 * What counts as a principal, and what counts as its token.
 *
 * These are the checks the machine routes are built on, tested directly rather than only through a
 * request. Through a request they are unreachable: a signed-in human is turned away by the
 * principal's class before the token's class is ever looked at, so a middleware test asserting 401
 * proves the first check and says nothing about the second.
 *
 * @command  vendor/bin/pest --compact tests/TokenPrincipalTest.php
 */

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RobotCouncil\Tests\Fixtures\HostTokenModel;

it('refuses the transient token a browser session carries', function (): void {
    $transient = new TransientToken;

    // Sanctum's own answer, which is why this cannot be delegated to `can()`
    expect($transient->can(Ability::CoordinatorDirect->value))->toBeTrue()
        ->and($transient->can('an-ability-nobody-defined'))->toBeTrue();

    expect(Tokens::isStored($transient))->toBeFalse()
        ->and(Tokens::allows($transient, Ability::SessionsStart))->toBeFalse()
        ->and(Tokens::abilities($transient))->toBeEmpty();
});

it('admits a stored token, and only for the abilities it carries', function (): void {
    $token = new PersonalAccessToken;
    $token->forceFill(['abilities' => [Ability::SessionsStart->value]]);

    expect(Tokens::isStored($token))->toBeTrue()
        ->and(Tokens::allows($token, Ability::SessionsStart))->toBeTrue()
        ->and(Tokens::allows($token, Ability::CoordinatorDirect))->toBeFalse()
        ->and(Tokens::abilities($token))->toBe([Ability::SessionsStart->value]);
});

it("admits a host's own token model, which need not be Sanctum's", function (): void {
    // What `Sanctum::usePersonalAccessTokenModel()` accepts: any `HasAbilities` implementation
    $token = new HostTokenModel;
    $token->forceFill(['abilities' => [Ability::SessionsStart->value]]);

    expect($token)->not->toBeInstanceOf(PersonalAccessToken::class)
        ->and(Tokens::isStored($token))->toBeTrue()
        ->and(Tokens::allows($token, Ability::SessionsStart))->toBeTrue()
        ->and(Tokens::allows($token, Ability::TasksCreate))->toBeFalse()
        ->and(Tokens::abilities($token))->toBe([Ability::SessionsStart->value]);
});

it('treats an absent token as no token at all', function (): void {
    expect(Tokens::isStored(null))->toBeFalse()
        ->and(Tokens::allows(null, Ability::SessionsStart))->toBeFalse()
        ->and(Tokens::abilities(null))->toBeEmpty();
});

it('reports a delete that did not answer with a count', function (): void {
    expect(Tokens::deleted(3))->toBe(3)
        ->and(Tokens::deleted(null))->toBe(0)
        ->and(Tokens::deleted('3'))->toBe(0);
});

it('returns a list of abilities after dropping one from the middle', function (): void {
    // **Two `Unwrap*` mutants survived here**, and they surfaced only because #172 started calling
    // into this class. `array_filter` preserves keys, so without `array_values` a token whose
    // abilities column holds a non-string in the middle comes back keyed `{0, 2}` -- which
    // `json_encode` writes as an OBJECT, on a value that reaches an agent as `abilities`.
    //
    // Driven through a real model rather than a stub, because `Tokens::abilities()` narrows on
    // `instanceof Model` before it reads the column.
    $token = new PersonalAccessToken;
    $token->setRawAttributes(['abilities' => json_encode(['tasks:create', null, 'events:post'])]);

    $abilities = Tokens::abilities($token);

    expect($abilities)->toBe(['tasks:create', 'events:post'])
        // The keys, which `toBe` already compares, said as bytes too: an object here is the defect.
        ->and(json_encode($abilities))->toBe('["tasks:create","events:post"]');
});

it('refuses to serve a route whose principal middleware never ran', function (): void {
    $request = Request::create('/robot-council/api/sessions', 'POST');

    expect(fn (): Installation => Principal::installation($request))
        ->toThrow(RuntimeException::class, 'installation middleware')
        ->and(fn (): AgentSession => Principal::agentSession($request))
        ->toThrow(RuntimeException::class, 'agent-session middleware');
});

it('refuses a principal of the wrong kind left on the request', function (): void {
    $request = Request::create('/robot-council/api/sessions', 'POST');

    // The agent guard's principal, on a route expecting the installation's
    $request->attributes->set(Principal::INSTALLATION, new AgentSession);
    $request->attributes->set(Principal::AGENT_SESSION, new Installation);

    expect(fn (): Installation => Principal::installation($request))->toThrow(RuntimeException::class)
        ->and(fn (): AgentSession => Principal::agentSession($request))->toThrow(RuntimeException::class);
});
