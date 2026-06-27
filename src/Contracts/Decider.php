<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Contracts;

use Padosoft\Iam\Client\DecisionRequest;
use Padosoft\Iam\Client\IamDecision;

/**
 * Trasporto di decisione pluggable: `LocalDecider` (PDP in-process), `HttpDecider` (Admin API),
 * `CachingDecider` (decorator). Il client di alto livello non sa quale trasporto sta usando.
 */
interface Decider
{
    public function decide(DecisionRequest $request): IamDecision;
}
