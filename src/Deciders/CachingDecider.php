<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Deciders;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Padosoft\Iam\Client\Contracts\Decider;
use Padosoft\Iam\Client\DecisionRequest;
use Padosoft\Iam\Client\IamDecision;

/**
 * Decorator di cache: la decisione del PDP è deterministica a parità di input, quindi è cachabile
 * per un TTL breve (i grant cambiano nel tempo). Le query `explain` NON si cachano (servono la
 * spiegazione fresca e non vanno condivise tra contesti). La chiave include tutti gli input.
 */
final class CachingDecider implements Decider
{
    public function __construct(
        private readonly Decider $inner,
        private readonly CacheRepository $cache,
        private readonly int $ttl,
        private readonly bool $enabled = true,
    ) {}

    public function decide(DecisionRequest $request): IamDecision
    {
        if (!$this->enabled || $this->ttl <= 0 || $request->explain) {
            return $this->inner->decide($request);
        }

        // IAM-23: se la chiave di cache non è calcolabile (context non serializzabile), NON cachare — una
        // chiave che collide servirebbe un ALLOW di un subject a un altro. Delega all'inner (fail-closed).
        try {
            $key = 'iam:dec:'.$request->cacheKey();
        } catch (\JsonException) {
            return $this->inner->decide($request);
        }
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return IamDecision::fromArray($cached);
        }

        $decision = $this->inner->decide($request);
        $this->cache->put($key, $decision->toArray(), $this->ttl);

        return $decision;
    }
}
