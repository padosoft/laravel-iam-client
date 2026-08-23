<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Padosoft\Iam\Client\Auth\DelegatedTokenVerifier;
use Padosoft\Iam\Client\IamClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * PEP per rotte ad audience DELEGATA: pretende un bearer delegato (claim `act`),
 * lo verifica via introspection (mandatory) e decide sull'INTERSEZIONE utente ∩
 * agente (`/decisions/check-delegated`). Un token non delegato qui è un 401: le
 * rotte per umani usano `iam.can`, quelle per agenti questa — mai ambiguità.
 *
 * Uso: ->middleware('iam.can.delegated:app:orders.read')
 */
final class IamCanDelegated
{
    public function __construct(
        private readonly DelegatedTokenVerifier $verifier,
        private readonly IamClient $client,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            abort(401, 'Delegated bearer token required.');
        }

        $delegation = $this->verifier->verify($bearer);
        if ($delegation === null || !$delegation->verified) {
            abort(401, 'Invalid or non-delegated token.');
        }

        $context = [];
        if ($delegation->grantId !== null) {
            $context['delegation_grant_id'] = $delegation->grantId;
        }

        if (!$this->client->canDelegated($delegation->sub, $delegation->actors, $permission, $context)) {
            abort(403, 'This delegated action is unauthorized.');
        }

        // Contesto per il downstream (controller/log): chi agisce, per conto di chi.
        $request->attributes->set('iam_delegation', [
            'sub' => $delegation->sub,
            'actors' => $delegation->actors,
            'grant_id' => $delegation->grantId,
            'scopes' => $delegation->scopes,
        ]);

        return $next($request);
    }
}
