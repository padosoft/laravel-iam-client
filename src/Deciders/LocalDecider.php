<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Deciders;

use Padosoft\Iam\Client\Contracts\Decider;
use Padosoft\Iam\Client\DecisionRequest;
use Padosoft\Iam\Client\IamDecision;
use Padosoft\Iam\Contracts\Authorization\AuthorizationEngine;
use Padosoft\Iam\Contracts\Delegation\ActorRef;
use Padosoft\Iam\Contracts\Delegation\DelegatedAuthorizationEngine;
use Padosoft\Iam\Contracts\Delegation\DelegationChain;
use Padosoft\Iam\Contracts\Support\SubjectRef;

/**
 * Trasporto in-process (doc 06): quando il server IAM vive nella stessa app, il client delega
 * direttamente al PDP (`AuthorizationEngine`) senza un round-trip di rete. È il percorso del
 * monorepo/same-app deployment ed è il più veloce e affidabile.
 *
 * Richieste DELEGATE (catena `act`): passano dal DelegatedAuthorizationEngine (intersezione
 * utente ∩ agente, modulo -agents). Fail-closed: modulo assente ⇒ deny, MAI un check
 * single-subject implicito per un token che porta `act`.
 */
final class LocalDecider implements Decider
{
    public function __construct(
        private readonly AuthorizationEngine $engine,
        private readonly ?DelegatedAuthorizationEngine $delegated = null,
    ) {}

    public function decide(DecisionRequest $request): IamDecision
    {
        try {
            if ($request->isDelegated()) {
                return $this->decideDelegated($request);
            }

            return IamDecision::fromArray($this->engine->check($request->toArray()));
        } catch (\Throwable $e) {
            // Fail-closed speculare a HttpDecider: un errore del PDP in-process → deny, non un 500
            // opaco che lascerebbe l'esito indefinito.
            return IamDecision::deny('engine: '.$e::class);
        }
    }

    private function decideDelegated(DecisionRequest $request): IamDecision
    {
        if ($this->delegated === null) {
            return IamDecision::deny('delegated engine unavailable (installa padosoft/laravel-iam-agents)');
        }

        $actors = [];
        foreach ($request->actors ?? [] as $actor) {
            if (!str_starts_with($actor, ActorRef::SUBJECT_TYPE.':')) {
                return IamDecision::deny('invalid actor chain');
            }
            $actors[] = ActorRef::fromAgentId(substr($actor, strlen(ActorRef::SUBJECT_TYPE) + 1));
        }

        $query = $request->toArray();
        unset($query['subject'], $query['actors']);

        return IamDecision::fromArray($this->delegated->checkDelegated(
            new SubjectRef($request->subjectType, $request->subjectId),
            new DelegationChain(...$actors),
            $query,
        ));
    }
}
