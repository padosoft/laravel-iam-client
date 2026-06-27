<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Deciders;

use Padosoft\Iam\Client\Contracts\Decider;
use Padosoft\Iam\Client\DecisionRequest;
use Padosoft\Iam\Client\IamDecision;
use Padosoft\Iam\Contracts\Authorization\AuthorizationEngine;

/**
 * Trasporto in-process (doc 06): quando il server IAM vive nella stessa app, il client delega
 * direttamente al PDP (`AuthorizationEngine`) senza un round-trip di rete. È il percorso del
 * monorepo/same-app deployment ed è il più veloce e affidabile.
 */
final class LocalDecider implements Decider
{
    public function __construct(private readonly AuthorizationEngine $engine) {}

    public function decide(DecisionRequest $request): IamDecision
    {
        try {
            return IamDecision::fromArray($this->engine->check($request->toArray()));
        } catch (\Throwable $e) {
            // Fail-closed speculare a HttpDecider: un errore del PDP in-process → deny, non un 500
            // opaco che lascerebbe l'esito indefinito.
            return IamDecision::deny('engine: '.$e::class);
        }
    }
}
